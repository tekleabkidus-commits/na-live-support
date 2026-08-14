<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Retention\MessagesRetention;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessagePartitions;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| Retention with legal hold (Doc 04 S20 step 9a — T-08, EC-09, AC-09, BR-03)
|--------------------------------------------------------------------------
|
| Real physics end to end: a synthetic 14-month-old partition with committed
| rows for a HELD and an UNHELD conversation, real MinIO objects, real
| cross-tenant revision purge on the platform connection, real DETACH+DROP on
| the migrator. AC-09: exactly the eligible partitions drop, holds are
| respected, retention.completed carries the counts.
*/

function retentionPartition(CarbonImmutable $month): string
{
    $name = sprintf('messages_%s', $month->format('Y_m'));
    $migrator = DB::connection('pgsql_migrator');

    $migrator->statement(sprintf(
        "CREATE TABLE %s PARTITION OF messages FOR VALUES FROM ('%s') TO ('%s')",
        $name,
        $month->toDateTimeString(),
        $month->addMonth()->toDateTimeString(),
    ));
    // Same protection the maintenance path applies — never a bare partition.
    $migrator->statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
    $migrator->statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
    $migrator->statement("CREATE POLICY tenant_isolation ON {$name} USING (tenant_id = current_setting('app.tenant_id')::uuid) WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid)");
    $migrator->statement("REVOKE DELETE ON {$name} FROM app");

    return $name;
}

/** @return array{0: string, 1: string} inserted message ids (plain, with-media) */
function retentionRows(string $tenantId, string $conversationId, CarbonImmutable $month, ?string $mediaKey): array
{
    $platform = DB::connection('pgsql_platform');
    $plain = (string) Str::uuid7();
    $withMedia = (string) Str::uuid7();

    foreach ([[$plain, '[]'], [$withMedia, json_encode([[
        'kind' => 'photo', 'mime' => 'image/jpeg', 'scan_status' => 'clean',
        'storage_key' => $mediaKey, 'thumbs' => [],
    ]], JSON_THROW_ON_ERROR)]] as [$id, $media]) {
        if ($media === '[]' || $mediaKey !== null) {
            $platform->table('messages')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
                'direction' => 'in',
                'author_type' => 'customer',
                'kind' => 'text',
                'body' => 'aged fixture',
                'media' => $media,
                'channel_meta' => '{}',
                'created_at' => $month->addDays(3),
            ]);
        }
    }

    return [$plain, $withMedia];
}

test('AC-09: the run drops exactly the eligible partition, copies held rows (with revision history) to hold storage, deletes unheld media, and announces counts', function () {
    $tenant = createTenant();
    $tenantId = $tenant->tenantId();
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096, mediaTypes: ['photo'], supportsTyping: false, supportsEdit: false,
            supportsDelete: false, supportsButtons: false, replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create(['display_name' => 'Fixture', 'username' => '@fixture_ret', 'is_shell' => true, 'created_source' => 'staff']);
    $repo = app(ConversationRepository::class);
    $held = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-held', $customer->identifier());
    $unheld = $repo->findOrCreateForInbound('fakechan', 'bot-1', 'chat-unheld', $customer->identifier());

    DB::table('conversations')->where('id', $held->identifier())->update(['legal_hold' => true]);

    commitFixtures();

    $now = CarbonImmutable::now('UTC');
    $oldMonth = $now->startOfMonth()->subMonths(14);   // beyond the 13-month default: drops
    $boundaryMonth = $now->startOfMonth()->subMonths(13); // exactly at it: survives

    $oldName = retentionPartition($oldMonth);
    $boundaryName = retentionPartition($boundaryMonth);

    $heldKey = sprintf('t/%s/conversations/%s/held.jpg', $tenantId, $held->identifier());
    $unheldKey = sprintf('t/%s/conversations/%s/unheld.jpg', $tenantId, $unheld->identifier());
    Storage::disk('media')->put($heldKey, 'held-bytes');
    Storage::disk('media')->put($unheldKey, 'unheld-bytes');

    try {
        [$heldPlain] = retentionRows($tenantId, $held->identifier(), $oldMonth, $heldKey);
        retentionRows($tenantId, $unheld->identifier(), $oldMonth, $unheldKey);

        // A revision on the held row — evidence that must survive the drop.
        DB::connection('pgsql_platform')->table('message_revisions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'message_id' => $heldPlain,
            'message_created_at' => $oldMonth->addDays(3),
            'conversation_id' => $held->identifier(),
            'revision_kind' => 'edit',
            'source' => 'customer',
            'prior_body' => 'the ORIGINAL wording',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $result = asPlatformWorker(fn (): array => app(MessagesRetention::class)->run($now));

        $partitions = app(MessagePartitions::class);
        expect($result['dropped'])->toBe([$oldName])
            ->and($partitions->existing())->not->toContain($oldName)
            ->and($partitions->existing())->toContain($boundaryName) // the boundary SURVIVES
            ->and($result['held'])->toBe(2)
            ->and($result['removed'])->toBe(2);

        // Hold storage: both held rows present, the revised one carrying its history.
        $platform = DB::connection('pgsql_platform');
        $holdRows = $platform->table('legal_hold_messages')->where('tenant_id', $tenantId)->get();
        expect($holdRows)->toHaveCount(2);

        $revised = $holdRows->firstWhere('id', $heldPlain);
        expect($revised)->not->toBeNull()
            ->and((string) $revised->revisions)->toContain('the ORIGINAL wording');

        // Media: unheld object GONE, held object KEPT.
        expect(Storage::disk('media')->exists($unheldKey))->toBeFalse()
            ->and(Storage::disk('media')->exists($heldKey))->toBeTrue();

        // The month's revisions are purged (the FK could never have been trusted).
        expect($platform->table('message_revisions')->where('message_id', $heldPlain)->count())->toBe(0);

        // Announced per tenant, with counts, plus the audit entry.
        $outbox = $platform->table('outbox')->where('tenant_id', $tenantId)->where('name', 'retention.completed')->first();
        $payload = json_decode((string) $outbox->payload, true);
        expect($outbox)->not->toBeNull()
            ->and($payload['messages_held'])->toBe(2)
            ->and($payload['messages_removed'])->toBe(2)
            ->and($platform->table('audit_log')->where('tenant_id', $tenantId)->where('action_key', 'retention.completed')->count())->toBe(1);
    } finally {
        foreach ([$oldName, $boundaryName] as $name) {
            removeMessagesPartition($name); // no-op for the dropped one
        }
        cleanupTenants([$tenantId]);
    }
});

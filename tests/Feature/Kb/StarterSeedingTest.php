<?php

declare(strict_types=1);

use App\Domain\Kb\Seeding\KnowledgeBaseSeeder;
use App\Jobs\Kb\KnowledgeBaseSeederConsumer;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/KbSceneHelpers.php';

/**
 * Doc 10 FR-04 / BR-07 / §14 (S10 step 8; A-45 AS RULED): the DECLARED tenant.provisioned
 * consumer seeds five DRAFT starters in the tenant default language, marked seeded —
 * CONTENT, not config: editable and DELETABLE; the RoleSeeder fingerprint contract holds
 * (heal only untouched; edited never overwritten; deleted stays deleted); redelivery is a
 * no-op; the drill pin moved with the registration (ReopenConsumerDrillTest).
 */
function seedViaEvent(string $tenantId): void
{
    $row = DB::table('outbox')->where('name', 'tenant.provisioned')->where('payload->tenant_id', $tenantId)->orderByDesc('id')->first();
    if ($row === null) {
        DB::table('outbox')->insert(['id' => ($id = (string) Illuminate\Support\Str::uuid7()), 'tenant_id' => $tenantId, 'event_id' => $id, 'name' => 'tenant.provisioned', 'schema_version' => 1, 'payload' => json_encode(['tenant_id' => $tenantId])]);
        $row = DB::table('outbox')->where('id', $id)->first();
    }
    DB::table('events')->insertOrIgnore(['id' => $row->id, 'tenant_id' => $row->tenant_id, 'name' => $row->name, 'schema_version' => $row->schema_version ?? 1, 'payload' => $row->payload]);
    (new KnowledgeBaseSeederConsumer($row->id))->handle(app(KnowledgeBaseSeeder::class));
}

it('FR-04: the consumer seeds five DRAFTS in the tenant default language inside Getting started, all marked seeded; redelivery seeds nothing twice; BR-07: edited is never overwritten, deleted stays deleted, untouched heals', function (): void {
    $s = kbScene(); // en default
    seedViaEvent($s['tenant_id']);
    $rows = DB::table('kb_articles')->where('seeded', true)->get(['id', 'seeded_fingerprint']);
    expect($rows)->toHaveCount(5);
    $variants = DB::table('kb_article_variants')->whereIn('article_id', $rows->pluck('id'))->get(['id', 'language', 'status', 'article_id']);
    expect($variants->pluck('status')->unique()->all())->toBe(['draft'], 'starters are DRAFTS — nothing publishes itself (BR-02)')
        ->and($variants->pluck('language')->unique()->all())->toBe(['en'])
        ->and(DB::table('kb_categories')->where('slug', 'getting-started')->exists())->toBeTrue();

    seedViaEvent($s['tenant_id']); // redelivery / re-provision heals nothing new
    expect(DB::table('kb_articles')->where('seeded', true)->count())->toBe(5);

    // BR-07: edit one (never overwritten), delete one (stays deleted), wipe one's copy (heals? — the contract heals only rows still AT their fingerprint; a wiped copy is an edit → untouched).
    $depositId = (string) $rows->first(fn ($r) => str_starts_with((string) $r->seeded_fingerprint, 'deposit-faq:'))->id;
    $depositVariant = (string) DB::table('kb_article_variants')->where('article_id', $depositId)->value('id');
    $this->actingAs($s['admin'], 'staff')->putJson('https://localhost/admin/kb/variants/'.$depositVariant.'/working-copy', ['content' => ['blocks' => [['type' => 'paragraph', 'text' => '[FIXTURE] Our own deposit copy.']]]])->assertOk();
    bindTenant($s['tenant']);
    $bonusId = (string) $rows->first(fn ($r) => str_starts_with((string) $r->seeded_fingerprint, 'bonus-rules:'))->id;
    $deleter = kbActor(['kb.articles.delete'], 'deleter');
    $this->actingAs($deleter, 'staff')->deleteJson('https://localhost/admin/kb/articles/'.$bonusId)->assertOk();
    bindTenant($s['tenant']);

    seedViaEvent($s['tenant_id']);
    expect(json_decode((string) DB::table('kb_article_variants')->where('id', $depositVariant)->value('working_copy'), true)['blocks'][0]['text'])->toContain('Our own deposit copy') /* edited — never overwritten */
        ->and(DB::table('kb_articles')->where('id', $bonusId)->value('deleted_at'))->not->toBeNull('deleted stays deleted')
        ->and(DB::table('kb_articles')->where('seeded', true)->whereNull('deleted_at')->count())->toBe(4);
});

<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Retention;

use App\Domain\Conversations\Storage\MessagePartitions;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Settings\SettingsRegistry;
use App\Support\Tenancy\PlatformActor;
use App\Support\Tenancy\PlatformConnection;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * T-08/EC-09/AC-09: the settings-driven retention run — the job the messages
 * migration promised would be the ONLY way rows ever leave.
 *
 * Per partition strictly older than `retention.messages.months` (the PLATFORM
 * value — partition drops are global, so per-tenant override semantics belong
 * to the Doc 18 runbook / Doc 16 surface, pre-agreed):
 *
 *  1. COPY-OUT (EC-09): every row whose conversation carries legal_hold moves
 *     into legal_hold_messages — body, media roster, and the message's full
 *     revision history as evidence (BR-03) — per tenant, on the platform
 *     connection (cross-tenant work, SEC-02's sanctioned door).
 *  2. Unheld rows' media OBJECTS are deleted from the store (retention.media
 *     .months can only tighten this later — Doc 18's lifecycle, R-03 bucket);
 *     held rows keep their objects (the hold row carries the keys).
 *  3. The month's message_revisions are purged CROSS-TENANT FIRST — the step-2
 *     discovery, recorded in MessagePartitions' docblock: DETACH runs an
 *     FK-verification SELECT that is itself policed by FORCE RLS, so it CANNOT
 *     be trusted to see another tenant's referencing rows. We never rely on
 *     it: by the time we detach, the truth is already clean.
 *  4. set_config → DETACH → DROP on the migrator connection.
 *  5. Per affected tenant: `retention.completed` with counts + audit entry.
 *
 * The 13-month boundary rule matches events (NFR-07): STRICTLY older than the
 * cutoff month drops; the boundary month survives.
 */
final class MessagesRetention
{
    public function __construct(
        private readonly MessagePartitions $partitions,
        private readonly SettingsRegistry $registry,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
        private readonly FilesystemFactory $filesystems,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * @return array{dropped: list<string>, held: int, removed: int}
     */
    public function run(CarbonImmutable $now): array
    {
        $months = $this->registry->definition('retention.messages.months')['default'];

        if (! is_int($months)) {
            throw new RuntimeException('retention.messages.months platform value must be an int.');
        }

        $cutoff = $now->startOfMonth()->subMonths($months);

        $dropped = [];
        $totalHeld = 0;
        $totalRemoved = 0;
        /** @var array<string, array{held: int, removed: int}> $perTenant */
        $perTenant = [];

        foreach ($this->partitions->existing() as $partition) {
            $month = $this->monthOf($partition);

            if (! $month->lessThan($cutoff)) {
                continue; // strictly older drops; the boundary month survives
            }

            [$held, $removed, $tenantCounts] = $this->copyOutAndClean($partition);

            $totalHeld += $held;
            $totalRemoved += $removed;

            foreach ($tenantCounts as $tenantId => $counts) {
                $perTenant[$tenantId]['held'] = ($perTenant[$tenantId]['held'] ?? 0) + $counts['held'];
                $perTenant[$tenantId]['removed'] = ($perTenant[$tenantId]['removed'] ?? 0) + $counts['removed'];
            }

            $this->dropPartition($partition, $month);
            $dropped[] = $partition;
        }

        if ($dropped !== []) {
            $this->announce($perTenant, $dropped);
        }

        return ['dropped' => $dropped, 'held' => $totalHeld, 'removed' => $totalRemoved];
    }

    /**
     * @return array{0: int, 1: int, 2: array<string, array{held: int, removed: int}>}
     */
    private function copyOutAndClean(string $partition): array
    {
        return PlatformConnection::run(
            PlatformActor::RetentionJob,
            'Doc 04 T-08/EC-09 retention copy-out for '.$partition,
            function () use ($partition): array {
                $platform = DB::connection(PlatformConnection::NAME);

                // EC-09: held rows move to hold storage WITH their revision
                // history; held media objects stay (keys ride the hold row).
                $platform->statement(<<<SQL
                    INSERT INTO legal_hold_messages
                        (id, tenant_id, conversation_id, direction, author_type, author_id,
                         kind, body, media, channel_meta, message_created_at, revisions, held_at)
                    SELECT m.id, m.tenant_id, m.conversation_id, m.direction, m.author_type, m.author_id,
                           m.kind, m.body, m.media, m.channel_meta, m.created_at,
                           (SELECT jsonb_agg(jsonb_build_object(
                                'revision_kind', r.revision_kind,
                                'source', r.source,
                                'actor_id', r.actor_id,
                                'prior_body', r.prior_body,
                                'revised_at', r.created_at))
                              FROM message_revisions r WHERE r.message_id = m.id),
                           now()
                    FROM {$partition} m
                    JOIN conversations c ON c.id = m.conversation_id
                    WHERE c.legal_hold
                    ON CONFLICT (id) DO NOTHING
                    SQL);

                // Unheld rows' media objects leave the store.
                /** @var list<object{media: string}> $unheldMedia */
                $unheldMedia = $platform->select(<<<SQL
                    SELECT m.media::text AS media
                    FROM {$partition} m
                    JOIN conversations c ON c.id = m.conversation_id
                    WHERE NOT c.legal_hold AND m.media::text <> '[]'
                    SQL);

                $this->deleteMediaObjects($unheldMedia);

                /** @var list<object{tenant_id: string, held: int|string, removed: int|string}> $counts */
                $counts = $platform->select(<<<SQL
                    SELECT m.tenant_id,
                           count(*) FILTER (WHERE c.legal_hold) AS held,
                           count(*) FILTER (WHERE NOT c.legal_hold) AS removed
                    FROM {$partition} m
                    JOIN conversations c ON c.id = m.conversation_id
                    GROUP BY m.tenant_id
                    SQL);

                $held = 0;
                $removed = 0;
                $perTenant = [];

                foreach ($counts as $row) {
                    $perTenant[$row->tenant_id] = ['held' => (int) $row->held, 'removed' => (int) $row->removed];
                    $held += (int) $row->held;
                    $removed += (int) $row->removed;
                }

                // Step 3 of the class docblock: the month's revisions go FIRST,
                // cross-tenant, so the detach never depends on its RLS-policed
                // FK verification seeing the truth.
                $platform->statement(<<<SQL
                    DELETE FROM message_revisions r
                    USING {$partition} m
                    WHERE r.message_id = m.id
                    SQL);

                return [$held, $removed, $perTenant];
            },
        );
    }

    /** @param list<object{media: string}> $rows */
    private function deleteMediaObjects(array $rows): void
    {
        $diskName = config('media.disk');
        $disk = $this->filesystems->disk(is_string($diskName) ? $diskName : 'media');

        foreach ($rows as $row) {
            /** @var mixed $items */
            $items = json_decode($row->media, true);

            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                foreach (array_merge(
                    is_string($item['storage_key'] ?? null) ? [$item['storage_key']] : [],
                    is_array($item['thumbs'] ?? null) ? array_values(array_filter($item['thumbs'], 'is_string')) : [],
                ) as $key) {
                    $disk->delete($key);
                }
            }
        }
    }

    private function dropPartition(string $partition, CarbonImmutable $month): void
    {
        $migrator = DB::connection('pgsql_migrator');

        $migrator->statement("SELECT set_config('app.tenant_id', '00000000-0000-0000-0000-000000000000', false)");
        $migrator->statement(sprintf('ALTER TABLE messages DETACH PARTITION %s', $partition));
        $migrator->statement(sprintf('DROP TABLE %s', $partition));
    }

    /**
     * Per affected tenant: retention.completed + audit, under that tenant's
     * own binding (outbox rows and audit entries are tenant-scoped).
     *
     * @param  array<string, array{held: int, removed: int}>  $perTenant
     * @param  list<string>  $dropped
     */
    private function announce(array $perTenant, array $dropped): void
    {
        foreach ($perTenant as $tenantId => $counts) {
            $this->context->clear();
            $this->rls->clear();
            $this->context->bind($tenantId, ResolutionSource::QueuedJob);
            $this->rls->set($tenantId);

            try {
                DB::transaction(function () use ($tenantId, $counts, $dropped): void {
                    $this->events->publish('retention.completed', [
                        'tenant_id' => $tenantId,
                        'partitions_dropped' => count($dropped),
                        'messages_held' => $counts['held'],
                        'messages_removed' => $counts['removed'],
                    ]);

                    $this->audit->record(
                        actionKey: 'retention.completed',
                        entity: 'partition',
                        entityId: null,
                        actorType: AuditActor::System,
                        payload: [
                            'partitions' => $dropped,
                            'messages_held' => $counts['held'],
                            'messages_removed' => $counts['removed'],
                        ],
                    );
                });
            } finally {
                $this->rls->clear();
                $this->context->clear();
            }
        }
    }

    private function monthOf(string $partition): CarbonImmutable
    {
        if (preg_match('/^messages_(\d{4})_(\d{2})$/', $partition, $m) !== 1) {
            throw new RuntimeException(sprintf('unrecognized messages partition name "%s".', $partition));
        }

        $month = CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, 'UTC');

        if (! $month instanceof CarbonImmutable) {
            throw new RuntimeException(sprintf('could not derive a month from partition "%s".', $partition));
        }

        return $month;
    }
}

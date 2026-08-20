<?php

declare(strict_types=1);

namespace App\Domain\Ai\Config;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * THE ONE WRITER of ai_config_versions (Doc 09 FR-04; SCHEMA AMENDMENT 3
 * item 2, countersigned).
 *
 * - A version's payload is IMMUTABLE once written (grant physics; the UPDATE
 *   grant reaches only status/published stamps). Rollback REPUBLISHES an
 *   older payload as a NEW version — history is append-only in substance.
 * - Publishing is one transaction: supersede the current published row, flip
 *   the target draft to published. The partial unique index
 *   (tenant, kind, key) WHERE published is the pointer's physics; a
 *   concurrent publish surfaces as the ConfigVersionRace sentinel, never a
 *   raw 23505 (flag 2's countersigned condition; proven by the held-window
 *   race test). published_at is stamped IN SQL from clock_timestamp() —
 *   never an app clock (R-19b).
 * - Audit (SEC-05 high-visibility) + the ai.config.published event (FR-21)
 *   attach AT THE FIRST DOOR (step 3 wires emission here before any HTTP or
 *   Backoffice surface can reach this service) — no door exists at step 2b.
 */
final class ConfigVersions
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventPublisher $events,
        private readonly AuditLogger $audit,
    ) {}

    /** @param array<string, mixed> $payload
     * @return string the new draft version's id */
    public function draft(string $kind, string $key, array $payload, string $createdBy): string
    {
        try {
            return DB::transaction(function () use ($kind, $key, $payload, $createdBy): string {
                /** @var int|null $max */
                $max = DB::table('ai_config_versions')
                    ->where('kind', $kind)
                    ->where('key', $key)
                    ->max('version');
                $next = 1 + ($max ?? 0);

                $id = (string) new UuidV7;

                DB::table('ai_config_versions')->insert([
                    'id' => $id,
                    'tenant_id' => $this->context->tenantId(),
                    'kind' => $kind,
                    'key' => $key,
                    'version' => $next,
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'status' => 'draft',
                    'created_by' => $createdBy,
                ]);

                return $id;
            });
        } catch (UniqueConstraintViolationException) {
            throw ConfigVersionRace::onDraft($kind, $key);
        }
    }

    /** Flip a draft to the single published version of its (kind, key). */
    public function publish(string $versionId, string $publishedBy): void
    {
        try {
            DB::transaction(function () use ($versionId, $publishedBy): void {
                /** @var object{kind: string, key: string, status: string, version: int|string}|null $target */
                $target = DB::table('ai_config_versions')->where('id', $versionId)->first();

                if ($target === null) {
                    throw new InvalidArgumentException('unknown config version — nothing to publish (FR-04)');
                }
                if ($target->status !== 'draft') {
                    throw new InvalidArgumentException(
                        'only a DRAFT publishes; rolling back republishes an older payload as a new draft (FR-04)',
                    );
                }

                DB::update(
                    <<<'SQL'
                    UPDATE ai_config_versions
                       SET status = 'superseded', updated_at = clock_timestamp()
                     WHERE tenant_id = ? AND kind = ? AND key = ? AND status = 'published'
                    SQL,
                    [$this->context->tenantId(), $target->kind, $target->key],
                );

                DB::update(
                    <<<'SQL'
                    UPDATE ai_config_versions
                       SET status = 'published', published_at = clock_timestamp(),
                           published_by = ?, updated_at = clock_timestamp()
                     WHERE id = ?
                    SQL,
                    [$publishedBy, $versionId],
                );

                // FR-21 + SEC-05, inside the transaction (the outbox makes
                // the event transactional; a rolled-back publish emits
                // nothing) — the RgFlagService pattern.
                $this->events->publish('ai.config.published', [
                    'tenant_id' => $this->context->tenantId(),
                    'kind' => $target->kind,
                    'key' => $target->key,
                    'version' => (int) $target->version,
                    'config_version_id' => $versionId,
                ]);
                $this->audit->record(
                    'ai.config.published',
                    'ai_config_version',
                    $versionId,
                    AuditActor::Staff,
                    ['kind' => $target->kind, 'key' => $target->key, 'version' => (int) $target->version],
                    actorId: $publishedBy,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw ConfigVersionRace::onPublish($versionId);
        }
    }

    /**
     * FR-04's rollback: REPUBLISH an older version's payload as a new one.
     *
     * @return string the newly published version's id
     */
    public function rollbackTo(string $versionId, string $actorId): string
    {
        /** @var object{kind: string, key: string, payload: string}|null $old */
        $old = DB::table('ai_config_versions')->where('id', $versionId)->first();

        if ($old === null) {
            throw new InvalidArgumentException('unknown config version — nothing to roll back to (FR-04)');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($old->payload, true, 512, JSON_THROW_ON_ERROR);

        $newId = $this->draft($old->kind, $old->key, $payload, $actorId);
        $this->publish($newId, $actorId);

        // SEC-05: the trail reads "rolled back", not "published again".
        $this->audit->record(
            'ai.config.rolled_back',
            'ai_config_version',
            $newId,
            AuditActor::Staff,
            [
                'kind' => $old->kind,
                'key' => $old->key,
                'version' => (function () use ($newId): int {
                    $v = DB::table('ai_config_versions')->where('id', $newId)->value('version');

                    return is_numeric($v) ? (int) $v : 0;
                })(),
                'republished_version_of' => $versionId,
            ],
            actorId: $actorId,
        );

        return $newId;
    }

    /**
     * The active (published) version row for a config, if any.
     *
     * @return object{id: string, tenant_id: string, kind: string, key: string, version: int, payload: string, status: string, published_at: ?string, published_by: ?string, created_by: string}|null
     */
    public function published(string $kind, string $key): ?object
    {
        $row = DB::table('ai_config_versions')
            ->where('kind', $kind)
            ->where('key', $key)
            ->where('status', 'published')
            ->first();

        if ($row === null) {
            return null;
        }

        /** @var object{id: string, tenant_id: string, kind: string, key: string, version: int|string, payload: string, status: string, published_at: ?string, published_by: ?string, created_by: string} $row */
        return (object) [
            'id' => $row->id,
            'tenant_id' => $row->tenant_id,
            'kind' => $row->kind,
            'key' => $row->key,
            'version' => is_numeric($row->version) ? (int) $row->version : 0,
            'payload' => $row->payload,
            'status' => $row->status,
            'published_at' => $row->published_at,
            'published_by' => $row->published_by,
            'created_by' => $row->created_by,
        ];
    }
}

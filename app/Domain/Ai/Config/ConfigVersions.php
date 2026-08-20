<?php

declare(strict_types=1);

namespace App\Domain\Ai\Config;

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
    public function __construct(private readonly TenantContext $context) {}

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
                /** @var object{kind: string, key: string, status: string}|null $target */
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

        return $newId;
    }

    /** The active (published) version row for a config, if any. */
    public function published(string $kind, string $key): ?object
    {
        return DB::table('ai_config_versions')
            ->where('kind', $kind)
            ->where('key', $key)
            ->where('status', 'published')
            ->first();
    }
}

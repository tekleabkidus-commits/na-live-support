<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Doc 12 FR-22 / BR-06 / SEC-06 — publish mints an IMMUTABLE version (the
 * grant physics: form_versions is INSERT-only) and moves the published
 * pointer. Any FR-22 block refuses the whole publish — the dialog shows the
 * blocks plus the usage banner (what already binds this form) BEFORE the
 * operator commits. form.published is a high-risk audit event (SEC-06).
 */
final class FormPublisher
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SchemaValidator $validator,
        private readonly UsageBanner $usage,
        private readonly FormWriter $writer,
    ) {}

    /**
     * The publish dialog's data: the FR-22 validation results + usage banner.
     *
     * @param  array<string, mixed>  $schema
     * @return array{blocks: list<array<string, mixed>>, usage: array<string, list<array<string, string>>>}
     */
    public function preview(string $formId, array $schema): array
    {
        return ['blocks' => $this->validator->blocks($schema), 'usage' => $this->usage->forForm($formId)];
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{version_id: string, version: int}
     */
    public function publish(string $formId, array $schema, string $actorId): array
    {
        $blocks = $this->validator->blocks($schema);
        if ($blocks !== []) {
            throw new DomainException('publish_blocked: '.json_encode($blocks, JSON_THROW_ON_ERROR));
        }

        return DB::transaction(function () use ($formId, $schema, $actorId): array {
            // Serialize concurrent publishes on the form row (Postgres refuses
            // FOR UPDATE on aggregates); form_versions_sequence stays the arbiter.
            DB::table('forms')->where('id', $formId)->lockForUpdate()->value('id');
            $max = DB::table('form_versions')->where('form_id', $formId)->max('version');
            $version = (is_numeric($max) ? (int) $max : 0) + 1;
            $versionId = (string) Str::uuid7();
            DB::table('form_versions')->insert([
                'id' => $versionId, 'tenant_id' => $this->context->tenantId(),
                'form_id' => $formId, 'version' => $version,
                'schema' => json_encode($schema, JSON_THROW_ON_ERROR),
                'published_by' => $actorId,
            ]);
            if (DB::table('forms')->where('id', $formId)->update(['current_version_id' => $versionId, 'updated_at' => DB::raw('clock_timestamp()')]) !== 1) {
                throw new InvalidArgumentException('unknown form');
            }
            $this->writer->announce($formId, $versionId, 'published', $actorId);

            return ['version_id' => $versionId, 'version' => $version];
        });
    }
}

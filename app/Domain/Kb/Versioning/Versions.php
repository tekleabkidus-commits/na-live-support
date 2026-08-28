<?php

declare(strict_types=1);

namespace App\Domain\Kb\Versioning;

use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Http\CursorPagination;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * Doc 10 FR-08 / NFR-04 / AC-07 — versions are IMMUTABLE BY GRANT (the app role holds no
 * UPDATE on kb_article_versions; KbGrantPhysicsTest pins it). This door MINTS a version
 * (full snapshot + author + note + sanitizer flags/override + audience at publish),
 * lists history cursor-paginated (1k versions per article without degradation), diffs
 * any two, and RESTORES as a NEW DRAFT working copy — never a rewrite of history.
 */
final class Versions
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly BlockDiff $diff,
    ) {}

    /**
     * Called INSIDE the publish transaction only (Transitions::publish).
     *
     * @param  array<string, mixed>  $content
     * @param  list<array{block: int, sentence: string, pattern: string}>  $flags
     * @param  array{by: string, at: string, reason: string}|null  $override
     * @return array{version_id: string, version: int, content_hash: string}
     */
    public function mint(string $variantId, array $content, string $authorId, ?string $note, array $flags, ?array $override): array
    {
        /** @var object{id: string, article_id: string, language: string, title: string, slug: string, summary: string}|null $variant */
        $variant = DB::table('kb_article_variants')->where('id', $variantId)->lockForUpdate()->first(['id', 'article_id', 'language', 'title', 'slug', 'summary']);
        if ($variant === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        $audienceRaw = DB::table('kb_articles')->where('id', $variant->article_id)->value('audience');
        $audience = is_string($audienceRaw) ? $audienceRaw : 'internal';
        $max = DB::table('kb_article_versions')->where('variant_id', $variantId)->max('version');
        $next = 1 + (is_numeric($max) ? (int) $max : 0);
        $id = (string) new UuidV7;
        $hash = self::contentHash($content);
        DB::table('kb_article_versions')->insert([
            'id' => $id, 'tenant_id' => $this->context->tenantId(), 'variant_id' => $variantId, 'article_id' => $variant->article_id,
            'version' => $next, 'language' => $variant->language, 'title' => $variant->title, 'slug' => $variant->slug, 'summary' => $variant->summary,
            'content' => json_encode($content, JSON_THROW_ON_ERROR), 'content_hash' => $hash, 'audience_at_publish' => $audience,
            'author_id' => $authorId, 'note' => $note, 'sanitizer_flags' => json_encode($flags, JSON_THROW_ON_ERROR),
            'sanitizer_override' => $override === null ? null : json_encode($override, JSON_THROW_ON_ERROR),
        ]);

        return ['version_id' => $id, 'version' => $next, 'content_hash' => $hash];
    }

    /**
     * T-02 determinism: the same content → the same hash → the same chunks.
     *
     * @param  array<string, mixed>  $content
     */
    public static function contentHash(array $content): string
    {
        return hash('sha256', json_encode(self::canonical($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonical(...), $value);
        }
        ksort($value);

        return array_map(self::canonical(...), $value);
    }

    /**
     * NFR-04: cursor-paginated history (newest first by version, id tiebreak).
     *
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function history(string $variantId, CursorPagination $pagination): array
    {
        // Newest first over the platform's ASCENDING keyset walker (a Doc 01 file, not
        // widened here): the sort key is a UUID-SHAPED string — hex(max_int - version)
        // zero-padded in the first group, the row id's tail in the last — whose ascending
        // order IS version-descending and whose shape the cursor decoder vouches for;
        // the subquery lets the walker's WHERE name it.
        $inner = DB::table('kb_article_versions')->where('variant_id', $variantId)
            ->select(['id', 'version', 'author_id', 'note', 'content_hash', 'audience_at_publish', 'sanitizer_flags', 'sanitizer_override', DB::raw('published_at::text AS published_at'), DB::raw("lpad(to_hex(2147483647 - version), 8, '0') || '-0000-7000-8000-' || substr(replace(id::text, '-', ''), 21, 12) AS sort_key")]);
        $query = DB::query()->fromSub($inner, 'v')->select(['id', 'version', 'author_id', 'note', 'content_hash', 'audience_at_publish', 'sanitizer_flags', 'sanitizer_override', 'published_at', 'sort_key']);
        $page = $pagination->paginate($query, 'sort_key');
        $out = $page->toArray();
        $data = [];
        /** @var object{id: string, version: int|string, author_id: string, note: string|null, content_hash: string, audience_at_publish: string, sanitizer_flags: string, sanitizer_override: string|null, published_at: string} $row */
        foreach ($out['data'] as $row) {
            $data[] = [
                'id' => $row->id, 'version' => (int) $row->version, 'author_id' => $row->author_id, 'note' => $row->note, 'content_hash' => $row->content_hash,
                'audience_at_publish' => $row->audience_at_publish, 'sanitizer_flags' => json_decode($row->sanitizer_flags, true),
                'sanitizer_override' => $row->sanitizer_override === null ? null : json_decode($row->sanitizer_override, true), 'published_at' => $row->published_at,
            ];
        }

        return ['data' => $data, 'next_cursor' => $out['next_cursor']];
    }

    /** @return array{id: string, variant_id: string, article_id: string, version: int, language: string, title: string, slug: string, summary: string, content: array<string, mixed>, content_hash: string, author_id: string, note: string|null} */
    public function show(string $versionId): array
    {
        /** @var object{id: string, variant_id: string, article_id: string, version: int|string, language: string, title: string, slug: string, summary: string, content: string, content_hash: string, author_id: string, note: string|null}|null $row */
        $row = DB::table('kb_article_versions')->where('id', $versionId)->first();
        if ($row === null) {
            throw new InvalidArgumentException('unknown version');
        }
        $content = json_decode($row->content, true);
        /** @var array<string, mixed> $content */
        $content = is_array($content) ? $content : [];

        return ['id' => $row->id, 'variant_id' => $row->variant_id, 'article_id' => $row->article_id, 'version' => (int) $row->version, 'language' => $row->language, 'title' => $row->title, 'slug' => $row->slug, 'summary' => $row->summary, 'content' => $content, 'content_hash' => $row->content_hash, 'author_id' => $row->author_id, 'note' => $row->note];
    }

    /**
     * FR-08: block-aware diff between any two versions of the SAME variant.
     *
     * @return array<string, mixed>
     */
    public function diffBetween(string $fromVersionId, string $toVersionId): array
    {
        $from = $this->show($fromVersionId);
        $to = $this->show($toVersionId);
        if ($from['variant_id'] !== $to['variant_id']) {
            throw new InvalidArgumentException('versions belong to different variants');
        }

        return ['from' => $from['version'], 'to' => $to['version']] + $this->diff->diff($from['content'], $to['content']);
    }

    /**
     * FR-08: restore = a NEW DRAFT working copy from the old version; history untouched.
     *
     * @return array{variant_id: string, restored_from: int, draft: bool}
     */
    public function restore(string $versionId, string $actorId): array
    {
        $version = $this->show($versionId);

        return DB::transaction(function () use ($version, $versionId, $actorId): array {
            DB::update('UPDATE kb_article_variants SET working_copy = ?::jsonb, working_copy_saved_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?', [json_encode($version['content'], JSON_THROW_ON_ERROR), $version['variant_id']]);
            $this->audit->record('kb.variant.restored', 'kb_article_variant', $version['variant_id'], AuditActor::Staff, ['version_id' => $versionId, 'version' => $version['version']], actorId: $actorId);

            return ['variant_id' => $version['variant_id'], 'restored_from' => $version['version'], 'draft' => true];
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Kb\Search;

use Illuminate\Support\Facades\DB;

/**
 * FR-17's document builder — ONE composition, always FROM TRUTH: a published variant indexes its
 * CURRENT VERSION's text (what customers read); any other status indexes the working copy (what
 * the manager is editing) so the status filter answers honestly. Meilisearch addDocuments is an
 * upsert keyed on id → every write is idempotent (C-09 by construction); a soft-deleted article's
 * variants are removed.
 */
final class KbSearchDocuments
{
    public function __construct(private readonly KbSearchIndex $index) {}

    public function writeFor(string $variantId): void
    {
        /** @var object{id: string, article_id: string, language: string, status: string, title: string, summary: string, slug: string, working_copy: string, current_version_id: string|null, audience: string, category_id: string, tags: string, deleted_at: string|null}|null $v */
        $v = DB::table('kb_article_variants as v')->join('kb_articles as a', 'a.id', '=', 'v.article_id')->where('v.id', $variantId)
            ->first(['v.id', 'v.article_id', 'v.language', 'v.status', 'v.title', 'v.summary', 'v.slug', 'v.working_copy', 'v.current_version_id', 'a.audience', 'a.category_id', 'a.tags', 'a.deleted_at']);
        if ($v === null) {
            return;
        }
        $index = $this->index->ensure();
        if ($v->deleted_at !== null) {
            $index->deleteDocument($v->id);

            return;
        }
        $raw = $v->status === 'published' && $v->current_version_id !== null
            ? DB::table('kb_article_versions')->where('id', $v->current_version_id)->value('content')
            : $v->working_copy;
        $decoded = json_decode(is_string($raw) ? $raw : '{}', true);
        /** @var array<string, mixed> $content */
        $content = is_array($decoded) ? $decoded : [];
        $task = $index->addDocuments([[
            'id' => $v->id, 'article_id' => $v->article_id, 'language' => $v->language, 'visibility' => $v->audience, 'status' => $v->status,
            'category_id' => $v->category_id, 'title' => $v->title, 'summary' => $v->summary, 'slug' => $v->slug, 'version_id' => $v->current_version_id,
            'body' => self::bodyText($content), 'tags' => array_values(array_filter((array) json_decode($v->tags, true), 'is_string')),
        ]]);
        /** @var array{taskUid: int} $task */
        $this->index->client()->waitForTask($task['taskUid']);
    }

    /** @param  array<string, mixed>  $document */
    public static function bodyText(array $document): string
    {
        $parts = [];
        foreach (is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
            if (is_array($block) && is_array($block['items'] ?? null)) {
                $parts = array_merge($parts, array_filter($block['items'], 'is_string'));
            }
        }

        return mb_substr(implode("\n", $parts), 0, 20000);
    }
}

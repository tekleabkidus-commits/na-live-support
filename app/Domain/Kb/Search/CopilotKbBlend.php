<?php

declare(strict_types=1);

namespace App\Domain\Kb\Search;

use Throwable;

/**
 * Doc 10 FR-17's second sentence (S10 step 10): Copilot's top-3 = Doc 09's semantic retrieval
 * BLENDED with this keyword index. Retrieval chunks come first (they are the grounded facts);
 * keyword hits fill the remaining slots by article, skipping articles retrieval already brought.
 * Internal articles are allowed (the Copilot is staff — FR-10). The CopilotPanel call site is a
 * Doc 09 file → packet P6, proposed at the batch-C checkpoint; this service is complete and
 * proven standalone until it is wired.
 */
final class CopilotKbBlend
{
    public function __construct(private readonly KbSearchIndex $index) {}

    /**
     * @param  list<array{article_ref: string, content: string, version_id?: string}>  $chunks
     * @return list<array{article_ref: string, excerpt: string, source: 'retrieval'|'keyword', version_id?: string}>
     */
    public function top3(string $query, array $chunks, string $language): array
    {
        $out = [];
        $seen = [];
        foreach ($chunks as $chunk) {
            if (count($out) === 3) {
                break;
            }
            if (isset($seen[$chunk['article_ref']])) {
                continue;
            }
            $seen[$chunk['article_ref']] = true;
            $out[] = ['article_ref' => $chunk['article_ref'], 'excerpt' => mb_substr($chunk['content'], 0, 160), 'source' => 'retrieval'] + (isset($chunk['version_id']) ? ['version_id' => $chunk['version_id']] : []);
        }
        if (count($out) < 3 && trim($query) !== '') {
            try {
                $hits = $this->index->client()->index($this->index->indexName())->search($query, ['filter' => ['status = published', 'language = '.preg_replace('/[^a-z]/', '', $language)], 'limit' => 6])->getHits();
            } catch (Throwable) {
                $hits = []; // FS-02: the keyword half degrades silently; retrieval's answer stands
            }
            foreach ($hits as $hit) {
                if (count($out) === 3) {
                    break;
                }
                $ref = is_string($hit['article_id'] ?? null) ? $hit['article_id'] : '';
                if ($ref === '' || isset($seen[$ref])) {
                    continue;
                }
                $seen[$ref] = true;
                $out[] = ['article_ref' => $ref, 'excerpt' => mb_substr(is_string($hit['summary'] ?? null) && $hit['summary'] !== '' ? $hit['summary'] : (is_string($hit['title'] ?? null) ? $hit['title'] : ''), 0, 160), 'source' => 'keyword'] + (isset($hit['version_id']) && is_string($hit['version_id']) ? ['version_id' => $hit['version_id']] : []);
            }
        }

        return $out;
    }
}

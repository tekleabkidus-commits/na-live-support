<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

/**
 * Doc 10 T-06 / FR-14 / FR-16 / SS-04 — citation URLs EMBED THE VERSION ID, so a citation
 * the chatbot showed on May 3 resolves in ONE lookup to exactly what the customer read
 * (kb_article_versions is immutable). Article/variant URLs are keyed by stable ids + a
 * per-language slug; slug history keeps old URLs alive (EC-06). The shapes are the
 * contract Doc 13 (widget) and Doc 05/12 (Telegram flows) render; staff resolve doors
 * serve them today.
 */
final class CitationUrls
{
    public static function citation(string $articleId, string $versionId): string
    {
        return sprintf('/kb/cite/%s/%s', $articleId, $versionId);
    }

    public static function article(string $language, string $slug): string
    {
        return sprintf('/kb/%s/%s', $language, $slug);
    }

    /** @return array{article_id: string, version_id: string}|null */
    public static function parseCitation(string $url): ?array
    {
        if (preg_match('#^/kb/cite/([0-9a-f-]{36})/([0-9a-f-]{36})$#', $url, $m) !== 1) {
            return null;
        }

        return ['article_id' => $m[1], 'version_id' => $m[2]];
    }
}

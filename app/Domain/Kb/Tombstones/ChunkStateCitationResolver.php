<?php

declare(strict_types=1);

namespace App\Domain\Kb\Tombstones;

use Illuminate\Support\Facades\DB;

/**
 * S10 step 1 — the resolver's first, chunk-state-fed implementation: a ref with
 * any retrievable chunk is Current; a ref whose chunks all retired (Doc 09's
 * EC-06 flip — never a delete) is Retired; a ref with no chunks is Unknown.
 * READ-ONLY on kb_chunks (the one indexer door stays KbIndexer — the
 * KbOneIndexerDoorTest sweep proves this file writes nothing). Step 7 replaces
 * the truth source with kb_article_versions + slug history behind the same
 * interface; this class then serves only citations minted before that.
 */
final class ChunkStateCitationResolver implements CitationResolver
{
    public function resolve(string $articleRef): CitationState
    {
        /** @var object{total: int|string, live: int|string}|null $row */
        $row = DB::table('kb_chunks')
            ->where('article_ref', $articleRef)
            ->selectRaw('count(*) AS total, count(*) FILTER (WHERE retrievable) AS live')
            ->first();

        if ($row === null || (int) $row->total === 0) {
            return CitationState::Unknown;
        }

        return (int) $row->live > 0 ? CitationState::Current : CitationState::Retired;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Kb\Editor;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Doc 10 FR-05 / NFR-02 / EC-12 / FS-05 — the autosave door: the working copy is
 * versioned as WORKING COPY, not history (versions are minted only by publish);
 * working_copy_saved_at is stamped IN SQL (clock_timestamp()) and is the EC-12
 * "restored unsaved changes from {time}" fact. FS-05: when the lock service is down
 * the caller passes the stamp it last saw; a mismatch is a SaveConflict, never a
 * silent overwrite. SEC-03: script/style are stripped at save (InertContent).
 */
final class WorkingCopy
{
    public function __construct(private readonly InertContent $inert) {}

    /**
     * @param  array<string, mixed>  $content  the block document
     * @return array{saved_at: string}
     */
    public function save(string $variantId, array $content, ?string $expectedSavedAt = null): array
    {
        $clean = $this->inert->sanitizeBlocks($content);
        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($variantId, $encoded, $expectedSavedAt): array {
            /** @var object{id: string, saved_at: string|null}|null $current */
            $current = DB::table('kb_article_variants')->where('id', $variantId)->lockForUpdate()->first(['id', DB::raw('working_copy_saved_at::text AS saved_at')]);
            if ($current === null) {
                throw new InvalidArgumentException('unknown variant');
            }
            if ($expectedSavedAt !== null && $current->saved_at !== null && $current->saved_at !== $expectedSavedAt) {
                throw new SaveConflict($current->saved_at);
            }
            DB::update('UPDATE kb_article_variants SET working_copy = ?::jsonb, working_copy_saved_at = clock_timestamp(), updated_at = clock_timestamp() WHERE id = ?', [$encoded, $variantId]);
            /** @var object{saved_at: string} $stamp */
            $stamp = DB::table('kb_article_variants')->where('id', $variantId)->first([DB::raw('working_copy_saved_at::text AS saved_at')]);

            return ['saved_at' => $stamp->saved_at];
        });
    }

    /**
     * EC-12: the recovery point + its stamp (the banner's fact).
     *
     * @return array{content: array<string, mixed>, saved_at: string|null}
     */
    public function recover(string $variantId): array
    {
        /** @var object{working_copy: string, saved_at: string|null}|null $row */
        $row = DB::table('kb_article_variants')->where('id', $variantId)->first(['working_copy', DB::raw('working_copy_saved_at::text AS saved_at')]);
        if ($row === null) {
            throw new InvalidArgumentException('unknown variant');
        }
        $content = json_decode($row->working_copy, true);
        /** @var array<string, mixed> $content */
        $content = is_array($content) ? $content : [];

        return ['content' => $content, 'saved_at' => $row->saved_at];
    }
}

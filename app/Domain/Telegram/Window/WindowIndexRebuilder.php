<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Window;

use Illuminate\Support\Facades\DB;

/**
 * FS-05: the Redis ZSET is a rebuildable INDEX, not truth. On loss (eviction,
 * flush, a fresh node) this repopulates it from the `reply_window_expires_at`
 * column — the source of truth — so the sweep recovers. Event-driven updates
 * (WindowTracker) keep working during the rebuild; the rebuild only restores
 * the sweep's ability to find windows in bulk.
 */
final class WindowIndexRebuilder
{
    public function __construct(private readonly WindowTracker $tracker) {}

    /** Rebuild the sweep index for the bound tenant from the column. Returns the row count. */
    public function rebuild(): int
    {
        $rows = DB::table('conversations')
            ->where('channel', 'telegram')
            ->whereNotNull('reply_window_expires_at')
            ->whereIn('reply_window_state', ['open', 'closing'])
            ->get(['id', 'reply_window_expires_at']);

        foreach ($rows as $row) {
            $this->tracker->index(
                is_scalar($row->id) ? (string) $row->id : '',
                is_scalar($row->reply_window_expires_at) ? (string) $row->reply_window_expires_at : '',
            );
        }

        return $rows->count();
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Sync\SyncReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * T-07: /v1/sync?cursor — THE single reconciliation path for
 * reconnect/mobile-resume (FR-29). Response contract (v:1):
 *   events[]     ordered (occurred_at, id), scope-filtered (A-31),
 *                watermarked (A-33 B-4 — settled events only; live = Reverb)
 *   next_cursor  opaque; resend verbatim
 *   overflow     true → abandon deltas, full-refetch the active views
 *   server_now   EC-07's clock anchor — relative times render from
 *                (server_now − local now) offset, never the client clock
 *   build_id     FR-30 — a mismatch against the loaded bundle shows the
 *                passive "refresh for updates" toast, never a forced reload
 *
 * EC-08: the route rides the `inbox-sync` limiter — reconnect storms get
 * 429s the client honors with 0–10 s jittered backoff (the client half is
 * step 13; the 429 contract is proven here).
 */
final class SyncController extends Controller
{
    public function __invoke(Request $request, SyncReader $reader): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $cursor = $request->query('cursor');
        $result = $reader->since(is_string($cursor) ? $cursor : null, $user);

        return new JsonResponse([
            'v' => 1,
            'events' => $result['events'],
            'next_cursor' => $result['next_cursor'],
            'overflow' => $result['overflow'],
            'server_now' => $result['server_now'],
            'build_id' => is_string($build = config('app.build_id')) ? $build : 'dev',
        ]);
    }
}

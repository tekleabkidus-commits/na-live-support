<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SEC-06's access split, tenant half: ai.decisions.view reads the decision
 * log through a CURATED allowlist that NEVER includes the payload pair —
 * raw prompt/output access is the stricter platform-debug tier (the
 * platform console's, not a tenant permission; no tenant door to payloads
 * EXISTS, which is the strongest split). C-05 cursor pagination; C-10 is
 * moot here (no path params — the collection is tenant-scoped by RLS).
 */
final class AiDecisionsController extends Controller
{
    /** The curated payload allowlist — the payload pair is structurally absent. */
    private const COLUMNS = [
        'id', 'surface', 'task', 'provider', 'model', 'config_version_id',
        'conversation_id', 'ticket_id', 'endpoint_ref', 'language', 'intent',
        'confidence', 'action', 'rg_risk', 'tokens_in', 'tokens_out',
        'latency_ms', 'error_category', 'outcome', 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $limit = min(100, max(1, (int) $request->integer('limit', 25)));
        $cursor = $request->string('cursor')->toString();

        $query = DB::table('ai_decisions')
            ->select(self::COLUMNS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit + 1);

        // S09 step 11: the SUPERVISOR FEED is this same door filtered by
        // surface (FR-17's outputs are decision rows) — a curated filter,
        // same allowlist, no second read path.
        $surface = $request->string('surface')->toString();
        if (in_array($surface, ['chatbot', 'copilot', 'supervisor'], true)) {
            $query->where('surface', $surface);
        }

        if ($cursor !== '') {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && is_string($decoded['created_at'] ?? null) && is_string($decoded['id'] ?? null)) {
                $query->whereRaw('(created_at, id) < (?::timestamptz, ?::uuid)', [$decoded['created_at'], $decoded['id']]);
            }
        }

        $rows = $query->get();
        $nextCursor = null;

        if ($rows->count() > $limit) {
            $rows = $rows->slice(0, $limit)->values();
            $last = $rows->last();
            if (is_object($last)
                && is_string($lastCreatedAt = $last->created_at ?? null)
                && is_string($lastId = $last->id ?? null)) {
                $nextCursor = base64_encode((string) json_encode([
                    'created_at' => $lastCreatedAt,
                    'id' => $lastId,
                ]));
            }
        }

        return response()->json([
            'data' => $rows->all(),
            'next_cursor' => $nextCursor,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Domain\Csat\ResponseIngest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doc 12 FR-25 / SEC-05 — the widget CSAT endpoint (T-08's "widget API" half;
 * the star-row RENDER is [D13]'s). The survey token is the credential — no
 * visitor session is required or consulted; binding is the hash compare in
 * ResponseIngest, rate limiting is layered (route throttle + the in-door
 * per-token counter). An unknown token answers the same shape as a stale one
 * would (nothing to enumerate).
 */
final class CsatController extends Controller
{
    public function store(Request $request, ResponseIngest $ingest): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:128'],
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $comment = $request->string('comment')->toString();
        $result = $ingest->capture($request->string('token')->toString(), $request->integer('score'), $comment === '' ? null : $comment);
        $status = match ($result['state']) {
            'rate_limited' => 429,
            'unknown' => 404,
            default => 200,
        };

        return response()->json($result, $status);
    }
}

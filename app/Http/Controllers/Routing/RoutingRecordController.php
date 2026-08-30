<?php

declare(strict_types=1);

namespace App\Http\Controllers\Routing;

use App\Domain\Routing\Record\RecordReader;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Doc 11 FR-17 / SEC-02 / §8 — the routing-record READ door the timeline expandable
 * consumes (Doc 07 slot-fill at step 11b). `tickets.view` opens the door; the per-agent
 * facts inside (candidate ids, the routable snapshot, the assigned agent's identity) are
 * ABSENT — not redacted — unless the viewer holds `reports.agents.individual` (RecordReader
 * does the elision from the record, once). Unknown record or foreign tenant → 404 (C-10).
 */
final class RoutingRecordController extends Controller
{
    public function show(Request $request, string $ticketId, string $recordId, RecordReader $reader): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $record = $reader->forViewer($viewer, $ticketId, $recordId);
        if ($record === null) {
            abort(404);
        }

        return response()->json($record);
    }
}

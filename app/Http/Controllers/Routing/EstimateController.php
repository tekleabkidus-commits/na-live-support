<?php

declare(strict_types=1);

namespace App\Http\Controllers\Routing;

use App\Domain\Routing\Estimator\EstimateEndpoint;
use App\Http\Controllers\Controller;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Doc 11 T-06 / SEC-05 — `GET /internal/routing/estimate/{queue}`: the cached band for a
 * queue, `{band, degraded}` and NOTHING else (EstimatorLeakageTest asserts the shape by
 * absence). Staff-authenticated (Doc 09/13 read it server-side; the widget/bot copy
 * consumes QueueWaitVariable in-process). An unknown queue is the 404 pattern (C-10).
 */
final class EstimateController extends Controller
{
    public function show(string $queueId, EstimateEndpoint $endpoint): JsonResponse
    {
        if (! DB::table('queues')->where('id', $queueId)->whereNull('deleted_at')->exists()) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse($endpoint->forQueue($queueId));
    }
}

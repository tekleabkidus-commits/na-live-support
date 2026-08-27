<?php

declare(strict_types=1);

namespace App\Http\Controllers\Kb;

use App\Domain\Kb\Editor\LockHeld;
use App\Domain\Kb\Editor\Locks;
use App\Domain\Kb\Editor\LockUnavailable;
use App\Domain\Kb\Editor\SaveConflict;
use App\Domain\Kb\Editor\WorkingCopy;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Doc 10 FR-05 / T-07 / EC-12 / FS-05 — the editor doors (S10 step 3; JSON only):
 * autosave (working copy + the DB stamp), recover (EC-12's banner fact), lock
 * acquire/heartbeat/release/takeover. A held lock is 409 kb_lock_held with the
 * holder; the lock service down answers {mode: optimistic} and the autosave then
 * requires expected_saved_at (a mismatch is 409 kb_save_conflict).
 */
final class KbEditorController extends Controller
{
    public function __construct(private readonly WorkingCopy $copy, private readonly Locks $locks) {}

    public function autosave(Request $request, string $variantId): JsonResponse
    {
        $content = $request->input('content');
        if (! is_array($content)) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'content must be a block document');
        }
        /** @var array<string, mixed> $content */
        $expected = $request->input('expected_saved_at');
        try {
            $saved = $this->copy->save($variantId, $content, is_string($expected) ? $expected : null);
        } catch (SaveConflict $e) {
            return ProblemDetails::response(ErrorCode::KbSaveConflict, 409, $e->getMessage(), extra: ['current_saved_at' => $e->currentSavedAt]);
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return new JsonResponse($saved);
    }

    public function recover(string $variantId): JsonResponse
    {
        try {
            return new JsonResponse($this->copy->recover($variantId));
        } catch (InvalidArgumentException) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }
    }

    public function acquire(Request $request, string $variantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $lock = $this->locks->acquire($variantId, $user->identifier());
        } catch (LockHeld $e) {
            return ProblemDetails::response(ErrorCode::KbLockHeld, 409, $e->getMessage(), extra: ['holder_id' => $e->holderId]);
        } catch (LockUnavailable) {
            return new JsonResponse(['mode' => 'optimistic', 'holder_id' => null, 'acquired' => false]); // FS-05: noisier, safe
        }

        return new JsonResponse($lock + ['mode' => 'locked']);
    }

    public function heartbeat(Request $request, string $variantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            return new JsonResponse(['alive' => $this->locks->heartbeat($variantId, $user->identifier())]);
        } catch (LockUnavailable) {
            return new JsonResponse(['alive' => false, 'mode' => 'optimistic']);
        }
    }

    public function release(Request $request, string $variantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $this->locks->release($variantId, $user->identifier());
        } catch (LockUnavailable) {
            return new JsonResponse(['released' => false, 'mode' => 'optimistic']);
        }

        return new JsonResponse(['released' => true]);
    }

    public function takeover(Request $request, string $variantId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        try {
            $previous = $this->locks->takeover($variantId, $user->identifier());
        } catch (LockUnavailable) {
            $request->attributes->set('audit.noop', true); // nothing to take over without the lock service; the optimistic path has no holder

            return new JsonResponse(['mode' => 'optimistic', 'previous_holder_id' => null]);
        }

        return new JsonResponse(['mode' => 'locked', 'previous_holder_id' => $previous]);
    }
}

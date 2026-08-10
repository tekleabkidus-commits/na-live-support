<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inbox;

use App\Domain\Inbox\Views\SavedViews;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * FR-06's saved-view surface (S07 step 4). Route → permission mapping
 * (same-commit, keys from the closed catalog): CRUD + duplicate ride
 * `views.manage` (R-26/A-34 — FR-06's "any user with tickets.view" names the
 * AUDIENCE, the write-tier grant is this key; the ReadOnlySweep SEV-1 is the
 * reason); reading the rail stays `tickets.view`; share/unshare ride
 * `views.share` (share-only, FR-06's own separation) with
 * `audit:` middleware (SEC-03 posture: share state changes are audited with
 * actor + target). Owner-only writes are the SERVICE's refusal
 * (read_only_view — AC-02's shared-views-read-only clause), surfaced as a
 * C-04 problem.
 */
final class SavedViewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Own views + views shared to anyone (Doc 11's membership will narrow
        // shared_to targeting; until then shared views are tenant-visible —
        // BR-03 still holds at ROW level via the scoped variants).
        $views = DB::table('inbox_saved_views')
            ->whereNull('deleted_at')
            ->where(function (\Illuminate\Database\Query\Builder $query) use ($user): void {
                $query->where('owner_user_id', $user->identifier())
                    ->orWhereNotNull('shared_to_type');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id', 'filters', 'sort', 'columns', 'shared_to_type', 'shared_to_id']);

        return new JsonResponse(['views' => $views]);
    }

    public function store(Request $request, SavedViews $savedViews): JsonResponse
    {
        /** @var array{name: string, filters: array<string, mixed>, sort?: array<string, mixed>, columns?: array<string, mixed>} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'sort' => ['sometimes', 'array'],
            'columns' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $id = $savedViews->create($user, $input['name'], $input['filters'], $input['sort'] ?? [], $input['columns'] ?? []);
        } catch (DomainException $e) {
            return ProblemDetails::response(ErrorCode::ViewFiltersInvalid, 422, $e->getMessage());
        }

        return new JsonResponse(['id' => $id], 201);
    }

    public function update(Request $request, string $viewId, SavedViews $savedViews): JsonResponse
    {
        /** @var array{name: string, filters: array<string, mixed>, sort?: array<string, mixed>, columns?: array<string, mixed>} $input */
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['required', 'array'],
            'sort' => ['sometimes', 'array'],
            'columns' => ['sometimes', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $savedViews->update($user, $viewId, $input['name'], $input['filters'], $input['sort'] ?? [], $input['columns'] ?? []);
        } catch (DomainException $e) {
            return $this->refusal($e);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function destroy(Request $request, string $viewId, SavedViews $savedViews): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $savedViews->softDelete($user, $viewId);
        } catch (DomainException $e) {
            return $this->refusal($e);
        }

        return new JsonResponse(['ok' => true]);
    }

    public function share(Request $request, string $viewId, SavedViews $savedViews, AuditLogger $audit): JsonResponse
    {
        /** @var array{to_type: string, to_id: string} $input */
        $input = $request->validate([
            'to_type' => ['required', 'string', 'in:team,department'],
            'to_id' => ['required', 'uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $savedViews->share($user, $viewId, $input['to_type'], $input['to_id']);
        } catch (DomainException $e) {
            return $this->refusal($e);
        }

        // SEC-03 posture: what a team/department now SEES changed — actor + target.
        $audit->record(
            actionKey: 'inbox.view_shared',
            entity: 'inbox_saved_view',
            entityId: $viewId,
            actorType: AuditActor::Staff,
            payload: ['to_type' => $input['to_type'], 'to_id' => $input['to_id']],
            actorId: $user->identifier(),
        );

        return new JsonResponse(['ok' => true]);
    }

    public function unshare(Request $request, string $viewId, SavedViews $savedViews, AuditLogger $audit): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $savedViews->unshare($user, $viewId);
        } catch (DomainException $e) {
            return $this->refusal($e);
        }

        $audit->record(
            actionKey: 'inbox.view_unshared',
            entity: 'inbox_saved_view',
            entityId: $viewId,
            actorType: AuditActor::Staff,
            payload: [],
            actorId: $user->identifier(),
        );

        return new JsonResponse(['ok' => true]);
    }

    public function duplicate(Request $request, string $viewId, SavedViews $savedViews): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $id = $savedViews->duplicate($user, $viewId);
        } catch (DomainException $e) {
            return $this->refusal($e);
        }

        return new JsonResponse(['id' => $id], 201);
    }

    private function refusal(DomainException $e): JsonResponse
    {
        // C-10: "not yours" and "not found" answer identically (404),
        // read_only_view carries its stable C-04 code — but ONLY for the
        // OWNER-mismatch on a view the caller can already see in the rail;
        // an unknown id is the plain 404.
        return $e->getMessage() === 'read_only_view'
            ? ProblemDetails::response(ErrorCode::ReadOnlyView, 403, __('foundation.error.read_only_view'))
            : ProblemDetails::response(ErrorCode::ViewNotFound, 404, __('foundation.error.view_not_found'));
    }
}

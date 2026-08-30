<?php

declare(strict_types=1);

namespace App\Http\Controllers\Org;

use App\Domain\Org\Restructure\ChunkedMover;
use App\Domain\Org\Restructure\DeleteDepartmentWizard;
use App\Domain\Org\Restructure\DeleteQueueWizard;
use App\Domain\Org\Restructure\RestructureRefused;
use App\Http\Controllers\Controller;
use App\Jobs\Org\RestructureJob;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Doc 11 FR-18 / AC-07 — the wizard doors: PREVIEW (inventory + choices + the computed
 * confirmation), EXECUTE (validated against the live inventory, then queued as RestructureJob —
 * `org.restructure.submitted` audited at the door; the per-chunk `org.restructure.executed` and the
 * tombstones' `org.*.deleted` are the job's), PROGRESS (the cached per-chunk row). The 375
 * mobile path is read-only at the frame (step 13); the doors themselves are the same.
 */
final class RestructureController extends Controller
{
    public function __construct(
        private readonly DeleteQueueWizard $queueWizard,
        private readonly DeleteDepartmentWizard $departmentWizard,
        private readonly ChunkedMover $mover,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function previewQueue(Request $request, string $queueId): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();
        try {
            return new JsonResponse($this->queueWizard->preview($queueId, $operator));
        } catch (RestructureRefused $e) {
            return $this->refused($e);
        }
    }

    public function previewDepartment(Request $request, string $departmentId): JsonResponse
    {
        /** @var User $operator */
        $operator = $request->user();
        try {
            return new JsonResponse($this->departmentWizard->preview($departmentId, $operator));
        } catch (RestructureRefused $e) {
            return $this->refused($e);
        }
    }

    public function executeQueue(Request $request, string $queueId): JsonResponse
    {
        return $this->submit($request, DeleteQueueWizard::WIZARD, $queueId);
    }

    public function executeDepartment(Request $request, string $departmentId): JsonResponse
    {
        return $this->submit($request, DeleteDepartmentWizard::WIZARD, $departmentId);
    }

    public function progress(string $wizard, string $targetId): JsonResponse
    {
        $row = $this->mover->readProgress($wizard, $targetId);

        return new JsonResponse($row ?? ['state' => 'unknown']);
    }

    private function submit(Request $request, string $wizard, string $targetId): JsonResponse
    {
        try {
            /** @var array{confirmation: string, choices?: array<string, mixed>} $input */
            $input = $request->validate(['confirmation' => ['required', 'string', 'max:64'], 'choices' => ['nullable', 'array']]);
        } catch (ValidationException $e) {
            /** @var array<string, list<string>> $errors */
            $errors = $e->errors();

            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, 'invalid input', errors: $errors);
        }
        /** @var User $operator */
        $operator = $request->user();
        $choices = $input['choices'] ?? [];
        // The preview's own checks run at the door too (refusals are immediate, not queued): the live
        // confirmation, the target/RG/rule choices — everything but the move itself.
        try {
            $wizard === DeleteQueueWizard::WIZARD ? $this->queueWizard->preview($targetId, $operator) : $this->departmentWizard->preview($targetId, $operator);
        } catch (RestructureRefused $e) {
            return $this->refused($e);
        }
        $this->mover->progress($wizard, $targetId, 0, 0, 0, 'queued');
        $this->audit->record('org.restructure.submitted', $wizard === DeleteQueueWizard::WIZARD ? 'queue' : 'department', $targetId, AuditActor::Staff, ['wizard' => $wizard, 'target_id' => $targetId, 'confirmation' => $input['confirmation']], actorId: $operator->identifier());
        dispatch(new RestructureJob($this->context->tenantId(), $wizard, $targetId, $choices, $input['confirmation'], $operator->identifier()));

        return new JsonResponse(['ok' => true, 'wizard' => $wizard, 'target_id' => $targetId, 'progress' => route('org.restructure.progress', ['wizard' => $wizard, 'targetId' => $targetId])], 202);
    }

    private function refused(RestructureRefused $e): JsonResponse
    {
        if (in_array($e->refusalCode, ['queue_not_found', 'department_not_found'], true)) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, 'not found');
        }

        return ProblemDetails::response(ErrorCode::RoutingRefused, 422, $e->getMessage(), extra: ['refusal_code' => $e->refusalCode]);
    }
}

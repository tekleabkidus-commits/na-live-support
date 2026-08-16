<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Merge\CustomerMerge;
use App\Domain\Customers\Merge\MergeBlocked;
use App\Domain\Customers\Merge\MergePlan;
use App\Domain\Customers\Merge\UnmergeCustomer;
use App\Models\Customer;
use App\Models\User;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * FR-11/FR-12/FR-13's doors — the merge flow's FIRST HUMAN REACH (step 11;
 * every guard was falsified before any of this was routable: sabotages
 * #5/#6, the chaos pair, EC-12, EC-04 — §21's ordering, honored).
 *
 * The preview and the execution read the SAME MergePlan (inherited row 6's
 * law: consequences at confirm from the live queries the saga runs — never
 * a second engine). The B4-S04 token: the client types MERGE (Latin, the
 * drawn law) — checked HERE so a bypassing client gains nothing. Reason
 * mandatory both ways (SEC-02; the frames draw no reason input — the
 * DOCUMENT wins, filed as the step-6 divergence in DESIGN-DEVIATIONS).
 */
final class MergeController
{
    public function preview(Request $request, string $customerId, MergePlan $plans): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{merged_id: string} $input */
        $input = $request->validate(['merged_id' => ['required', 'uuid']]);
        Customer::query()->findOrFail($input['merged_id']);

        try {
            $plan = $plans->build($customerId, $input['merged_id']);
        } catch (MergeBlocked $e) {
            return $this->blocked($e);
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        // The CURATED confirm payload: the per-field diff + counts (the
        // checklist reads as lists WITH counts — §13) — never row dumps.
        return new JsonResponse([
            'field_diff' => $plan['field_diff'],
            'tags_union' => $plan['tags_union'],
            'consequences' => [
                'conversations' => count($plan['conversations']),
                'tickets' => count($plan['tickets']),
                'notes' => count($plan['notes']),
                'identities' => count($plan['identities']),
                // FR-12's collision, SURFACED in the checklist (sanctioned
                // multi-open, not an error).
                'linked_work_flagged' => count($plan['linked_work_to_flag']),
            ],
        ]);
    }

    public function merge(Request $request, string $customerId, CustomerMerge $merges): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{merged_id: string, choices?: array<string, string>, reason: string, confirm: string} $input */
        $input = $request->validate([
            'merged_id' => ['required', 'uuid'],
            'choices' => ['sometimes', 'array'],
            'choices.*' => ['in:survivor,merged'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            // B4-S04: the typed token is MERGE, Latin, in both layouts.
            'confirm' => ['required', 'in:MERGE'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            /** @var array<string, 'merged'|'survivor'> $choices */
            $choices = [];
            foreach ($input['choices'] ?? [] as $field => $choice) {
                if ($choice === 'survivor' || $choice === 'merged') {
                    $choices[$field] = $choice;
                }
            }
            $snapshotId = $merges->execute($customerId, $input['merged_id'], $choices, $input['reason'], $actor->identifier());
        } catch (MergeBlocked $e) {
            return $this->blocked($e);
        } catch (InvalidArgumentException|DomainException $e) {
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['snapshot_id' => $snapshotId], 201);
    }

    public function unmergePreview(string $customerId, string $snapshotId, UnmergeCustomer $unmerges): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        try {
            return new JsonResponse($unmerges->preview($snapshotId));
        } catch (InvalidArgumentException $e) {
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, $e->getMessage());
        }
    }

    public function unmerge(Request $request, string $customerId, string $snapshotId, UnmergeCustomer $unmerges): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $actor */
        $actor = $request->user();

        try {
            $unmerges->undo($snapshotId, $input['reason'], $actor->identifier());
        } catch (InvalidArgumentException|DomainException $e) {
            // EC-04: the chain explains its required order in the refusal.
            return ProblemDetails::response(ErrorCode::ValidationFailed, 422, $e->getMessage());
        }

        return new JsonResponse(['restored' => true]);
    }

    private function blocked(MergeBlocked $e): JsonResponse
    {
        // EC-12: refused at confirm with the EXACT tickets listed.
        return ProblemDetails::response(
            ErrorCode::ValidationFailed,
            409,
            $e->getMessage(),
            extra: ['blocking_tickets' => $e->openLinkedWorkTicketIds],
        );
    }
}

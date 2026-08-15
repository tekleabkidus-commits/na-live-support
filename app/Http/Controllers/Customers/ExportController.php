<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Jobs\Customers\CustomerExportJob;
use App\Models\Customer;
use App\Models\User;
use App\Support\Audit\AuditActor;
use App\Support\Audit\AuditLogger;
use App\Support\Problem\ErrorCode;
use App\Support\Problem\ProblemDetails;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Uid\UuidV7;

/**
 * SEC-04's doors. The POST audits the REQUEST synchronously and queues the
 * job (AI/exports never run inline); the job re-checks the verdict at
 * generation time (SEC-06) and audits the completed export itself. The GET
 * re-issues a signed URL ONLY when the whole artifact exists — FS-05's
 * "partials never get signed URLs" falls out of the atomic-put design (an
 * absent object is the only other state).
 *
 * Erasure INITIATION only (A-38: rides customers.delete; [D17] owns
 * approval and execution — building more here would be scope creep).
 */
final class ExportController
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function request(Request $request, string $customerId): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var User $requester */
        $requester = $request->user();
        $exportId = (string) new UuidV7;

        $this->audit->record('customer.export_requested', 'customer', $customerId, AuditActor::Staff, ['export_id' => $exportId], actorId: $requester->identifier());
        CustomerExportJob::dispatch($this->context->tenantId(), $exportId, $customerId, $requester->identifier());

        return new JsonResponse(['export_id' => $exportId], 202);
    }

    public function url(string $customerId, string $exportId): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        $disk = Storage::disk(config()->string('media.disk'));
        $key = CustomerExportJob::storageKey($this->context->tenantId(), $exportId);

        if (! $disk->exists($key)) {
            // FS-05: not complete = not signable; absent and partial are the
            // same honest answer (the atomic put admits no third state).
            return ProblemDetails::response(ErrorCode::ResourceNotFound, 404, __('customers.export.not_ready'));
        }

        return new JsonResponse(['url' => $disk->temporaryUrl(
            $key,
            CarbonImmutable::now('UTC')->addSeconds(config()->integer('media.signed_url_ttl_seconds')),
        )]);
    }

    public function initiateErasure(Request $request, string $customerId): JsonResponse
    {
        Customer::query()->findOrFail($customerId); // C-10

        /** @var array{reason: string} $input */
        $input = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);

        /** @var User $requester */
        $requester = $request->user();

        // [D17]: the INITIATION is the whole act here — the audit entry IS
        // the durable record Doc 17's approval workflow consumes.
        $this->audit->record('customer.erasure.initiated', 'customer', $customerId, AuditActor::Staff, ['reason' => $input['reason']], actorId: $requester->identifier());

        return new JsonResponse(['initiated' => true], 202);
    }
}

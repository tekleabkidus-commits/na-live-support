<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Domain\Sms\Dlr\DlrApplier;
use App\Jobs\Middleware\BindTenantContextToJob;
use App\Models\SmsGateway;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The queued half of the DLR door (FR-13 / NFR-03): the ingress ACKs 202 and
 * THIS is where everything happens, on the dedicated `sms-dlr` lane so a
 * receipt never queues behind a send burst (HORIZON FAMILY AMENDMENT 1's
 * fourth supervisor). Carries the gateway id and the raw payload — the
 * payload holds the vendor's own fields (a phone, the text); it is never
 * logged, and the applier masks everything it says.
 */
final class ProcessDlrJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 5;

    /** @param array<string, mixed> $payload */
    public function __construct(public readonly string $gatewayId, public readonly array $payload)
    {
        $this->captureTenant();
        $this->onQueue('sms-dlr');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContextToJob];
    }

    public function handle(DlrApplier $applier): void
    {
        $gateway = SmsGateway::query()->find($this->gatewayId);
        if ($gateway === null) {
            return; // the gateway was deleted between ACK and processing: nothing to apply against
        }
        $applier->apply($gateway, $this->payload);
    }
}

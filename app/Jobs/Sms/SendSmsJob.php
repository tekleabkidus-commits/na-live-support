<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Domain\Sms\Pipeline\SendPipeline;
use App\Jobs\Middleware\BindTenantContextToJob;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The thin queue shell around SendPipeline (T-04: one job per message, on the
 * EVENT's lane queue — the four-supervisor physics keeps a broadcast burst
 * from ever queueing ahead of a resolved-notification). Carries the row id
 * and NOTHING else — no phone, no text, no code rides a queue payload (C-13's
 * spirit; the row is re-read under the job's own tenant context).
 *
 * `$tries` is deliberately generous: a shaping WAIT and a circuit cool-down
 * release the job without consuming the send's retry budget — the ×4 rounds
 * live on the row's `attempts` (SendPipeline::RETRY_ROUNDS), where they are
 * visible and auditable.
 */
final class SendSmsJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 40;

    public function __construct(public readonly string $smsMessageId, string $lane)
    {
        $this->captureTenant();
        $this->onQueue($lane);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new BindTenantContextToJob];
    }

    public function handle(SendPipeline $pipeline): void
    {
        $delaySeconds = $pipeline->run($this->smsMessageId);
        if ($delaySeconds > 0) {
            $this->release($delaySeconds);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Domain\Automation\Webhooks\WebhookDeliveries;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Doc 12 EC-13 — one delivery attempt per job run on the `automation`
 * family; a `retry` outcome re-queues THIS delivery with EC-13's backoff (the
 * row's own next_attempt_at is the truth the sweep-free design reads). The
 * job never retries itself (tries=1): the attempt count lives on the row,
 * not in the queue, so a crashed worker cannot double-count.
 */
final class WebhookDeliveryJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 1;

    public function __construct(public readonly string $deliveryId)
    {
        $this->captureTenant();
    }

    public function handle(WebhookDeliveries $deliveries): void
    {
        if ($deliveries->attempt($this->deliveryId) !== 'retry') {
            return;
        }
        $attempts = DB::table('automation_webhook_deliveries')->where('id', $this->deliveryId)->value('attempts');
        $attempts = is_int($attempts) ? $attempts : (is_numeric($attempts) ? (int) $attempts : 1);
        self::dispatch($this->deliveryId)->onQueue('automation')->delay(WebhookDeliveries::delaySeconds($attempts));
    }
}

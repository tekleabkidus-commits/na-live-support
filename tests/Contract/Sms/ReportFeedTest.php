<?php

declare(strict_types=1);

use App\Domain\Sms\Reports\SmsFacts;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__, 2).'/Feature/Sms/PipelineSupport.php';

/*
| Doc 14 §21 / B5 — the report feed's VOCABULARY is the closed key set Doc 15
| consumes (Sent · Delivered · Undelivered rate · Opt-out rate · DLR latency ·
| Spend · per-gateway; filters Template · Gateway). The keys are the contract;
| rendering is [D15]. A drift here is a Doc 15 integration break.
*/

it('SmsFacts::summary answers EXACTLY the B5 vocabulary keys — the Doc 15 contract', function (): void {
    smsPipeScene();
    Queue::fake();
    $keys = array_keys(app(SmsFacts::class)->summary());
    sort($keys);
    expect($keys)->toBe([
        'delivered', 'dlr_latency_avg_seconds', 'expired', 'failed', 'opt_out_count',
        'per_gateway', 'segments_limit', 'segments_used', 'sent', 'spend', 'spend_display', 'undelivered',
    ]);
});

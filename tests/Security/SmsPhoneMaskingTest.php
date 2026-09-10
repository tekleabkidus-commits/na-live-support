<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Pipeline\EnqueueSms;
use App\Domain\Sms\Pipeline\SendRequest;
use App\Domain\Sms\Support\PhoneMask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once dirname(__DIR__).'/Feature/Sms/PipelineSupport.php';

/*
| Doc 14 SEC-01/[D17] / the B4-S08 seam row — the ONE masked-phone vocabulary:
| the DISPLAY form byte-exact to the frozen drawn copy ("+251 91 ••• 4417"),
| the stricter log form on every pipeline log line, and a plant→detect sweep
| proving no pipeline log record carries the full number.
*/

it('B4-S08 byte-exact: the drawn receipt form is "+251 91 ••• 4417" — country code, two subscriber digits, three dots, the last four', function (): void {
    expect(PhoneMask::display('+251912344417'))->toBe('+251 91 ••• 4417')
        ->and(PhoneMask::display('+251900001234'))->toBe('+251 90 ••• 1234')
        ->and(PhoneMask::display('+14155551234'))->toBe('+141 55 ••• 1234')
        ->and(PhoneMask::display('junk'))->toBe('••••');

    expect(PhoneMask::forLog('+251912344417'))->toBe('+251 •• ••• 4417', 'a log line gets LESS than a UI: no operator digits');
});

it('plant → detect: after a full pipeline run, no captured log record carries the raw number — the pipeline speaks masked forms only', function (): void {
    $records = &capturedLogsRef();
    $scene = smsPipeScene(phone: '+251900007788');
    Queue::fake();

    Illuminate\Support\Facades\Log::info('plant raw phone +251900007788'); // the scanner's positive control

    $result = DB::transaction(fn () => app(EnqueueSms::class)->enqueue(new SendRequest(
        SendableEvent::TicketResolved, $scene['ticket']->customer_id, $scene['ticket']->identifier(),
    )));
    smsPipeRunJob((string) $result->smsMessageId);
    expect((string) DB::table('sms_messages')->where('id', $result->smsMessageId)->value('state'))->toBe('sent');

    $offenders = [];
    $planted = 0;
    foreach ($records as $record) {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (str_contains($line, 'plant raw phone')) {
            $planted++;

            continue;
        }
        if (str_contains($line, '251900007788')) {
            $offenders[] = $line;
        }
    }
    expect($planted)->toBe(1, 'the scanner control must be seen or the sweep proves nothing')
        ->and($offenders)->toBe([]);
});

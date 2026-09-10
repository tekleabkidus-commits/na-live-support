<?php

declare(strict_types=1);

use App\Domain\Sms\Otp\VerificationCodeSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/PipelineSupport.php';

/*
| Doc 14 FR-01 verification_code / BR-06 / STOP-M — the OTP send door with a
| FIXTURE caller (the Doc 02 SMS-2FA fallback that would call it is NOT built;
| no staff phone column exists anywhere): the platform's code renders through
| the published template's {{code}} slot AT ENQUEUE (the code never rides a
| queue payload or an event), the row has no customer, and a malformed code
| is refused before anything exists.
*/

it('the fixture caller sends a code: rendered at enqueue with the version recorded, customer NULL, sent through the mock; the code reaches no outbox payload', function (): void {
    smsPipeScene();
    Queue::fake();

    $result = app(VerificationCodeSender::class)->send('fixture-user-1', '+251900004417', '482913');
    expect($result->status)->toBe('queued');

    /** @var App\Models\SmsMessage $row */
    $row = App\Models\SmsMessage::query()->findOrFail($result->smsMessageId);
    expect($row->customer_id)->toBeNull()
        ->and($row->event)->toBe('verification_code')
        ->and($row->rendered_body)->toContain('482913')
        ->and($row->template_version_id)->not->toBeNull()
        ->and($row->lane)->toBe('sms-transactional');

    foreach (DB::table('outbox')->get(['payload']) as $outboxRow) {
        expect((string) $outboxRow->payload)->not->toContain('482913')->not->toContain('251900004417');
    }

    smsPipeRunJob($result->smsMessageId ?? '');
    $row->refresh();
    expect($row->state)->toBe('sent')->and($row->provider_id)->not->toBeNull();
});

it('BR-06: the door refuses anything but a 4–10 digit platform code, and refuses a code on any other event', function (): void {
    smsPipeScene();

    expect(fn () => app(VerificationCodeSender::class)->send('fixture-user-1', '+251900004418', 'FREETEXT1'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(VerificationCodeSender::class)->send('fixture-user-1', '+251900004418', '123'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => new App\Domain\Sms\Pipeline\SendRequest(App\Domain\Sms\Catalog\SendableEvent::TicketResolved, 'c-1', code: '123456'))
        ->toThrow(InvalidArgumentException::class);
    expect(DB::table('sms_messages')->count())->toBe(0);
});

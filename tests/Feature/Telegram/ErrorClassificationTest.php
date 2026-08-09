<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\DeliveryFailureReason;
use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramTransportError;
use App\Domain\Telegram\ErrorClassifier;

/*
|--------------------------------------------------------------------------
| AC-05 / FR-18 — error classification against the RECORDED-REALITY corpus
|--------------------------------------------------------------------------
|
| Step 3 shipped the classifier and the conformance suite proved the six
| frozen classes are inducible. THIS is AC-05: every real-shaped Telegram
| error string maps to exactly the Doc 04 state and reason FR-18 specifies,
| incl. EC-08's doomed-retry avoidance — the fixture corpus hardens the
| step-3 patterns against reality (honest note 4, S23 step 3).
*/

/** @return array{cases: list<array<string, mixed>>, ec08_cases: list<array<string, mixed>>} */
function classificationCorpus(): array
{
    /** @var array{cases: list<array<string, mixed>>, ec08_cases: list<array<string, mixed>>} $data */
    $data = json_decode((string) file_get_contents(base_path('fixtures/telegram/errors/classification-corpus.json')), true);

    return $data;
}

it('AC-05: every recorded Telegram error maps to the exact frozen reason and retryability', function (): void {
    foreach (classificationCorpus()['cases'] as $case) {
        /** @var array{name: string, http: int, description: string, retry_after?: int, expected: string, retryable: bool} $case */
        $params = [];
        if (isset($case['retry_after'])) {
            $params['retry_after'] = $case['retry_after'];
        }

        $error = TelegramApiError::fromResponse('sendMessage', $case['http'], [
            'ok' => false,
            'error_code' => $case['http'],
            'description' => $case['description'],
            'parameters' => $params,
        ]);

        $result = ErrorClassifier::classify($error);

        expect($result->reason->value)->toBe($case['expected'], "[{$case['name']}] '{$case['description']}' must classify {$case['expected']}");
        expect($result->retryable())->toBe($case['retryable'], "[{$case['name']}] retryability");
    }
});

it('FR-18: a transport failure (no HTTP answer) is network_error, retryable', function (): void {
    $result = ErrorClassifier::classify(TelegramTransportError::forMethod('sendMessage', 'ConnectException', 'connection refused'));

    expect($result->reason)->toBe(DeliveryFailureReason::NetworkError)->and($result->retryable())->toBeTrue();
});

it('EC-08: a 429 whose retry_after fits the window RETRIES; one that overruns it is window_expired NOW (no doomed retry)', function (): void {
    foreach (classificationCorpus()['ec08_cases'] as $case) {
        /** @var array{name: string, http: int, retry_after: int, remaining_window: int, expected: string} $case */
        $error = TelegramApiError::fromResponse('sendMessage', 429, [
            'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after '.$case['retry_after'],
            'parameters' => ['retry_after' => $case['retry_after']],
        ]);

        $result = ErrorClassifier::classify($error, remainingWindowSeconds: $case['remaining_window']);

        expect($result->reason->value)->toBe($case['expected'], "[{$case['name']}] {$case['note']}");
    }
});

it('EC-08 retryAfter is carried on a within-window rate-limit so the lane honours the header over its backoff step (T-08)', function (): void {
    $error = TelegramApiError::fromResponse('sendMessage', 429, [
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 12',
        'parameters' => ['retry_after' => 12],
    ]);

    $result = ErrorClassifier::classify($error, remainingWindowSeconds: 600);

    expect($result->reason)->toBe(DeliveryFailureReason::RateLimited)
        ->and($result->retryAfterSeconds)->toBe(12);
});

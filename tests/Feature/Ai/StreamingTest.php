<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Copilot\CopilotStreamRelay;
use App\Domain\Ai\Guards\FixturesOnlyEgress;
use App\Domain\Ai\Providers\OpenAiCompatibleClient;
use App\Domain\Ai\Providers\ProviderResponse;
use App\Domain\Ai\Providers\SentenceChunker;
use App\Events\Conversations\RealtimePush;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Event;

/**
 * Doc 09 T-04, the Copilot half (Telegram takes the COMPLETED message —
 * proven at step 9): provider stream → Reverb relay. The real client
 * speaks SSE (`stream: true`, `[DONE]`, usage on the final frame); the
 * relay buffers to SENTENCE boundaries (§13 — announced at sentence
 * boundaries, never token-by-token; §9 reduced-motion chunks) onto the
 * agent's user channel through Doc 04 FR-25's one door (v:1, 32 KB).
 * [D13]: the widget's streaming half is Doc 13's.
 */
it('T-04 client: an SSE response is parsed delta-by-delta in order, [DONE] ends it, usage from the final frame meters the call; stream:true + include_usage ride the request; the guard still runs first', function (): void {
    $sse = implode("\n", [
        'data: {"choices":[{"delta":{"role":"assistant"}}]}',
        'data: {"choices":[{"delta":{"content":"Thanks for "}}]}',
        'data: {"choices":[{"delta":{"content":"your patience. "}}]}',
        'data: {"choices":[{"delta":{"content":"I will check now."},"finish_reason":"stop"}]}',
        'data: {"choices":[],"usage":{"prompt_tokens":321,"completion_tokens":12}}',
        'data: [DONE]',
        '',
    ]);
    $http = new HttpFactory;
    $http->fake(['api.fixture-ai.test/*' => $http->response($sse, 200, ['Content-Type' => 'text/event-stream'])]);
    $client = new OpenAiCompatibleClient($http, new FixturesOnlyEgress, 'https://api.fixture-ai.test/v1', 'fixture-key', null, 10);

    $deltas = [];
    $response = $client->stream(['model' => 'm', 'messages' => [['role' => 'user', 'content' => '[FIXTURE] hi']]], function (string $delta) use (&$deltas): void {
        $deltas[] = $delta;
    });

    expect($deltas)->toBe(['Thanks for ', 'your patience. ', 'I will check now.'])
        ->and($response->content)->toBe('Thanks for your patience. I will check now.')
        ->and($response->tokensIn)->toBe(321)->and($response->tokensOut)->toBe(12)
        ->and($response->raw['streamed'])->toBeTrue()->and($response->raw['usage_missing'])->toBeFalse();
    $http->assertSent(fn (ClientRequest $request): bool => $request['stream'] === true && $request['stream_options']['include_usage'] === true && $request->hasHeader('Authorization'));

    // Missing usage is RECORDED as missing, never invented (T-07).
    $http->fake(['api.fixture-ai.test/*' => $http->response("data: {\"choices\":[{\"delta\":{\"content\":\"ok.\"}}]}\ndata: [DONE]\n", 200)]);
    $bare = $client->stream(['model' => 'm', 'messages' => [['role' => 'user', 'content' => '[FIXTURE] hi']]], static fn (string $d) => null);
    expect($bare->tokensIn)->toBe(0)->and($bare->raw['usage_missing'])->toBeTrue();

    // The guard runs BEFORE any byte leaves — a non-fixture payload never reaches the fake.
    expect(fn () => $client->stream(['model' => 'm', 'messages' => [['role' => 'user', 'content' => 'real text']]], static fn (string $d) => null))
        ->toThrow(App\Domain\Ai\Guards\NonFixturePayloadRefused::class);
});

it('§13 sentence chunker: Latin and Ethiopic terminators both bound a sentence; a dangling remainder stays buffered until close', function (): void {
    expect(SentenceChunker::chunks('One. Two! Three? አራት። አምስት፧ tail'))->toBe(['One.', 'Two!', 'Three?', 'አራት።', 'አምስት፧', 'tail']);
    expect(SentenceChunker::split('First sentence. Second is still com'))->toBe([['First sentence.'], 'Second is still com']);
    expect(SentenceChunker::split('Done here.'))->toBe([['Done here.'], '']);
    expect(SentenceChunker::split(''))->toBe([[], '']);
});

it('T-04 relay: token-sized deltas become SENTENCE frames on the agent\'s user channel, in sequence, with the remainder flushed on close; the card closes the stream with the artifact id; every frame is v:1', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] where is my withdrawal?');
    // The FakeDriver streams its content in sentence chunks; the relay re-buffers regardless of delta size.
    copilotDriver(copilotEmbedding(2), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Thanks for waiting. I am checking your withdrawal now. I will update you shortly', 'citations' => [], 'confidence' => 0.9]), 50, 30));
    Event::fake([RealtimePush::class]);

    $relay = app(CopilotStreamRelay::class);
    $stream = $relay->open($actor->identifier(), 'req-1');
    $card = app(ComposerActions::class)->draft($actor->identifier(), $conversation, $stream);
    $relay->card($actor->identifier(), 'req-1', $card);

    $frames = [];
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use (&$frames, $tenantId, $actor): bool {
        expect($push->channelName)->toBe(sprintf('tenant.%s.user.%s', $tenantId, $actor->identifier()))->and($push->payload['v'])->toBe(1);
        $frames[] = [$push->eventName, $push->payload];

        return true;
    });

    $deltas = array_values(array_filter($frames, static fn (array $f): bool => $f[0] === 'ai.copilot.delta'));
    $cards = array_values(array_filter($frames, static fn (array $f): bool => $f[0] === 'ai.copilot.card'));
    expect(count($deltas))->toBeGreaterThanOrEqual(2, 'the draft streamed as sentence frames');
    expect(array_column(array_column($deltas, 1), 'seq'))->toBe(range(1, count($deltas)));
    foreach ($deltas as $delta) {
        expect($delta[1]['request_id'])->toBe('req-1');
    }
    // Reassembled frames = the JSON the model produced (the relay never rewrites content).
    expect(implode(' ', array_column(array_column($deltas, 1), 'text')))->toContain('Thanks for waiting.')->toContain('I will update you shortly');
    expect($cards)->toHaveCount(1)->and($cards[0][1]['state'])->toBe('artifact')->and($cards[0][1]['artifact_id'])->toBe($card['artifact_id']);
});

it('T-04 job: CopilotTaskJob runs the stream end-to-end and ends in exactly ONE card; a busy semaphore yields a busy card and no provider call', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] hello');
    $driver = copilotDriver(copilotEmbedding(2), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Hello! How can I help?', 'citations' => [], 'confidence' => 0.95]), 10, 10));
    Event::fake([RealtimePush::class]);

    (new App\Jobs\Ai\CopilotTaskJob('req-job', 'draft', $conversation->identifier(), $actor->identifier()))->handle(app(ComposerActions::class), app(CopilotStreamRelay::class), app(App\Domain\Ai\Concurrency\TenantSemaphore::class));
    Event::assertDispatchedTimes(RealtimePush::class, 3); // two sentence frames ("…Hello!" / "How can I help?…") + ONE card
    $cards = 0;
    Event::assertDispatched(RealtimePush::class, function (RealtimePush $push) use (&$cards): bool {
        if ($push->eventName === 'ai.copilot.card') {
            $cards++;
        }

        return true;
    });
    expect($cards)->toBe(1);

    // Busy: saturate the semaphore, run again → busy card, provider untouched.
    app(App\Support\Settings\SettingsRepository::class)->set('ai.concurrency.per_tenant', 1, tenantSurface: false);
    app(App\Domain\Ai\Concurrency\TenantSemaphore::class)->acquire();
    $calls = count($driver->seen);
    Event::fake([RealtimePush::class]);
    (new App\Jobs\Ai\CopilotTaskJob('req-busy', 'draft', $conversation->identifier(), $actor->identifier()))->handle(app(ComposerActions::class), app(CopilotStreamRelay::class), app(App\Domain\Ai\Concurrency\TenantSemaphore::class));
    Event::assertDispatched(RealtimePush::class, fn (RealtimePush $push): bool => $push->eventName === 'ai.copilot.card' && $push->payload['state'] === 'busy' && $push->payload['request_id'] === 'req-busy');
    expect(count($driver->seen))->toBe($calls);
});

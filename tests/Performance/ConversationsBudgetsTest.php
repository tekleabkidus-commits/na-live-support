<?php

declare(strict_types=1);

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Adapters\Capabilities;
use App\Domain\Conversations\Adapters\ReplyWindow;
use App\Domain\Conversations\Storage\ConversationRepository;
use App\Domain\Conversations\Storage\MessageRepository;
use App\Http\Controllers\Channels\ChannelWebhookController;
use App\Models\Customer;
use App\Support\Http\CursorPagination;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\Adapters\FakeChannelAdapter;

/*
|--------------------------------------------------------------------------
| SS-01 + §12/NFR indicative budgets (Doc 04 S20 step 9c)
|--------------------------------------------------------------------------
|
| DEV-HARDWARE-INDICATIVE, exactly like the Doc 01/02/03 budget suites: the
| default run asserts a 5× regression ceiling; PERF_BUDGETS_ENFORCE=1
| (`make perf`) asserts the spec numbers. The FORMAL closure of §12/NFR-01/02
| and SS-01's full match-day profile (50 msg/s for 10 min on one endpoint =
| 30 000 messages under load profile L2) is Doc 19's L2 work — the pre-agreed
| bucket. What runs here is the SHAPE of SS-01 at in-suite scale, plus the
| §12 thread-pagination budget.
|
| SS-01's CORRECTNESS half — ordering intact under burst — is not measured
| here at all: it is proven exactly, at 10k, by the AC-03 soak.
*/

function burstFixture(): array
{
    $tenant = activateTenant(createTenant());
    bindTenant($tenant);

    $registry = app(AdapterRegistry::class);
    if (! in_array('fakechan', $registry->channels(), true)) {
        $registry->register('fakechan', new FakeChannelAdapter(new Capabilities(
            maxText: 4096,
            mediaTypes: ['photo'],
            supportsTyping: false,
            supportsEdit: false,
            supportsDelete: false,
            supportsButtons: false,
            replyWindow: ReplyWindow::none(),
        )), 'webchat-in', 'webchat-out');
    }

    $customer = Customer::query()->create([
        'display_name' => 'Fixture', 'username' => '@fixture_burst', 'is_shell' => true,
    ]);

    return [$tenant, $customer];
}

test('SS-01/NFR-01 indicatively: the webhook edge ACKs within budget under a sustained burst on one endpoint', function () {
    burstFixture();
    Route::post('burst-webhooks/{channel}/{endpoint}', [ChannelWebhookController::class, 'ingest']);
    Queue::fake();

    $sequence = 0;
    $stats = measure(function () use (&$sequence): void {
        $sequence++;
        $this->postJson('/burst-webhooks/fakechan/bot-1', [
            'update_id' => 300_000 + $sequence,
            'chat_id' => 'chat-burst',
            'message_id' => 'ext-burst-'.$sequence,
            'from' => ['id' => 'user-burst', 'display' => 'Fixture Customer'],
            'kind' => 'text',
            'text' => 'burst message '.$sequence,
            'occurred_at' => '2026-08-05T13:00:00+00:00',
        ]);
    }, samples: 300);

    // NFR-01: inbound webhook ACK ≤ 250 ms p99. The edge only authenticates,
    // meters and enqueues — never processes (FR-10 step 1), which is why this
    // number is dominated by the framework rather than by messaging work.
    reportBudget('NFR-01 webhook ACK (burst)', 250.0, $stats);
    assertBudget('NFR-01 webhook ACK (burst)', $stats, 250.0);
});

test('§12 indicatively: a 50-message thread page reads within the pagination budget', function () {
    [, $customer] = burstFixture();

    $conversation = app(ConversationRepository::class)
        ->findOrCreateForInbound('fakechan', 'bot-1', 'chat-page', $customer->identifier());
    $messages = app(MessageRepository::class);

    // 200 rows so the 50-row page is a real window, not the whole table.
    for ($i = 0; $i < 200; $i++) {
        $messages->persistOutbound($conversation, 'agent', null, 'text', 'page fixture '.$i, null);
    }

    $stats = measure(function () use ($conversation, $messages): void {
        $pagination = CursorPagination::fromRequest(Request::create('/threads', 'GET', ['limit' => 50]));
        $messages->threadPage($conversation, $pagination);
    }, samples: 200);

    // §12: "Thread pagination: cursor by (created_at,id), 50/page, p95 ≤ 120 ms warm."
    reportBudget('§12 thread page (50 msgs)', 120.0, $stats);
    assertBudget('§12 thread page (50 msgs)', $stats, 120.0);
});

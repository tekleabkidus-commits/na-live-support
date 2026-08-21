<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 FR-15 (the Doc 07 FR-19 slot): Draft reply · Improve · Translate
 * (US-04: the original preserved) · Summarize thread → NOTE draft; every
 * action mints its FR-16 row; the composer doors are queued (202) and the
 * provider never runs inline in the request.
 */
it('FR-15 Draft reply: a grounded draft cites a retrieved INTERNAL article (FR-10: Copilot-only articles are visible here) and lands as an insertable artifact with its confidence', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] how long does a telebirr deposit take?');
    app(App\Domain\Ai\Retrieval\KbIndexer::class)->index('018f6b2e-0000-7000-8000-0000000000a1', 0, 'en', 'Internal: telebirr deposits settle within 30 minutes; escalate after 2 hours.', audience: 'internal', fixtureVector: copilotVector(4));
    $driver = copilotDriver(
        copilotEmbedding(4),
        new ProviderResponse((string) json_encode(['text' => '[FIXTURE] telebirr deposits usually settle within 30 minutes — I will check yours now.', 'citations' => ['018f6b2e-0000-7000-8000-0000000000a1'], 'confidence' => 0.88]), 120, 50),
    );

    $card = app(ComposerActions::class)->draft($actor->identifier(), $conversation);

    expect($card['state'])->toBe('artifact')
        ->and($card['citations'])->toBe(['018f6b2e-0000-7000-8000-0000000000a1'])
        ->and($card['confidence'])->toBe(0.88)
        ->and($card['notices'])->toBe([])
        ->and($card['kb'][0]['article_ref'])->toBe('018f6b2e-0000-7000-8000-0000000000a1');
    // The internal chunk rode the prompt (fenced as DATA).
    expect($driver->seen[1]['messages'][0]['content'])->toContain('<<<chunk article_ref=018f6b2e-0000-7000-8000-0000000000a1>>>');
    $row = DB::table('ai_decisions')->where('id', $card['artifact_id'])->first(['surface', 'task', 'action', 'tokens_in', 'tokens_out']);
    expect($row->surface)->toBe('copilot')->and($row->task)->toBe('draft_reply')->and($row->action)->toBe('artifact')->and((int) $row->tokens_in)->toBe(120);
});

it('FR-14 guardrail notice: a money-intent draft WITHOUT a citation is not suppressed but carries "low confidence — verify the reference manually"; an invented citation is dropped (AC-02\'s set-membership rule)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] what is the withdrawal fee?', 'en', ['intent' => 'withdrawals']);
    copilotDriver(copilotEmbedding(9), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Let me confirm the current withdrawal fee for you.', 'citations' => ['kb-invented'], 'confidence' => 0.7]), 10, 10));

    $card = app(ComposerActions::class)->draft($actor->identifier(), $conversation);

    expect($card['state'])->toBe('artifact')
        ->and($card['citations'])->toBe([])
        ->and($card['notices'])->toBe(['ai.copilot.notice.verify_reference']);
});

it('FR-15 Improve: tone/grammar kept to meaning; the §14 digit rule applies to improve too (an "improvement" that changes an amount is refused)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotDriver(
        new ProviderResponse('[FIXTURE] Thank you for your patience — I will check your 500 ETB deposit right away.', 30, 20),
        new ProviderResponse('[FIXTURE] Thank you — I will check your 5,000 ETB deposit right away.', 30, 20),
        new ProviderResponse('[FIXTURE] Thank you — I will check your 5,000 ETB deposit right away.', 30, 20),
    );
    $actions = app(ComposerActions::class);

    $ok = $actions->improve($actor->identifier(), $conversation, '[FIXTURE] thanks for patience, i check your 500 ETB deposit now');
    expect($ok['state'])->toBe('artifact')->and($ok['kind'])->toBe('improve_text')->and($ok['text'])->toContain('500 ETB');

    $bad = $actions->improve($actor->identifier(), $conversation, '[FIXTURE] thanks, i check your 500 ETB deposit now');
    expect($bad['state'])->toBe('refused')->and($bad['notices'])->toBe(['ai.copilot.notice.digit_mismatch']);
});

it('FR-15 Summarize thread: an INTERNAL NOTE draft (kind note_draft) built from customer+agent+ai turns and NEVER from notes (BR-06); nothing is written to the notes path', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] my withdrawal of 900 ETB is pending since Monday');
    DB::table('messages')->insert(['id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(), 'direction' => 'out', 'author_type' => 'agent', 'author_id' => $actor->identifier(), 'kind' => 'note', 'is_internal' => true, 'body' => 'STAFF-PRIVATE: suspected bonus abuse, hold payout', 'delivery_state' => 'sent']);
    $driver = copilotDriver(new ProviderResponse('[FIXTURE] The customer reports a 900 ETB withdrawal pending since Monday. No action taken yet. Open: payments check.', 60, 40));

    $card = app(ComposerActions::class)->summarize($actor->identifier(), $conversation);

    expect($card['state'])->toBe('artifact')->and($card['kind'])->toBe('note_draft')->and($card['text'])->toContain('900 ETB');
    expect(json_encode($driver->seen[0]['messages']))->not->toContain('STAFF-PRIVATE')->not->toContain('bonus abuse');
    expect(DB::table('messages')->where('conversation_id', $conversation->identifier())->where('kind', 'note')->count())->toBe(1, 'the summarize action wrote a note — it may only DRAFT one');
});

it('FR-15 doors: every composer action is QUEUED (202 + request id, events named); the provider call runs in the job, not in the request; validation refuses an empty improve text', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    Illuminate\Support\Facades\Queue::fake();

    $base = 'https://localhost/inbox/ai/copilot/'.$conversation->identifier();
    $r = $this->actingAs($actor, 'staff')->postJson($base.'/actions/translate', ['text' => '[FIXTURE] hello', 'target_language' => 'am']);
    $r->assertStatus(202);
    expect($r->json('events'))->toBe(['ai.copilot.delta', 'ai.copilot.card'])->and($r->json('request_id'))->toBeString();
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\Ai\CopilotTaskJob::class, fn (App\Jobs\Ai\CopilotTaskJob $job): bool => $job->action === 'translate' && $job->queue === 'ai' && $job->tenantId === $tenantId);

    $this->actingAs($actor, 'staff')->postJson($base.'/actions/improve', ['text' => ''])->assertStatus(422);
    $this->actingAs($actor, 'staff')->postJson($base.'/actions/unknown', ['text' => 'x'])->assertStatus(404);
    $this->actingAs($actor, 'staff')->postJson($base.'/draft')->assertStatus(202);
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\Ai\CopilotTaskJob::class, 2);
});

it('C-10: an out-of-scope conversation answers the 404 pattern on every Copilot door', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    // An Own-scoped role holding the Copilot keys (scope narrow, permissions present).
    $role = App\Models\Role::query()->create(['name' => 'S09 Copilot Own', 'color' => 'role-agent',
        'permissions' => ['tickets.view', 'conversations.view', 'ai.copilot.use', 'ai.copilot.insert_draft'],
        'scopes' => ['tickets' => 'Own', 'conversations' => 'Own', 'customers' => 'Own', 'reports' => 'Own']]);
    $stranger = App\Models\User::factory()->create();
    $stranger->forceFill(['role_id' => $role->getKey()])->save();
    app(App\Support\Rbac\CapabilityCacheInvalidator::class)->forgetUser($stranger);
    $base = 'https://localhost/inbox/ai/copilot/'.$conversation->identifier();
    $this->actingAs($stranger, 'staff')->getJson($base.'/panel')->assertStatus(404);
    $this->actingAs($stranger, 'staff')->postJson($base.'/draft')->assertStatus(404);
    $this->actingAs($stranger, 'staff')->postJson($base.'/actions/summarize')->assertStatus(404);
});

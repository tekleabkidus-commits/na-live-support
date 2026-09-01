<?php

declare(strict_types=1);

use App\Domain\Forms\Runtime\ActiveFlowProvider;
use App\Domain\Forms\Runtime\TelegramFlowEngine;
use App\Domain\Forms\Seeding\FormSeeder;
use App\Domain\Forms\SubmissionWriter;
use App\Domain\Tickets\Creation\CreateFromHandoff;
use App\Domain\Tickets\Creation\HandoffPacket;
use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-21 / T-07 / EC-06 / SS-03 / FS-04 / US-04 — THE TELEGRAM FLOW
| ENGINE (closes Doc 10 FR-19's tap-engine half)
|--------------------------------------------------------------------------
*/

/** @return array{0: mixed, 1: string, 2: mixed, 3: TelegramFlowEngine} tenant, conversationId, agent, engine */
function formFlowScene(): array
{
    $tenant = createTenant();
    [, $conversation] = conversationFixture();
    $agent = grantAllPermissions(ticketActor([]));
    app(App\Domain\Org\Seeding\OrganizationSeeder::class)->seed();
    app(FormSeeder::class)->seed($agent);

    return [$tenant, $conversation->identifier(), $agent, app(TelegramFlowEngine::class)];
}

it('US-04 end-to-end (direct mode): one field per message, buttons for choices, native file, regex validation — completed product lands via the handoff door with everything attached', function (): void {
    [, $conversationId, $agent, $engine] = formFlowScene();
    $depositId = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('id');

    $prompt = $engine->start($conversationId, $depositId, 'direct', 'en');
    expect($prompt['kind'])->toBe('prompt')->and($prompt['field'])->toBe('amount');
    // Doc 09's seam sees the live flow (closing row 5's run_form half).
    expect(app(ActiveFlowProvider::class)->activeState($conversationId))->toBe(['form_id' => $depositId, 'step' => 0])
        ->and(app(ActiveFlowProvider::class)->availableForms())->toContain($depositId);

    $engine->handle($conversationId, ['text' => '2500']);
    $prompt = $engine->handle($conversationId, ['choice' => 'ETB']);          // currency buttons
    expect($prompt['field'])->toBe('method')->and(array_column($prompt['buttons'], 'value'))->toContain('telebirr');
    $engine->handle($conversationId, ['choice' => 'telebirr']);
    // The reference regex (^[A-Za-z0-9-]{6,32}$) refuses garbage, then accepts.
    $retry = $engine->handle($conversationId, ['text' => '!!']);
    expect($retry['kind'])->toBe('retry')->and($retry['text_key'])->toBe('workflow.flow.retry');
    $engine->handle($conversationId, ['text' => 'TB12345678']);
    $engine->handle($conversationId, ['text' => '']);                          // happened_at — optional skip
    $prompt = $engine->handle($conversationId, ['file' => 'media:fixture-screenshot-01']);
    expect($prompt['field'])->toBe('details');
    $done = $engine->handle($conversationId, ['text' => '']);                  // details — optional skip

    expect($done['kind'])->toBe('completed')
        ->and($done['form_data']['complete'])->toBeTrue()
        ->and($done['form_data']['answers']['screenshot'])->toBe('media:fixture-screenshot-01')
        ->and($done['completion']['tags'])->toBe(['deposit-issue']);

    // US-04's tail: "hands me to the morning queue with everything attached" —
    // the product rides the EXISTING handoff door, then snapshots (BR-06).
    $conversation = App\Models\Conversation::query()->findOrFail($conversationId);
    $ticket = app(CreateFromHandoff::class)->create($conversation, new HandoffPacket(
        'Fixture player filed the deposit form. All fields captured overnight.',
        'deposit_issue', 0.99, 'en', 'neutral', 'normal', null, 'normal', [], [],
        ['from_message_id' => '01a05ed6-0000-7000-8000-000000000001', 'to_message_id' => '01a05ed6-0000-7000-8000-000000000002'],
        $done['form_data'],
    ));
    app(SubmissionWriter::class)->record($ticket->identifier(), $done['form_data']['version_id'], $done['form_data']['answers'], 'telegram');
    /** @var object{form_data: string} $row */
    $row = DB::selectOne('SELECT form_data::text AS form_data FROM tickets WHERE id = ?', [$ticket->identifier()]);
    expect(json_decode($row->form_data, true)['answers']['amount'])->toBe('2500');
    expect((int) DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->where('outcome', 'completed')->count())->toBe(1);
});

it('FR-21 retries: three friendly failures then the human offer; /human hands off PARTIAL data flagged incomplete (SS-03)', function (): void {
    [, $conversationId, , $engine] = formFlowScene();
    $depositId = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('id');
    $engine->start($conversationId, $depositId, 'direct', 'am');

    expect($engine->handle($conversationId, ['text' => 'abc'])['kind'])->toBe('retry')
        ->and($engine->handle($conversationId, ['text' => 'still not a number'])['kind'])->toBe('retry');
    $offer = $engine->handle($conversationId, ['text' => 'nope']);
    expect($offer['kind'])->toBe('offer_human')->and($offer['text_key'])->toBe('workflow.flow.offer_human');
    // The offer copy exists in both launch languages (N-03).
    expect(__('workflow.flow.offer_human', [], 'en'))->toContain('/human')
        ->and(__('workflow.flow.offer_human', [], 'am'))->toContain('/human');

    $engine->handle($conversationId, ['text' => '2500']);                      // recovers, answers one field
    $handoff = $engine->handle($conversationId, ['text' => '/human']);
    expect($handoff['kind'])->toBe('handed_off')
        ->and($handoff['form_data']['complete'])->toBeFalse()
        ->and($handoff['form_data']['answers'])->toBe(['amount' => '2500']);
    expect(DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->value('outcome'))->toBe('handed_off');
});

it('EC-06: a version published MID-FLOW never moves the running flow — it completes on ITS checkpointed version', function (): void {
    [, $conversationId, $agent, $engine] = formFlowScene();
    [$publisher, $writer] = [app(App\Domain\Forms\FormPublisher::class), app(App\Domain\Forms\FormWriter::class)];
    $formId = $writer->create(['name' => 'Fixture Midflow Form'], $agent->identifier());
    $v1 = $publisher->publish($formId, minimalFormSchema(), $agent->identifier());

    $engine->start($conversationId, $formId, 'business', 'en');
    $v2 = $publisher->publish($formId, minimalFormSchema(), $agent->identifier());   // mid-flow publish
    $done = $engine->handle($conversationId, ['text' => 'my payout is stuck']);

    expect($done['kind'])->toBe('completed')
        ->and($done['form_data']['version_id'])->toBe($v1['version_id'])
        ->and($v2['version_id'])->not->toBe($v1['version_id']);
});

it('FS-04 DRILL: Redis state killed mid-flow → the next message resumes from the DB checkpoint with the recap, boundary answers intact', function (): void {
    [, $conversationId, $agent, $engine] = formFlowScene();
    $depositId = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('id');
    $engine->start($conversationId, $depositId, 'direct', 'en');
    // Cross the first step boundary (amount, currency, method, reference, time) so a checkpoint exists.
    $engine->handle($conversationId, ['text' => '2500']);
    $engine->handle($conversationId, ['choice' => 'ETB']);
    $engine->handle($conversationId, ['choice' => 'telebirr']);
    $engine->handle($conversationId, ['text' => 'TB12345678']);
    $prompt = $engine->handle($conversationId, ['text' => '']);                // completes step 1 → checkpoint
    expect($prompt['field'])->toBe('screenshot');

    app(TenantCache::class)->forget('flow:'.$conversationId);                  // THE DRILL: Redis dies

    $resumed = $engine->handle($conversationId, ['file' => 'media:fixture-after-crash']);
    expect($resumed['kind'])->toBe('resumed')
        ->and($resumed['recap_key'])->toBe('workflow.flow.resumed_recap')
        ->and($resumed['kept'])->toBe(5)
        ->and($resumed['field'])->toBe('screenshot');
    expect(__('workflow.flow.resumed_recap', [], 'am'))->not->toBe('workflow.flow.resumed_recap');

    $engine->handle($conversationId, ['file' => 'media:fixture-after-crash']);
    $done = $engine->handle($conversationId, ['text' => '']);
    expect($done['kind'])->toBe('completed')
        ->and($done['form_data']['answers']['amount'])->toBe('2500')
        ->and($done['form_data']['answers']['screenshot'])->toBe('media:fixture-after-crash');
});

it('T-07: one live flow per conversation — a second start supersedes the first by outcome, never by delete', function (): void {
    [, $conversationId, , $engine] = formFlowScene();
    $depositId = (string) DB::table('forms')->where('name', 'Deposit Issue')->value('id');
    $withdrawalId = (string) DB::table('forms')->where('name', 'Withdrawal Issue')->value('id');
    $engine->start($conversationId, $depositId, 'direct', 'en');
    $engine->start($conversationId, $withdrawalId, 'direct', 'en');

    $outcomes = DB::table('form_flow_checkpoints')->where('conversation_id', $conversationId)->orderBy('created_at')->pluck('outcome')->all();
    expect($outcomes)->toBe(['superseded', null]);
    expect(app(ActiveFlowProvider::class)->activeState($conversationId)['form_id'])->toBe($withdrawalId);
});

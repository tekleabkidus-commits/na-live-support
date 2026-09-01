<?php

declare(strict_types=1);

use App\Domain\Macros\MacroGuard;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 T-06 / BR-04 / FR-15 — NO BLANK EVER REACHES A CUSTOMER
| (sabotage (c)'s standing red)
|--------------------------------------------------------------------------
|
| The AUTOMATION half, enforced now: the send_macro_reply arm resolves through
| THE ONE RESOLVER and refuses `blank_variable` when anything is unresolved —
| NOTHING is sent (no message row exists to walk back). A fully-resolved macro
| sends with the system author. The STAFF half is packet P-SEND's ComposeOutbound
| guard — its PRE-STATE is pinned here (a pill-carrying body passes compose
| today) so the landing has its fail-first flip on record.
*/

it('T-06 automation half: an unresolved variable refuses blank_variable and sends NOTHING; a resolved macro sends as system', function (): void {
    [$tenant, $ticket, $agent, $writer] = macroScene();
    registerFakeChannel();
    $blankMacro = $writer->create(['name' => 'Blanky', 'scope' => 'tenant', 'body' => 'ETA: {{custom.payout_eta}}'], $agent->identifier());
    $cleanMacro = $writer->create(['name' => 'Clean', 'scope' => 'tenant', 'body' => 'Hi {{customer.name}}, we are on it.'], $agent->identifier());

    $actions = [
        ['type' => 'send_macro_reply', 'macro_id' => $blankMacro],
        ['type' => 'send_macro_reply', 'macro_id' => $cleanMacro],
    ];
    $executionId = automationActionScene($tenant, $ticket, $actions);
    $before = (int) DB::table('messages')->where('conversation_id', $ticket->getAttribute('conversation_id'))->count();

    $outcomes = runChain($executionId, $actions);

    expect($outcomes[0]['reason'])->toBe(MacroGuard::CODE)
        ->and($outcomes[0]['unresolved'])->toBe(['custom.payout_eta'])
        ->and($outcomes[1]['outcome'])->toBe('ok', json_encode($outcomes[1]));
    /** @var list<object{author_type: string, body: string}> $sent */
    $sent = DB::select('SELECT author_type, body FROM messages WHERE conversation_id = ? AND direction = ? ORDER BY created_at DESC', [$ticket->getAttribute('conversation_id'), 'out']);
    expect(count($sent))->toBe($before + 1, 'the blank macro sent something — BR-04 broken')
        ->and($sent[0]->author_type)->toBe('system')
        ->and($sent[0]->body)->toContain('Fixture Player')
        ->and($sent[0]->body)->not->toContain('{{');
});

it('the guard grammar: pills detected anywhere, resolved text passes; P-SEND\'s pre-state pinned (compose accepts a pill body TODAY — flips at the packet\'s landing)', function (): void {
    expect(MacroGuard::unresolvedIn('Hi {{ customer.name }}, {{custom.x}} and {{custom.x}} again'))->toBe(['customer.name', 'custom.x'])
        ->and(MacroGuard::unresolvedIn('no pills here'))->toBe([]);
    expect(fn () => MacroGuard::assertNoBlank('ETA {{custom.payout_eta}}'))->toThrow(DomainException::class, 'blank_variable');
    MacroGuard::assertNoBlank('all resolved');

    // P-SEND pre-state: the STAFF send API does not yet refuse pills — pinned so
    // the countersigned landing flips THIS assertion (its fail-first, on record).
    [$tenant, $ticket] = macroScene();
    registerFakeChannel();
    $conversation = App\Models\Conversation::query()->findOrFail($ticket->getAttribute('conversation_id'));
    $message = app(App\Domain\Conversations\Outbound\ComposeOutbound::class)
        ->compose($conversation, 'system', null, 'text', 'pill survives: {{custom.payout_eta}}', 'psend-prestate');
    expect($message->body)->toContain('{{custom.payout_eta}}');
});

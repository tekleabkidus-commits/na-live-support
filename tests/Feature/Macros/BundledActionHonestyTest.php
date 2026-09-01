<?php

declare(strict_types=1);

use App\Domain\Macros\BundledActions;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 EC-09 / AC-05's honesty half — bundled actions, per-capability honest
|--------------------------------------------------------------------------
|
| The text inserts regardless; each bundled action runs through ITS OWNING
| DOOR as the INSERTING AGENT: held capability → applied; missing → SKIPPED
| WITH A NOTICE (no silent privilege, no silent drop). The notice keys exist
| in both launch languages.
*/

it('EC-09: a tags-only agent applies add_tags, set_priority is skipped WITH the notice; a full agent applies both', function (): void {
    [$tenant, $ticket] = macroScene();
    $bundled = [
        ['type' => 'add_tags', 'value' => ['macro-tag']],
        ['type' => 'set_priority', 'value' => 'high'],
    ];
    $limited = ticketActor(['tickets.tags.manage'], 'Bundled Limited Role');
    $ticketModel = App\Models\Ticket::query()->findOrFail($ticket->identifier());

    $outcomes = app(BundledActions::class)->applyOnSend($ticketModel, $bundled, $limited);
    expect($outcomes[0]['outcome'])->toBe('applied')
        ->and($outcomes[1]['outcome'])->toBe('skipped')
        ->and($outcomes[1]['notice_key'])->toBe('workflow.macros.bundled_skipped')
        ->and(__($outcomes[1]['notice_key'], [], 'en'))->not->toBe($outcomes[1]['notice_key'])
        ->and(__($outcomes[1]['notice_key'], [], 'am'))->not->toBe($outcomes[1]['notice_key']);
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('priority'))->toBe('normal', 'the skipped action still applied — silent privilege');
    /** @var object{tags: string} $row */
    $row = DB::selectOne('SELECT array_to_json(tags)::text AS tags FROM tickets WHERE id = ?', [$ticket->identifier()]);
    expect(json_decode($row->tags, true))->toContain('macro-tag');

    $full = grantAllPermissions(ticketActor([], 'Bundled Full Role'));
    $outcomes = app(BundledActions::class)->applyOnSend($ticketModel, $bundled, $full);
    expect($outcomes[1]['outcome'])->toBe('applied');
    expect(DB::table('tickets')->where('id', $ticket->identifier())->value('priority'))->toBe('high');
});

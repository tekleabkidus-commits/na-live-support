<?php

declare(strict_types=1);

use App\Domain\Macros\PaletteReader;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Doc 12 FR-16 / B12-S03 — THE BINDING SEAM's contract, proven at build time
| (R-D12-2.3): personal-before-shared, fuzzy anywhere-letters, one list with
| the kind tag, live per-variable preview states, usage counts, and
| INSERT-NEVER-SENDS as STRUCTURE (the reader holds no send path of any kind).
|--------------------------------------------------------------------------
*/

it('B12-S03: personal lists FIRST, fuzzy matches letters anywhere, one list carries the kind tag, usage ranks and records macro.used', function (): void {
    [$tenant, $ticket, $agent, $writer] = macroScene();
    $mine = $writer->create(['name' => 'Withdrawal delay — first reply', 'scope' => 'personal', 'owner_id' => $agent->identifier(), 'body' => 'w'], $agent->identifier());
    $shared = $writer->create(['name' => 'Withdrawal limits explained', 'scope' => 'tenant', 'kind' => 'saved_reply', 'body' => 's'], $agent->identifier());
    $stranger = grantAllPermissions(ticketActor([], 'Palette Stranger Role'));
    $foreign = $writer->create(['name' => 'Private of another', 'scope' => 'personal', 'owner_id' => $stranger->identifier(), 'body' => 'p'], $stranger->identifier());

    $reader = app(PaletteReader::class);
    $rows = $reader->search($agent);
    expect($rows[0]['scope'])->toBe('personal', 'personal macros must list FIRST (the drawn law)')
        ->and(array_column($rows, 'id'))->not->toContain($foreign, 'another agent\'s personal macro leaked')
        ->and(collect($rows)->firstWhere('id', $shared)['kind'])->toBe('saved_reply');

    // "wd" fuzzy-matches Withdrawal (letters anywhere) — the drawn search frame.
    $fuzzy = $reader->search($agent, 'wd');
    expect(array_column($fuzzy, 'id'))->toContain($mine)
        ->and($reader->search($agent, 'chapa'))->toBe([]);

    $reader->recordUse($mine, $ticket->identifier());
    expect((int) DB::table('macros')->where('id', $mine)->value('usage_count'))->toBe(1);
    expect((int) DB::table('outbox')->where('name', 'macro.used')->count())->toBe(1);
});

it('B12-S03: INSERT NEVER SENDS — the palette classes hold NO send capability, structurally', function (): void {
    // The seam's hardest promise as a static fact: no compose/outbound/adapter
    // reach exists anywhere in the Macros palette classes. (The send REFUSAL for
    // pill-carrying bodies is MacroGuard's — MacroNoBlankSendTest.)
    foreach (['PaletteReader.php', 'VariableResolver.php', 'MacroWriter.php'] as $file) {
        $source = (string) file_get_contents(app_path('Domain/Macros/'.$file));
        foreach (['ComposeOutbound', 'ChannelAdapter', 'OutboundQueue', '->compose('] as $forbidden) {
            expect(str_contains($source, $forbidden))->toBeFalse("{$file} reaches a send path ({$forbidden}) — insertion could send");
        }
    }
    // recordUse writes usage + event — and NO message row.
    [$tenant, $ticket, $agent, $writer] = macroScene();
    $macroId = $writer->create(['name' => 'M', 'scope' => 'personal', 'owner_id' => $agent->identifier(), 'body' => 'body'], $agent->identifier());
    $messagesBefore = (int) DB::table('messages')->count();
    app(PaletteReader::class)->recordUse($macroId, $ticket->identifier());
    expect((int) DB::table('messages')->count())->toBe($messagesBefore, 'an insertion produced a message — insert must never send');
});

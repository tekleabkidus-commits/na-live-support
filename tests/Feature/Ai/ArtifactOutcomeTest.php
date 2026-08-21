<?php

declare(strict_types=1);

use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 FR-16 / AC-06's second clause: every Copilot artifact's outcome —
 * inserted_unedited | inserted_edited{diff_ref} | discarded — captured on
 * the artifact's OWN decision row through T-08's enrichment column; doors
 * gated per FR-14 (insert/sent on ai.copilot.insert_draft); another
 * principal's artifact is invisible (404 pattern).
 */
function mintedDraft(string $text = '[FIXTURE] Thanks — I will check the 500 ETB deposit now.'): array
{
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] my 500 ETB deposit is missing');
    copilotDriver(copilotEmbedding(1), new ProviderResponse((string) json_encode(['text' => $text, 'citations' => [], 'confidence' => 0.9]), 100, 40));

    $card = app(App\Domain\Ai\Copilot\ComposerActions::class)->draft($actor->identifier(), $conversation);
    expect($card['state'])->toBe('artifact');

    return [$tenant, $tenantId, $conversation, $actor, (string) $card['artifact_id'], $text];
}

it('FR-16 inserted_unedited: insert (gated ai.copilot.insert_draft) flips the row; the composer send of the IDENTICAL body keeps it unedited', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $artifactId, $text] = mintedDraft();

    $insert = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/insert');
    $insert->assertStatus(200);
    expect($insert->json('text'))->toBe($text)->and($insert->json('outcome'))->toBe('inserted_unedited');

    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => $text]);
    $sent->assertStatus(201);
    $verdict = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/sent', ['message_id' => $sent->json('message_id')]);
    $verdict->assertStatus(200);
    expect($verdict->json('outcome'))->toBe('inserted_unedited');

    asTenant($tenant, function () use ($artifactId, $sent): void {
        $row = DB::table('ai_decisions')->where('id', $artifactId)->first(['outcome', 'outcome_meta']);
        expect($row->outcome)->toBe('inserted_unedited');
        $meta = json_decode((string) $row->outcome_meta, true);
        expect($meta['message_id'])->toBe($sent->json('message_id'));
    });
});

it('FR-16 inserted_edited{diff_ref}: the agent edits before sending — the verdict upgrades, diff_ref carries identifiers + NUMERIC edit metrics (Q7: no text on the enrichment column)', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $artifactId, $text] = mintedDraft();
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/insert')->assertStatus(200);

    $edited = $text.' Please allow up to 30 minutes.';
    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => $edited]);
    $sent->assertStatus(201);
    $verdict = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/sent', ['message_id' => $sent->json('message_id')]);
    expect($verdict->json('outcome'))->toBe('inserted_edited');

    asTenant($tenant, function () use ($artifactId, $sent, $text, $edited): void {
        $row = DB::table('ai_decisions')->where('id', $artifactId)->first(['outcome', 'outcome_meta']);
        expect($row->outcome)->toBe('inserted_edited');
        $meta = json_decode((string) $row->outcome_meta, true);
        expect($meta['diff_ref']['message_id'])->toBe($sent->json('message_id'))
            ->and($meta['diff_ref']['original_length'])->toBe(mb_strlen($text))
            ->and($meta['diff_ref']['final_length'])->toBe(mb_strlen($edited))
            ->and($meta['diff_ref']['edit_distance'])->toBeGreaterThan(0);
        // Q7: no prose rides the enrichment column — every leaf is an id or a number.
        array_walk_recursive($meta, function ($leaf): void {
            expect(is_int($leaf) || is_float($leaf) || (is_string($leaf) && preg_match('/^[0-9a-f-]{36}$/', $leaf) === 1))->toBeTrue('outcome_meta carries text: '.var_export($leaf, true));
        });
    });
});

it('FR-16 discarded: the discard door records it; a discarded artifact is still the agent\'s (the row stays, the outcome is final)', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $artifactId] = mintedDraft();
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/discard')->assertStatus(200);
    asTenant($tenant, function () use ($artifactId): void {
        expect(DB::table('ai_decisions')->where('id', $artifactId)->value('outcome'))->toBe('discarded');
    });
});

it('FR-14 gating: insert WITHOUT ai.copilot.insert_draft is refused (403) while the panel and discard stay open on ai.copilot.use', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $artifactId] = mintedDraft();
    $viewer = inboxScopedActor('All', 'S09 Copilot Viewer');
    grantPermissions($viewer, ['tickets.view', 'conversations.view', 'ai.copilot.use'], 'S09 Copilot Viewer Role');

    $this->actingAs($viewer, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/insert')->assertStatus(403);
    $this->actingAs($viewer, 'staff')->getJson('https://localhost/inbox/ai/copilot/'.$conversation->identifier().'/panel')->assertStatus(200);
    // Another principal's artifact: the 404 pattern, not 403 (C-10 — no existence oracle).
    $this->actingAs($viewer, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/discard')->assertStatus(404);
    // The owner with insert_draft: insert works.
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$artifactId.'/insert')->assertStatus(200);
});

it('FR-16: a non-usable row (low_confidence / refused) cannot be inserted — the door answers the 404 pattern', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] hello?', 'en', ['confidence' => 0.1]); // below handoff_below 0.3
    copilotDriver(copilotEmbedding(1), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Hi!', 'citations' => [], 'confidence' => 0.9]), 10, 10));
    $card = app(App\Domain\Ai\Copilot\ComposerActions::class)->draft($actor->identifier(), $conversation);
    expect($card['state'])->toBe('low_confidence')->and($card)->not->toHaveKey('text');

    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$card['artifact_id'].'/insert')->assertStatus(404);
});

<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\DB;

/**
 * The S09 step-10 COUNTERSIGNED core-edit packet's proving obligations
 * (a–d): Doc 09 FR-15's original-in-message-metadata through the REAL send
 * path (Doc 07 composer door -> Doc 04 ComposeOutbound -> persistOutbound),
 * the negative controls, the default-preserving claim TESTED, and FR-16's
 * verdict on the send. (Obligation (a) — Doc 04's and Doc 07's own suites
 * green on the edited files — is the step's pasted battery.)
 */
function translateArtifact(): array
{
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotDriver(new ProviderResponse('[FIXTURE] የ500 ETB ተቀማጭዎ ማጣቀሻ TX-48213 ነው።', 40, 20));
    $original = '[FIXTURE] Your 500 ETB deposit reference is TX-48213.';
    $card = app(ComposerActions::class)->translate($actor->identifier(), $conversation, $original, 'am');
    expect($card['state'])->toBe('artifact');

    return [$tenant, $tenantId, $conversation, $actor, $card, $original];
}

it('(b)+(d): a translate send carries channel_meta.ai_translation {artifact_id, original_text, source_language, target_language} ON THE MESSAGE ROW through the real send path, and FR-16 records inserted_unedited on the artifact', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $card, $original] = translateArtifact();

    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => $card['text'], 'ai_artifact_id' => $card['artifact_id']]);
    $sent->assertStatus(201);

    asTenant($tenant, function () use ($sent, $card, $original): void {
        $row = DB::table('messages')->where('id', $sent->json('message_id'))->first(['body', 'channel_meta', 'author_type', 'delivery_state']);
        expect($row->body)->toBe($card['text'])->and($row->author_type)->toBe('agent')->and($row->delivery_state)->toBe('queued');
        $meta = json_decode((string) $row->channel_meta, true);
        expect($meta['ai_translation'])->toEqualCanonicalizing([
            'artifact_id' => $card['artifact_id'], 'original_text' => $original,
            'source_language' => 'en', 'target_language' => 'am', 'edited' => false,
        ]);
        expect(DB::table('ai_decisions')->where('id', $card['artifact_id'])->value('outcome'))->toBe('inserted_unedited');
    });
});

it('(d): an EDITED translation still carries the original and the verdict is inserted_edited{diff_ref: message_id, …}', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $card, $original] = translateArtifact();
    $edited = $card['text'].' እናመሰግናለን።';
    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => $edited, 'ai_artifact_id' => $card['artifact_id']]);
    $sent->assertStatus(201);
    asTenant($tenant, function () use ($sent, $card, $original): void {
        $meta = json_decode((string) DB::table('messages')->where('id', $sent->json('message_id'))->value('channel_meta'), true);
        expect($meta['ai_translation']['original_text'])->toBe($original)->and($meta['ai_translation']['edited'])->toBeTrue();
        $decision = DB::table('ai_decisions')->where('id', $card['artifact_id'])->first(['outcome', 'outcome_meta']);
        expect($decision->outcome)->toBe('inserted_edited');
        expect(json_decode((string) $decision->outcome_meta, true)['diff_ref']['message_id'])->toBe($sent->json('message_id'));
    });
});

it('(c) default-preserving, TESTED: a reply WITHOUT ai_artifact_id writes a row byte-identical to the pre-edit shape — channel_meta is the DB default {} (not []), nothing else differs', function (): void {
    [$tenant, $tenantId, $conversation, $actor] = translateArtifact();
    $sent = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$conversation->identifier().'/reply', ['body' => 'Plain reply, no artifact.']);
    $sent->assertStatus(201);
    asTenant($tenant, function () use ($sent): void {
        $raw = DB::selectOne('SELECT channel_meta::text AS t, jsonb_typeof(channel_meta) AS kind FROM messages WHERE id = ?', [$sent->json('message_id')]);
        expect($raw->t)->toBe('{}')->and($raw->kind)->toBe('object');
    });
});

it('(c) negative controls: wrong-owner artifact, missing ai.copilot.insert_draft, foreign-conversation artifact, non-translate artifact — each sends the reply UNCHANGED with no ai_translation and no FR-16 verdict', function (): void {
    [$tenant, $tenantId, $conversation, $actor, $card] = translateArtifact();
    $url = 'https://localhost/inbox/thread/'.$conversation->identifier().'/reply';

    // Wrong owner (All-scoped, has insert_draft, but not the minter).
    bindTenant($tenant);
    $other = inboxScopedActor('All', 'S09 Send Other');
    grantPermissions($other, ['tickets.view', 'conversations.view', 'tickets.reply', 'ai.copilot.use', 'ai.copilot.insert_draft'], 'S09 Send Other Role');
    $r1 = $this->actingAs($other, 'staff')->postJson($url, ['body' => $card['text'], 'ai_artifact_id' => $card['artifact_id']]);
    $r1->assertStatus(201);

    // Missing the gate (a minter whose role lacks insert_draft).
    bindTenant($tenant);
    $noGate = inboxScopedActor('All', 'S09 Send NoGate');
    grantPermissions($noGate, ['tickets.view', 'conversations.view', 'tickets.reply', 'ai.copilot.use'], 'S09 Send NoGate Role');
    copilotDriver(new ProviderResponse('[FIXTURE] ሰላም።', 5, 5));
    $ownCard = app(ComposerActions::class)->translate($noGate->identifier(), $conversation, '[FIXTURE] Hello.', 'am');
    $r2 = $this->actingAs($noGate, 'staff')->postJson($url, ['body' => $ownCard['text'], 'ai_artifact_id' => $ownCard['artifact_id']]);
    $r2->assertStatus(201);

    // Foreign conversation (the minter's artifact, sent in ANOTHER conversation of the tenant).
    bindTenant($tenant);
    $foreign = anotherConversation($tenantId, 'foreign');
    inboxTicketRow($tenantId, $foreign, 'open');
    runListProjector(inboxSeedEvent('ticket.created', ['tenant_id' => $tenantId, 'ticket_id' => DB::table('tickets')->where('conversation_id', $foreign->identifier())->value('id'), 'conversation_id' => $foreign->identifier(), 'customer_id' => $foreign->customer_id, 'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null]));
    $r3 = $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/thread/'.$foreign->identifier().'/reply', ['body' => $card['text'], 'ai_artifact_id' => $card['artifact_id']]);
    $r3->assertStatus(201);

    // Non-translate artifact (a draft): nothing to preserve → no ai_translation, but FR-16 STILL records (it is the minter's).
    bindTenant($tenant);
    copilotCustomerMessage($tenantId, $conversation, '[FIXTURE] hi');
    copilotDriver(copilotEmbedding(1), new ProviderResponse((string) json_encode(['text' => '[FIXTURE] Hello there!', 'citations' => [], 'confidence' => 0.9]), 5, 5));
    $draft = app(ComposerActions::class)->draft($actor->identifier(), $conversation);
    $r4 = $this->actingAs($actor, 'staff')->postJson($url, ['body' => $draft['text'], 'ai_artifact_id' => $draft['artifact_id']]);
    $r4->assertStatus(201);

    asTenant($tenant, function () use ($r1, $r2, $r3, $r4, $card, $ownCard, $draft): void {
        foreach ([$r1, $r2, $r3, $r4] as $r) {
            expect(DB::selectOne('SELECT channel_meta::text AS t FROM messages WHERE id = ?', [$r->json('message_id')])->t)->toBe('{}');
        }
        expect(DB::table('ai_decisions')->where('id', $card['artifact_id'])->value('outcome'))->toBeNull('a wrong-owner or foreign-conversation send must not enrich');
        expect(DB::table('ai_decisions')->where('id', $ownCard['artifact_id'])->value('outcome'))->toBeNull('no gate, no verdict');
        expect(DB::table('ai_decisions')->where('id', $draft['artifact_id'])->value('outcome'))->toBe('inserted_unedited');
    });
});

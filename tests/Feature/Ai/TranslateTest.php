<?php

declare(strict_types=1);

use App\Domain\Ai\Copilot\ComposerActions;
use App\Domain\Ai\Copilot\DigitValidator;
use App\Domain\Ai\Providers\ProviderResponse;
use Illuminate\Support\Facades\DB;

/**
 * Doc 09 §14's money-safety rule + EC-10. The validator rejects altered
 * digits (sabotage #14 disables it → the altered-digit fixture goes red);
 * mixed-language input resolves by dominant script; embedded terms are
 * preserved; one repair retry precedes the refusal; the agent NEVER
 * receives altered text.
 */
it('§14 validator unit: same digits in any order pass; a reordered digit, a dropped zero, a "localized" amount, an altered reference all fail — with the exact missing/extra tokens named', function (): void {
    expect(DigitValidator::compare('Deposit 1,500 ETB ref TX-48213 on 12/08/2026', 'የ1,500 ETB ተቀማጭ TX-48213 በ12/08/2026')['ok'])->toBeTrue();
    expect(DigitValidator::compare('Your code is 4821', 'Your code is 4812'))->toMatchArray(['ok' => false, 'missing' => ['4821'], 'extra' => ['4812']]);
    expect(DigitValidator::compare('Pay 500 ETB', 'Pay 50 ETB')['ok'])->toBeFalse();
    expect(DigitValidator::compare('Pay 1,500 ETB', 'Pay 1500 ETB')['ok'])->toBeFalse('grouping separators are part of the verbatim token');
    expect(DigitValidator::compare('ref TX-48213', 'ref TX-48231')['ok'])->toBeFalse();
    expect(DigitValidator::compare('two amounts: 100 and 100', 'one amount: 100')['ok'])->toBeFalse('multiplicity counts');
    expect(DigitValidator::compare('No numbers here.', 'ቁጥር የለም።')['ok'])->toBeTrue();
});

it('§14 (sabotage #14 target): a translation that ALTERS a digit is rejected after one repair retry — the agent sees the refusal, never the altered text; the row records guard_refused with counts only', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $driver = copilotDriver(
        new ProviderResponse('[FIXTURE] የ500 ETB ተቀማጭዎ ማጣቀሻ TX-48231 ነው።', 40, 20), // altered: 48213 → 48231
        new ProviderResponse('[FIXTURE] የ5000 ETB ተቀማጭዎ ማጣቀሻ TX-48213 ነው።', 40, 20), // repair still wrong: 500 → 5000
    );

    $card = app(ComposerActions::class)->translate($actor->identifier(), $conversation, '[FIXTURE] Your 500 ETB deposit reference is TX-48213.', 'am');

    expect($card['state'])->toBe('refused')
        ->and($card['notices'])->toBe(['ai.copilot.notice.digit_mismatch'])
        ->and($card)->not->toHaveKey('text');
    expect(count($driver->seen))->toBe(2, 'exactly one repair retry');
    // The repair instruction names the mismatch so the model can fix it.
    $repairMessages = $driver->seen[1]['messages'];
    expect(end($repairMessages)['content'])->toContain('48213')->toContain('48231');

    $row = DB::table('ai_decisions')->where('id', $card['artifact_id'])->first(['error_category', 'action', 'response_payload']);
    expect($row->error_category)->toBe('guard_refused')->and($row->action)->toBe('refused');
    $payload = json_decode((string) $row->response_payload, true);
    expect($payload['text'])->toBeNull()->and($payload['digit_mismatch'])->toEqualCanonicalizing(['missing' => 1, 'extra' => 1]);

    // And the refused row is NOT insertable (FR-16's doors see artifacts only).
    $this->actingAs($actor, 'staff')->postJson('https://localhost/inbox/ai/copilot/artifacts/'.$card['artifact_id'].'/insert')->assertStatus(404);
});

it('§14: the repair retry SUCCEEDING yields the artifact — digits verbatim, with the original preserved on the artifact (FR-15: original_text, source/target language)', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    copilotDriver(
        new ProviderResponse('[FIXTURE] የ50 ETB ተቀማጭዎ ማጣቀሻ TX-48213 ነው።', 40, 20),
        new ProviderResponse('[FIXTURE] የ500 ETB ተቀማጭዎ ማጣቀሻ TX-48213 ነው።', 40, 20),
    );
    $original = '[FIXTURE] Your 500 ETB deposit reference is TX-48213.';
    $card = app(ComposerActions::class)->translate($actor->identifier(), $conversation, $original, 'am');

    expect($card['state'])->toBe('artifact')
        ->and($card['text'])->toBe('[FIXTURE] የ500 ETB ተቀማጭዎ ማጣቀሻ TX-48213 ነው።')
        ->and($card['source_language'])->toBe('en')
        ->and($card['target_language'])->toBe('am');
    $payload = json_decode((string) DB::table('ai_decisions')->where('id', $card['artifact_id'])->value('response_payload'), true);
    expect($payload['original_text'])->toBe($original);
});

it('EC-10 mixed-language input: the dominant script decides the source language; embedded English terms are carried through verbatim; the digit rule still applies', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $driver = copilotDriver(new ProviderResponse('[FIXTURE] Your telebirr deposit of 250 ETB will be checked within 30 minutes.', 40, 20));

    // Amharic-dominant with embedded English "telebirr" and digits.
    $input = '[FIXTURE] የtelebirr ተቀማጭዎ 250 ETB በ30 ደቂቃ ውስጥ ይፈተሻል።';
    $card = app(ComposerActions::class)->translate($actor->identifier(), $conversation, $input, 'en');

    expect($card['state'])->toBe('artifact')
        ->and($card['source_language'])->toBe('am')
        ->and($card['target_language'])->toBe('en')
        ->and($card['text'])->toContain('telebirr')->toContain('250 ETB')->toContain('30 minutes');
    // The prompt instructs preservation of embedded terms + exact digits (EC-10's two clauses).
    $system = $driver->seen[0]['messages'][0]['content'];
    expect($system)->toContain('Keep embedded product names and technical terms')->toContain('EXACTLY as written')->toContain('English (en)');
});

it('SEC-02 on staff text: a phone number in the agent\'s draft never reaches the provider (masked positionally) and comes back unmasked to the agent; the digit validator compares the UNMASKED texts', function (): void {
    [$tenant, $tenantId, $conversation, $ticket, $actor] = copilotScene();
    $driver = copilotDriver(new ProviderResponse('[FIXTURE] በ[masked:phone-1] ይደውሉልን፤ ማጣቀሻ 7731።', 40, 20));

    $card = app(ComposerActions::class)->translate($actor->identifier(), $conversation, '[FIXTURE] Call us on +251900001234, reference 7731.', 'am');

    $sentToProvider = json_encode($driver->seen[0]['messages']);
    expect($sentToProvider)->not->toContain('251900001234')->toContain('[masked:phone-1]');
    expect($card['state'])->toBe('artifact')->and($card['text'])->toContain('+251900001234')->toContain('7731');
    // The decision row's request payload holds the MAP (label => kind), never the value.
    $request = (string) DB::table('ai_decisions')->where('id', $card['artifact_id'])->value('request_payload');
    expect($request)->not->toContain('251900001234')->toContain('[masked:phone-1]');
});

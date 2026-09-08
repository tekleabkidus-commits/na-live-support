<?php

declare(strict_types=1);

/*
| Doc 14 FR-15 / §14 — ተወው SURVIVES EVERY LAYER BYTE-EXACT.
|
| The ONE Ethiopic string in a source document (CONFLICTS.md). The stop-word
| lists are seeded per language (en: STOP; am: ተወው). This test reads the word
| FROM `docs/Doc14-SMS-Notification-Module.md` AT TEST TIME and asserts byte
| equality against the seed fixture — no NFC/NFD normalization, no
| transliteration, no trimming may alter it. Later steps extend the same
| assertion to the settings registry (2b), the DB row (8) and the parsed
| inbound (8). A mangled byte at any layer is a sabotage red (j).
*/

function smsStopWordFromTheDocument(): string
{
    $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/Doc14-SMS-Notification-Module.md');
    // FR-15's own parenthesis: "(STOP, ተወው, configurable list)"
    $matched = preg_match('/\(STOP, ([^,]+), configurable list\)/u', $doc, $m);

    expect($matched)->toBe(1, 'FR-15\'s stop-word parenthesis was not found in the document');

    return $m[1];
}

/** @return array{en: list<string>, am: list<string>} */
function smsStopWordSeed(): array
{
    $raw = (string) file_get_contents(dirname(__DIR__, 3).'/fixtures/sms/stop-words.json');

    return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

test('the seed carries the document\'s Amharic stop-word byte-for-byte', function () {
    $fromDoc = smsStopWordFromTheDocument();
    $seed = smsStopWordSeed();

    expect($seed['en'])->toBe(['STOP'])
        ->and($seed['am'])->toHaveCount(1)
        ->and(bin2hex($seed['am'][0]))->toBe(bin2hex($fromDoc))
        ->and(strlen($fromDoc))->toBe(9)                        // THREE Ethiopic syllables × 3 bytes (ተ · ወ · ው)
        ->and(mb_strlen($fromDoc, 'UTF-8'))->toBe(3)
        ->and(bin2hex($fromDoc))->toBe('e189b0e18b88e18b8d');   // ተ ወ ው — pinned so the doc file itself cannot drift silently
});

test('no normalization, transliteration or trimming is applied to the word (a one-byte change is detected)', function () {
    $word = smsStopWordSeed()['am'][0];

    // NFC and NFD of these precomposed syllables are the word itself — but the assertion is on RAW bytes, not a normalized form
    expect(Normalizer::normalize($word, Normalizer::FORM_C))->toBe($word)
        ->and(Normalizer::normalize($word, Normalizer::FORM_D))->toBe($word);

    $mutated = substr($word, 0, -1).chr(ord($word[strlen($word) - 1]) ^ 0x01);
    expect(bin2hex($mutated))->not->toBe(bin2hex($word))
        ->and(strlen($mutated))->toBe(strlen($word));

    // the control the parser (step 8) must honour: byte comparison after trimming ASCII whitespace ONLY
    expect(trim(" {$word} \n"))->toBe($word)
        ->and(trim("\u{00A0}{$word}"))->not->toBe($word);   // a non-ASCII space is NOT trimmed — the word is compared, not "cleaned"
});

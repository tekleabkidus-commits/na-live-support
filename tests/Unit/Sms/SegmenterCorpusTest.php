<?php

declare(strict_types=1);

use App\Domain\Sms\Encoding\Encoding;
use App\Domain\Sms\Encoding\Segmenter;

/*
| Doc 14 FR-05 / NFR-06 / EC-09 / AC-04 / A-66 — THE ENCODING & SEGMENTATION CORPUS.
|
| GSM-7 vs UCS-2 auto-detected: ONE character outside the GSM-7 set (basic +
| extension tables, 3GPP TS 23.038) forces UCS-2 — "Amharic forces UCS-2
| 70-char segments; the editor states this plainly". A-66 (the multipart
| boundary, filed interpretation): a single segment carries 160 GSM-7 septets
| or 70 UCS-2 code units; once a message exceeds one segment the concatenation
| header takes room and each segment carries 153 / 67. GSM-7 extension
| characters (^ { } \ [ ~ ] | €) cost TWO septets. Astral-plane characters
| (emoji) cost TWO UCS-2 code units. The badge copy "UCS-2 · 70/segment" stays
| verbatim (§8); the counter computes with the header.
|
| The fixture `fixtures/sms/encoding-corpus.json` is hand-law: expected values
| are stated per string; the segmenter must match every one.
*/

/** @return list<array{id: string, text: string, encoding: string, units: int, segments: int, per_segment: int}> */
function smsCorpus(): array
{
    $raw = file_get_contents(dirname(__DIR__, 3).'/fixtures/sms/encoding-corpus.json');
    $decoded = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);

    return $decoded['entries'];
}

test('every corpus entry segments exactly as the fixture states', function () {
    $segmenter = new Segmenter;
    $seen = [];

    foreach (smsCorpus() as $entry) {
        $result = $segmenter->segment($entry['text']);
        $seen[] = $entry['encoding'];

        expect($result->encoding->value)->toBe($entry['encoding'], "[{$entry['id']}] encoding")
            ->and($result->units)->toBe($entry['units'], "[{$entry['id']}] units")
            ->and($result->segments)->toBe($entry['segments'], "[{$entry['id']}] segments")
            ->and($result->perSegment)->toBe($entry['per_segment'], "[{$entry['id']}] per_segment");
    }

    // the corpus covers both encodings (a corpus that never reaches UCS-2 proves nothing about Amharic)
    expect(array_unique($seen))->toContain('gsm7')->toContain('ucs2');
});

test('the corpus names the six mandated classes: GSM-7, GSM-7 extension, UCS-2, mixed, Amharic, rare script (EC-09)', function () {
    $ids = array_column(smsCorpus(), 'id');

    foreach (['gsm7-', 'gsm7-ext-', 'ucs2-', 'mixed-', 'amharic-', 'rare-script-'] as $prefix) {
        expect(array_filter($ids, static fn (string $id): bool => str_starts_with($id, $prefix)))
            ->not->toBeEmpty("no corpus entry with prefix {$prefix}");
    }
});

test('one non-GSM character forces UCS-2 (the sabotage (j) control: Amharic counted as GSM-7 is a red)', function () {
    $segmenter = new Segmenter;
    $ascii = str_repeat('a', 100);

    expect($segmenter->segment($ascii)->encoding)->toBe(Encoding::Gsm7)
        ->and($segmenter->segment($ascii.'ሀ')->encoding)->toBe(Encoding::Ucs2)
        ->and($segmenter->segment($ascii.'ሀ')->units)->toBe(101)
        ->and($segmenter->segment($ascii.'ሀ')->segments)->toBe(2)
        ->and($segmenter->segment('ትኬትዎ ተፈትቷል')->encoding)->toBe(Encoding::Ucs2);
});

test('segment boundaries are reported for the editor\'s inline markers and lie on the per-segment stride', function () {
    $segmenter = new Segmenter;

    $gsm = $segmenter->segment(str_repeat('a', 400));
    expect($gsm->segments)->toBe(3)->and($gsm->boundaries)->toBe([153, 306]);

    $ucs = $segmenter->segment(str_repeat('ሀ', 150));
    expect($ucs->segments)->toBe(3)->and($ucs->boundaries)->toBe([67, 134]);

    $single = $segmenter->segment('short');
    expect($single->segments)->toBe(1)->and($single->boundaries)->toBe([]);

    expect($segmenter->segment('')->segments)->toBe(0);
});

test('US-03: the Amharic counter warns at the 71st character — 70 is one segment, 71 is two', function () {
    $segmenter = new Segmenter;

    expect($segmenter->segment(str_repeat('ሀ', 70))->segments)->toBe(1)
        ->and($segmenter->segment(str_repeat('ሀ', 71))->segments)->toBe(2)
        ->and($segmenter->segment(str_repeat('ሀ', 71))->perSegment)->toBe(67);
});

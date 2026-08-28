<?php

declare(strict_types=1);

use App\Domain\Kb\Chunking\DeterministicChunker;

/**
 * Doc 10 T-02 / FR-13 / FR-14 / EC-08 / §12 (S10 step 5): chunk 0 is the summary line;
 * heading paths prefix every later chunk; ~kb.chunk_target_tokens per chunk; the
 * SAME content yields the SAME chunks and hashes; a minor edit to one section
 * changes ONLY that section's chunk hashes (the cost fixture — only changed chunks
 * re-embed); a 20,000-word article is handled (more chunks, no failure).
 */
beforeEach(function (): void {
    bindTenant(createTenant()); // the chunker reads kb.chunk_target_tokens through the tenant-scoped settings engine
});

function chunkerDoc(string $limitsText = '[FIXTURE] Minimum deposit is 50 ETB. Maximum is 50,000 ETB per day.'): array
{
    return ['blocks' => [
        ['type' => 'heading', 'level' => 2, 'text' => 'Deposits'],
        ['type' => 'paragraph', 'text' => '[FIXTURE] Deposits with telebirr settle within 30 minutes.'],
        ['type' => 'heading', 'level' => 3, 'text' => 'Limits'],
        ['type' => 'paragraph', 'text' => $limitsText],
        ['type' => 'heading', 'level' => 2, 'text' => 'Withdrawals'],
        ['type' => 'list', 'items' => ['[FIXTURE] Withdrawals to CBE arrive within one business day.', '[FIXTURE] A pending withdrawal can be cancelled.']],
    ]];
}

it('T-02: summary is chunk 0, heading paths prefix the rest, the SAME content → the SAME chunks and hashes', function (): void {
    $chunker = app(DeterministicChunker::class);
    $a = $chunker->chunk('[FIXTURE] Payments guide', '[FIXTURE] How deposits and withdrawals work.', chunkerDoc());
    $b = $chunker->chunk('[FIXTURE] Payments guide', '[FIXTURE] How deposits and withdrawals work.', chunkerDoc());
    expect($a)->toBe($b);
    expect($a[0]['index'])->toBe(0)->and($a[0]['text'])->toBe('[FIXTURE] How deposits and withdrawals work.')
        ->and(array_column($a, 'heading_path'))->toBe(['[FIXTURE] Payments guide', '[FIXTURE] Payments guide > Deposits', '[FIXTURE] Payments guide > Deposits > Limits', '[FIXTURE] Payments guide > Withdrawals'])
        ->and($a[2]['text'])->toStartWith("[FIXTURE] Payments guide > Deposits > Limits\n");
});

it('§12 cost fixture: a minor edit to ONE section changes only that section\'s hash — every other chunk re-uses its embedding', function (): void {
    $chunker = app(DeterministicChunker::class);
    $before = $chunker->chunk('[FIXTURE] Payments guide', '[FIXTURE] Summary.', chunkerDoc());
    $after = $chunker->chunk('[FIXTURE] Payments guide', '[FIXTURE] Summary.', chunkerDoc('[FIXTURE] Minimum deposit is 100 ETB. Maximum is 50,000 ETB per day.'));
    $changed = [];
    foreach ($before as $i => $chunk) {
        if ($chunk['hash'] !== $after[$i]['hash']) {
            $changed[] = $i;
        }
    }
    expect(count($before))->toBe(count($after))->and($changed)->toBe([2], 'only the Limits chunk changed');
});

it('EC-08 + the target: a 20,000-word article chunks into ~target-sized pieces (no chunk far above kb.chunk_target_tokens), oversized blocks split at sentence boundaries', function (): void {
    $chunker = app(DeterministicChunker::class);
    $sentence = '[FIXTURE] Deposits with telebirr settle within thirty minutes on business days.';
    $long = implode(' ', array_fill(0, 2000, $sentence)); // ~20,000 words in ONE block
    $chunks = $chunker->chunk('[FIXTURE] Mega', '[FIXTURE] Summary.', ['blocks' => [['type' => 'paragraph', 'text' => $long]]]);
    expect(count($chunks))->toBeGreaterThan(30);
    foreach (array_slice($chunks, 1) as $chunk) {
        expect($chunker->tokens($chunk['text']))->toBeLessThanOrEqual(520);
    }
    // Determinism holds at scale too.
    expect(array_column($chunks, 'hash'))->toBe(array_column($chunker->chunk('[FIXTURE] Mega', '[FIXTURE] Summary.', ['blocks' => [['type' => 'paragraph', 'text' => $long]]]), 'hash'));
});

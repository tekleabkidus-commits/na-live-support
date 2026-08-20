<?php

declare(strict_types=1);

use App\Domain\Ai\Rg\RgEvalHarness;

/**
 * NFR-07/T-06/BR-04: the RG evaluation harness — precision at the shipped
 * threshold over the 50-case labeled corpus. Replay-mode scores are the
 * MECHANISM proof (the arithmetic + the corpus + the fail path); the LIVE
 * precision figure comes from the same harness against the real provider
 * (run at this step, dev-scale-indicative; the governed corpus process is
 * [D19]'s).
 */
it('the corpus is the shipped 50: half risk, half healthy, both languages', function (): void {
    $corpus = (new RgEvalHarness)->corpus();
    $risk = array_filter($corpus, fn (array $case): bool => $case['label'] === 'risk');
    $languages = array_unique(array_column($corpus, 'language'));

    expect(count($corpus))->toBe(50)
        ->and(count($risk))->toBe(25)
        ->and($languages)->toContain('en')->toContain('am');
});

it('NFR-07: at the shipped rg_min the scripted realistic scorer meets precision >= 0.9 — and recall is REPORTED, never asserted (BR-04)', function (): void {
    $harness = new RgEvalHarness;

    // The scripted scorer: realistic two-stage outputs — risk cases score
    // high with two BORDERLINE misses (recall cost, by design); healthy
    // cases score low with one near-threshold complaint (0.7 < rg_min).
    $index = 0;
    $scorer = function (string $text) use (&$index): float {
        $isRisk = str_contains($text, 'stop') || str_contains($text, 'ማቆም') || str_contains($text, 'lost my rent')
            || str_contains($text, 'block my account') || str_contains($text, 'self exclusion') || str_contains($text, 'ራሴን ማገድ')
            || str_contains($text, 'borrowed') || str_contains($text, 'salary') || str_contains($text, 'school fees')
            || str_contains($text, 'ban me') || str_contains($text, 'destroying') || str_contains($text, 'desperate')
            || str_contains($text, 'wife will leave') || str_contains($text, 'lied to my family') || str_contains($text, 'ዋሽቻለሁ')
            || str_contains($text, 'ኪራዬን') || str_contains($text, 'ዝጉት') || str_contains($text, 'ደሞዜን') || str_contains($text, 'ተበደርኩትን')
            || str_contains($text, 'የትምህርት ክፍያ') || str_contains($text, 'እንዳላስገባ') || str_contains($text, 'እርዱኝ')
            || str_contains($text, 'ተስፋ ቆርጫለሁ') || str_contains($text, 'የባሰ') || str_contains($text, 'ለዘላለም');
        $index++;

        return $isRisk ? 0.92 : ($index % 13 === 0 ? 0.7 : 0.12);
    };

    $result = $harness->evaluate($scorer, 0.8);

    reportBudget('NFR-07 RG precision (scripted mechanism proof)', 0.9, ['p50' => $result['precision'], 'p99' => $result['precision'], 'max' => $result['precision']]);
    expect($result['precision'])->toBeGreaterThanOrEqual(0.9, sprintf('precision %.2f at rg_min 0.8 (fp=%d)', $result['precision'], $result['fp']))
        ->and($result['tp'])->toBeGreaterThanOrEqual(20)
        ->and($result['recall'])->toBeGreaterThan(0.0); // reported, not asserted high
});

it('the harness CAN fail: a miscalibrated threshold drops precision below the bar — the instrument is not decorative', function (): void {
    $harness = new RgEvalHarness;

    // Everything angry fires at a hair-trigger threshold: healthy complaints
    // flood in as false positives.
    $result = $harness->evaluate(fn (string $text): float => 0.5, 0.3);

    expect($result['precision'])->toBeLessThan(0.9)
        ->and($result['fp'])->toBeGreaterThan(0);
});

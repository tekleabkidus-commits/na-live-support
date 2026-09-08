<?php

declare(strict_types=1);

use App\Domain\Sms\Cost\CostEstimate;
use App\Domain\Sms\Cost\RateCard;
use App\Domain\Sms\Encoding\Segmenter;
use App\Domain\Sms\Templates\TemplatePublisher;

/*
| Doc 14 NFR-06 / AC-04 / FS-04 — "Segment/cost estimates in the editor match
| actual accrual within 1 segment for the fixture corpus". The editor estimates
| the BODY with sample values; the accrual meters the RENDERED text. Both go
| through the ONE Segmenter and the ONE CostEstimate, so the only variable is
| the substituted values' length — A-66's one-segment margin. This test drives
| the corpus (no variables → exact) and template bodies with variables against
| realistic rendered values (→ within one segment). STATED LIMIT: a body that
| repeats a free-length variable many times can exceed the margin by design —
| the estimate is a sample, not an oracle; the seeded templates use each
| variable once.
*/

/** @return list<array{id: string, text: string}> */
function smsParityCorpus(): array
{
    $raw = (string) file_get_contents(dirname(__DIR__, 3).'/fixtures/sms/encoding-corpus.json');
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    return $decoded['entries'];
}

test('the corpus: estimate == accrual exactly (no variables), through the one segmenter and the one cost arithmetic', function () {
    $segmenter = new Segmenter;
    $card = new RateCard('+251', '0.7500', 'ETB');

    foreach (smsParityCorpus() as $entry) {
        $estimate = $segmenter->segment(TemplatePublisher::withSamples($entry['text']))->segments;
        $accrual = $segmenter->segment($entry['text'])->segments;
        expect($estimate)->toBe($accrual, $entry['id'])
            ->and(CostEstimate::amount($estimate, $card))->toBe(CostEstimate::amount($accrual, $card));
    }
});

test('template bodies with variables: the sample-value estimate is within ONE segment of the accrual on realistic rendered values (NFR-06)', function () {
    $segmenter = new Segmenter;
    $rendered = [
        // body → a realistic rendered text
        ['{{brand.name}}: ticket {{ticket.number}} is resolved. Thank you, {{customer.name}}. Reply STOP to opt out.', 'NA BET: ticket #4812 is resolved. Thank you, Tesfaye Gebre. Reply STOP to opt out.'],
        ['{{brand.name}}፦ ትኬት {{ticket.number}} ተፈትቷል። እናመሰግናለን {{customer.name}}። ለማቆም ተወው ብለው ይላኩ።', 'ና ቤት፦ ትኬት #4812 ተፈትቷል። እናመሰግናለን ተስፋዬ ገብሬ። ለማቆም ተወው ብለው ይላኩ።'],
        ['{{brand.name}}: your code is {{code}}. It expires in 10 minutes. Never share it.', 'NA BET Support: your code is 913004. It expires in 10 minutes. Never share it.'],
        ['{{brand.name}}: we could not reach you on Telegram about ticket {{ticket.number}}. Please write to us there so we can continue. {{link}}', 'NA BET: we could not reach you on Telegram about ticket #4812. Please write to us there so we can continue. https://s.nabet.example/s/kb_article.0192a3b4-0000-7000-8000-00000000abcd.1a2b3c4d5e6f'],
    ];
    foreach ($rendered as [$body, $text]) {
        $estimate = $segmenter->segment(TemplatePublisher::withSamples($body))->segments;
        $accrual = $segmenter->segment($text)->segments;
        expect(abs($estimate - $accrual))->toBeLessThanOrEqual(1, $body);
    }
});

test('cost arithmetic is decimal-string exact (never a float) and the rate card refuses a malformed price or currency', function () {
    $card = new RateCard('+251', '0.7500', 'ETB');
    expect(CostEstimate::amount(3, $card))->toBe('2.2500')
        ->and(CostEstimate::amount(0, $card))->toBe('0.0000')
        ->and(CostEstimate::amount(7, new RateCard('+254', '1.1', 'KES')))->toBe('7.7000');

    expect(fn () => new RateCard('+251', '0,75', 'ETB'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new RateCard('+251', '0.75', 'birr'))->toThrow(InvalidArgumentException::class)
        ->and(RateCard::fromArray(['country_prefix' => '+251', 'price_per_segment' => 0.75, 'currency' => 'ETB'])->pricePerSegment)->toBe('0.7500');
});

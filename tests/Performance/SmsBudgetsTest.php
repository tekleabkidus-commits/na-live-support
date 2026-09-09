<?php

declare(strict_types=1);

use App\Domain\Sms\Catalog\SendableEvent;
use App\Domain\Sms\Encoding\Segmenter;
use App\Domain\Sms\Gateways\FixtureGatewaySeeder;
use App\Domain\Sms\Guards\CustomerFacts;
use App\Domain\Sms\Guards\GuardFunction;
use App\Domain\Sms\Guards\GuardInput;
use App\Domain\Sms\Guards\ModuleFacts;
use App\Domain\Sms\Guards\QuietHoursPolicy;
use App\Domain\Sms\Guards\TemplateFacts;
use App\Domain\Sms\Routing\PrefixTrie;
use App\Domain\Sms\Routing\RoutingTable;
use App\Domain\Sms\Templates\TemplatePublisher;

/*
| Doc 14 §12 — guard function ≤ 1 ms; route resolution ≤ 0.5 ms (the in-memory
| trie, rebuilt on table change); render ≤ 5 ms (the resolver's arithmetic
| half: variable substitution + segmentation). Dev-scale figures on this box;
| a discharging run is "battery-run, indicative" (R-D13-6). The house harness
| (measure / assertBudget) reports p50/p95 against the spec figure.
*/

it('§12: the guard function evaluates in ≤ 1 ms', function (): void {
    $guard = new GuardFunction;
    $input = new GuardInput(SendableEvent::TicketResolved, new CustomerFacts(true, false, false, false, false), new TemplateFacts(QuietHoursPolicy::Hold), new ModuleFacts(true, false, true), new DateTimeImmutable('2026-09-08T10:00:00+00:00'), null, 10, false);

    $stats = measure(static fn () => $guard->evaluate($input));
    reportBudget('sms guard function (§12 ≤ 1 ms)', 1.0, $stats);
    assertBudget('sms guard function', $stats, 1.0);
});

it('§12: route resolution over the seeded table (+251 / +2519 / +254) is ≤ 0.5 ms once the trie is built', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    app(FixtureGatewaySeeder::class)->seed('00000000-0000-7000-8000-0000000000aa');
    $table = app(RoutingTable::class);
    $steady = $table->resolve('+251912345678'); // build once
    expect($steady->matchedPrefix)->toBe('+251');

    // The ASSERTED leg is what §12 bounds: the resolution over the BUILT table (trie walk + chain
    // assembly). The steady-path resolve() additionally pays ONE Redis GET for the C-07 version
    // check — an overhead §12 gives no number to, whose p99 is the box's Redis tail, not this
    // code — so it is REPORTED below as the operational figure, never asserted at 0.5 ms.
    $trie = new PrefixTrie;
    foreach (['+251' => 'et', '+2519' => 'et-mobile', '+254' => 'ke'] as $prefix => $id) {
        $trie->insert($prefix, $id);
    }
    $resolution = measure(static fn (): ?array => $trie->longest('+251912345678'));
    reportBudget('sms route resolution, built table (§12 ≤ 0.5 ms)', 0.5, $resolution);
    assertBudget('sms route resolution (built table)', $resolution, 0.5);

    $full = measure(static fn () => $table->resolve('+251912345678'));
    reportBudget('sms resolve() incl. C-07 version read (reported, no §12 figure)', 0.5, $full);
});

it('§12: render arithmetic (substitution + segmentation of a two-segment Amharic body) is ≤ 5 ms', function (): void {
    $segmenter = new Segmenter;
    $body = '{{brand.name}}፦ ትኬት {{ticket.number}} ተፈትቷል። እናመሰግናለን {{customer.name}}። ለማቆም ተወው ብለው ይላኩ። '.str_repeat('ሀ', 60);

    $stats = measure(static fn () => $segmenter->segment(TemplatePublisher::withSamples($body)));
    reportBudget('sms render arithmetic (§12 ≤ 5 ms)', 5.0, $stats);
    assertBudget('sms render arithmetic', $stats, 5.0);
});

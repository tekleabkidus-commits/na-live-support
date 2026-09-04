<?php

declare(strict_types=1);

use App\Domain\Tickets\Metrics\MetricExclusion;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * P-N14 (Doc 06 `MetricExclusion`, countersigned 2026-09-04): N-14's SECOND
 * half — "test traffic never counts in any metric" — with the WIDGET as the
 * platform's test-traffic carrier (Doc 13 BR-07: `widgets.test_mode`). Both
 * query forms from ONE truth (the class's own dual-form discipline); the
 * positive controls: a live widget's ticket stays INCLUDED, a non-webchat
 * ticket is untouched. RED before the packet (a test-mode conversation
 * appears in the included set); green after.
 */
function webchatTicketUnderWidget(bool $testMode): array
{
    [$tenant, $widget] = widgetFixture();
    bindTenant($tenant);
    DB::table('widgets')->where('id', $widget->getKey())->update(['test_mode' => $testMode]);

    $customer = App\Models\Customer::query()->create([
        'display_name' => null, 'username' => '@fixture_widget_'.Str::random(6), 'is_shell' => true, 'created_source' => 'webchat',
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(),
        'channel' => 'webchat', 'channel_ref_id' => (string) $widget->getKey(), 'external_chat_id' => 'visitor-'.Str::random(8),
        'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);
    $ticketId = (string) Str::uuid7();
    DB::table('tickets')->insert([
        'id' => $ticketId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'customer_id' => $customer->identifier(), 'number' => random_int(1, 2_000_000_000),
        'status' => 'open', 'priority' => 'normal', 'source' => 'agent',
    ]);

    return [Ticket::query()->findOrFail($ticketId), $widget];
}

function includedByBothForms(Ticket $ticket): array
{
    $exclusion = app(MetricExclusion::class);
    $row = ! $exclusion->excluded($ticket);
    $sqlCount = (int) DB::selectOne(
        'SELECT count(*) AS n FROM tickets t WHERE t.id = ? AND '.$exclusion->includedSql('t.status', 't.conversation_id'),
        [$ticket->identifier()],
    )->n;

    return [$row, $sqlCount === 1];
}

it('N-14 second half (P-N14): a ticket whose conversation opened under a TEST-MODE widget is EXCLUDED by BOTH query forms', function (): void {
    [$ticket] = webchatTicketUnderWidget(testMode: true);

    [$rowIncluded, $sqlIncluded] = includedByBothForms($ticket);

    expect($rowIncluded)->toBeFalse('excluded() included a test-mode conversation — N-14\'s second half is missing')
        ->and($sqlIncluded)->toBeFalse('includedSql() included a test-mode conversation');
});

it('positive control: a LIVE widget\'s ticket stays INCLUDED by both forms, and a non-webchat ticket is untouched', function (): void {
    [$live] = webchatTicketUnderWidget(testMode: false);
    [$rowIncluded, $sqlIncluded] = includedByBothForms($live);
    expect($rowIncluded)->toBeTrue()->and($sqlIncluded)->toBeTrue();

    [$tenant, , $other] = ticketFixture('open');
    bindTenant($tenant);
    [$rowIncluded, $sqlIncluded] = includedByBothForms($other);
    expect($rowIncluded)->toBeTrue('a fakechan ticket must be untouched by the widget predicate')
        ->and($sqlIncluded)->toBeTrue();

    // Spam stays excluded — the first half of N-14 is untouched by the second.
    [$t2, , $spam] = ticketFixture('spam');
    bindTenant($t2);
    [$rowIncluded, $sqlIncluded] = includedByBothForms($spam);
    expect($rowIncluded)->toBeFalse()->and($sqlIncluded)->toBeFalse();
});

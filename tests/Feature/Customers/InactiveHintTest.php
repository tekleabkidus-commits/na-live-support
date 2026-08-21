<?php

declare(strict_types=1);

use App\Domain\Customers\Panel\InactiveHint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Doc 08 EC-03's heuristic chip (the S09 side-row, R-D09-3, countersigned
 * packet): the informational inactive hint fires for a deleted-account
 * customer — a run of hard delivery failures — and ONLY there. Every
 * negative control is a way the heuristic must stay quiet. The chip's DOM
 * half rides the S09 step-12 browser run.
 */
function outboundRow(string $tenantId, App\Models\Conversation $conversation, string $state, ?string $reason = null): void
{
    DB::table('messages')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'conversation_id' => $conversation->identifier(),
        'direction' => 'out', 'author_type' => 'agent', 'kind' => 'text', 'body' => 'hello', 'delivery_state' => $state,
        'delivery_error' => $reason === null ? null : json_encode(['reason' => $reason]),
    ]);
}

function hintScene(): array
{
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S09 Hint Viewer');
    $conversation = anotherConversation($tenantId, 'hint');

    return [$tenantId, $viewer, $conversation];
}

it('fires for a deleted-account customer: the last three sends on the channel failed_final as blocked_by_user → header.inactive_hint {channel, hard_failures, since}', function (): void {
    [$tenantId, $viewer, $conversation] = hintScene();
    outboundRow($tenantId, $conversation, 'sent');
    foreach (range(1, 3) as $i) {
        outboundRow($tenantId, $conversation, 'failed_final', 'blocked_by_user');
    }

    $hint = app(InactiveHint::class)->for((string) $conversation->customer_id);
    expect($hint)->not->toBeNull()->and($hint['channel'])->toBe('fakechan')->and($hint['hard_failures'])->toBe(3)->and($hint['since'])->toBeString();

    $panel = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$conversation->customer_id}/panel");
    $panel->assertOk();
    expect($panel->json('header.inactive_hint.channel'))->toBe('fakechan')->and($panel->json('header.inactive_hint.hard_failures'))->toBe(3);
});

it('ONLY there: two failures → null; three failures then one sent → null; three failed_final with a transient reason → null; a mixed run → null; another customer of the tenant → null; a fresh customer → null', function (): void {
    [$tenantId, $viewer, $conversation] = hintScene();
    $hint = app(InactiveHint::class);
    $customerId = (string) $conversation->customer_id;

    outboundRow($tenantId, $conversation, 'failed_final', 'blocked_by_user');
    outboundRow($tenantId, $conversation, 'failed_final', 'blocked_by_user');
    expect($hint->for($customerId))->toBeNull('two failures are not a run');

    outboundRow($tenantId, $conversation, 'failed_final', 'blocked_by_user');
    expect($hint->for($customerId))->not->toBeNull();
    outboundRow($tenantId, $conversation, 'sent');
    expect($hint->for($customerId))->toBeNull('a later success ends the run');

    $transient = anotherConversation($tenantId, 'transient');
    foreach (range(1, 3) as $i) {
        outboundRow($tenantId, $transient, 'failed_final', 'network_error');
    }
    expect($hint->for((string) $transient->customer_id))->toBeNull('a transient reason is not a deleted account');

    $mixed = anotherConversation($tenantId, 'mixed');
    outboundRow($tenantId, $mixed, 'failed_final', 'blocked_by_user');
    outboundRow($tenantId, $mixed, 'failed_final', 'window_expired');
    outboundRow($tenantId, $mixed, 'failed_final', 'blocked_by_user');
    expect($hint->for((string) $mixed->customer_id))->toBeNull('a mixed run is not a run');

    $deleted = anotherConversation($tenantId, 'deleted');
    foreach (range(1, 3) as $i) {
        outboundRow($tenantId, $deleted, 'failed_final', 'blocked_by_user');
    }
    $fresh = anotherConversation($tenantId, 'fresh');
    expect($hint->for((string) $deleted->customer_id))->not->toBeNull()
        ->and($hint->for((string) $fresh->customer_id))->toBeNull('another customer\'s failures never leak');
    $panel = test()->actingAs($viewer, 'staff')->getJson("https://localhost/api/staff/customers/{$fresh->customer_id}/panel");
    expect($panel->json('header.inactive_hint'))->toBeNull();
});

it('the chip\'s strings exist in both launch languages (C-08)', function (): void {
    foreach (['en', 'am'] as $lang) {
        expect(__('customers.panel.inactive_hint', [], $lang))->not->toBe('customers.panel.inactive_hint')
            ->and(__('customers.panel.inactive_hint_title', ['n' => 3, 'channel' => 'telegram'], $lang))->toContain('3');
    }
});

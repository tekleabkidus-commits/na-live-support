<?php

declare(strict_types=1);

use App\Domain\Inbox\ReadModels\ListReadModel;
use App\Domain\Inbox\ReadModels\ViewZsets;
use App\Domain\Inbox\Realtime\ViewHash;
use App\Domain\Inbox\Views\SavedViews;
use App\Domain\Inbox\Views\ViewVariants;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| EC-04 / A-30 (S07 step 4): per-viewer counts via lazy per-scope ZSET
| variants — two scopes → two counts, ZERO out-of-scope rows, NO indication
| rows are hidden (BR-03). A-30's four countersign conditions, executed.
|--------------------------------------------------------------------------
*/

it('EC-04: a shared saved view shows each viewer THEIR count — the variant materializes LAZILY, the projector patches it, eviction self-heals (A-30 conditions 1–4)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $owner = inboxScopedActor('All', 'S07 EC04 Owner');
    $narrow = inboxScopedActor('Own', 'S07 EC04 Narrow');

    $apply = function (App\Models\Ticket $t, App\Models\Conversation $c) use ($tenantId): void {
        runListProjector(inboxSeedEvent('ticket.created', [
            'tenant_id' => $tenantId, 'ticket_id' => $t->identifier(),
            'conversation_id' => $c->identifier(), 'customer_id' => $t->customer_id,
            'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
        ]));
    };
    $apply($ticket, $conversation);

    // Three open tickets: one assigned to NARROW, two to nobody/others.
    $cMine = anotherConversation($tenantId, 'ec04-mine');
    [$tMine] = inboxTicketRow($tenantId, $cMine, 'open', ['assignee_id' => $narrow->identifier()]);
    $apply($tMine, $cMine);
    $cOther = anotherConversation($tenantId, 'ec04-other');
    [$tOther] = inboxTicketRow($tenantId, $cOther, 'open', ['assignee_id' => $owner->identifier()]);
    $apply($tOther, $cOther);

    $savedViews = app(SavedViews::class);
    $viewId = $savedViews->create($owner, 'All open', ['status' => ['new', 'open']]);
    $savedViews->share($owner, $viewId, 'team', '00000000-0000-0000-0000-000000000001');

    $zsets = app(ViewZsets::class);
    $hashes = app(ViewHash::class);
    $variants = app(ViewVariants::class);

    // The OWNER (All scope) reads the base set: 3 members.
    expect($hashes->savedZsetKeyForViewer($viewId, $owner))->toBe('saved:'.$viewId)
        ->and($zsets->count('saved:'.$viewId))->toBe(3);

    // CONDITION 1 — LAZY: the narrow viewer's variant does not exist until read.
    $narrowKey = $hashes->savedZsetKeyForViewer($viewId, $narrow);
    expect($narrowKey)->not->toBe('saved:'.$viewId);
    expect((int) Illuminate\Support\Facades\Redis::connection('volatile')->exists($zsets->key($narrowKey)))->toBe(0);

    /** @var array<string, mixed> $filters */
    $filters = json_decode((string) DB::table('inbox_saved_views')->where('id', $viewId)->value('filters'), true, 16, JSON_THROW_ON_ERROR);
    $ensuredKey = $variants->ensure('saved:'.$viewId, $hashes->scopeSignature($narrow), $filters);
    expect($ensuredKey)->toBe($narrowKey);

    // TWO COUNTS: per-viewer ZCARDs — 3 for All, exactly 1 for Own.
    expect($zsets->count($narrowKey))->toBe(1, 'the narrow viewer\'s count is not their subset — EC-04 broke')
        ->and($zsets->allIds($narrowKey))->toBe([$tMine->identifier()]);

    // ZERO out-of-scope rows + BR-03 (no hidden-row artifacts): the PAGE is
    // exactly the subset, nothing else, no placeholders.
    $page = app(ListReadModel::class)->page($narrowKey, 'saved', $narrow);
    expect(array_column($page, 'ticket_id'))->toBe([$tMine->identifier()]);

    // The PROJECTOR patches the materialized variant: a new ticket assigned
    // to narrow joins BOTH sets on its event alone.
    $cNew = anotherConversation($tenantId, 'ec04-new');
    [$tNew] = inboxTicketRow($tenantId, $cNew, 'open', ['assignee_id' => $narrow->identifier()]);
    $apply($tNew, $cNew);
    expect($zsets->count('saved:'.$viewId))->toBe(4)
        ->and($zsets->count($narrowKey))->toBe(2);

    // CONDITIONS 2+3 — C-07 eviction self-heals through the SAME ensure path.
    $zsets->flush($narrowKey);
    $variants->ensure('saved:'.$viewId, $hashes->scopeSignature($narrow), $filters);
    expect($zsets->count($narrowKey))->toBe(2, 'an evicted variant did not rebuild to truth — A-30 condition 3');
});

it('EC-04 on a SYSTEM view: the base unassigned set and an Own-scope variant disagree exactly as scope says (empty, per the pinned literal composition)', function (): void {
    [$tenant, $conversation, $ticket] = ticketFixture('new');
    $tenantId = $tenant->identifier();
    $narrow = inboxScopedActor('Own', 'S07 EC04 SysNarrow');

    runListProjector(inboxSeedEvent('ticket.created', [
        'tenant_id' => $tenantId, 'ticket_id' => $ticket->identifier(),
        'conversation_id' => $conversation->identifier(), 'customer_id' => $ticket->customer_id,
        'source' => 'agent', 'actor_type' => 'agent', 'actor_id' => null,
    ]));

    $zsets = app(ViewZsets::class);
    $hashes = app(ViewHash::class);
    expect($zsets->count('unassigned'))->toBe(1);

    $variantKey = app(ViewVariants::class)->ensure('unassigned', $hashes->scopeSignature($narrow));
    expect($zsets->count($variantKey))->toBe(0, 'an Own-scope unassigned variant must be EMPTY — the pinned Doc 03 literal composition (step-2 disposition)');
});

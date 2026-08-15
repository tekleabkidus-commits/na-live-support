<?php

declare(strict_types=1);

use App\Domain\Customers\Fields\CustomerFieldValues;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 08 SEC-05 (S08 step 9b) — the duplicate-player_id hint reveals COUNTS
| + SCOPE-CHECKED LINKS, never a name across the viewer's scope boundary.
| US-07 is why it exists (tip the payments teammate); it is never an
| enforcement. Sabotage #11 makes it return cross-scope ids and this suite
| goes red.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    legacyIdentityShapeEnforce();
});

/** Three customers, two sharing a player_id; the shared OTHER is out of Own scope. */
function duplicateHintScene(string $tenantId, App\Models\User $owner): array
{
    $mine = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');
    DB::table('customers')->where('id', $mine)->update(['display_name' => 'Hint Mine']);
    inboxTicketRow($tenantId, anotherConversation($tenantId, 'hint-mine', $mine), 'open', ['assignee_id' => $owner->identifier()]);

    $other = enforcedCustomer($tenantId, '2026-06-01 10:05:00+00');
    DB::table('customers')->where('id', $other)->update(['display_name' => 'Hint Other Sensitive Name']);
    inboxTicketRow($tenantId, anotherConversation($tenantId, 'hint-other', $other), 'open');

    $unrelated = enforcedCustomer($tenantId, '2026-06-01 10:10:00+00');

    $writer = app(CustomerFieldValues::class);
    $actor = (string) Str::uuid7();
    $writer->set($mine, ['player_id' => 'NB-SHARED-9'], $actor);
    $writer->set($other, ['player_id' => 'NB-SHARED-9'], $actor);
    $writer->set($unrelated, ['player_id' => 'NB-LONER-1'], $actor);

    return [$mine, $other, $unrelated];
}

it('SEC-05: the hint is COUNT + scope-checked links — All-scope gets the link, Own-scope gets the honest count with NO link, and a NAME appears nowhere in the payload', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $own = customersScopedActor('Own', 'S08 Hint Own');
    $all = customersScopedActor('All', 'S08 Hint All');

    [$mine, $other] = duplicateHintScene($tenantId, $own);
    $fields = app(CustomerFieldValues::class);

    // All-scope: the count and the link.
    $wide = $fields->duplicateHint($mine, $all);
    expect($wide)->not->toBeNull()
        ->and($wide['count'])->toBe(1)
        ->and($wide['links'])->toBe([$other]);

    // Own-scope: the OTHER profile sits outside the viewer's scope — the
    // COUNT stays honest (US-07's tip works), the LINK is withheld (SEC-05's
    // boundary), and no name rides either way.
    $narrow = $fields->duplicateHint($mine, $own);
    expect($narrow)->not->toBeNull()
        ->and($narrow['count'])->toBe(1)
        ->and($narrow['links'])->toBe([], 'a cross-scope link escaped — SEC-05\'s boundary is gone (sabotage #11\'s subject)');

    // The payload never carries a name, structurally: exactly {count, links}.
    expect(array_keys($wide))->toBe(['count', 'links'])
        ->and(json_encode($narrow))->not->toContain('Sensitive');
});

it('SEC-05 through the DOOR: the card\'s hint payload is {count, links} only — display names of other profiles never serialize', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $own = customersScopedActor('Own', 'S08 Hint Door Own');

    [$mine] = duplicateHintScene($tenantId, $own);

    $card = test()->actingAs($own, 'staff')->getJson("https://localhost/api/staff/customers/{$mine}/panel/betting-fields");
    $card->assertOk();

    expect($card->json('duplicate_hint.count'))->toBe(1)
        ->and($card->json('duplicate_hint.links'))->toBe([])
        ->and($card->getContent())->not->toContain('Sensitive');
});

it('no player_id on the customer → no hint at all (null, not a zero-count chip)', function (): void {
    $tenant = createTenant();
    $tenantId = $tenant->identifier();
    activateTenant($tenant);
    $viewer = customersScopedActor('All', 'S08 Hint None');
    $bare = enforcedCustomer($tenantId, '2026-06-01 10:00:00+00');

    expect(app(CustomerFieldValues::class)->duplicateHint($bare, $viewer))->toBeNull();
});

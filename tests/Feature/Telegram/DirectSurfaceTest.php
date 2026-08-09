<?php

declare(strict_types=1);

use App\Domain\Telegram\Direct\CommandHandler;
use App\Domain\Telegram\Direct\MenuDesigner;
use App\Domain\Telegram\Direct\TemplateResolver;
use App\Domain\Telegram\ModePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-05/06/25/27/28 — the direct surface + mode handling
|--------------------------------------------------------------------------
*/

/** @return array{tenant: string, bot: string, conversation: string, customer: string} */
function directFixture(): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $bot = (string) Str::uuid7();
    DB::table('telegram_bots')->insert([
        'id' => $bot, 'tenant_id' => $tenant->identifier(), 'telegram_bot_id' => random_int(1_000_000_000, 9_999_999_999),
        'username' => 'directbot', 'display_name' => 'Direct Bot', 'mode' => 'direct',
    ]);
    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Direct Player', 'username' => '@fixture_direct_'.Str::random(6), 'is_shell' => true,
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => $bot, 'external_chat_id' => 'chat-d', 'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);

    return ['tenant' => $tenant->identifier(), 'bot' => $bot, 'conversation' => $conversationId, 'customer' => (string) $customer->identifier()];
}

function seedTemplate(string $bot, string $lang, string $key, string $body): void
{
    DB::table('telegram_templates')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => app(App\Support\Tenancy\TenantContext::class)->tenantId(),
        'bot_id' => $bot, 'language' => $lang, 'template_key' => $key, 'body' => $body,
    ]);
}

it('FR-25 /status: reads REAL Doc 06 ticket data through the tickets table — open and recent', function (): void {
    $f = directFixture();
    // Real tickets for this customer — each on its OWN conversation (the
    // one-open-family index allows one open ticket per conversation).
    foreach ([['open', 101], ['pending_customer', 102], ['resolved', 103], ['closed', 104]] as [$status, $num]) {
        $conv = (string) Str::uuid7();
        DB::table('conversations')->insert([
            'id' => $conv, 'tenant_id' => $f['tenant'], 'channel' => 'telegram', 'channel_ref_id' => $f['bot'],
            'external_chat_id' => 'chat-'.$num, 'customer_id' => $f['customer'], 'state' => 'active',
        ]);
        DB::table('tickets')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $f['tenant'], 'conversation_id' => $conv,
            'customer_id' => $f['customer'], 'number' => $num, 'status' => $status, 'priority' => 'normal', 'source' => 'agent',
        ]);
    }

    $status = app(CommandHandler::class)->status($f['conversation']);

    $openNumbers = array_column($status['open'], 'number');
    $recentNumbers = array_column($status['recent'], 'number');
    expect($openNumbers)->toContain(101)->toContain(102)->not->toContain(103);
    expect($recentNumbers)->toContain(103)->toContain(104)->not->toContain(101);
});

it('FR-25 /human: emits telegram.handoff_requested (the Doc 09/06 seam)', function (): void {
    $f = directFixture();
    app(CommandHandler::class)->human($f['conversation'], 'chat-d');

    $row = DB::table('outbox')->where('name', 'telegram.handoff_requested')->first(['payload']);
    expect($row)->not->toBeNull();
    expect(json_decode((string) $row->payload, true)['conversation_id'])->toBe($f['conversation']);
});

it('FR-25/§14 /language completeness gate: a COMPLETE language is listed, an INCOMPLETE one is ABSENT (both ways)', function (): void {
    $f = directFixture();
    // en: all six required keys → complete.
    foreach (TemplateResolver::REQUIRED_KEYS as $key) {
        seedTemplate($f['bot'], 'en', $key, 'en '.$key);
    }
    // am: only one key → incomplete.
    seedTemplate($f['bot'], 'am', 'greeting', 'am greeting');

    $languages = app(CommandHandler::class)->availableLanguages($f['bot']);

    // §14: a complete set is offered, an incomplete one is not.
    expect($languages)->toContain('en');
    expect($languages)->not->toContain('am');
});

it('FR-28: a template renders variables; an UNBUILT source ({{queue_wait}}) resolves through a STATED seam fixture, never blank', function (): void {
    $f = directFixture();
    seedTemplate($f['bot'], 'en', 'handoff', 'Hi {{first_name}}, a human joins in {{queue_wait}}.');

    $rendered = app(TemplateResolver::class)->render($f['bot'], 'en', 'handoff', ['first_name' => 'Sam']);

    expect($rendered)->toBe('Hi Sam, a human joins in a few minutes.')
        ->and($rendered)->not->toContain('{{queue_wait}}', 'the unbuilt source resolves via the seam fixture, never a silent blank');
});

it('FR-05 business mode: a direct message gets the redirect reply, which MUST carry a tappable link (BR-06)', function (): void {
    $f = directFixture();
    DB::table('telegram_bots')->where('id', $f['bot'])->update(['mode' => 'business']);
    seedTemplate($f['bot'], 'en', 'redirect', 'Please contact our support account @BrandSupport for help.');

    expect(app(ModePolicy::class)->classifyInbound('business', 'direct'))->toBe('redirect');
    $reply = app(ModePolicy::class)->redirectReply($f['bot'], 'en');
    expect($reply)->toContain('@BrandSupport');
});

it('BR-06 (sabotage #14): a redirect template WITHOUT a tappable link is REFUSED on validation — dead ends prohibited', function (): void {
    $policy = app(ModePolicy::class);

    // With a link: allowed.
    $policy->assertRedirectHasLink('Contact @BrandSupport'); // no throw

    // Without any tappable link: refused.
    expect(fn () => $policy->assertRedirectHasLink('Please go away, we cannot help here.'))
        ->toThrow(DomainException::class, 'tappable account link');
});

it('FR-05 direct mode: a business update is flagged unconfigured_source (misconfiguration signal), not routed as normal', function (): void {
    expect(app(ModePolicy::class)->classifyInbound('direct', 'business'))->toBe('unconfigured_source');
    expect(app(ModePolicy::class)->classifyInbound('hybrid', 'business'))->toBe('normal');
    expect(app(ModePolicy::class)->classifyInbound('hybrid', 'direct'))->toBe('normal');
});

it('FR-06: leaving business mode with active connections needs typed confirmation and does NOT disconnect them', function (): void {
    $f = directFixture();
    DB::table('telegram_bots')->where('id', $f['bot'])->update(['mode' => 'business']);
    DB::table('telegram_business_connections')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $f['tenant'], 'bot_id' => $f['bot'],
        'connection_id' => 'conn-m', 'account_user_id' => 555, 'account_name' => 'Acct', 'user_chat_id' => 555,
        'state' => 'active', 'can_reply' => true,
    ]);

    expect(fn () => app(ModePolicy::class)->changeMode($f['bot'], 'direct', typedConfirmation: false))
        ->toThrow(DomainException::class, 'typed confirmation');

    app(ModePolicy::class)->changeMode($f['bot'], 'direct', typedConfirmation: true);
    expect(DB::table('telegram_bots')->where('id', $f['bot'])->value('mode'))->toBe('direct');
    // The connection is UNTOUCHED (handling changed, not topology).
    expect(DB::table('telegram_business_connections')->where('connection_id', 'conn-m')->value('state'))->toBe('active');
});

it('FR-27/T-06/NFR-05: publish is versioned with instant rollback, and cache invalidation propagates in ≤10 s (MEASURED)', function (): void {
    $f = directFixture();
    $menuId = (string) Str::uuid7();
    DB::table('telegram_menus')->insert([
        'id' => $menuId, 'tenant_id' => $f['tenant'], 'bot_id' => $f['bot'], 'language' => 'en',
    ]);
    $designer = app(MenuDesigner::class);
    $admin = (string) App\Models\User::factory()->create()->getKey();

    $v1 = $designer->publish($menuId, $admin, [['row' => 1, 'buttons' => [['label' => 'Help', 'target_key' => 'help']]]]);
    expect($v1)->toBe(1);
    // Warm the cache (a customer interaction reads it).
    $designer->activeDefinition($menuId);
    expect($designer->isCacheWarm($menuId))->toBeTrue();

    // Publish v2 → cache invalidated → next read reflects v2 within the window.
    $start = hrtime(true);
    $v2 = $designer->publish($menuId, $admin, [['row' => 1, 'buttons' => [['label' => 'Deposit', 'target_key' => 'deposit']]]]);
    expect($designer->isCacheWarm($menuId))->toBeFalse('publish invalidated the cache immediately');
    $def = $designer->activeDefinition($menuId);
    $propagationMs = (hrtime(true) - $start) / 1_000_000;
    expect($v2)->toBe(2);
    expect($def[0]['buttons'][0]['label'])->toBe('Deposit', 'the next interaction sees the new version');
    expect($propagationMs)->toBeLessThan(10_000.0);
    fwrite(STDERR, sprintf("\n[dev-hardware-indicative] NFR-05 menu publish propagation: %.2f ms (budget 10000 ms) WITHIN\n", $propagationMs));

    // Instant rollback to v1.
    $designer->rollback($menuId, 1);
    expect($designer->activeDefinition($menuId)[0]['buttons'][0]['label'])->toBe('Help');
});

it('FR-27: the menu limits (≤12 buttons, rows ≤3) are enforced', function (): void {
    $f = directFixture();
    $menuId = (string) Str::uuid7();
    DB::table('telegram_menus')->insert(['id' => $menuId, 'tenant_id' => $f['tenant'], 'bot_id' => $f['bot'], 'language' => 'en']);
    $designer = app(MenuDesigner::class);
    $admin = (string) App\Models\User::factory()->create()->getKey();

    expect(fn () => $designer->publish($menuId, $admin, [['row' => 1, 'buttons' => array_fill(0, 4, ['label' => 'x', 'target_key' => 'x'])]]))
        ->toThrow(DomainException::class, 'row');
});

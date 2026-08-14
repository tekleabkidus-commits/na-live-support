<?php

declare(strict_types=1);

use App\Domain\Telegram\Health\RawUpdateLog;
use App\Domain\Telegram\Health\UpdateMasker;
use App\Http\Controllers\Telegram\HealthAdminController;
use App\Http\Controllers\Telegram\TelegramScreensController;
use App\Models\TelegramBot;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Doc 05 SEC-01 / SEC-03 / EC-12 / FR-30 — the security file the plan-time
| canonical command named (AC-09's remaining halves, S23 step 11)
|--------------------------------------------------------------------------
|
| - SEC-01: the UI NEVER carries a stored token — asserted at the Inertia
|   props layer (the only data the pages render from), plus the $hidden pin.
| - SEC-03/EC-12: the raw-update viewer masks phones, contact payloads and
|   forwarded-origin fields for EVERYONE — there is no unmask parameter, by
|   reflection. Positive controls prove the masking bites; a visible field
|   proves it doesn't over-mask.
| - FR-30: simulate-window-expiry works on staging and REFUSES in
|   production, with no bypass parameter — proven both ways.
| - The permission DENY path (telegram.health.view) is proven here; the
|   ALLOW path through the full web middleware is the browser suite's
|   (real login), the step-10 precedent.
|
| Earlier AC-09 halves already EXECUTED elsewhere: webhook secret/replay
| (WebhookRouteTest, step 4), unconfirmed-connection routing block
| (ConnectionLifecycleTest SEC-04, step 6), callback replay rejection
| (InboundPipelineTest SEC-06, step 5), scrubber patterns
| (TelegramScrubberPatternsTest, step 1).
*/

const FIXTURE_TOKEN = '8144038600:FAKECREDsecurityfixturesecurityfix00';

/** @return array{bot: TelegramBot, tenant: string} */
function securityBot(): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $bot = TelegramBot::query()->create([
        'telegram_bot_id' => random_int(1_000_000, 9_999_999),
        'username' => 'fixture_sec_'.Str::random(6),
        'display_name' => 'Security Fixture',
        'mode' => 'business',
        'bot_token' => FIXTURE_TOKEN,
    ]);

    return ['bot' => $bot, 'tenant' => $tenant->identifier()];
}

/** Render an Inertia page response to its full HTML (the data-page props JSON rides inside). */
function inertiaJson(Inertia\Response $response): string
{
    return (string) $response->toResponse(Request::create('/admin/telegram', 'GET'))->getContent();
}

it('SEC-01: no screen props ever carry the stored token — bots, health; only a boolean has_token exists', function (): void {
    $f = securityBot();
    $controller = app(TelegramScreensController::class);

    $bots = inertiaJson($controller->bots(Request::create('/admin/telegram/bots')));
    $health = inertiaJson($controller->health(Request::create('/admin/telegram/health'), $f['bot']->id));

    foreach (['bots' => $bots, 'health' => $health] as $screen => $json) {
        expect(str_contains($json, FIXTURE_TOKEN))->toBeFalse("SEC-01: the {$screen} props contain the raw token");
        expect(str_contains($json, 'FAKECREDsecurity'))->toBeFalse("SEC-01: the {$screen} props contain a token fragment");
    }
    expect($health)->toContain('has_token'); // the UI learns presence (a boolean), never the value
});

it('SEC-01: the model itself hides the token from serialization (the $hidden pin) while the encrypted cast round-trips', function (): void {
    $f = securityBot();

    expect($f['bot']->toArray())->not->toHaveKey('bot_token');
    expect(json_encode($f['bot']))->not->toContain(FIXTURE_TOKEN);
    // The DB holds ciphertext, not the token (A-19 posture).
    $raw = DB::table('telegram_bots')->where('id', $f['bot']->id)->value('bot_token');
    expect($raw)->not->toBeNull()->and(str_contains((string) $raw, FIXTURE_TOKEN))->toBeFalse();
});

it('SEC-03 POSITIVE CONTROLS: phones, contact payloads and forwarded-origin fields mask; ordinary fields survive', function (): void {
    $masker = new UpdateMasker;

    $masked = $masker->mask([
        'update_id' => 77,
        'business_message' => [
            'from' => ['id' => 1, 'first_name' => 'Abebe', 'phone_number' => '+251900001111'],
            'contact' => ['phone_number' => '+251900002222', 'first_name' => 'Contact Name', 'vcard' => 'BEGIN:VCARD'],
            'forward_from' => ['id' => 9, 'first_name' => 'Original Author'],
            'forward_sender_name' => 'Hidden Name',
            'text' => 'my number is +251 90 000 3333 please call',
            'caption' => 'a plain caption stays visible',
        ],
    ]);

    $json = json_encode($masked, JSON_THROW_ON_ERROR);

    // Masked: the values are GONE…
    expect($json)->not->toContain('+251900001111')
        ->and($json)->not->toContain('+251900002222')
        ->and($json)->not->toContain('Original Author')
        ->and($json)->not->toContain('Hidden Name')
        ->and($json)->not->toContain('000 3333'); // phone-shaped free-text value
    // …but the FIELDS stay visible so the payload keeps its shape (F07).
    expect($masked['business_message'])->toHaveKeys(['from', 'contact', 'forward_from', 'forward_sender_name']);
    expect($masked['business_message']['from']['phone_number'])->toBe(UpdateMasker::MASKED);
    // Over-masking control: ordinary content is untouched.
    expect($masked['business_message']['caption'])->toBe('a plain caption stays visible')
        ->and($masked['business_message']['from']['first_name'])->toBe('Abebe')
        ->and($masked['update_id'])->toBe(77);
});

it('SEC-03/EC-12: RawUpdateLog stores raw but READS masked — and no unmask path exists on the class, by reflection', function (): void {
    securityBot();
    $log = app(RawUpdateLog::class);
    $botId = (string) Str::uuid7();

    $log->push($botId, [
        'update_id' => 900500,
        'message' => [
            'contact' => ['phone_number' => '+251900004444'],
            'forward_origin' => ['sender_user' => ['first_name' => 'Fwd Author']],
            'text' => 'visible text',
        ],
    ]);

    $read = $log->read($botId);
    $json = json_encode($read, JSON_THROW_ON_ERROR);
    expect($read)->toHaveCount(1)
        ->and($json)->not->toContain('+251900004444')
        ->and($json)->not->toContain('Fwd Author')
        ->and($json)->toContain('visible text');

    // No unmasked read door: read() takes exactly one parameter (the bot id),
    // and no other public method returns entries.
    $reflection = new ReflectionClass(RawUpdateLog::class);
    $read = $reflection->getMethod('read');
    expect($read->getNumberOfParameters())->toBe(1, 'SEC-03: an unmask flag on read() would be a bypass');
    $public = array_map(fn (ReflectionMethod $m): string => $m->getName(), $reflection->getMethods(ReflectionMethod::IS_PUBLIC));
    expect(array_diff($public, ['__construct', 'push', 'read']))->toBeEmpty('SEC-03: no other read door may exist');
});

it('FR-30 BOTH WAYS: simulate-window-expiry expires a real window outside production — and REFUSES in production with a stable code, no bypass', function (): void {
    $f = securityBot();

    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Sim Player', 'username' => '@fixture_sim_'.Str::random(6), 'is_shell' => true,
        'created_source' => 'staff',
    ]);
    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $f['tenant'], 'channel' => 'telegram',
        'channel_ref_id' => $f['bot']->id, 'external_chat_id' => 'chat-sim',
        'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);
    DB::statement("UPDATE conversations SET reply_window_expires_at = clock_timestamp() + interval '1 hour', reply_window_state = 'open' WHERE id = ?", [$conversationId]);

    $controller = app(HealthAdminController::class);
    $audit = app(App\Support\Audit\AuditLogger::class);

    // STAGING WAY: the tool works, through the REAL sweep door.
    $ok = $controller->simulateExpiry(
        Request::create('/t', 'POST', ['conversation_id' => $conversationId]),
        $audit,
    );
    expect($ok->getStatusCode())->toBe(200);
    expect(DB::table('conversations')->where('id', $conversationId)->value('reply_window_state'))->toBe('expired');
    expect(DB::table('outbox')->where('name', 'telegram.window.expired')->count())->toBe(1);
    expect(DB::table('audit_log')->where('action_key', 'telegram.window_expiry_simulated')->count())->toBe(1);

    // PRODUCTION WAY: the same call REFUSES — stable code, nothing mutated.
    $originalEnv = app('env');
    app()['env'] = 'production';
    try {
        $refused = $controller->simulateExpiry(
            Request::create('/t', 'POST', ['conversation_id' => $conversationId]),
            $audit,
        );
    } finally {
        app()['env'] = $originalEnv;
    }
    expect($refused->getStatusCode())->toBe(403);
    /** @var array<string, mixed> $body */
    $body = json_decode((string) $refused->getContent(), true);
    expect($body['code'])->toBe('telegram.simulate_expiry_production_refused');
    // No second audit row and no second event: the refusal executes nothing.
    expect(DB::table('audit_log')->where('action_key', 'telegram.window_expiry_simulated')->count())->toBe(1);
    expect(DB::table('outbox')->where('name', 'telegram.window.expired')->count())->toBe(1);
});

it('SEC-03 permission DENY: without telegram.health.view the health screen and the raw viewer are refused', function (): void {
    $f = securityBot();

    /** @var User $user */
    $user = User::factory()->create();
    grantPermissions($user, ['telegram.bots.view'], 'Fixture No-Health Role');

    $this->actingAs($user, 'staff')
        ->get('/admin/telegram/health/'.$f['bot']->id)
        ->assertForbidden();

    $this->actingAs($user, 'staff')
        ->get('/admin/telegram/bots/'.$f['bot']->id.'/raw-updates')
        ->assertForbidden();
});

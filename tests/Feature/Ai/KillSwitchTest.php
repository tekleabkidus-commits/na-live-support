<?php

declare(strict_types=1);

use App\Domain\Ai\Config\ConfigVersions;
use App\Domain\Ai\KillSwitch\KillSwitches;
use App\Domain\Ai\KillSwitch\SwitchesAndFlags;
use App\Domain\Ai\Pipeline\ChatbotEnablement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-18/US-07/AC-08/SEC-05: kill switches as EXACT blast radii — proven in
 * BOTH directions (off where told, on everywhere else), instant (direct
 * reads), high-visibility audited + evented, flipped_at a byte-copy of the
 * audit stamp (sabotage #16's control). Sabotage #9 leaks a neighbor.
 */
function switches(): KillSwitches
{
    return app(KillSwitches::class);
}

it('AC-08 BOTH DIRECTIONS: an endpoint switch silences EXACTLY its endpoint — the sibling bot, the other channel and the other surfaces stay ON', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();

    switches()->flip('chatbot', 'telegram', 'bot-a', true, '[FIXTURE] incident', $actor);

    expect(switches()->isOff('chatbot', 'telegram', 'bot-a'))->toBeTrue('the target stayed ON')
        ->and(switches()->isOff('chatbot', 'telegram', 'bot-b'))->toBeFalse('the sibling bot was silenced — blast radius leaked')
        ->and(switches()->isOff('chatbot', 'web', 'widget-1'))->toBeFalse('another channel was silenced')
        ->and(switches()->isOff('copilot', 'telegram', 'bot-a'))->toBeFalse('another surface was silenced')
        ->and(switches()->isOff('supervisor', null, null))->toBeFalse();
});

it('AC-08: a channel-wide switch covers every endpoint on that channel and nothing on another; the all-switch covers everything; flipping back restores exactly', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();

    switches()->flip('chatbot', 'telegram', null, true, null, $actor);
    expect(switches()->isOff('chatbot', 'telegram', 'bot-a'))->toBeTrue()
        ->and(switches()->isOff('chatbot', 'telegram', 'bot-z'))->toBeTrue()
        ->and(switches()->isOff('chatbot', 'web', 'widget-1'))->toBeFalse();

    switches()->flip('chatbot', 'telegram', null, false, null, $actor);
    expect(switches()->isOff('chatbot', 'telegram', 'bot-a'))->toBeFalse('flipping back did not restore');

    switches()->flip('copilot', null, null, true, '[FIXTURE] platform incident', $actor);
    expect(switches()->isOff('copilot', 'telegram', 'bot-a'))->toBeTrue()
        ->and(switches()->isOff('copilot', 'web', null))->toBeTrue()
        ->and(switches()->isOff('chatbot', 'telegram', 'bot-a'))->toBeFalse('the copilot all-switch bled into the chatbot');
});

it('SEC-05: every flip is audited with actor + old/new and evented with the closed payload', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();

    $id = switches()->flip('chatbot', 'telegram', 'bot-a', true, '[FIXTURE] reason', $actor);

    $audit = DB::table('audit_log')->where('action_key', 'ai.killswitch.changed')->where('entity_id', $id)->first();
    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($actor);
    $payload = json_decode((string) $audit->payload, true);
    expect($payload['old']['is_off'])->toBeFalse()->and($payload['new']['is_off'])->toBeTrue();

    $event = DB::table('outbox')->where('name', 'ai.killswitch.changed')->first();
    $eventPayload = json_decode((string) $event->payload, true);
    expect($eventPayload['surface'])->toBe('chatbot')
        ->and($eventPayload['endpoint_ref'])->toBe('bot-a')
        ->and($eventPayload['is_off'])->toBeTrue();
});

it('SABOTAGE #16 CONTROL (amendment R-19b): flipped_at is a BYTE-COPY of the flip\'s own audit-entry DB stamp — never a fresh clock', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();

    // THE A-33 B-2 SHAPE — static pin of the copy expression: the writer
    // names the audit stamp, not a clock. (A first run of sabotage #16 stayed
    // GREEN: audit_log.created_at is DB now() at WHOLE-SECOND precision =
    // transaction start, and a wall-clock write in the same second is
    // byte-identical — the clock-granularity trap R-23's family principle
    // names. The static pin + the forced second boundary below make the
    // control non-vacuous.)
    $source = (string) php_strip_whitespace(app_path('Domain/Ai/KillSwitch/KillSwitches.php'));
    expect(str_contains($source, "'flipped_at' => \$stamp->created_at"))->toBeTrue('the writer no longer copies the audit stamp into flipped_at');
    expect(preg_match('/flipped_at[^\n]{0,40}now\(\)/', $source))->toBe(0, 'flipped_at is written from a clock');

    // Behavioral: the harness opened the outer transaction at test start; a
    // write >= 1.1 s later from a wall clock CANNOT equal the transaction-
    // start stamp the audit row carries.
    usleep(1_150_000);
    $id = switches()->flip('chatbot', 'telegram', 'bot-a', true, null, $actor);

    $flippedAt = DB::table('ai_kill_switches')->where('id', $id)->value('flipped_at');
    $auditAt = DB::table('audit_log')->where('action_key', 'ai.killswitch.changed')->where('entity_id', $id)->orderByDesc('created_at')->value('created_at');

    expect((string) $flippedAt)->toBe((string) $auditAt, 'flipped_at diverged from the audit stamp — a wall clock wrote it');
});

it('FR-07 enablement (SwitchesAndFlags): scope config ON + no switch = enabled; a switch off wins; DEFAULT IS OFF (§21 rec 2)', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();
    $enablement = app(ChatbotEnablement::class);
    expect($enablement)->toBeInstanceOf(SwitchesAndFlags::class);

    // Default: no scope config → OFF, never on by accident.
    expect($enablement->enabledFor('chatbot', 'telegram', 'bot-a'))->toBeFalse('the chatbot was on with no scope config');

    $configs = app(ConfigVersions::class);
    $v = $configs->draft('thresholds', 'bot-a', ['chatbot_enabled' => true, 'rg_min' => 0.8], $actor);
    $configs->publish($v, $actor);
    expect($enablement->enabledFor('chatbot', 'telegram', 'bot-a'))->toBeTrue()
        ->and($enablement->enabledFor('chatbot', 'telegram', 'bot-b'))->toBeFalse('an unconfigured sibling inherited enablement');

    switches()->flip('chatbot', 'telegram', 'bot-a', true, null, $actor);
    expect($enablement->enabledFor('chatbot', 'telegram', 'bot-a'))->toBeFalse('the kill switch did not win over scope config');
});

it('the scope grid is a singleton per combination — a second flip UPDATES the row, never doubles it (NULLS NOT DISTINCT)', function (): void {
    $tenant = createTenant();
    bindTenant($tenant);
    $actor = (string) Str::uuid7();

    $first = switches()->flip('supervisor', null, null, true, null, $actor);
    $second = switches()->flip('supervisor', null, null, false, null, $actor);

    expect($first)->toBe($second)
        ->and(DB::table('ai_kill_switches')->where('surface', 'supervisor')->count())->toBe(1);
});

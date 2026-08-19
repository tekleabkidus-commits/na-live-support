<?php

declare(strict_types=1);

use App\Support\Logging\ScrubSecrets;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEC-03 DISCHARGE PROOFS (Doc 08 carry; ruling R-D09-1; S09 step 1).
 *
 * The scrubber gained phone (E.164 + local Ethiopian) and email VALUE
 * patterns before Doc 09's provider logging exists. Key-shape scrubbing
 * cannot catch a phone under `detail` or an email in a message string; these
 * proofs pin that the new patterns do — and that the boundary sits exactly
 * where the diff says (near-miss survivors, the S19 #7 counting bar).
 *
 * FIVE SINKS (the AC-08 swept set): the rendered log (scrubbed here), plus
 * jobs / outbox / audit_log / events — each with its own plant→detect→
 * rollback positive control (the SecretsNeverSerializeTest idiom) before the
 * absence sweep, so no scanner is ever blind.
 *
 * All values synthetic: +251-90000xxxx phones, fixture-*@example.et emails
 * (CLAUDE.md fixture law — a leak is self-identifying). Sabotage #15 stubs
 * the three new patterns and expects the absence assertions here to go red.
 */

// Same sink mechanics as LogScrubberTest / TelegramScrubberPatternsTest (the
// real `single` channel, path repointed); distinct names — no load-order
// coupling between the security suites.
function peScrubSink(): string
{
    $path = sys_get_temp_dir().'/scrub-sink-'.bin2hex(random_bytes(8)).'.log';

    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['single'],
        'logging.channels.single.path' => $path,
    ]);

    Log::forgetChannel('stack');
    Log::forgetChannel('single');

    return $path;
}

function peSinkContains(string $path, string $needle): bool
{
    $content = file_exists($path) ? (string) file_get_contents($path) : '';

    return str_contains($content, $needle);
}

// The sentinel set — both ruled phone formats with separator variants, two
// email shapes. Each is asserted ABSENT from the rendered sink after riding
// innocent keys and the message string.
const PE_SENTINELS = [
    'e164_plain' => '+251900001234',
    'e164_dashed' => '+251-900001234',
    'e164_spaced' => '+251 90 000 12 34',
    'local_09' => '0900001234',
    'local_07' => '0700001234',
    'local_spaced' => '09 00 00 12 34',
    'email_plain' => 'fixture-customer@example.et',
    'email_subaddressed' => 'fixture.a+tag@ex-ample.co.uk',
];

// One property off each — these must SURVIVE to the sink, proving the write
// path, the scanner, and the exact pattern boundary in one move.
const PE_NEAR_MISSES = [
    'phone_08_prefix' => '0812345678',
    'phone_nine_digits' => '091234567',
    'email_no_tld' => 'fixture@localhost',
    'e164_too_short' => '+2519 12',
];

it('SEC-03: near-miss controls survive to the sink — the scanner and the exact boundary are proven before any absence is trusted', function (): void {
    $path = peScrubSink();

    Log::info('pe scrub liveness probe', PE_NEAR_MISSES);

    expect(peSinkContains($path, 'pe scrub liveness probe'))->toBeTrue('the probe never reached the sink — every scan below would be vacuous');

    foreach (PE_NEAR_MISSES as $name => $value) {
        expect(peSinkContains($path, $value))->toBeTrue(
            sprintf('near-miss "%s" (%s) was eaten — the pattern is broader than the diff declared', $name, $value),
        );
    }
});

it('SEC-03: phone and email VALUES under innocent keys never reach the rendered sink', function (): void {
    $path = peScrubSink();

    foreach (PE_SENTINELS as $name => $value) {
        Log::info('pe innocent-key probe '.$name, ['detail' => $value, 'note' => 'ref '.$value, 'url' => 'callback?to='.$value]);
    }

    expect(peSinkContains($path, 'pe innocent-key probe'))->toBeTrue('probes never reached the sink');
    expect(peSinkContains($path, ScrubSecrets::REDACTED))->toBeTrue();

    foreach (PE_SENTINELS as $name => $value) {
        expect(peSinkContains($path, $value))->toBeFalse(
            sprintf('sentinel "%s" (%s) reached the rendered sink under an innocent key (SEC-03)', $name, $value),
        );
    }
});

it('SEC-03: phone and email VALUES inside the message string itself are scrubbed', function (): void {
    $path = peScrubSink();

    Log::warning('provider turn context: [FIXTURE] my withdrawal to 0900001234 failed, reach me at fixture-customer@example.et or +251-900001234, ref TX-FIXTURE-1');

    expect(peSinkContains($path, 'provider turn context'))->toBeTrue('the message never reached the sink');
    expect(peSinkContains($path, '0900001234'))->toBeFalse('a local-format phone survived in the message string');
    expect(peSinkContains($path, 'fixture-customer@example.et'))->toBeFalse('an email survived in the message string');
    expect(peSinkContains($path, '+251-900001234'))->toBeFalse('an E.164 phone survived in the message string');
    expect(peSinkContains($path, 'TX-FIXTURE-1'))->toBeTrue('benign reference eaten — over-scrubbing broke the log SEC-08 wants kept useful');
});

it('SEC-03: benign context survives — UUIDs, timezone offsets, _id keys stay diagnosable', function (): void {
    $path = peScrubSink();

    Log::info('pe benign probe', [
        'trace_id' => 'b649704f-aaaa-4bbb-8ccc-1234567890ab',
        'occurred_at' => '2026-08-19T21:30:00+03:00',
        'tenant_id' => '018f6b2e-0000-7000-8000-000000000000',
    ]);

    expect(peSinkContains($path, 'b649704f-aaaa-4bbb-8ccc-1234567890ab'))->toBeTrue('a UUID was eaten');
    expect(peSinkContains($path, '2026-08-19T21:30:00+03:00'))->toBeTrue('a timezone offset was eaten by the E.164 pattern');
    expect(peSinkContains($path, '018f6b2e-0000-7000-8000-000000000000'))->toBeTrue('an _id value was eaten');
});

it('SEC-03: the four DB sinks — per-sink plant→detect→rollback controls, then the five-sink absence sweep', function (): void {
    $path = peScrubSink();
    $tenant = createTenant();
    $tenantId = $tenant->getAttribute('id');

    $sweepPhone = '+251-900007777';
    $sweepLocal = '0900007777';
    $sweepEmail = 'fixture-sweep@example.et';

    // The log-bound plant: the sentinels enter REAL log context here and, per
    // R-D09-1, must then appear in NO sink at all.
    Log::info('pe five-sink probe', ['detail' => $sweepPhone.' '.$sweepLocal.' '.$sweepEmail]);
    expect(peSinkContains($path, 'pe five-sink probe'))->toBeTrue('the log-bound plant never landed');

    $sinks = [
        'jobs' => fn (): string => json_encode(DB::table('jobs')->get()->all(), JSON_THROW_ON_ERROR),
        'outbox' => fn (): string => json_encode(asTenant($tenantId, fn () => DB::table('outbox')->get()->all()), JSON_THROW_ON_ERROR),
        'audit_log' => fn (): string => json_encode(asTenant($tenantId, fn () => DB::table('audit_log')->get()->all()), JSON_THROW_ON_ERROR),
        'events' => fn (): string => json_encode(asTenant($tenantId, fn () => DB::table('events')->get()->all()), JSON_THROW_ON_ERROR),
    ];

    // Per-sink positive control: plant a phone+email directly, detect, roll
    // back, gone — the scanner is proven per sink before absence is trusted.
    foreach ($sinks as $name => $content) {
        $plantPhone = '+251-9000088'.str_pad((string) array_search($name, array_keys($sinks), true), 2, '0', STR_PAD_LEFT);
        $plantEmail = 'fixture-plant-'.$name.'@example.et';
        expect($content())->not->toContain($plantEmail);

        DB::beginTransaction();
        match ($name) {
            'jobs' => DB::table('jobs')->insert([
                'queue' => 'pe-plant-probe',
                'payload' => json_encode(['note' => $plantPhone.' '.$plantEmail], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]),
            'outbox' => asTenant($tenantId, fn (): bool => DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'event_id' => (string) Str::uuid7(),
                'name' => 'pe.plant.probe',
                'payload' => json_encode(['note' => $plantPhone.' '.$plantEmail], JSON_THROW_ON_ERROR),
            ])),
            'audit_log' => asTenant($tenantId, fn (): bool => DB::table('audit_log')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'action_key' => 'pe.plant.probe',
                'entity' => 'probe',
                'actor_type' => 'system',
                'payload' => json_encode(['note' => $plantPhone.' '.$plantEmail], JSON_THROW_ON_ERROR),
            ])),
            'events' => asTenant($tenantId, fn (): bool => DB::table('events')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'name' => 'pe.plant.probe',
                'payload' => json_encode(['note' => $plantPhone.' '.$plantEmail], JSON_THROW_ON_ERROR),
            ])),
        };

        expect(str_contains($content(), $plantEmail))->toBeTrue(
            sprintf('scanner failed its positive control for the %s sink', $name),
        );
        DB::rollBack();
        expect(str_contains($content(), $plantEmail))->toBeFalse(
            sprintf('the %s plant survived its rollback — the control leaked', $name),
        );
    }

    // The sweep: the log-bound sentinels appear in NO sink. Rendered sink
    // first (the scrubber's job), then the four serialization sinks (no flow
    // may carry log-bound context into them; the controls above prove the
    // scanners would see it if one did).
    foreach ([$sweepPhone, $sweepLocal, $sweepEmail] as $value) {
        expect(peSinkContains($path, $value))->toBeFalse(
            sprintf('log-bound sentinel "%s" reached the rendered sink (SEC-03)', $value),
        );

        foreach ($sinks as $name => $content) {
            expect(str_contains($content(), $value))->toBeFalse(
                sprintf('log-bound sentinel "%s" reached the %s sink (SEC-03/AC-08)', $value, $name),
            );
        }
    }
});

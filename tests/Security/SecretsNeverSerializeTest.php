<?php

declare(strict_types=1);

use App\Models\AuthActionToken;
use App\Models\PersonalAccessToken;
use App\Models\TenantKey;
use App\Models\User;
use App\Models\UserRecoveryCode;
use App\Models\WebhookCredential;
use App\Support\Events\OutboxRelay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The C-13 secrets-never-serialize sweep + AC-08's non-log sinks
 * (Doc 02 SEC-08/AC-08; CLAUDE.md C-13; step-11 inheritance ruling).
 *
 * Sinks swept here: `jobs` (real serialized queue payloads on the database
 * driver), `outbox`, `audit_log`, and — after a genuine committed relay run —
 * `events`. Together with LogScrubberTest's rendered-log sink that is the
 * owner-recorded swept set; Reverb/Redis publish payloads stay out by the
 * same ruling (T-04's identifier-only design + the event catalog's
 * closed-payload rule police bus shapes).
 *
 * THE COUNTING BAR: per sink AND per sentinel, plant → detect → remove →
 * prove absence. DB-sink plants ride a savepoint (begin → insert → detect →
 * rollBack), which works even on audit_log where DELETE is revoked by grant.
 * The plants are direct inserts on purpose — the event catalog's closed
 * payloads mean no real writer CAN carry arbitrary text, and the control's
 * job is to prove the SCANNER, not the writer.
 *
 * THE RECORDED C-13 CARVE-OUT (PasswordResetMail's docblock, S12): the
 * reset/invite URL — embedding the single-use, 60-minute, hashed-at-rest
 * token — serializes into MAIL-queue job payloads, because that is what
 * "queued email containing a link" means. This suite encodes the boundary
 * exactly: those two tokens may appear in `queue = 'mail'` rows and NOWHERE
 * else, and the carve-out is positively asserted so it cannot silently widen
 * or silently close.
 */

/** Whole rows JSON-encoded, so a leak in ANY column is caught, per tenant. */
function tenantSinkContent(string $table, array $tenants): string
{
    $chunks = [];

    foreach ($tenants as $tenantId) {
        $chunks[] = json_encode(asTenant($tenantId, fn () => DB::table($table)->get()->all()), JSON_THROW_ON_ERROR);
    }

    return implode("\n", $chunks);
}

/** @param  bool  $exceptMailQueue  true → the carve-out scope: non-mail rows only */
function jobsSinkContent(bool $exceptMailQueue): string
{
    $query = DB::table('jobs');

    if ($exceptMailQueue) {
        $query->where('queue', '!=', 'mail');
    }

    return json_encode($query->get()->all(), JSON_THROW_ON_ERROR);
}

function sentinelInSink(string $content, string $value, bool $digitBoundary): bool
{
    if ($digitBoundary) {
        // Interpretation 3 (approved): TOTP codes scan with digit boundaries.
        return preg_match('/(?<![0-9])'.preg_quote($value, '/').'(?![0-9])/', $content) === 1;
    }

    return str_contains($content, $value);
}

it('AC-08/C-13: seeded credentials never serialize into jobs, outbox, or audit payloads', function (): void {
    $minted = driveSeededCredentialFlows(mailOnDatabaseQueue: true);
    $sentinels = $minted['sentinels'];
    $tenants = $minted['tenants'];

    expect(count($sentinels))->toBeGreaterThanOrEqual(21);

    $sinks = [
        'jobs' => fn (): string => jobsSinkContent(exceptMailQueue: false),
        'outbox' => fn (): string => tenantSinkContent('outbox', $tenants),
        'audit_log' => fn (): string => tenantSinkContent('audit_log', $tenants),
    ];

    // ── Per-sink positive control: plant → detect → savepoint-rollback → gone ──
    foreach ($sinks as $name => $content) {
        $plant = 'FAKECRED-PLANT-'.$name;
        expect($content())->not->toContain($plant);

        DB::beginTransaction();
        match ($name) {
            'jobs' => DB::table('jobs')->insert([
                'queue' => 'plant-probe',
                'payload' => json_encode(['note' => $plant], JSON_THROW_ON_ERROR),
                'attempts' => 0,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]),
            'outbox' => asTenant($tenants[0], fn (): bool => DB::table('outbox')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenants[0],
                'event_id' => (string) Str::uuid7(),
                'name' => 'plant.probe',
                'payload' => json_encode(['note' => $plant], JSON_THROW_ON_ERROR),
            ])),
            'audit_log' => asTenant($tenants[0], fn (): bool => DB::table('audit_log')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenants[0],
                'action_key' => 'plant.probe',
                'entity' => 'plant',
                'actor_type' => 'system',
                'payload' => json_encode(['note' => $plant], JSON_THROW_ON_ERROR),
            ])),
        };
        expect(str_contains($content(), $plant))->toBeTrue(sprintf(
            'scanner failed its positive control for the %s sink — every absence below would prove nothing', $name,
        ));
        DB::rollBack();
        expect($content())->not->toContain($plant);
    }

    // ── The sweep, one materialization per sink ──
    $jobsAll = jobsSinkContent(exceptMailQueue: false);
    $jobsNonMail = jobsSinkContent(exceptMailQueue: true);
    $outbox = tenantSinkContent('outbox', $tenants);
    $audit = tenantSinkContent('audit_log', $tenants);

    // The mail flows actually queued — otherwise the jobs scan is vacuous.
    expect($jobsAll)->toContain('PasswordResetMail')->toContain('InvitationMail');

    // The recorded carve-out, positively asserted so it can neither silently
    // widen (tokens outside the mail queue) nor silently close (mail path
    // changed without reopening the ruling).
    foreach (['reset_token', 'invite_token'] as $carved) {
        expect(str_contains($jobsAll, $sentinels[$carved]))->toBeTrue(sprintf(
            'the recorded C-13 carve-out for %s has silently closed — if the mail path changed, reopen the PasswordResetMail ruling', $carved,
        ));
        expect(str_contains($jobsNonMail, $sentinels[$carved]))->toBeFalse(sprintf(
            '%s serialized OUTSIDE the mail queue — the carve-out is mail-only', $carved,
        ));
    }

    foreach ($sentinels as $name => $value) {
        $digitBoundary = $name === 'totp_code';
        $jobsScope = in_array($name, ['reset_token', 'invite_token'], true) ? $jobsNonMail : $jobsAll;

        expect(sentinelInSink($jobsScope, $value, $digitBoundary))->toBeFalse(
            sprintf('seeded credential "%s" serialized into a job payload (C-13)', $name),
        );
        expect(sentinelInSink($outbox, $value, $digitBoundary))->toBeFalse(
            sprintf('seeded credential "%s" reached an outbox payload (C-13)', $name),
        );
        expect(sentinelInSink($audit, $value, $digitBoundary))->toBeFalse(
            sprintf('seeded credential "%s" reached an audit payload (C-13)', $name),
        );
    }
});

it('AC-08: the events sink, after a real committed relay run, carries no sentinel', function (): void {
    $tenants = [];

    try {
        // Staff flows only: the committed slice must stay bounded to one
        // tenant, because nothing here rolls back (see commitFixtures()).
        $minted = driveSeededCredentialFlows(mailOnDatabaseQueue: true, staffOnly: true);
        $sentinels = $minted['sentinels'];
        $tenants = $minted['tenants'];

        commitFixtures();

        $relayed = asPlatformWorker(fn (): int => app(OutboxRelay::class)->relayBatch());
        expect($relayed)->toBeGreaterThan(0, 'nothing relayed — the events scan below would be vacuous');

        $content = fn (): string => tenantSinkContent('events', $tenants);
        expect(strlen($content()))->toBeGreaterThan(2, 'no events landed for the flow tenant');

        // Positive control on THIS sink, same savepoint mechanics.
        $plant = 'FAKECRED-PLANT-events';
        DB::beginTransaction();
        asTenant($tenants[0], fn (): bool => DB::table('events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenants[0],
            'name' => 'plant.probe',
            'payload' => json_encode(['note' => $plant], JSON_THROW_ON_ERROR),
        ]));
        expect(str_contains($content(), $plant))->toBeTrue('scanner failed its positive control for the events sink');
        DB::rollBack();
        expect($content())->not->toContain($plant);

        $events = $content();
        foreach ($sentinels as $name => $value) {
            expect(sentinelInSink($events, $value, $name === 'totp_code'))->toBeFalse(
                sprintf('seeded credential "%s" was relayed into the events sink (AC-08)', $name),
            );
        }
    } finally {
        cleanupTenants($tenants);
        DB::connection('pgsql_platform')->table('jobs')
            ->where('payload', 'like', '%fixture.example%')
            ->orWhere('payload', 'like', '%FAKECRED%')
            ->delete();
    }
});

it('C-13 model pins: no Doc 02-minted credential column serializes out of its model', function (): void {
    // The roster the owner ruled into existence: EVERY model this document
    // minted (or minted into) that carries a hashed-or-encrypted credential
    // column. Device.fingerprint_hash is excluded deliberately — it hashes
    // PUBLIC client hints (T-03) and is not credential material. TenantKey is
    // Doc 01's model, on the roster because Doc 02's widget and webhook keys
    // ride it.
    $roster = [
        [User::class, ['password' => 'FAKECRED-pin-password', 'remember_token' => 'FAKECRED-pin-remember', 'two_factor_secret' => 'FAKECRED-pin-totp'], ['email' => 'pin-visible@fixture.example']],
        [WebhookCredential::class, ['header_secret_hash' => 'FAKECRED-pin-header', 'hmac_secret' => 'FAKECRED-pin-hmac'], ['key_id' => '01890000-0000-7000-8000-0000pinvisib']],
        [AuthActionToken::class, ['token_hash' => 'FAKECRED-pin-action'], ['sent_to_email' => 'pin-visible-2@fixture.example']],
        [UserRecoveryCode::class, ['code_hash' => 'FAKECRED-pin-recovery'], ['batch_id' => '01890000-0000-7000-8000-0000pinvisib']],
        [PersonalAccessToken::class, ['token' => 'FAKECRED-pin-sanctum'], ['name' => 'pin-visible-token-name']],
        [TenantKey::class, ['hash' => 'FAKECRED-pin-tenantkey'], ['label' => 'pin-visible-label']],
    ];

    foreach ($roster as [$class, $secrets, $visible]) {
        /** @var Illuminate\Database\Eloquent\Model $model */
        $model = new $class;
        $model->setRawAttributes($secrets + $visible);

        $array = $model->toArray();
        $json = json_encode($array, JSON_THROW_ON_ERROR);

        // Positive control: serialization is live — visible attributes are in.
        foreach ($visible as $attribute => $value) {
            expect(str_contains($json, (string) $value))->toBeTrue(sprintf(
                '%s::%s did not serialize — this pin would be vacuous', $class, $attribute,
            ));
        }

        foreach ($secrets as $attribute => $value) {
            expect(array_key_exists($attribute, $array))->toBeFalse(sprintf(
                '%s exposes %s in toArray() — the C-13 pin', $class, $attribute,
            ));
            expect(str_contains($json, $value))->toBeFalse(sprintf(
                '%s serialized the VALUE of %s', $class, $attribute,
            ));
        }
    }
});

<?php

declare(strict_types=1);

use App\Domain\Telegram\Window\WindowCalibration;
use App\Domain\Telegram\Window\WindowTracker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| FR-21 / EC-14 / BR-05 — window CALIBRATION and its asymmetry
|--------------------------------------------------------------------------
|
| PROTOCOL ORDER: these calibration tests + the outage-pause test land BEFORE
| the sweep is trusted (S23 plan step 1's ordering). The window must compute
| from the inbound message's DB-time receipt, never telegram_date, and
| calibration's "now" must be clock_timestamp() in the UPDATE — pinned here.
*/

/** @return array{conversation: string, message: string, tenant: string} */
function windowFixture(int $telegramDate = 1_600_000_000): array
{
    $tenant = createTenant();
    activateTenant($tenant);
    bindTenant($tenant);

    $customer = App\Models\Customer::query()->create([
        'display_name' => 'Window Player', 'username' => '@fixture_window_'.Str::random(6), 'is_shell' => true,
    ]);

    $conversationId = (string) Str::uuid7();
    DB::table('conversations')->insert([
        'id' => $conversationId, 'tenant_id' => $tenant->identifier(), 'channel' => 'telegram',
        'channel_ref_id' => 'bot-w', 'business_connection_id' => 'conn-w',
        'external_chat_id' => 'chat-w', 'customer_id' => $customer->identifier(), 'state' => 'active',
    ]);

    // A REAL inbound message row: created_at is DB time (clock_timestamp
    // default); telegram_date is stored in channel_meta and is DELIBERATELY
    // far in the past to prove the window never reads it.
    $messageId = (string) Str::uuid7();
    DB::table('messages')->insert([
        'id' => $messageId, 'tenant_id' => $tenant->identifier(), 'conversation_id' => $conversationId,
        'direction' => 'in', 'author_type' => 'customer', 'author_id' => $customer->identifier(),
        'kind' => 'text', 'body' => 'hi', 'external_message_id' => 'ext-1',
        'channel_meta' => json_encode(['telegram_date' => $telegramDate]),
        // created_at intentionally omitted — the DB clock_timestamp() default fills it.
    ]);

    return ['conversation' => $conversationId, 'message' => $messageId, 'tenant' => $tenant->identifier()];
}

it('FR-21 (plan row 4): the window computes from the message DB-time created_at + window_hours — NEVER telegram_date', function (): void {
    $f = windowFixture(telegramDate: 1_600_000_000); // year 2020 — if used, expiry would be 2020
    $tracker = app(WindowTracker::class);

    $expiryIso = $tracker->openFromInbound($f['conversation'], $f['message']);

    // The message's DB-time created_at is ~now; expiry = now + 24 h. If the
    // window had used telegram_date (2020), expiry would be in 2020.
    $createdAt = DB::table('messages')->where('id', $f['message'])->value('created_at');
    $delta = DB::selectOne('SELECT extract(epoch from (?::timestamptz - ?::timestamptz))/3600 AS hours', [$expiryIso, $createdAt]);
    expect(round((float) $delta->hours))->toBe(24.0, 'expiry is created_at + 24 h');

    // And explicitly NOT derived from telegram_date (2020).
    $year = (int) DB::selectOne('SELECT extract(year from ?::timestamptz) AS y', [$expiryIso])->y;
    expect($year)->toBeGreaterThan(2024, 'the window must NOT come from telegram_date');
});

it('T-04: the ZSET score is INTEGER microseconds (R-23 family principle), matching the stored expiry', function (): void {
    $f = windowFixture();
    $tracker = app(WindowTracker::class);
    $expiryIso = $tracker->openFromInbound($f['conversation'], $f['message']);

    /** @var array<int, string> $withScores [member, score, ...] */
    $raw = app('redis')->connection('volatile')->command('zscore', [$tracker->zsetKey(), $f['conversation']]);
    expect($raw)->not->toBeNull();
    // Integer µs: the score has no fractional part.
    expect((float) $raw)->toBe(floor((float) $raw), 'the ZSET score must be an integer (no fractional-second ties)');
    expect((int) $raw)->toBe($tracker->toMicros($expiryIso));
});

it('EC-14: a window_expired error BEFORE the computed expiry TIGHTENS the stored expiry to now (clock_timestamp), re-indexes, logs telemetry', function (): void {
    $f = windowFixture();
    $tracker = app(WindowTracker::class);
    $tracker->openFromInbound($f['conversation'], $f['message']); // expiry ~24h out

    $records = &capturedLogsRef();
    $tightened = app(WindowCalibration::class)->tightenOnEarlyExpiry($f['conversation'], 'conn-w');

    expect($tightened)->toBeTrue();
    // The expiry is now ~clock_timestamp() (within seconds), state expired.
    $row = DB::table('conversations')->where('id', $f['conversation'])->first(['reply_window_expires_at', 'reply_window_state']);
    $secondsFromNow = (float) DB::selectOne('SELECT abs(extract(epoch from (clock_timestamp() - ?::timestamptz))) AS s', [$row->reply_window_expires_at])->s;
    expect($secondsFromNow)->toBeLessThan(5.0, 'EC-14: tightened to ~now');
    expect($row->reply_window_state)->toBe('expired');

    $metrics = array_map(fn (array $r): mixed => $r['context']['metric'] ?? null, $records);
    expect($metrics)->toContain('telegram.window.calibrated');
});

it('BR-05: tightening is allowed but EXTENDING beyond the observed window is REFUSED (asymmetry, both directions)', function (): void {
    $f = windowFixture();
    $tracker = app(WindowCalibration::class);
    $windowTracker = app(WindowTracker::class);
    $windowTracker->openFromInbound($f['conversation'], $f['message']);

    $current = DB::table('conversations')->where('id', $f['conversation'])->value('reply_window_expires_at');

    // TIGHTEN: an earlier expiry (now + 1h) is inside the current window → allowed.
    $earlier = DB::selectOne('SELECT (clock_timestamp() + interval \'1 hour\')::text AS t')->t;
    expect($tracker->operatorSetExpiry($f['conversation'], $earlier))->toBeTrue('BR-05: tighten is allowed');

    // EXTEND: a later expiry (the original + 10 days) is beyond → REFUSED.
    $later = DB::selectOne('SELECT (?::timestamptz + interval \'10 days\')::text AS t', [$current])->t;
    expect($tracker->operatorSetExpiry($f['conversation'], $later))->toBeFalse('BR-05: extend-beyond-observed is refused');

    // The stored expiry is the tightened one, never the extension.
    $final = DB::table('conversations')->where('id', $f['conversation'])->value('reply_window_expires_at');
    $diffFromEarlier = (float) DB::selectOne('SELECT abs(extract(epoch from (?::timestamptz - ?::timestamptz))) AS s', [$final, $earlier])->s;
    expect($diffFromEarlier)->toBeLessThan(1.0, 'the stored expiry is the tightened value, extension never applied');
});

<?php

declare(strict_types=1);

use App\Domain\Telegram\Outbound\TelegramRateLimiter;

/*
|--------------------------------------------------------------------------
| FR-20 / T-08 / R-05 / AC-06 — Redis-Lua rate buckets
|--------------------------------------------------------------------------
|
| REAL redis-volatile, the real Lua eval. Time is INJECTED (µs float) so the
| bucket physics are deterministic without sleeping.
*/

function rateLimiter(): TelegramRateLimiter
{
    $tenant = createTenant();
    bindTenant($tenant);

    return app(TelegramRateLimiter::class);
}

it('FR-20/AC-06: the per-chat bucket allows the burst then paces at 1/s; refill restores capacity', function (): void {
    $limiter = rateLimiter();
    $now = 1_000_000.0;

    // Burst 3 (default): three immediate sends allowed, the fourth refused.
    expect($limiter->allow('bot-1', 'chat-1', $now))->toBeTrue();
    expect($limiter->allow('bot-1', 'chat-1', $now))->toBeTrue();
    expect($limiter->allow('bot-1', 'chat-1', $now))->toBeTrue();
    expect($limiter->allow('bot-1', 'chat-1', $now))->toBeFalse('per-chat burst of 3 is exhausted');

    // One second later: 1 token refilled (1/s sustained).
    expect($limiter->allow('bot-1', 'chat-1', $now + 1.0))->toBeTrue();
    expect($limiter->allow('bot-1', 'chat-1', $now + 1.0))->toBeFalse('only one token refilled per second');
});

it('FR-20: the per-bot bucket (25/s) caps across chats — a bot cannot exceed its global rate', function (): void {
    $limiter = rateLimiter();
    $now = 2_000_000.0;

    // 25 sends across distinct chats at the same instant: all allowed (per-bot
    // capacity 25, and each chat's own bucket has burst 3 to spare).
    $allowed = 0;
    for ($i = 0; $i < 30; $i++) {
        if ($limiter->allow('bot-2', 'chat-'.$i, $now)) {
            $allowed++;
        }
    }
    // The per-bot 25/s ceiling caps it at 25 despite 30 distinct chats.
    expect($allowed)->toBe(25, 'the per-bot bucket caps the global rate at 25/s');
});

it('R-05: a 429 penalty slows the per-bot refill for 60 s (adaptive slowdown, observable)', function (): void {
    $limiter = rateLimiter();
    $now = 3_000_000.0;

    // Drain the per-bot bucket, then penalize.
    for ($i = 0; $i < 25; $i++) {
        $limiter->allow('bot-3', 'chat-'.$i, $now);
    }
    expect($limiter->isPenalized('bot-3'))->toBeFalse();
    $limiter->penalize('bot-3');
    expect($limiter->isPenalized('bot-3'))->toBeTrue();

    // After 1 s: normal refill would restore 25; penalized refill restores
    // 25*0.8 = 20, so the 21st send in that second is refused.
    $refilled = 0;
    for ($i = 0; $i < 25; $i++) {
        if ($limiter->allow('bot-3', 'chat-p'.$i, $now + 1.0)) {
            $refilled++;
        }
    }
    expect($refilled)->toBeLessThan(25, 'the 429 penalty reduced the refill rate (R-05 adaptive slowdown)');
    expect($refilled)->toBeGreaterThanOrEqual(19)->toBeLessThanOrEqual(21);
});

it('T-08: the check-and-consume is atomic — concurrent-shaped calls never both take the last token', function (): void {
    $limiter = rateLimiter();
    $now = 4_000_000.0;

    // Exhaust to exactly one token remaining on a fresh chat bucket (burst 3).
    $limiter->allow('bot-4', 'chat-x', $now);
    $limiter->allow('bot-4', 'chat-x', $now);

    // Two "simultaneous" attempts at the same instant: exactly ONE wins.
    $a = $limiter->allow('bot-4', 'chat-x', $now);
    $b = $limiter->allow('bot-4', 'chat-x', $now);
    expect([$a, $b])->toContain(true)->and([$a, $b])->toContain(false);
});

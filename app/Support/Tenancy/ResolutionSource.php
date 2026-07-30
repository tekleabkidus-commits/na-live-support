<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * The six — and only six — ways a tenant may be resolved (Doc 01 FR-03).
 *
 * This enum is the closed set. FR-04 is enforced by omission as much as by any
 * check: there is no `RequestBody`, `QueryString` or `Header` case, so no code
 * path can name one. If a seventh entry point is ever needed it is a
 * specification change with an ADR, not a quiet addition here.
 *
 * Every case is server-controlled. `QueuedJob` looks like the weak link — the
 * tenant id travels inside the serialised job payload — but the payload was
 * written by a dispatcher that already had a bound context, and a job arriving
 * with no tenant id fails before its handler runs (FR-03f), so a forged or
 * missing value cannot reach domain code.
 *
 * Carried on every resolution so the audit trail and the AC-02 fuzz proof can
 * say HOW a request was resolved, not merely that it was.
 */
enum ResolutionSource: string
{
    /** FR-03a — staff web session or Sanctum token; token → user → tenant. */
    case StaffSession = 'staff_session';

    /** FR-03b — public API key record. */
    case ApiKey = 'api_key';

    /** FR-03c — Telegram webhook route's bot key → bot record. */
    case TelegramBotKey = 'telegram_bot_key';

    /** FR-03d — widget key → widget record. */
    case WidgetKey = 'widget_key';

    /** FR-03e — SMS DLR webhook gateway key → gateway record. */
    case SmsGatewayKey = 'sms_gateway_key';

    /** FR-03f — tenant id serialised into the job payload at dispatch time. */
    case QueuedJob = 'queued_job';

    /** Artisan --tenant option (T-05's CLI binding). */
    case Console = 'console';

    /** Reverb channel authorisation callback (T-05's socket binding). */
    case Socket = 'socket';

    /**
     * The `kind` value in tenant_keys that this source presents, if any.
     *
     * Returning DATA rather than branching on a channel name is what keeps
     * invariant N-20 intact: callers pass this straight to the resolver
     * function, and no domain code ever compares against the literal
     * 'telegram'.
     */
    public function keyKind(): ?string
    {
        return match ($this) {
            self::ApiKey => 'api',
            self::TelegramBotKey => 'telegram_bot',
            self::WidgetKey => 'widget',
            self::SmsGatewayKey => 'sms_gateway',
            self::StaffSession, self::QueuedJob, self::Console, self::Socket => null,
        };
    }
}

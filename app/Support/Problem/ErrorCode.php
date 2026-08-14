<?php

declare(strict_types=1);

namespace App\Support\Problem;

/**
 * The stable `code` enum for RFC 9457 problem-details responses (Doc 01 T-07, C-04).
 *
 * T-07: "code values are stable strings (reply_window_expired, quota_exceeded, …)
 * registered in a shared enum".
 *
 * STABLE means these strings are API surface. A client — the agent app, the widget,
 * an operator's integration — branches on them. Renaming one is a breaking change
 * that needs the same care as removing an endpoint; adding one is safe. The HTTP
 * status says what class of problem it is, the code says exactly which, and the
 * human-readable `detail` is free to change with translations because nothing parses
 * it.
 *
 * Doc 01 owns the foundation codes. Later documents add their own; `reply_window_expired`
 * is listed in T-07's example and belongs to Doc 05, so it is not here yet.
 */
enum ErrorCode: string
{
    /** NFR-06: a per-tenant quota ceiling was hit. Always names the quota. */
    case QuotaExceeded = 'quota_exceeded';

    /** FR-05: the tenant's lifecycle state forbids this surface (AC-08). */
    case TenantStateForbidden = 'tenant_state_forbidden';

    /** FR-07: a setting key that the registry does not know. */
    case UnregisteredSetting = 'unregistered_setting';

    /** EC-06: optimistic-concurrency loser on a settings write. */
    case SettingWriteConflict = 'setting_write_conflict';

    /** T-08: cursor pagination misuse — bad cursor, or limit above 100. */
    case InvalidPagination = 'invalid_pagination';

    /** Generic validation failure; `errors` carries the per-field detail. */
    case ValidationFailed = 'validation_failed';

    /*
    |--------------------------------------------------------------------------
    | Doc 02 — Authentication, Sessions & Security Core
    |--------------------------------------------------------------------------
    */

    /** SEC-06: the request arrived over cleartext. Refused in every environment. */
    case InsecureTransport = 'insecure_transport';

    /**
     * FR-03 / SEC-05: a per-IP rate limit refused the request BEFORE any
     * expensive work. Distinct from quota_exceeded, which is a tenant's usage
     * ceiling — this one applies pre-tenant, to a shared surface.
     */
    case RateLimited = 'rate_limited';

    /**
     * FR-02 / AC-01: a login attempt was refused. ONE code for every credential
     * failure — a client must not be able to tell "no such account" from "wrong
     * password" by branching on the code any more than by reading the message.
     */
    case InvalidCredentials = 'invalid_credentials';

    /**
     * FR-15/FR-16: a candidate password failed the tenant's policy. `errors`
     * carries one entry per failed RULE (length, classes, reuse, breached) —
     * that per-rule shape is the "live policy validation" contract the views
     * step's strength meter will consume.
     */
    case PasswordPolicyViolation = 'password_policy_violation';

    /**
     * FR-16/FR-17/SEC-01: a presented reset or invite link cannot act — unknown,
     * expired, consumed, superseded, or otherwise dead, in ONE code with no
     * distinction (distinguishing them would confirm the token was once real).
     */
    case ActionLinkInvalid = 'action_link_invalid';

    /**
     * FR-15 max-age: the authenticated principal's password is past the
     * tenant's auth.password.max_age_days and this request is outside the
     * change surface. The client routes the user to the Security page.
     */
    case PasswordExpired = 'password_expired';

    /*
    | FR-20's CLOSED code set for the bearer surface (step-9 owner ruling):
    | exactly these three. Revoked tokens and disabled owners render as
    | invalid_token — the door flags them internally for audit, the response
    | never distinguishes them, and no fourth code may be added here without
    | reopening that ruling.
    */

    /** FR-20: no valid token was presented — unknown, malformed, revoked, or a disabled owner. 401. */
    case InvalidToken = 'invalid_token';

    /** FR-20: the token was real and has passed its own expires_at. 401. */
    case TokenExpired = 'token_expired';

    /** FR-20/AC-05: authenticated, but the T-05 intersection lacks the required permission — named in `detail` and `errors`. 403. */
    case InsufficientScope = 'insufficient_scope';

    /**
     * Doc 03 T-02/SEC-02: a STAFF principal's role/overrides deny the
     * declared permission — 403 naming the key for staff clients
     * (`{code:"forbidden", permission:key}`); object EXISTENCE stays masked
     * separately (out-of-scope object reads are 404 per Doc 01 SEC-06).
     */
    case Forbidden = 'forbidden';

    /**
     * FR-21/AC-06/EC-08: a widget session request from an origin (or with a
     * frame ancestor) outside the widget's domain allowlist. ONE code for
     * origin and ancestor refusals alike — distinguishing them would tell a
     * probing page which of its two identities failed. 403.
     */
    case OriginNotAllowed = 'origin_not_allowed';

    /**
     * FR-23/FR-24/AC-07: a webhook request failed its second credential —
     * secret mismatch, absent header, bad signature, stale timestamp,
     * replayed nonce, or unconfigured key, in ONE code with no distinction
     * (step-11 ruling: responses never say which check refused; metrics do).
     * 403.
     */
    case WebhookRejected = 'webhook_rejected';

    /**
     * Doc 04 FS-05: a re-drive was attempted on a dead letter that is not
     * parked (already re-driven by a racing operator, usually).
     */
    case DeadLetterNotParked = 'dead_letter_not_parked';

    // ── Doc 07 (S07 step 4): the saved-view surface's stable refusals ──
    case ViewFiltersInvalid = 'view_filters_invalid';

    // AC-02: shared views are read-only to consumers — duplicate to edit.
    case ReadOnlyView = 'read_only_view';

    // C-10: "not yours" and "does not exist" answer identically.
    case ViewNotFound = 'view_not_found';

    // EC-01 (Doc 07, S07 step 10): the 404-PATTERN's stable code — one
    // answer for missing AND out-of-scope (C-10: no existence oracle).
    case ResourceNotFound = 'resource_not_found';

    // S07 step 11 — the composer's stable refusals (Doc 07 FR-21, Doc 06
    // EC-08's prompt, Doc 04 BR-02's blocked conversation).
    case ReopenConfirmationRequired = 'reopen_confirmation_required';
    case TransitionRejected = 'transition_rejected';
    case ConversationBlocked = 'conversation_blocked';

    // S07 step 13 — Doc 06 FR-16's claim race surfaces over HTTP: the loser
    // DECIDES on this code (renders "claimed by {name}"), so it is enum-stable.
    case AlreadyClaimed = 'already_claimed';

    /**
     * Doc 08 FR-09: the value already belongs to another customer — blocked,
     * with the merge-instead offer riding the response's extra members.
     */
    case IdentityAlreadyLinked = 'identity_already_linked';

    /**
     * The i18n key for this code's human-readable title (C-08/N-03).
     *
     * Titles are translated, codes are not. A client that needs to display something
     * uses the title; a client that needs to DECIDE something uses the code.
     */
    public function titleI18nKey(): string
    {
        return sprintf('foundation.error.%s', $this->value);
    }
}

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

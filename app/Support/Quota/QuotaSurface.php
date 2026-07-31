<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * FS-01's failure postures for quota checks when Redis is unreachable.
 *
 * "Rate limits fail-open for staff traffic but fail-closed for public ingestion."
 * The posture belongs to the SURFACE, not the quota: the same messages/min quota
 * fails open for an agent replying (locking staff out of their own workspace over a
 * cache outage compounds the incident) and closed for an unauthenticated webhook
 * (unmetered public ingestion during an outage is exactly when abuse arrives).
 */
enum QuotaSurface
{
    /** Authenticated staff traffic — degrade open, log the degradation. */
    case Staff;

    /** Public ingestion (webhooks, widget, API keys) — refuse until metering returns. */
    case PublicIngestion;
}

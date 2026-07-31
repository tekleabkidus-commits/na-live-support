<?php

declare(strict_types=1);

namespace App\Support\Quota;

use RuntimeException;

/**
 * The fail-closed half of FS-01: metering is unreachable and this surface must not
 * proceed unmetered. Ingestion pipelines (Docs 04/05/13) translate this into their
 * bounded-queue backpressure; it deliberately does NOT render as quota_exceeded,
 * because telling a client "you are over quota" when the truth is "we cannot count
 * right now" poisons their backoff behaviour and R-04's usage-dispute answers.
 */
final class QuotaCheckUnavailable extends RuntimeException {}

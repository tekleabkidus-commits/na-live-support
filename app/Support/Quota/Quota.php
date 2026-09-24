<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * The four foundation quotas (Doc 01 NFR-06): messages/min, AI tokens/day, storage GB,
 * API req/min. Limits live in the settings registry (platform defaults, tenant_editable:
 * false — an operator cannot raise their own ceiling) with per-tenant overrides in
 * tenants.quota_overrides, which only the platform console writes.
 *
 * P-QUOTA-SMS (Doc 14 FR-19, countersigned S14 step 2a): a FIFTH case — monthly SMS
 * segments — keyed by the document's own setting `sms.quota.segments` and bucketed by
 * the TENANT-LOCAL calendar month from DB time (a calendar month is not a fixed second
 * count; `intdiv(time(), window)` cannot express it). The four foundation cases keep
 * their `intdiv` wall-clock buckets byte-identically (QuotaSmsMonthlyTest pins it).
 */
enum Quota: string
{
    case MessagesPerMinute = 'messages_per_minute';
    case AiTokensPerDay = 'ai_tokens_per_day';
    case StorageGb = 'storage_gb';
    case ApiRequestsPerMinute = 'api_requests_per_minute';
    case SmsSegmentsPerMonth = 'sms_segments_per_month';

    /** The registered setting holding this quota's platform default. */
    public function settingKey(): string
    {
        return match ($this) {
            self::SmsSegmentsPerMonth => 'sms.quota.segments',
            default => 'quota.'.$this->value,
        };
    }

    /** P-QUOTA-SMS: a calendar-month quota buckets by the tenant-local YYYY-MM, never by intdiv. */
    public function isCalendarMonth(): bool
    {
        return $this === self::SmsSegmentsPerMonth;
    }

    /** The counter's TTL for a calendar-month bucket: the longest month plus the reconcile margin. */
    public function calendarMonthTtlSeconds(): int
    {
        return 35 * 86_400;
    }

    /**
     * Seconds per counting window — null for cumulative quotas, which have no window
     * to reset and therefore live in the database, not in a TTL'd Redis counter
     * (C-07 makes an eternal cache key impossible, and storage is not a rate).
     */
    public function windowSeconds(): ?int
    {
        return match ($this) {
            self::MessagesPerMinute, self::ApiRequestsPerMinute => 60,
            self::AiTokensPerDay => 86_400,
            // a calendar month has no fixed second count — the meter buckets it by name (isCalendarMonth)
            self::SmsSegmentsPerMonth => 86_400,
            self::StorageGb => null,
        };
    }

    /**
     * What one unit of the LIMIT is worth in the units usage is counted in.
     * storage_gb limits are gigabytes while usage accrues in bytes.
     */
    public function limitUnitMultiplier(): int
    {
        return match ($this) {
            self::StorageGb => 1024 ** 3,
            default => 1,
        };
    }
}

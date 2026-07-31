<?php

declare(strict_types=1);

namespace App\Support\Quota;

/**
 * The four foundation quotas (Doc 01 NFR-06): messages/min, AI tokens/day, storage GB,
 * API req/min. Limits live in the settings registry (platform defaults, tenant_editable:
 * false — an operator cannot raise their own ceiling) with per-tenant overrides in
 * tenants.quota_overrides, which only the platform console writes.
 */
enum Quota: string
{
    case MessagesPerMinute = 'messages_per_minute';
    case AiTokensPerDay = 'ai_tokens_per_day';
    case StorageGb = 'storage_gb';
    case ApiRequestsPerMinute = 'api_requests_per_minute';

    /** The registered setting holding this quota's platform default. */
    public function settingKey(): string
    {
        return 'quota.'.$this->value;
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

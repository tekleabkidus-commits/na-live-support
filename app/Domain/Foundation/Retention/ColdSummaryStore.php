<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Retention;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * BR-05's "count + last message pointer", exactly (Doc 01 BR-05).
 *
 * One atomic upsert per summarised message: the count grows, the pointer is replaced.
 * ON CONFLICT rather than read-then-write for the same reason as everywhere else in
 * this codebase — two workers summarising concurrently must both count.
 *
 * Timestamps are DB now() (EC-08); the tenant comes from the bound context and is
 * additionally enforced by RLS WITH CHECK on the table. There is deliberately no
 * read-the-messages-back API: the messages were never stored, which is the point.
 */
final class ColdSummaryStore
{
    public function __construct(private readonly TenantContext $context) {}

    public function record(string $channel, string $messagePointer): void
    {
        if ($channel === '' || strlen($channel) > 32) {
            throw new InvalidArgumentException('Channel keys are non-empty and at most 32 characters.');
        }

        DB::statement(
            <<<'SQL'
                INSERT INTO tenant_cold_summaries
                    (id, tenant_id, channel, message_count, last_message_pointer, last_message_at, created_at, updated_at)
                VALUES (?, ?, ?, 1, ?, now(), now(), now())
                ON CONFLICT (tenant_id, channel) DO UPDATE SET
                    message_count = tenant_cold_summaries.message_count + 1,
                    last_message_pointer = EXCLUDED.last_message_pointer,
                    last_message_at = now(),
                    updated_at = now()
                SQL,
            [(string) Str::uuid7(), $this->context->tenantId(), $channel, $messagePointer],
        );
    }

    /**
     * The summary for one channel — what §8's console shows a platform owner deciding
     * whether a dead account is worth chasing.
     *
     * @return object{channel: string, message_count: int, last_message_pointer: string|null, last_message_at: string|null}|null
     */
    public function summaryFor(string $channel): ?object
    {
        /** @var object{channel: string, message_count: int, last_message_pointer: string|null, last_message_at: string|null}|null $row */
        $row = DB::table('tenant_cold_summaries')
            ->where('tenant_id', $this->context->tenantId())
            ->where('channel', $channel)
            ->first(['channel', 'message_count', 'last_message_pointer', 'last_message_at']);

        return $row;
    }
}

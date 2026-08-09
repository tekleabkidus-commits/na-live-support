<?php

declare(strict_types=1);

namespace App\Domain\Telegram;

use App\Domain\Conversations\Adapters\NormalizedInbound;
use App\Domain\Conversations\Inbound\InboundAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * T-03/BR-03 (ruling A-27): resolve an owner-phone business_message to its
 * reserved staff identity — or to NULL (the customer), which is the answer
 * for every ordinary inbound.
 *
 * THE AUTHORITY CHECK (A-27 condition 2): agent authorship is granted ONLY
 * when the inbound's sender id matches the connection's REGISTERED
 * account_user_id, compared server-side against telegram_business_connections.
 * The adapter's channel_meta.owner_phone flag is a HINT that gates the lookup;
 * this comparison is the authority. A crafted inbound claiming owner_phone
 * whose from.id is not the registered owner resolves to null → customer (the
 * negative spoof control). Sabotage #20 removes this gate.
 *
 * EC-05: the reserved identity is lazily upserted and its display_name moves
 * FORWARD to the connection's current name; the message snapshots the name it
 * was written under (channel_meta.owner_phone_display), so historical
 * attribution keeps the old display.
 */
final class OwnerPhoneAuthor
{
    public function resolve(NormalizedInbound $inbound): ?InboundAuthor
    {
        $connectionId = $inbound->channelMeta['connection_id'] ?? null;
        $senderId = $inbound->sender->externalUserId;

        if (! is_string($connectionId) || $connectionId === '' || ! ctype_digit($senderId)) {
            return null;
        }

        // Server-side authority: the sender must BE the connection's
        // registered owner. Anything else is the customer.
        $connection = DB::table('telegram_business_connections')
            ->where('connection_id', $connectionId)
            ->where('account_user_id', (int) $senderId)
            ->first(['id', 'account_name', 'account_user_id']);

        if ($connection === null) {
            return null;
        }

        $tenantId = (string) app(\App\Support\Tenancy\TenantContext::class)->tenantId();
        $display = (is_string($connection->account_name) ? $connection->account_name : 'Account').' (phone)';
        $connectionRowId = is_scalar($connection->id) ? (string) $connection->id : '';
        $ownerUserId = is_numeric($connection->account_user_id) ? (int) $connection->account_user_id : 0;

        $identityId = $this->lazyIdentity($tenantId, $connectionRowId, $ownerUserId, $display);

        return new InboundAuthor(authorType: 'agent', authorId: $identityId, reportedAgentType: 'owner_phone', display: $display);
    }

    /** Lazily create the reserved identity; EC-05 forward-rename on the display. */
    private function lazyIdentity(string $tenantId, string $connectionRowId, int $accountUserId, string $display): string
    {
        $existing = DB::table('telegram_owner_phone_identities')
            ->where('connection_id', $connectionRowId)
            ->first(['id']);

        if ($existing !== null) {
            DB::table('telegram_owner_phone_identities')
                ->where('id', $existing->id)
                ->update(['display_name' => $display, 'updated_at' => DB::raw('clock_timestamp()')]);

            return is_scalar($existing->id) ? (string) $existing->id : '';
        }

        $id = (string) Str::uuid7();
        DB::table('telegram_owner_phone_identities')->insert([
            'id' => $id,
            'tenant_id' => $tenantId,
            'connection_id' => $connectionRowId,
            'account_user_id' => $accountUserId,
            'display_name' => $display,
        ]);

        return $id;
    }
}

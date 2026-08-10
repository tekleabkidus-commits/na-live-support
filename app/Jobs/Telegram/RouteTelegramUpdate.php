<?php

declare(strict_types=1);

namespace App\Jobs\Telegram;

use App\Domain\Conversations\Adapters\AdapterRegistry;
use App\Domain\Conversations\Revisions\MessageEditing;
use App\Domain\Telegram\Api\TelegramApi;
use App\Domain\Telegram\Api\TelegramApiError;
use App\Domain\Telegram\Api\TelegramTransportError;
use App\Domain\Telegram\Connections\ConnectionLifecycle;
use App\Domain\Telegram\Direct\CallbackNonce;
use App\Domain\Telegram\Direct\DeepLinkResolver;
use App\Jobs\Conversations\NormalizeInbound;
use App\Models\Message;
use App\Models\TelegramBot;
use App\Support\Events\EventPublisher;
use App\Support\Tenancy\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The adapter-side update router (S23 plan, inherited row 3): one queued hop
 * on `telegram-in` fanning each accepted update to its DOOR. Non-message
 * updates cannot produce a NormalizedInbound — that is WHY this hop exists
 * adapter-side instead of as a core edit.
 *
 * Doors, per flow (the plan's row-3 table made code):
 *  - message / business_message / edited_*  → NormalizeInbound (the Doc 04
 *    inbound discipline; a direct /start with a deep-link payload FIRST
 *    records FR-26 acquisition metadata via DeepLinkResolver (S23 step 12);
 *    edits land on the FR-21 revision path via the persister's
 *    existing-external-id detection — the redelivery-vs-edit guard is Doc
 *    04's and is re-proven, not rebuilt).
 *  - deleted_business_messages → MessageEditing::customerDeleted per id
 *    (FR-12 tombstones through the existing revision door).
 *  - callback_query → SEC-06 first: nonce validated single-use, ACK via
 *    answerCallbackQuery within its 1 s budget (FR-14) — first act of this
 *    job; invalid/replayed answers the neutral toast and logs. Flow-target
 *    EXECUTION is Docs 09/10/12's (pre-agreed bucket); the designer arrives
 *    at step 9.
 *  - my_chat_member → FR-15: telegram.bot_blocked|unblocked through the
 *    outbox (closed payloads).
 *  - business_connection → the FR-07…10 lifecycle machinery (S23 step 6);
 *    logged-and-held until that step lands. Dev has no public ingress
 *    (A-24), so no real update can reach the gap.
 *
 * OWNER-PHONE DETECTION (T-03/FR-11): a business_message whose from.id
 * equals the connection's account_user_id is the owner replying from the
 * phone — marked on the envelope; the persist-side attribution contract gap
 * is escalated at the step-5 checkpoint.
 */
final class RouteTelegramUpdate implements ShouldQueue
{
    use Dispatchable, Queueable, TenantAware;

    /** @param array<string, mixed> $update */
    public function __construct(
        public readonly string $botId,
        public readonly array $update,
    ) {
        $this->captureTenant();
        $this->onQueue('telegram-in');
    }

    public function handle(
        AdapterRegistry $registry,
        MessageEditing $editing,
        CallbackNonce $nonces,
        TelegramApi $api,
        EventPublisher $events,
        ConnectionLifecycle $connections,
        DeepLinkResolver $deepLinks,
    ): void {
        $update = $this->update;

        // FS-02's no-inbound heartbeat: ANY accepted update proves Telegram
        // can reach us. Pinned provenance (clock_timestamp() in the UPDATE —
        // the plan's last_inbound_at ruling); job latency is seconds against
        // a minutes-scale threshold, stated in the migration docblock.
        \Illuminate\Support\Facades\DB::update(
            'UPDATE telegram_bots SET last_inbound_at = clock_timestamp() WHERE id = ?',
            [$this->botId],
        );

        // business_connection is the lifecycle's input (FR-07…10) — handled
        // BEFORE messages so a same-batch connect is applied first.
        if (is_array($update['business_connection'] ?? null)) {
            /** @var array<string, mixed> $connection */
            $connection = $update['business_connection'];
            $connections->applyUpdate($this->botId, $connection);

            return;
        }

        if (isset($update['message']) || isset($update['business_message'])
            || isset($update['edited_message']) || isset($update['edited_business_message'])) {
            // SEC-04 NEVER-ROUTES: a business message on a connection that is
            // not ACTIVE (pending/unconfirmed, or gone) is NOT routed — the
            // attacker-connects-their-account defence. Direct messages (no
            // connection id) are unaffected.
            if (! $this->connectionRoutable($update)) {
                Log::info('telegram business message held: connection not active', [
                    'metric' => 'telegram.connection.unrouted_hold',
                    'bot_id' => $this->botId,
                    'update_id' => $update['update_id'] ?? null,
                ]);

                return;
            }

            // FR-26: a DIRECT /start with a deep-link payload records
            // acquisition metadata (first-wins, write-once). Adapter-side —
            // keyed by the conversation's own (bot, chat) identity, so it
            // needs no ordering against the Doc 04 persist. Unknown payloads
            // parse to null and the message proceeds untouched.
            $this->recordDeepLink($update, $deepLinks);

            NormalizeInbound::dispatch('telegram', $this->botId, [
                'bot' => $this->botId,
                'update' => $update,
                'owner_phone' => $this->isOwnerPhone($update),
            ])->onQueue($registry->inboundFamily('telegram'));

            return;
        }

        if (is_array($update['deleted_business_messages'] ?? null)) {
            $this->tombstone($update['deleted_business_messages'], $editing);

            return;
        }

        if (is_array($update['callback_query'] ?? null)) {
            $this->callback($update['callback_query'], $nonces, $api);

            return;
        }

        if (is_array($update['my_chat_member'] ?? null)) {
            $this->chatMember($update['my_chat_member'], $events);

            return;
        }

        // Anything else: counted, never silently invisible. Type only — no
        // payload content (SEC-03/T-07).
        Log::info('telegram update type not handled', [
            'bot_id' => $this->botId,
            'update_type' => 'unknown',
            'update_id' => $update['update_id'] ?? null,
        ]);
    }

    /**
     * FR-26: parse a direct message's text as a deep-linked /start and
     * record the acquisition. Business messages never carry deep links
     * (t.me/{bot}?start targets the bot chat), so only the plain `message`
     * line is inspected.
     *
     * @param  array<string, mixed>  $update
     */
    private function recordDeepLink(array $update, DeepLinkResolver $deepLinks): void
    {
        $message = is_array($update['message'] ?? null) ? $update['message'] : null;
        if ($message === null) {
            return;
        }

        $text = $message['text'] ?? null;
        $chatId = is_array($message['chat'] ?? null) ? ($message['chat']['id'] ?? null) : null;
        if (! is_string($text) || ! is_scalar($chatId)) {
            return;
        }

        $acquisition = $deepLinks->parse($text);
        if ($acquisition === null) {
            return;
        }

        $deepLinks->record($this->botId, (string) $chatId, $acquisition['source'], $acquisition['context']);
    }

    /**
     * SEC-04: a business message routes ONLY when its connection is active.
     * A direct message (no business_connection_id) always routes.
     *
     * @param  array<string, mixed>  $update
     */
    private function connectionRoutable(array $update): bool
    {
        $message = is_array($update['business_message'] ?? null) ? $update['business_message']
            : (is_array($update['edited_business_message'] ?? null) ? $update['edited_business_message'] : null);

        if ($message === null) {
            return true; // direct message — no connection gate
        }

        $connectionId = $message['business_connection_id'] ?? null;
        if (! is_string($connectionId) || $connectionId === '') {
            return true;
        }

        return \Illuminate\Support\Facades\DB::table('telegram_business_connections')
            ->where('bot_id', $this->botId)
            ->where('connection_id', $connectionId)
            ->where('state', 'active')
            ->exists();
    }

    /** @param array<string, mixed> $update */
    private function isOwnerPhone(array $update): bool
    {
        $message = is_array($update['business_message'] ?? null) ? $update['business_message']
            : (is_array($update['edited_business_message'] ?? null) ? $update['edited_business_message'] : null);

        if ($message === null) {
            return false;
        }

        $connectionId = $message['business_connection_id'] ?? null;
        $fromId = is_array($message['from'] ?? null) ? ($message['from']['id'] ?? null) : null;

        if (! is_string($connectionId) || ! is_int($fromId)) {
            return false;
        }

        return \Illuminate\Support\Facades\DB::table('telegram_business_connections')
            ->where('bot_id', $this->botId)
            ->where('connection_id', $connectionId)
            ->where('account_user_id', $fromId)
            ->exists();
    }

    /** @param array<array-key, mixed> $payload */
    private function tombstone(array $payload, MessageEditing $editing): void
    {
        $ids = is_array($payload['message_ids'] ?? null) ? $payload['message_ids'] : [];

        foreach ($ids as $externalId) {
            if (! is_scalar($externalId)) {
                continue;
            }
            $message = Message::query()
                ->where('external_message_id', (string) $externalId)
                ->where('direction', 'in')
                ->first();

            if ($message !== null && $message->getAttribute('deleted_at') === null) {
                $editing->customerDeleted($message); // FR-12: the existing door
            }
        }
    }

    /** @param array<array-key, mixed> $callback */
    private function callback(array $callback, CallbackNonce $nonces, TelegramApi $api): void
    {
        $callbackId = is_string($callback['id'] ?? null) ? $callback['id'] : '';
        $data = is_string($callback['data'] ?? null) ? $callback['data'] : '';

        $verdict = $nonces->validate($this->botId, $data, time());

        // FR-14: the ACK is this job's FIRST outward act either way; an
        // invalid/replayed callback gets the NEUTRAL toast (SEC-06 — the
        // answer leaks nothing about why).
        $bot = TelegramBot::query()->find($this->botId);
        $token = $bot?->bot_token;
        if (is_string($token) && $callbackId !== '') {
            try {
                $api->call($token, 'answerCallbackQuery', $verdict['valid']
                    ? ['callback_query_id' => $callbackId]
                    : ['callback_query_id' => $callbackId, 'text' => __('telegram.callback.neutral_toast')]);
            } catch (TelegramApiError|TelegramTransportError) {
                // Best-effort ACK: the 1 s budget means we never retry-loop here.
            }
        }

        if (! $verdict['valid']) {
            Log::info('telegram callback refused', [
                'metric' => 'telegram.callback.rejected',
                'reason' => $verdict['reason'],
                'bot_id' => $this->botId,
            ]);

            return;
        }

        // Valid: the flow target resolves through the indirection table when
        // the designer lands (step 9); until then the acceptance is logged.
        Log::info('telegram callback accepted', [
            'bot_id' => $this->botId,
            'flow_key' => $verdict['flow_key'],
        ]);
    }

    /** @param array<array-key, mixed> $member */
    private function chatMember(array $member, EventPublisher $events): void
    {
        $status = is_array($member['new_chat_member'] ?? null)
            ? ($member['new_chat_member']['status'] ?? null)
            : null;
        $chat = is_array($member['chat'] ?? null) ? $member['chat'] : [];

        if (! in_array($status, ['kicked', 'member'], true)) {
            return; // administrator/left etc. — not FR-15's blocked/unblocked pair
        }

        $events->publish(
            $status === 'kicked' ? 'telegram.bot_blocked' : 'telegram.bot_unblocked',
            [
                'tenant_id' => $this->tenantId,
                'bot_id' => $this->botId,
                'external_chat_id' => is_scalar($chat['id'] ?? null) ? (string) $chat['id'] : '',
            ],
        );
    }
}

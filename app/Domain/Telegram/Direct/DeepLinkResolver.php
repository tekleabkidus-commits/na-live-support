<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Direct;

use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-26 deep links: t.me/{bot}?start={payload}, payload schema
 * `src-{source}[_ctx-{context}]` (e.g. src-app_ctx-deposit) → stored as
 * acquisition metadata for the conversation and read by Doc 09's first turn
 * (the context-aware greeting — that half is Doc 09's, pre-agreed bucket).
 * Unknown payloads are ignored GRACEFULLY: parse() answers null and the
 * message proceeds as a plain /start — never an error to the customer.
 *
 * SEC-05 — deep-link payloads are UNTRUSTED INPUT, enforced here:
 * - length-capped (Telegram's own 64-char start-payload cap, asserted
 *   BEFORE the regex runs so an oversized payload never reaches it);
 * - schema-validated (the closed `src-…[_ctx-…]` token grammar — lowercase
 *   alphanumerics and hyphens only);
 * - never executed as flow ids: there is NO code path from a payload to
 *   the flow-target indirection surface — the tokens are inert strings
 *   stored for attribution, and the test asserts this structurally (the
 *   source-scan means this class must never name that table).
 *
 * Storage is FIRST-WINS and write-once (the migration revokes UPDATE and
 * DELETE): acquisition answers where the player came FROM; a later /start
 * cannot rewrite it. record() is idempotent on redelivery (C-09) via
 * ON CONFLICT DO NOTHING on the identity unique.
 */
final class DeepLinkResolver
{
    /** Telegram's own start-payload cap; the SEC-05 length cap. */
    public const MAX_PAYLOAD_LENGTH = 64;

    private const SCHEMA = '/^src-([a-z0-9][a-z0-9-]{0,31})(?:_ctx-([a-z0-9][a-z0-9-]{0,31}))?$/';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Parse a message text as a /start deep link. Answers null for anything
     * that is not a well-formed deep-linked /start — including a bare
     * /start, an oversized payload, and a payload outside the schema
     * (FR-26: "unknown payloads ignored gracefully").
     *
     * @return array{source: string, context: string|null}|null
     */
    public function parse(string $text): ?array
    {
        if (preg_match('~^/start(?:@[A-Za-z0-9_]+)?\s+(\S+)\s*$~', $text, $m) !== 1) {
            return null;
        }

        $payload = $m[1];
        if (strlen($payload) > self::MAX_PAYLOAD_LENGTH) {
            return null; // SEC-05 cap — checked before the grammar
        }

        if (preg_match(self::SCHEMA, $payload, $parts) !== 1) {
            return null;
        }

        return [
            'source' => $parts[1],
            'context' => $parts[2] ?? null,
        ];
    }

    /**
     * Record an acquisition for the conversation identified by (bot,
     * external chat). First-wins: a conflict on the identity unique is a
     * no-op (redelivery-safe per C-09, and a second /start never rewrites
     * the original attribution).
     */
    public function record(string $botId, string $externalChatId, string $source, ?string $context): void
    {
        DB::statement(
            'INSERT INTO telegram_acquisitions (id, tenant_id, bot_id, external_chat_id, source, context)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, bot_id, external_chat_id) DO NOTHING',
            [(string) Str::uuid7(), $this->context->tenantId(), $botId, $externalChatId, $source, $context],
        );
    }

    /**
     * The Doc 09 read seam: a conversation's acquisition metadata, resolved
     * through the conversation's own channel identity.
     *
     * @return array{source: string, context: string|null}|null
     */
    public function forConversation(string $conversationId): ?array
    {
        $conversation = DB::table('conversations')
            ->where('id', $conversationId)
            ->where('channel', 'telegram')
            ->first(['channel_ref_id', 'external_chat_id']);

        if ($conversation === null || ! is_string($conversation->channel_ref_id) || ! is_string($conversation->external_chat_id)) {
            return null;
        }

        $row = DB::table('telegram_acquisitions')
            ->where('bot_id', $conversation->channel_ref_id)
            ->where('external_chat_id', $conversation->external_chat_id)
            ->first(['source', 'context']);

        if ($row === null || ! is_string($row->source)) {
            return null;
        }

        return [
            'source' => $row->source,
            'context' => is_string($row->context) && $row->context !== '' ? $row->context : null,
        ];
    }
}

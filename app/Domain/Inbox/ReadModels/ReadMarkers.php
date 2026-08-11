<?php

declare(strict_types=1);

namespace App\Domain\Inbox\ReadModels;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The REAL marker writer (S07 step 9 — SCHEMA AMENDMENT 1 rider (i)'s
 * subject): one marker per (user, conversation); unread boundary = messages
 * with created_at AFTER the marker.
 *
 * THE BYTE-COPY DISCIPLINE (R-19b, the amendment's filed analysis):
 * `last_read_at` is a PINNED-PROVENANCE COPY of the last-read message's own
 * DB-time `messages.created_at` — the unread comparison runs in
 * message-stamp space, so a fresh clock read here would shift the unread
 * boundary by clock skew. The copy is made INSIDE SQL (INSERT … SELECT from
 * the message row — the OutboxRelay idiom): the value never round-trips
 * through PHP, so there is nothing to reformat. The rider's byte-equality
 * proof reads both sides back as text and compares them verbatim.
 *
 * LWW on the viewer's own marker (the amendment's updated_at note) — but
 * never BACKWARD: marking an older message read after a newer one would
 * resurrect unread rows the viewer already cleared, so the update guards
 * on last_read_at.
 */
final class ReadMarkers
{
    public function __construct(private readonly TenantContext $context) {}

    /** Marks $messageId (which must belong to $conversationId) read for the user. */
    public function markRead(User $user, string $conversationId, string $messageId): void
    {
        $affected = DB::affectingStatement(
            <<<'SQL'
            INSERT INTO inbox_read_markers (id, tenant_id, user_id, conversation_id, last_read_message_id, last_read_at, updated_at)
            SELECT ?, ?, ?, m.conversation_id, m.id, m.created_at, clock_timestamp()
            FROM messages m
            WHERE m.id = ? AND m.conversation_id = ?
            ON CONFLICT (tenant_id, user_id, conversation_id) DO UPDATE
            SET last_read_message_id = EXCLUDED.last_read_message_id,
                last_read_at = EXCLUDED.last_read_at,
                updated_at = clock_timestamp()
            WHERE inbox_read_markers.last_read_at IS NULL
               OR EXCLUDED.last_read_at >= inbox_read_markers.last_read_at
            SQL,
            [(string) Str::uuid7(), $this->context->tenantId(), $user->identifier(), $messageId, $conversationId],
        );

        // Zero rows: the message does not exist in that conversation (C-10:
        // the answer never distinguishes which half was wrong). A backward
        // mark also lands here — deliberately a no-op, not an error the UI
        // must handle.
        if ($affected === 0 && ! DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->exists()) {
            throw new DomainException('message_not_found');
        }
    }

    /**
     * The per-viewer hydration overlay (the amendment's design): unread
     * counts + boundaries + the viewer's live drafts for a page of
     * conversations, in TWO grouped queries — never per-row N+1.
     *
     * Unread = non-internal, non-deleted messages stamped AFTER the marker
     * (all of them when no marker exists), excluding the viewer's own.
     *
     * @param  list<string>  $conversationIds
     * @return array{unread: array<string, int>, drafts: array<string, bool>}
     */
    public function overlay(User $user, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return ['unread' => [], 'drafts' => []];
        }

        $placeholders = implode(',', array_fill(0, count($conversationIds), '?'));

        /** @var list<object{conversation_id: string, unread: int|string}> $unreadRows */
        $unreadRows = DB::select(
            <<<SQL
            SELECT m.conversation_id, count(*) AS unread
            FROM messages m
            LEFT JOIN inbox_read_markers r
                   ON r.conversation_id = m.conversation_id AND r.user_id = ?
            WHERE m.conversation_id IN ({$placeholders})
              AND m.is_internal = false
              AND m.deleted_at IS NULL
              AND NOT (m.author_type = 'staff' AND m.author_id = ?)
              AND (r.last_read_at IS NULL OR m.created_at > r.last_read_at)
            GROUP BY m.conversation_id
            SQL,
            [$user->identifier(), ...$conversationIds, $user->identifier()],
        );

        /** @var list<string> $draftRows */
        $draftRows = DB::table('message_drafts')
            ->where('user_id', $user->identifier())
            ->whereIn('conversation_id', $conversationIds)
            ->whereNull('cleared_at')
            ->pluck('conversation_id')
            ->all();

        $unread = [];
        foreach ($unreadRows as $row) {
            $unread[$row->conversation_id] = (int) $row->unread;
        }

        return ['unread' => $unread, 'drafts' => array_fill_keys($draftRows, true)];
    }
}

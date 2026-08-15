<?php

declare(strict_types=1);

namespace App\Domain\Customers\Panel;

use App\Domain\Conversations\Media\MediaStore;
use Illuminate\Support\Facades\DB;

/**
 * FR-19: all media the customer ever SENT (inbound direction — Doc 04's
 * message media, the jsonb array on messages), filterable by kind / date /
 * ticket, served through Doc 04's OWN MediaStore::signedUrl — the serve half
 * of the avatar-media seam (closing row 4a). Infected/held items list with
 * their scan_status (the UI renders the block); the URL door REFUSES
 * non-clean through MediaStore's own guard — no second implementation, no
 * role exception (Doc 04 SEC-04).
 *
 * The ticket filter resolves to the ticket's CONVERSATION (v1 — the honest
 * granularity: message-anchor precision joins when a consumer needs it).
 *
 * C-05: cursor pagination over (created_at, id) of the carrying message;
 * items flatten per media index.
 */
final class FilesGallery
{
    public function __construct(private readonly MediaStore $store) {}

    /**
     * @param  array{kind?: string, from?: string, to?: string, ticket_id?: string}  $filters
     * @return array{items: list<array<string, mixed>>, next_cursor: ?string}
     */
    public function listFor(string $customerId, array $filters = [], ?string $cursor = null, int $limit = 50): array
    {
        $limit = min(max($limit, 1), 100);

        $query = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.customer_id', $customerId)
            ->where('messages.direction', 'in')
            ->whereRaw('jsonb_array_length(messages.media) > 0');

        if (isset($filters['ticket_id'])) {
            $conversationId = DB::table('tickets')->where('id', $filters['ticket_id'])->value('conversation_id');
            // C-10 posture: an unknown/cross-tenant ticket filters to nothing.
            $query->where('messages.conversation_id', $conversationId ?? '00000000-0000-0000-0000-000000000000');
        }
        if (isset($filters['from'])) {
            $query->where('messages.created_at', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('messages.created_at', '<=', $filters['to']);
        }

        if ($cursor !== null && str_contains($cursor, '|')) {
            [$cursorAt, $cursorId] = explode('|', $cursor, 2);
            $query->whereRaw('(messages.created_at, messages.id) < (?::timestamptz, ?::uuid)', [$cursorAt, $cursorId]);
        }

        /** @var list<object{id: string, media: string, created_at: string, conversation_id: string}> $rows */
        $rows = $query->orderByDesc('messages.created_at')->orderByDesc('messages.id')
            ->limit($limit)
            ->get([
                'messages.id', 'messages.media',
                DB::raw('messages.created_at::text AS created_at'),
                'messages.conversation_id',
            ])->all();

        $items = [];
        foreach ($rows as $row) {
            /** @var list<array<string, mixed>> $media */
            $media = (array) json_decode($row->media, true);
            foreach ($media as $index => $item) {
                $kind = is_string($item['kind'] ?? null) ? $item['kind'] : 'file';
                if (isset($filters['kind']) && $filters['kind'] !== $kind) {
                    continue;
                }
                // The curated item allowlist — never the raw jsonb (no
                // source_url: an origin-channel URL is not ours to re-serve).
                $items[] = [
                    'message_id' => $row->id,
                    'media_index' => $index,
                    'conversation_id' => $row->conversation_id,
                    'kind' => $kind,
                    'mime' => $item['mime'] ?? null,
                    'bytes' => $item['bytes'] ?? null,
                    'alt_text' => $item['alt_text'] ?? null,
                    'scan_status' => $item['scan_status'] ?? 'pending',
                    'created_at' => $row->created_at,
                ];
            }
        }

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'items' => $items,
            'next_cursor' => count($rows) === $limit && $last !== null ? $last->created_at.'|'.$last->id : null,
        ];
    }

    /**
     * A fresh signed URL for ONE item — through Doc 04's own guard (clean
     * only; a non-clean item throws, the door renders the refusal).
     */
    public function signedUrlFor(string $customerId, string $messageId, int $index): string
    {
        /** @var object{media: string}|null $row */
        $row = DB::table('messages')
            ->join('conversations', 'conversations.id', '=', 'messages.conversation_id')
            ->where('conversations.customer_id', $customerId)
            ->where('messages.id', $messageId)
            ->first(['messages.media']);

        if ($row === null) {
            throw new \DomainException('media_not_found'); // C-10: wrong customer pairing = missing
        }

        /** @var list<array<string, mixed>> $media */
        $media = (array) json_decode($row->media, true);
        $item = $media[$index] ?? null;
        if (! is_array($item) || ! is_string($item['storage_key'] ?? null)) {
            throw new \DomainException('media_not_found');
        }

        return $this->store->signedUrl($item['storage_key'], is_string($item['scan_status'] ?? null) ? $item['scan_status'] : 'pending');
    }
}

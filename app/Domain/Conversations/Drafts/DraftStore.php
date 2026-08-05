<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Drafts;

use App\Models\Conversation;
use App\Models\MessageDraft;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-20/T-07: the SERVER half of drafts — one row per (user, conversation),
 * last-writer-wins with the updated_at guard, cleared on send, restored after
 * re-login (the row simply persists; Doc 02's session lifecycle never touches
 * it). The local-IndexedDB half and its 2 s cadence live in the inbox app
 * (Doc 07); the 10 s server cadence is the CALLER's rhythm — this store is
 * every-call durable.
 *
 * EC-06: a device saving over a NEWER draft (its updated_at guard is stale)
 * LOSES: the row stays, the save reports the conflict, and the loser's UI shows
 * the "draft updated on another device" toast (i18n key messaging.draft.*; the
 * rendering is Doc 07's). Timestamps compare in DB time (C-11).
 *
 * draft.saved is LOCAL ONLY (FR-26): nothing here touches the outbox, and the
 * events test asserts the name is absent from the catalog.
 */
final class DraftStore
{
    /**
     * @return array{draft: MessageDraft, conflict: bool}
     */
    public function save(User $user, Conversation $conversation, ?string $body, ?CarbonImmutable $expectedUpdatedAt): array
    {
        return DB::transaction(function () use ($user, $conversation, $body, $expectedUpdatedAt): array {
            $existing = MessageDraft::query()
                ->where('user_id', $user->identifier())
                ->where('conversation_id', $conversation->identifier())
                ->lockForUpdate()
                ->first();

            if (! $existing instanceof MessageDraft) {
                $draft = MessageDraft::query()->create([
                    'id' => (string) Str::uuid7(),
                    'user_id' => $user->identifier(),
                    'conversation_id' => $conversation->identifier(),
                    'body' => $body,
                ]);

                return ['draft' => $draft, 'conflict' => false];
            }

            // EC-06: the guard — a writer holding a STALE updated_at is the
            // loser; the newer draft stands and the caller shows the toast.
            $current = $existing->getAttribute('updated_at');

            if ($expectedUpdatedAt !== null && $current instanceof \DateTimeInterface
                && CarbonImmutable::instance($current)->greaterThan($expectedUpdatedAt)) {
                return ['draft' => $existing, 'conflict' => true];
            }

            $existing->fill(['body' => $body, 'cleared_at' => null])->save();

            return ['draft' => $existing, 'conflict' => false];
        });
    }

    public function for(User $user, Conversation $conversation): ?MessageDraft
    {
        $draft = MessageDraft::query()
            ->where('user_id', $user->identifier())
            ->where('conversation_id', $conversation->identifier())
            ->whereNull('cleared_at')
            ->first();

        return $draft instanceof MessageDraft ? $draft : null;
    }

    /** FR-20: cleared on send — a tombstone, not a delete (the table REVOKEs DELETE). */
    public function clearOnSend(User $user, Conversation $conversation): void
    {
        MessageDraft::query()
            ->where('user_id', $user->identifier())
            ->where('conversation_id', $conversation->identifier())
            ->update(['body' => null, 'cleared_at' => DB::raw('now()')]);
    }
}

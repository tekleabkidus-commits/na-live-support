<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Realtime;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * T-06: the conversation.{id} subscribe callback enforces Doc 03 scope — the
 * inherited row 2 this session closes. Order: permission verdict, then object
 * scope, and every refusal is the same silent false (C-10's posture at the
 * socket layer: a denied subscriber learns nothing about why).
 *
 * PRE-DOC-06 SCOPE DEGRADATION, deliberate and fail-closed (NFR-04): the
 * conversations table carries no assignment columns yet (owner/team/dept
 * arrive with Doc 06's ticketing), so a role scoped Own/Team/Dept has an EMPTY
 * conversation scope set here — only All-scoped roles can subscribe today.
 * Doc 06 re-proves this gate against real assignment columns (recorded in the
 * close-out traceability); loosening it to "everything visible" would be the
 * exact open-fall Doc 03's NFR-04 sabotage exists to catch.
 */
final class ConversationChannelGate
{
    public function __construct(private readonly CapabilityMapProvider $verdicts) {}

    /** @return array{id: string, name: string}|false presence member info, or refusal */
    public function authorize(User $user, string $conversationId): array|false
    {
        if (! $this->verdicts->verdictFor($user, 'conversations.view')->allowed) {
            return false;
        }

        if ($this->verdicts->mapFor($user)->scopeFor('conversations') !== 'All') {
            return false; // pre-Doc 06 degradation — see the class docblock
        }

        if (! Conversation::query()->whereKey($conversationId)->exists()) {
            return false; // unknown and foreign look identical (C-10)
        }

        $name = $user->getAttribute('name');

        return [
            'id' => $user->identifier(),
            'name' => is_string($name) ? $name : '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Inbox\Realtime;

use App\Models\User;
use App\Support\Rbac\CapabilityMapProvider;

/**
 * SEC-01 on subscribe (the ConversationChannelGate sibling): a view channel
 * admits a viewer only when the presented hash equals one THIS viewer's
 * CURRENT capabilities derive — tickets.view first (FR-06's workspace entry
 * permission), then re-derivation across the system views. A hash minted
 * under old scope stops matching the moment Doc 03 FR-07 changes the
 * resolution, which is what forces the resubscribe with pruned membership.
 * Every refusal is the same false — the socket layer explains nothing (C-10).
 *
 * SABOTAGE #12's TARGET: skip the re-derivation (accept any presented hash)
 * and the SEC-01 tests in ViewChannelAuthTest go red.
 */
final class ViewChannelGate
{
    public function __construct(
        private readonly CapabilityMapProvider $verdicts,
        private readonly ViewHash $hashes,
    ) {}

    public function authorize(User $user, string $viewHash): bool
    {
        if (! $this->verdicts->verdictFor($user, 'tickets.view')->allowed) {
            return false;
        }

        foreach ([...ViewHash::PER_USER_VIEWS, ...ViewHash::SHARED_VIEWS] as $view) {
            if (hash_equals($this->hashes->forViewer($view, $user), $viewHash)) {
                return true;
            }
        }

        return false;
    }

    /** The workspace topbar channel (FR-03/FR-26): any tickets.view holder of the tenant. */
    public function authorizeInbox(User $user): bool
    {
        return $this->verdicts->verdictFor($user, 'tickets.view')->allowed;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Conversations\Identity;

use App\Domain\Conversations\Adapters\InboundSender;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Doc 08 identity-resolver SEAM (Doc 04 FR-10 step 3, out-of-scope rule).
 *
 * Doc 04's whole contract with identity is one call: sender → customer_id,
 * existing or shell. DOC 08 OWNS EVERYTHING RICHER — merge rules, shell-customer
 * semantics beyond match-or-create, profile fields, dedupe across channels. This
 * class must stay exactly this small; a Doc 04 session extending it is scope creep
 * by definition (session mandate §SCOPE).
 *
 * Every stored field is channel-reported or staff-collected, never `verified`
 * (invariant N-06 — the customers migration carries the same note).
 *
 * Race-safety: the (tenant, channel, external_user_id) unique index arbitrates
 * concurrent first-contact. The create runs in a NESTED transaction (savepoint):
 * without it, the caught unique violation would poison the caller's enclosing
 * persist transaction — Postgres refuses every later statement in an aborted
 * transaction, so the catch would "handle" the race and then crash the persist.
 * The savepoint also takes the orphan shell customer down with the rolled-back
 * identity row.
 */
final class CustomerResolver
{
    public function resolve(string $channel, InboundSender $sender): string
    {
        $existing = CustomerIdentity::query()
            ->where('channel', $channel)
            ->where('external_user_id', $sender->externalUserId)
            ->first();

        if ($existing instanceof CustomerIdentity) {
            return $existing->customer_id;
        }

        try {
            return DB::transaction(function () use ($channel, $sender): string {
                $customer = Customer::query()->create([
                    'display_name' => $sender->display,
                    'username' => $sender->username,
                    'phone' => $sender->phone,
                    'is_shell' => true,
                ]);

                CustomerIdentity::query()->create([
                    'customer_id' => $customer->identifier(),
                    'channel' => $channel,
                    'external_user_id' => $sender->externalUserId,
                ]);

                return $customer->identifier();
            });
        } catch (UniqueConstraintViolationException) {
            // The concurrent first-contact won; adopt their customer.
            $winner = CustomerIdentity::query()
                ->where('channel', $channel)
                ->where('external_user_id', $sender->externalUserId)
                ->first();

            if (! $winner instanceof CustomerIdentity) {
                throw new RuntimeException('unique violation on customer identity but no winner row visible — investigate RLS context.');
            }

            return $winner->customer_id;
        }
    }
}

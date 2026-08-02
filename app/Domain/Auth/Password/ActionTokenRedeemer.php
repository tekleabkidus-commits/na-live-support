<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Models\AuthActionToken;
use App\Models\User;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Answers "may this presented token act?" and spends it (Doc 02 FR-16, FR-17,
 * SEC-01, SEC-02).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE VERDICT FOR EVERY KIND OF DEAD. Unknown, expired, consumed, superseded,
 * wrong-purpose, email-changed, tenant-not-active — resolve() answers null for
 * all of them, and the caller renders ONE dead-link screen. Distinguishing them
 * to the presenter would leak: "expired" confirms the token was real, which
 * confirms the request that minted it, which confirms the account (SEC-01).
 * The distinctions the operator legitimately needs live in the audit trail and
 * the token rows, both behind authentication.
 *
 * THE EMAIL BINDING IS COMPARED HERE, NOT LOOKED UP BY. SEC-02 binds a token to
 * the address it was SENT to. The row is found by hash alone (the presenter has
 * nothing else); then, with the tenant bound, the account's CURRENT email is
 * compared against `sent_to_email` — so changing the account's email kills
 * every outstanding link for it, which is exactly the property SEC-02 wants
 * when "someone changed the email" is the incident in progress.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ActionTokenRedeemer
{
    use RunsWithinTenant;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Validate a presented plaintext against every SEC-02 gate.
     *
     * Returns the redemption facts if the token may act, null otherwise —
     * with no observable reason for the refusal (SEC-01).
     */
    public function resolve(string $plaintext, TokenPurpose $expected): ?ActionTokenRedemption
    {
        // 64 hex chars is the only shape issue() ever produces. Refusing other
        // shapes before hashing is not a secrecy measure (the lookup would miss
        // anyway) — it keeps garbage out of the query log and makes the
        // door's zero-rows answer mean one thing.
        if (preg_match('/^[0-9a-f]{64}$/', $plaintext) !== 1) {
            return null;
        }

        /** @var list<object> $rows */
        $rows = DB::select(
            'SELECT * FROM resolve_auth_action_token(?)',
            [hash('sha256', $plaintext)],
        );

        if ($rows === []) {
            return null;
        }

        $redemption = ActionTokenRedemption::fromRow($rows[0]);

        if (! $redemption->isLive($expected)) {
            return null;
        }

        // The email binding, compared under the token's own tenant. The user
        // read happens through the ordinary scoped path — the door answered
        // the pre-tenant question already.
        $bound = $this->withinTenant($redemption->tenantId, function () use ($redemption): bool {
            $user = User::query()->find($redemption->userId);

            if ($user === null || $user->isDisabled()) {
                return false;
            }

            $current = mb_strtolower(trim((string) $user->getAttribute('email')));

            return $current === mb_strtolower(trim($redemption->sentToEmail));
        }, $this->context);

        return $bound ? $redemption : null;
    }

    /**
     * Spend the token — after this it is dead to every future presenter.
     *
     * Runs as PasswordChanger's `oncePolicyPassed` gate: after the new
     * password validated, before anything mutates. The `whereNull` makes the
     * stamp a compare-and-set — two concurrent redemptions of one token race
     * on it, exactly one sees an affected row, and the loser's return of
     * false aborts its mutation entirely (single-use under concurrency, by
     * the same UPDATE that enforces it sequentially).
     */
    public function consume(ActionTokenRedemption $redemption, CarbonImmutable $now): bool
    {
        return $this->withinTenant($redemption->tenantId, function () use ($redemption, $now): bool {
            $affected = AuthActionToken::query()
                ->whereKey($redemption->tokenId)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => $now]);

            return $affected === 1;
        }, $this->context);
    }
}

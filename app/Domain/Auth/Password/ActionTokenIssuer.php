<?php

declare(strict_types=1);

namespace App\Domain\Auth\Password;

use App\Models\AuthActionToken;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Mints reset and invite tokens (Doc 02 FR-16, FR-17, SEC-02, EC-04).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE PLAINTEXT EXISTS FOR ONE RETURN VALUE. issue() generates 32 random bytes
 * (SEC-02's 256 bits), stores their SHA-256 and returns the hex plaintext to
 * exactly one caller — the one about to put it in an email. Nothing else ever
 * sees it: not the audit trail, not the logs (SEC-08), not the database, which
 * has no column that could hold it.
 *
 * SUPERSEDE-THEN-INSERT, IN THAT ORDER, AND THE INDEX ENFORCES IT. EC-04 says a
 * resend invalidates the previous link. The partial unique index
 * (`auth_action_tokens_one_live_per_purpose`) allows at most one unconsumed
 * token per (user, purpose), so this class stamps `consumed_at` on any live
 * token before inserting the new one — and if a future edit "simplifies" the
 * stamping away, the INSERT itself fails on the index rather than leaving two
 * live links. The invalidation is physics; this class merely cooperates.
 *
 * `consumed_at` on a superseded token means "dead", not "redeemed": the
 * distinction is visible in the trail (a superseded token has a successor row;
 * a redeemed one is the newest of its purpose) and invisible to the presenter,
 * who gets the same dead-link screen either way — indistinguishability is
 * SEC-01's shape.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class ActionTokenIssuer
{
    use RunsWithinTenant;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Mint a token for one account, superseding any live one of the same
     * purpose. Binds the account's tenant for the writes — issuance happens on
     * pre-tenant paths (a reset request has no session), and the rows live
     * behind RLS in the account's own tenant.
     *
     * @return string the plaintext token — put it in the email, then forget it
     */
    public function issue(
        string $tenantId,
        string $userId,
        TokenPurpose $purpose,
        string $sentToEmail,
        CarbonImmutable $now,
        CarbonImmutable $expiresAt,
        ?Request $request = null,
    ): string {
        $plaintext = bin2hex(random_bytes(32));

        $this->withinTenant($tenantId, function () use ($userId, $purpose, $sentToEmail, $now, $expiresAt, $request, $plaintext): void {
            AuthActionToken::query()
                ->where('user_id', $userId)
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->get()
                ->each(function (AuthActionToken $stale) use ($now): void {
                    // The exact instant is irrelevant (any non-null is dead) and
                    // the clock is the caller's (CLAUDE.md §8). Ordering
                    // decisions stay on UUIDv7 ids (C-11), never on this stamp.
                    $stale->consumed_at = $now;
                    $stale->save();
                });

            AuthActionToken::query()->create([
                'user_id' => $userId,
                'purpose' => $purpose->value,
                'token_hash' => hash('sha256', $plaintext),
                'sent_to_email' => mb_strtolower(trim($sentToEmail)),
                'expires_at' => $expiresAt,
                'requested_ip' => $request?->ip(),
            ]);
        }, $this->context);

        return $plaintext;
    }
}

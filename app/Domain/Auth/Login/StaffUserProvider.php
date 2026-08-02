<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use App\Models\User;
use App\Support\Auth\Sessions\TenantHint;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\RunsWithinTenant;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * The staff guard's user provider (Doc 02 FR-01, FR-02, T-02; SEC-01/AC-01).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY A CUSTOM PROVIDER RATHER THAN EloquentUserProvider.
 *
 * The stock provider calls `User::where('email', …)->first()`, which goes through
 * the model's tenant global scope — and at login there is no tenant, because
 * finding the account IS how the tenant gets discovered. Doc 01 handed this over
 * as a named constraint and forbade the tempting fix (removing the scope). So
 * every read here goes through StaffLoginResolver's narrow door instead, and the
 * scope stays intact for all ordinary queries.
 *
 * THE TIMING CONTRACT (AC-01), which is the reason this class is shaped the way
 * it is. An attacker distinguishes a real address from a fake one by how long
 * the answer takes: a real account costs one argon2id verification (~100 ms per
 * NFR-03), a fake one costs nothing. So:
 *
 *   - zero candidates  → verify the presented password against a DUMMY HASH and
 *                        return null. One verification.
 *   - one candidate    → verify against the real hash. One verification.
 *
 * Both paths therefore perform exactly one argon2id verification before
 * answering, which is what makes the ±50 ms bound achievable rather than
 * aspirational.
 *
 * A RESIDUAL THIS DOES NOT CLOSE, stated plainly rather than hidden: when the
 * same address exists in SEVERAL tenants, disambiguation costs one verification
 * per candidate, so a 2-tenant address is measurably slower than a 1-tenant one.
 * That leaks "this address exists in more than one workspace" to an attacker who
 * already knows the address exists — it does NOT leak existence itself, which is
 * what AC-01 specifies. Closing it fully would mean padding every login to the
 * worst case, which would put a permanent multi-hash tax on the shift-start
 * spike (§12: 50 logins/s). Recorded in the session report as a known residual.
 *
 * WHAT THIS CLASS REFUSES TO DECIDE. It answers one question — "which account,
 * if any, does this email+password identify" — and nothing else. Lockout state,
 * disabled accounts, suspended tenants and 2FA are all VISIBLE on the candidate,
 * and are all decided by the login pipeline, because each has its own audited
 * outcome and its own distinct user-facing message (FR-05). A provider that
 * silently returned null for a locked account would collapse four different
 * failures into one and make AC-02's matrix untestable.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class StaffUserProvider implements UserProvider
{
    use RunsWithinTenant;

    /**
     * A hash of a value no password can be, used to spend the same time on a
     * miss as on a hit. Computed once per process, never compared successfully.
     */
    private ?string $dummyHash = null;

    /**
     * How many argon2id verifications a credential check ALWAYS performs
     * (owner ruling R-3, 2026-08-01).
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY A FIXED BUDGET RATHER THAN "one per candidate".
     *
     * The earlier design spent one verification per candidate and one on a miss.
     * That equalised 0-vs-1 — AC-01's literal requirement — but left a residual
     * the S4 session recorded and accepted: an address in TWO tenants cost two
     * verifications, so "this address exists in more than one workspace" was
     * measurable by anyone who already knew the address existed.
     *
     * The owner has ruled that residual closed rather than accepted, so the work
     * is now constant: real verifications first, then dummy verifications until
     * the budget is spent. 0, 1 and 2 candidates all cost exactly two hashes.
     *
     * WHY 2, AND WHAT HAPPENS ABOVE IT. Two covers the realistic shape — one
     * account, occasionally the same address in a second workspace. Above the
     * budget the padding cannot help and timing degrades again; that is a stated
     * bound, not a silent one, and the test asserts the budget it claims. Raising
     * it costs every login ~90 ms, so it should not be raised without a reason.
     *
     * NFR-01 SANCTIONS THIS COST EXPLICITLY: its p99 budget is "≤ 400 ms
     * EXCLUDING intentional equalization". The padding is exactly that.
     * ─────────────────────────────────────────────────────────────────────────
     */
    private const EQUALISED_VERIFICATIONS = 2;

    /**
     * The verdict computed during retrieveByCredentials, keyed by user id.
     *
     * Laravel calls retrieveByCredentials() and then validateCredentials() as
     * two steps, but disambiguating between candidates REQUIRES verifying the
     * password in the first step. Re-verifying in the second would double the
     * argon2id cost of every login for no security gain — the same hasher, the
     * same inputs, the same answer. So the verdict is carried across the pair.
     *
     * @var array<string, true>
     */
    private array $verified = [];

    /**
     * The candidates the last credential lookup considered.
     *
     * The lockout step needs these: an address may match accounts in several
     * tenants, and a failed attempt counts against EVERY one (owner ruling).
     * The provider is the only place that knows the full candidate set, and
     * re-running the lookup in the controller would double the pre-tenant read
     * on every failed login.
     *
     * @var list<StaffLoginCandidate>
     */
    private array $lastCandidates = [];

    /**
     * Whether the last credential check was refused because the email AND
     * password matched in more than one tenant (owner ruling R-2).
     *
     * Carried out of the provider because only the provider can see it — by the
     * time the controller learns the attempt failed, the candidate set has
     * already been reduced to "no single match".
     */
    private bool $lastAttemptWasAmbiguous = false;

    public function __construct(
        private readonly StaffLoginResolver $resolver,
        private readonly Hasher $hasher,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
        private readonly TenantHint $hint,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = is_string($credentials['email'] ?? null) ? $credentials['email'] : '';
        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

        $candidates = $this->resolver->candidatesFor($email);
        $this->lastCandidates = $candidates;
        $this->lastAttemptWasAmbiguous = false;

        $verifications = 0;
        $matched = [];

        foreach ($candidates as $candidate) {
            // The verification runs for EVERY candidate, locked or not, before
            // any lockout check — a locked account that skipped hashing would
            // answer faster than an unlocked one, which is the AC-01 timing
            // oracle again, this time keyed on lock state rather than existence.
            $verified = $this->hasher->check($password, $candidate->passwordHash);
            $verifications++;

            if ($verified && ! $candidate->isLockedAt(CarbonImmutable::now())) {
                $matched[] = $candidate;
            }
        }

        // Pad to the fixed budget so 0, 1 and 2 candidates are indistinguishable
        // by duration (owner ruling R-3). The dummy hash can never match, so this
        // changes timing and nothing else.
        while ($verifications < self::EQUALISED_VERIFICATIONS) {
            $this->hasher->check($password, $this->dummyHash());
            $verifications++;
        }

        if ($candidates === []) {
            return null;
        }

        if (count($matched) !== 1) {
            // Zero: wrong password — indistinguishable from a miss, by design.
            //
            // More than one: the same email AND the same password in two
            // tenants. Refusing is the only safe answer — picking one would
            // silently sign the person into a workspace they did not choose, and
            // asking "which workspace?" would confirm the address exists in
            // both (an enumeration oracle). The escape hatch is password reset,
            // which issues one token per matching account (owner ruling Q1).
            if (count($matched) > 1) {
                $this->lastAttemptWasAmbiguous = true;
                $this->recordAmbiguousLogin($matched);
            }

            return null;
        }

        $candidate = $matched[0];
        $this->verified[$candidate->userId] = true;

        return $this->hydrate($candidate);
    }

    /**
     * @return list<StaffLoginCandidate>
     */
    public function lastCandidates(): array
    {
        return $this->lastCandidates;
    }

    public function lastAttemptWasAmbiguous(): bool
    {
        return $this->lastAttemptWasAmbiguous;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $identifier = $user->getAuthIdentifier();
        $id = $user instanceof User ? $user->identifier() : (is_string($identifier) ? $identifier : '');

        // The verdict from retrieveByCredentials, consumed once. Anything that
        // reaches here WITHOUT having gone through that path (a caller holding a
        // model from elsewhere) falls through to a real verification rather than
        // being trusted.
        if (isset($this->verified[$id])) {
            unset($this->verified[$id]);

            return true;
        }

        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';
        $hash = (string) $user->getAuthPassword();

        return $hash !== '' && $this->hasher->check($password, $hash);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        // ─────────────────────────────────────────────────────────────────────
        // THIS IS WHERE THE TENANT HINT EARNS ITS KEEP, and the first version of
        // this method assumed the opposite — that "a tenant IS bound by then".
        // It is not. Rehydrating a session's user is the FIRST database read of
        // an authenticated request, and the tenant middleware cannot run before
        // it, because the tenant is resolved FROM the user (Doc 01 FR-03a). So
        // the read arrived with no context bound and threw MissingTenantContext,
        // which surfaced as a 500 on every session-authenticated request — a
        // defect invisible to every PHP test, because actingAs() injects the
        // user object and never performs this read.
        //
        // The hint closes the loop: it names the tenant whose keyspace the
        // session was read from, so it can also scope the read that loads the
        // user. It is still not AUTHORITY — VerifyTenantHint independently
        // confirms the loaded user actually belongs to the hinted tenant, and
        // discards the session if not. A forged hint therefore buys a lookup in
        // a tenant the attacker chose, which returns THEIR OWN user id or
        // nothing, and is then rejected.
        //
        // No hint means no safe way to scope this read, so there is no session.
        // ─────────────────────────────────────────────────────────────────────
        $tenantId = $this->hint->current();

        if ($tenantId === null) {
            return null;
        }

        $user = $this->withinTenant($tenantId, fn (): ?User => User::query()->find($identifier));

        if (! $user instanceof User) {
            return null;
        }

        // BR-04/EC-05's staff-side per-request backstop (step-9 ruling): a
        // disabled user is refused AT REHYDRATION, so a session key that
        // somehow survives the disable-time Redis revocation still
        // authenticates nothing — the next request re-reads disabled state
        // from the row itself. RefuseBlockedPrincipal covers login; this
        // covers every request after it.
        if ($user->isDisabled()) {
            return null;
        }

        // FR-19's second non-login layer (step-9 ruling): resolve_staff_login
        // already excludes service accounts, so none can ever LOG IN — this
        // refusal covers the path that does not go through login at all, a
        // session key somehow existing for one. No session may rehydrate a
        // service-account principal, whatever planted it.
        if ($user->isServiceAccount()) {
            return null;
        }

        return $user;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token): ?Authenticatable
    {
        if (! is_string($identifier)) {
            return null;
        }

        $user = $this->retrieveById($identifier);

        if ($user === null) {
            return null;
        }

        $stored = $user->getRememberToken();

        // Both are declared string by the interface, so the only real check is
        // emptiness: hash_equals() on empty strings would compare equal and turn
        // "this account has no remember token" into a valid credential.
        if ($stored === '' || $token === '') {
            return null;
        }

        // hash_equals, not ===: remember-me tokens are compared against
        // attacker-supplied input, and a short-circuiting comparison leaks the
        // matching prefix length through timing (FR-13).
        return hash_equals($stored, $token) ? $user : null;
    }

    public function updateRememberToken(Authenticatable $user, #[\SensitiveParameter] $token): void
    {
        $user->setRememberToken($token);

        if ($user instanceof User) {
            $user->save();
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void
    {
        // NFR-03: "parameters reviewed annually" — when the cost factors change,
        // a user's hash is upgraded on their next successful login rather than
        // by a mass rehash nobody can perform (passwords are not recoverable).
        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

        if ($password === '' || ! $user instanceof User) {
            return;
        }

        if (! $force && ! $this->hasher->needsRehash((string) $user->getAuthPassword())) {
            return;
        }

        $user->forceFill(['password' => $this->hasher->make($password)])->save();
    }

    /**
     * Bind the tenant the candidate belongs to, then read the model through the
     * ordinary scope.
     *
     * Binding here is what turns a pre-tenant lookup back into normal, policed
     * access: from this line on, every query in the request is inside the
     * tenant, both layers agree, and nothing else in the codebase needs to know
     * that a door was used.
     */
    private function hydrate(StaffLoginCandidate $candidate): ?Authenticatable
    {
        $this->context->clear();
        $this->context->bind($candidate->tenantId, ResolutionSource::StaffSession);
        $this->rls->set($candidate->tenantId);

        return User::query()->find($candidate->userId);
    }

    private function dummyHash(): string
    {
        // Hashing a random value once per process, rather than a constant baked
        // into the source: a constant would be a published hash of a known
        // string, which tells an attacker the exact cost parameters in use.
        return $this->dummyHash ??= $this->hasher->make(bin2hex(random_bytes(32)));
    }

    /**
     * @param  list<StaffLoginCandidate>  $matched
     */
    private function recordAmbiguousLogin(array $matched): void
    {
        // A security metric, not an audit entry: no tenant is bound (that is the
        // whole problem), and audit entries are tenant-scoped. The count and the
        // user ids are enough to investigate; the email is deliberately absent
        // (SEC-08 — no credential material, and an address IS identifying).
        logger()->warning('Ambiguous staff login refused: identical credentials in multiple tenants', [
            'candidate_count' => count($matched),
            'user_ids' => array_map(static fn (StaffLoginCandidate $c): string => $c->userId, $matched),
        ]);
    }
}

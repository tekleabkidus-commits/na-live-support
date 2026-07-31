<?php

declare(strict_types=1);

namespace App\Domain\Auth\Login;

use App\Models\User;
use App\Support\Tenancy\ResolutionSource;
use App\Support\Tenancy\RlsSession;
use App\Support\Tenancy\TenantContext;
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
    /**
     * A hash of a value no password can be, used to spend the same time on a
     * miss as on a hit. Computed once per process, never compared successfully.
     */
    private ?string $dummyHash = null;

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

    public function __construct(
        private readonly StaffLoginResolver $resolver,
        private readonly Hasher $hasher,
        private readonly TenantContext $context,
        private readonly RlsSession $rls,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $email = is_string($credentials['email'] ?? null) ? $credentials['email'] : '';
        $password = is_string($credentials['password'] ?? null) ? $credentials['password'] : '';

        $candidates = $this->resolver->candidatesFor($email);

        if ($candidates === []) {
            // The equalisation, and the single most important line in this file:
            // spend a verification on a miss so the miss is not fast.
            $this->hasher->check($password, $this->dummyHash());

            return null;
        }

        $matched = [];
        foreach ($candidates as $candidate) {
            if ($this->hasher->check($password, $candidate->passwordHash)) {
                $matched[] = $candidate;
            }
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
                $this->recordAmbiguousLogin($matched);
            }

            return null;
        }

        $candidate = $matched[0];
        $this->verified[$candidate->userId] = true;

        return $this->hydrate($candidate);
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
        // Called on every authenticated request to rehydrate the session's user.
        // A tenant IS bound by then (the session carries it), so this is an
        // ordinary scoped read — no narrow door, no bypass.
        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        return User::query()->find($identifier);
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

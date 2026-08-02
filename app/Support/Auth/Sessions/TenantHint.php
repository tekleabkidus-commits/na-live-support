<?php

declare(strict_types=1);

namespace App\Support\Auth\Sessions;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * The signed tenant hint that lets a session be stored under `t:{tenant}:`
 * (Doc 02 T-04; owner ruling Q7).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THE CIRCULARITY THIS BREAKS, AND WHY THE ANSWER IS A HINT RATHER THAN TRUST.
 *
 * T-04 requires session keys to carry a tenant prefix. But the prefix must be
 * known BEFORE the session is read, and the tenant is only known FROM the
 * session's user. Something outside the session has to name the tenant, and the
 * only thing the browser sends before a session is loaded is a cookie.
 *
 * A cookie is attacker-controlled, so the danger is immediate: if the hint were
 * TRUSTED, editing one cookie would point the session lookup at another tenant's
 * keyspace. That is why this class is deliberately small and why its name is
 * "hint" — it selects WHERE TO LOOK, and nothing more. Authority comes from the
 * session's own user record, checked afterwards (VerifyTenantHint): if the
 * loaded session's user does not belong to the hinted tenant, the session is
 * discarded rather than re-interpreted.
 *
 * Two independent properties therefore hold, and both are tested:
 *   - The hint is SIGNED, so it cannot be pointed at an arbitrary tenant without
 *     the app key. (Defence in depth: even a valid signature is not authority.)
 *   - A hint that does not match the session's user is treated as NO SESSION,
 *     never as a session belonging to the hinted tenant.
 *
 * WHY NOT ENCRYPT IT. The value is a tenant id the holder already belongs to;
 * it is not a secret. Signing gives integrity, which is the property that
 * matters, and leaves the value readable in logs where a support engineer
 * debugging "which workspace was this request for" benefits from seeing it.
 * ─────────────────────────────────────────────────────────────────────────────
 */
final class TenantHint
{
    public const COOKIE = 'na_tenant_hint';

    /**
     * A login-time override, keyed by the identity of the request it belongs to.
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY THIS IS KEYED BY REQUEST, AND WHY NOTHING ELSE IS MEMOISED.
     *
     * The first version cached the resolved hint in a plain property and read
     * the request injected at construction. Both were wrong, and the test caught
     * it: the instance outlived a single request, so after login every LATER
     * request in the same process kept answering with the LOGIN tenant — which
     * meant VerifyTenantHint compared a user against the tenant it had just come
     * from, matched, and never discarded anything. The tamper tests returned 200
     * and the defence was decoration.
     *
     * That is not merely a test artifact. It is exactly Doc 01 R-01's shape:
     * state that outlives its request looks correct in every green run and
     * serves the previous request's tenant under Octane. So the resolution is
     * not cached at all — an HMAC over a short string, once per call, is far
     * cheaper than the class of bug caching invites — and the login override is
     * scoped to the identity of the request that set it.
     *
     * A WEAK REFERENCE TO THE REQUEST, NOT ITS spl_object_id.
     *
     * The scoping held `spl_object_id($request)` until S11, and that was a real
     * production defect of exactly the kind this docblock warns about. PHP
     * REUSES object ids once an object is collected, so under Octane a later,
     * unrelated request could be handed the id of the request that set the
     * override — and `current()` would then answer with the PREVIOUS request's
     * tenant. A guest request whose id happened to collide read its session
     * from `t:{tenant}:sess:` instead of `guest:sess:`, found nothing, silently
     * started a fresh session, and the CSRF token it had just been issued no
     * longer matched: every affected login died with a bare 419 "Page Expired"
     * and no server-side error to explain it.
     *
     * A weak reference compares the OBJECT, so a recycled id cannot match — and
     * once the request is collected the reference reads null, which matches
     * nothing. Found by falsification: enrol → next login 419, deterministically,
     * while the same login against a DB-only 2FA state was fine.
     * ─────────────────────────────────────────────────────────────────────────
     */
    /** @var \WeakReference<Request>|null */
    private ?\WeakReference $overrideRequest = null;

    private ?string $overrideValue = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The tenant this request's session should be looked up under, or null when
     * there is no usable hint (a guest, or a forged cookie).
     */
    public function current(): ?string
    {
        $request = $this->request();

        if ($request === null) {
            return null;
        }

        if ($this->overrideRequest?->get() === $request) {
            return $this->overrideValue;
        }

        $cookie = $request->cookie(self::COOKIE);

        return is_string($cookie) ? $this->verify($cookie) : null;
    }

    public function sign(string $tenantId): string
    {
        return $tenantId.'|'.hash_hmac('sha256', $tenantId, $this->key());
    }

    /**
     * Return the tenant id only if the signature is intact.
     */
    public function verify(string $cookieValue): ?string
    {
        if (! str_contains($cookieValue, '|')) {
            return null;
        }

        [$tenantId, $signature] = explode('|', $cookieValue, 2);

        if ($tenantId === '' || $signature === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $tenantId, $this->key());

        // hash_equals: the comparison is against attacker-supplied input, and a
        // short-circuiting compare leaks the matching prefix length.
        return hash_equals($expected, $signature) ? $tenantId : null;
    }

    /**
     * Overrides the cookie for the remainder of THIS request — used at login,
     * where the session is written under the tenant we have just discovered but
     * the cookie only reaches the browser with the response.
     */
    public function useForThisRequest(string $tenantId): void
    {
        $request = $this->request();

        if ($request === null) {
            return;
        }

        $this->overrideRequest = \WeakReference::create($request);
        $this->overrideValue = $tenantId;
    }

    private function request(): ?Request
    {
        // Resolved per call from the container, never held: the request this
        // object was built with is not necessarily the one being handled.
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        return $request instanceof Request ? $request : null;
    }

    private function key(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }
}

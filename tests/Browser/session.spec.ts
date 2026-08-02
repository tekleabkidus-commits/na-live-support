// Doc 02 step 6 — the session proofs that need a REAL COOKIE JAR.
//
// ─────────────────────────────────────────────────────────────────────────────
// WHY THESE LIVE HERE AND NOT IN THE PEST SUITE.
//
// S6 tried to prove SEC-04 (fixation) and Q7 (tenant-hint rejection) with the
// PHP HTTP test client and produced three false greens: that client does not
// replay Set-Cookie, so every simulated request starts a fresh session. The
// pre/post-login session ids differed because they were two unrelated sessions,
// and the forged hint cookie never reached the server at all. Both suites stayed
// green when the defences were deliberately deleted. See BUILD-STATUS S6.
//
// Playwright's APIRequestContext keeps a real cookie jar, so a session survives
// across requests exactly as a browser's would. That is the whole reason these
// tests exist here.
//
// EVERY assertion below is paired with a POSITIVE CONTROL — a case that must
// SUCCEED. Without one, "the request was refused" is indistinguishable from "the
// request was broken", which is precisely how the earlier suites lied.
// ─────────────────────────────────────────────────────────────────────────────
import { execFileSync } from 'node:child_process';

import { APIRequestContext, expect, request, test } from '@playwright/test';

const PASSWORD = 'browser-fixture-password';
const PRIMARY_EMAIL = 'agent@fixture.example';
const HINT_COOKIE = 'na_tenant_hint';

/**
 * Ask the application something the browser structurally cannot compute.
 *
 * Two Doc 02 properties are only observable server-side: SEC-04 (Laravel
 * re-encrypts the session cookie per response, so cookie values say nothing
 * about the id inside) and VerifyTenantHint's own comparison (which needs a
 * VALIDLY SIGNED hint for another tenant — only the app key can mint that).
 * `auth:session-inspect` is the test-only affordance that answers both; it
 * refuses to run outside local/testing/ci.
 */
function inspect(...args: string[]): string {
    return execFileSync(
        'docker',
        [
            'compose',
            '-f',
            'docker/compose.yaml',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'auth:session-inspect',
            ...args,
        ],
        { encoding: 'utf8' },
    ).trim();
}

function tenantIds(): { primary: string; other: string } {
    const out = execFileSync(
        'docker',
        [
            'compose',
            '-f',
            'docker/compose.yaml',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'tinker',
            '--execute',
            'foreach (DB::connection("pgsql_platform")->table("tenants")->orderBy("name")->get(["name","id"]) as $t) { echo $t->name, "=", $t->id, "\n"; }',
        ],
        { encoding: 'utf8' },
    );

    const map = new Map<string, string>();
    for (const line of out.split('\n')) {
        const [name, id] = line.split('=');
        if (name && id) map.set(name.trim(), id.trim());
    }

    const primary = map.get('Fixture Operator A');
    const other = map.get('Fixture Operator B');
    expect(primary, 'fixture tenant A missing — run the BrowserFixtureSeeder').toBeTruthy();
    expect(other, 'fixture tenant B missing — run the BrowserFixtureSeeder').toBeTruthy();

    return { primary: primary!, other: other! };
}

async function sessionIdOf(ctx: APIRequestContext): Promise<string> {
    const cookie = (await ctx.storageState()).cookies.find((c) => c.name.endsWith('-session'));
    expect(cookie, 'no session cookie present').toBeTruthy();

    return inspect('decrypt-cookie', `--cookie=${cookie!.value}`);
}

async function freshContext(baseURL: string): Promise<APIRequestContext> {
    // A NEW context per login = a new cookie jar = an independent browser, which
    // is what the concurrent-session tests need.
    return request.newContext({ baseURL, ignoreHTTPSErrors: true });
}

async function csrfToken(ctx: APIRequestContext): Promise<string> {
    // Exactly what a real SPA does, and proof of T-06 in passing: session routes
    // are CSRF-protected, so a POST without the token is refused with 419. The
    // first attempt at this suite omitted it and every login came back 419 —
    // which is the framework behaving correctly, not a bug.
    await ctx.get('/', { headers: { Accept: 'text/html' } });
    const token = await cookieValue(ctx, 'XSRF-TOKEN');
    expect(token, 'no XSRF-TOKEN cookie was issued').toBeTruthy();

    return decodeURIComponent(token!);
}

async function login(ctx: APIRequestContext, email = PRIMARY_EMAIL, password = PASSWORD) {
    const token = await csrfToken(ctx);

    return ctx.post('/login', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        data: { email, password },
    });
}

async function cookieValue(ctx: APIRequestContext, name: string): Promise<string | undefined> {
    const { cookies } = await ctx.storageState();
    return cookies.find((c) => c.name === name)?.value;
}

test.describe('session lifecycle (Doc 02 SEC-04, T-04, Q7)', () => {
    // SERIAL, and not for speed. These tests share global state by nature —
    // one Redis keyspace, one per-IP login budget, one fixture account — and the
    // cap test's clear-tenant reset wipes sessions belonging to whichever test
    // happens to be running beside it. In parallel, SEC-04 failed because the
    // cap test had just deleted the session it was measuring. Tests that share a
    // real backing store cannot be made independent by wishing.
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(() => {
        // Every worker in this suite dials the app from the SAME client address,
        // so they share one per-IP login budget (SEC-05/FR-03, 20/min). CI failed
        // with "login 5 failed" and the cap assertion then failed for a reason
        // unrelated to eviction. Resetting the limiter between tests keeps the
        // production control untouched — LockoutTest still proves it — and stops
        // one test's traffic deciding another's outcome.
        inspect('clear-throttle');
    });

    test('POSITIVE CONTROL: a session established by login survives to the next request', async ({
        baseURL,
    }) => {
        // If this ever fails, every negative assertion in this file becomes
        // meaningless and must not be read as evidence.
        const ctx = await freshContext(baseURL!);

        const loginResponse = await login(ctx);
        expect(loginResponse.status(), 'login itself failed').toBe(200);

        const authed = await ctx.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });

        expect(
            authed.status(),
            'the session did not survive one request — the cookie jar is not working and no other ' +
                'assertion in this file can be trusted',
        ).toBe(200);

        await ctx.dispose();
    });

    test('SEC-04: the pre-login session id is DESTROYED and replaced at the password boundary', async ({
        baseURL,
    }) => {
        // ─────────────────────────────────────────────────────────────────────
        // THE THIRD ATTEMPT AT THIS TEST, AND THE FIRST ONE THAT CAN FAIL.
        //
        // Attempts 1 and 2 compared session COOKIE VALUES, in the PHP client and
        // then in the browser. Both passed with regenerate() deliberately
        // removed, because Laravel re-encrypts the cookie with a fresh IV on
        // every response — the value changes whether or not the id does.
        //
        // The observable that actually distinguishes them is in STORAGE: after
        // authentication the pre-login session key must be GONE, and a different
        // one must exist. That is what an attacker's planted id losing its
        // meaning looks like from the server side.
        // ─────────────────────────────────────────────────────────────────────
        const { primary } = tenantIds();
        const ctx = await freshContext(baseURL!);

        // A guest session — the id an attacker plants. It lives in the
        // unprefixed guest keyspace, because no tenant is known yet.
        await csrfToken(ctx);
        const plantedId = await sessionIdOf(ctx);
        expect(plantedId, 'no guest session id could be read').toBeTruthy();
        expect(
            inspect('key-exists', `--session=${plantedId}`),
            'the guest session was never stored',
        ).toBe('1');

        expect((await login(ctx)).status()).toBe(200);

        const authenticatedId = await sessionIdOf(ctx);

        expect(
            authenticatedId,
            'SEV-1: the session id survived authentication — a planted pre-login id is now an ' +
                'authenticated session (SEC-04 fixation)',
        ).not.toBe(plantedId);

        // The planted id is gone from storage, so replaying it buys nothing.
        expect(
            inspect('key-exists', `--session=${plantedId}`),
            'SEV-1: the pre-login session key still exists after authentication — the planted id is ' +
                'still live even though a new one was issued',
        ).toBe('0');

        // …and the new id really is stored under the tenant prefix (T-04).
        expect(
            inspect('key-exists', `--tenant=${primary}`, `--session=${authenticatedId}`),
            'the authenticated session is not stored under its tenant prefix',
        ).toBe('1');

        await ctx.dispose();
    });

    test('Q7: a VALIDLY SIGNED hint for a DIFFERENT tenant is refused (VerifyTenantHint itself)', async ({
        baseURL,
    }) => {
        // The forged-signature case below proves the signature check. This one
        // proves the MIDDLEWARE: the hint verifies perfectly and names a real
        // workspace — it simply is not this session's. Only something holding the
        // app key can mint it, which is why it comes from the artisan affordance.
        const { other } = tenantIds();
        const ctx = await freshContext(baseURL!);

        await login(ctx);
        expect(
            (
                await ctx.get('/api/staff/settings', { headers: { Accept: 'application/json' } })
            ).status(),
            'positive control: the session must work before the hint is swapped',
        ).toBe(200);

        const wrongButValid = inspect('sign-hint', `--tenant=${other}`);
        const state = await ctx.storageState();
        const swapped = state.cookies.map((c) =>
            c.name === HINT_COOKIE ? { ...c, value: wrongButValid } : c,
        );
        const swappedCtx = await request.newContext({
            baseURL: baseURL!,
            ignoreHTTPSErrors: true,
            storageState: { cookies: swapped, origins: state.origins },
        });

        const response = await swappedCtx.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });

        expect(
            response.status(),
            'SEV-1: a session was served under a validly-signed hint naming ANOTHER tenant — one ' +
                'cookie swap points session storage at a workspace the user does not belong to',
        ).not.toBe(200);

        await ctx.dispose();
        await swappedCtx.dispose();
    });

    // WHAT THIS PROVES, PRECISELY. Appending a character breaks the HMAC, so the
    // hint fails verification and resolves to null — and a request with NO usable
    // hint gets no session, because StaffUserProvider::retrieveById has no safe
    // tenant to scope the read by. That is a real, falsifiable property and it is
    // what this test establishes.
    //
    // What it does NOT establish is VerifyTenantHint's own contribution: with
    // that middleware's comparison disabled, this test still passes, because the
    // provider refuses first. Exercising the middleware needs a VALIDLY SIGNED
    // hint naming a DIFFERENT tenant — which requires the app key to forge from
    // the browser side. Recorded as the remaining gap rather than implied.
    test('Q7: a hint whose signature does not verify yields no session', async ({ baseURL }) => {
        const ctx = await freshContext(baseURL!);

        await login(ctx);

        // Control: with the hint the server issued, the request is served.
        const withGoodHint = await ctx.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });
        expect(withGoodHint.status(), 'positive control: correct hint must be served').toBe(200);

        // Now corrupt ONLY the hint, leaving the session cookie untouched. This is
        // the attack: one cookie edit pointing session lookup at another keyspace.
        const state = await ctx.storageState();
        const tampered = state.cookies.map((c) =>
            c.name === HINT_COOKIE ? { ...c, value: `${c.value}x` } : c,
        );
        const tamperedCtx = await request.newContext({
            baseURL: baseURL!,
            ignoreHTTPSErrors: true,
            storageState: { cookies: tampered, origins: state.origins },
        });

        const withBadHint = await tamperedCtx.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });

        expect(
            withBadHint.status(),
            'SEV-1: a session was served under a tampered tenant hint',
        ).not.toBe(200);

        await ctx.dispose();
        await tamperedCtx.dispose();
    });

    test('FR-10: the 6th concurrent login evicts the OLDEST session, not the newest', async ({
        baseURL,
    }) => {
        // ─────────────────────────────────────────────────────────────────────
        // ASSERTED VIA SESSION KEYS, NOT VIA THE INDEX. The registry's sorted set
        // is bookkeeping; storage is truth. A test that read the index could pass
        // while the evicted session was still perfectly usable — which is the
        // only thing that actually matters here.
        //
        // Six independent cookie jars = six browsers. The cap is 5.
        // ─────────────────────────────────────────────────────────────────────
        const { primary } = tenantIds();

        // Start from a known-empty state. Without this the fixture user carries
        // sessions from every previous run, the eviction count grows, and the
        // oldest session disappears whichever end the code evicts from — the
        // test passed with the eviction order deliberately REVERSED before this
        // line existed.
        inspect('clear-tenant', `--tenant=${primary}`);

        const contexts: APIRequestContext[] = [];
        const sessionIds: string[] = [];

        for (let i = 0; i < 6; i++) {
            const ctx = await freshContext(baseURL!);
            const response = await login(ctx);
            expect(response.status(), `login ${i + 1} failed`).toBe(200);

            contexts.push(ctx);
            sessionIds.push(await sessionIdOf(ctx));
        }

        // Named locals rather than indexed access: TypeScript's
        // noUncheckedIndexedAccess types `arr[0]` as possibly-undefined, and CI
        // caught it while a cached local vue-tsc did not — the second time this
        // session that a stale type-check reported success.
        const oldestSessionId = sessionIds.at(0);
        const newestSessionId = sessionIds.at(-1);
        expect(oldestSessionId, 'no first session id captured').toBeTruthy();
        expect(newestSessionId, 'no last session id captured').toBeTruthy();

        // The FIRST session is gone from storage…
        expect(
            inspect('key-exists', `--tenant=${primary}`, `--session=${oldestSessionId}`),
            'SEV-1: the oldest session survived the cap — six concurrent sessions exist where the ' +
                'policy allows five',
        ).toBe('0');

        // …and the newest is not the one that was sacrificed.
        expect(
            inspect('key-exists', `--tenant=${primary}`, `--session=${newestSessionId}`),
            'SEV-1: the cap evicted the session that just authenticated — logging a user out at the ' +
                'moment they logged in',
        ).toBe('1');

        // Same reason as the ids above: indexed access is possibly-undefined
        // under noUncheckedIndexedAccess.
        const evictedContext = contexts.at(0);
        const survivingContext = contexts.at(-1);
        expect(evictedContext, 'no evicted context captured').toBeTruthy();
        expect(survivingContext, 'no surviving context captured').toBeTruthy();

        // The evicted session is genuinely unusable, not merely missing a key.
        const evictedResponse = await evictedContext!.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });
        expect(evictedResponse.status(), 'the evicted session still authenticates').not.toBe(200);

        // POSITIVE CONTROL: the surviving newest session still works, so the
        // eviction was selective rather than a blanket logout.
        const survivorResponse = await survivingContext!.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });
        expect(survivorResponse.status(), 'the newest session was collateral damage').toBe(200);

        for (const ctx of contexts) {
            await ctx.dispose();
        }
    });

    test('FR-12: the devices API sees both browsers, refuses to revoke the current one, and revoke-others kills the sibling', async ({
        baseURL,
    }) => {
        const { primary } = tenantIds();
        inspect('clear-tenant', `--tenant=${primary}`);

        // Two REAL browsers: independent cookie jars, real logins.
        const mine = await freshContext(baseURL!);
        const sibling = await freshContext(baseURL!);
        expect((await login(mine)).status(), 'first login failed').toBe(200);
        expect((await login(sibling)).status(), 'second login failed').toBe(200);

        // POSITIVE CONTROL: the sibling works before anything is revoked.
        expect(
            (
                await sibling.get('/api/staff/settings', {
                    headers: { Accept: 'application/json' },
                })
            ).status(),
            'positive control: the sibling session must work before revocation',
        ).toBe(200);

        const listResponse = await mine.get('/api/staff/security/sessions', {
            headers: { Accept: 'application/json' },
        });
        expect(listResponse.status(), 'the sessions list was refused').toBe(200);

        const { sessions } = (await listResponse.json()) as {
            sessions: {
                digest: string;
                current: boolean;
                device_label: string;
                location: string | null;
            }[];
        };

        expect(sessions.length, 'both browsers must be listed').toBe(2);

        const current = sessions.find((row) => row.current);
        const other = sessions.find((row) => !row.current);
        expect(current, 'no row is marked as the current session').toBeTruthy();
        expect(other, 'the sibling browser is missing from the list').toBeTruthy();
        expect(sessions[0]?.current, '§8: the current device is pinned first').toBe(true);
        expect(
            current!.device_label.length,
            'T-03: the current row carries a parsed device label',
        ).toBeGreaterThan(0);

        const token = decodeURIComponent((await cookieValue(mine, 'XSRF-TOKEN'))!);

        // The current session is refused by single revoke — logout owns that.
        const selfRevoke = await mine.delete(`/api/staff/security/sessions/${current!.digest}`, {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        });
        expect(
            selfRevoke.status(),
            'revoking the CURRENT session must be refused — sawing off the branch you sit on has a dedicated verb (logout)',
        ).toBe(422);

        // Revoke all others: the sibling dies, this browser survives.
        const revokeOthers = await mine.post('/api/staff/security/sessions/revoke-others', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        });
        expect(revokeOthers.status(), 'revoke-others was refused').toBe(200);
        expect(((await revokeOthers.json()) as { count: number }).count).toBe(1);

        const siblingAfter = await sibling.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });
        expect(
            siblingAfter.status(),
            'SEV-1: the sibling session survived revoke-others (US-03)',
        ).not.toBe(200);

        // POSITIVE CONTROL: the revoking browser itself still works.
        const mineAfter = await mine.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });
        expect(mineAfter.status(), 'revoke-others took the current session with it').toBe(200);

        await mine.dispose();
        await sibling.dispose();
    });

    test('FR-12: the /security page is served to a signed-in agent and gated from guests', async ({
        baseURL,
    }) => {
        // The PHP suite cannot touch this route: stage 3 builds no assets, so
        // @vite has no manifest there (the S0 lesson). Here the real page is
        // served with the real build.
        const guest = await freshContext(baseURL!);
        const guestResponse = await guest.get('/security', {
            headers: { Accept: 'text/html' },
            maxRedirects: 0,
        });
        expect(
            guestResponse.status(),
            'a guest reached the Security page — auth:staff is not gating it',
        ).not.toBe(200);

        const ctx = await freshContext(baseURL!);
        expect((await login(ctx)).status(), 'login failed').toBe(200);

        const page = await ctx.get('/security', { headers: { Accept: 'text/html' } });
        expect(page.status(), 'the Security page was refused to a signed-in agent').toBe(200);

        const html = await page.text();
        // Inertia JSON-escapes the component name in data-page, so the slash
        // arrives as `Security\/Devices` — matching the unescaped form fails
        // against a perfectly correct page.
        expect(
            html.includes('Security\\/Devices'),
            'the response is not the Inertia Security/Devices page',
        ).toBe(true);

        await guest.dispose();
        await ctx.dispose();
    });

    test('FR-06/FR-07: the full 2FA journey — enrol, challenge, SEC-04 second boundary, recovery code', async ({
        baseURL,
    }) => {
        // Idempotency across runs: fixtures persist, so run N's enrolment
        // would break run N+1's password-only logins elsewhere in this file.
        // This user is reset at the start and at the end.
        inspect('clear-2fa', `--email=${PRIMARY_EMAIL}`);

        try {
            // ── Enrol, driving the REAL endpoints. ───────────────────────────
            const ctx = await freshContext(baseURL!);
            expect((await login(ctx)).status(), 'login failed').toBe(200);

            const token = async () => decodeURIComponent((await cookieValue(ctx, 'XSRF-TOKEN'))!);

            const begin = await ctx.post('/api/staff/security/2fa', {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': await token() },
            });
            expect(begin.status(), '2FA begin was refused').toBe(200);
            const { secret, enrolment_id } = (await begin.json()) as {
                secret: string;
                enrolment_id: string;
            };

            const confirm = await ctx.post('/api/staff/security/2fa/confirm', {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': await token() },
                data: { code: inspect('totp', `--secret=${secret}`), enrolment_id },
            });
            expect(confirm.status(), '2FA confirm was refused').toBe(200);
            const { recovery_codes } = (await confirm.json()) as { recovery_codes: string[] };
            expect(recovery_codes.length, 'FR-06: eight recovery codes, shown once').toBe(8);

            await ctx.dispose();

            // ── The challenge path, in a fresh browser. ──────────────────────
            const fresh = await freshContext(baseURL!);
            const half = await login(fresh);
            expect(half.status(), 'a 2FA account must NOT be signed in by password alone').toBe(
                401,
            );
            expect(((await half.json()) as { status: string }).status).toBe('two_factor_required');

            // SS-02: the half-authenticated browser can read NOTHING.
            expect(
                (
                    await fresh.get('/api/staff/settings', {
                        headers: { Accept: 'application/json' },
                    })
                ).status(),
                'SEV-1 (SS-02): a valid-password-no-TOTP caller read staff data',
            ).not.toBe(200);

            // SEC-04's SECOND boundary: capture the pending-challenge session id…
            const pendingId = await sessionIdOf(fresh);

            const challengeToken = decodeURIComponent((await cookieValue(fresh, 'XSRF-TOKEN'))!);
            const challenge = await fresh.post('/login/two-factor', {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': challengeToken },
                data: { code: inspect('totp', `--secret=${secret}`) },
            });
            expect(challenge.status(), 'the challenge refused a valid TOTP').toBe(200);

            // …and prove it DIED in storage when the code verified (the S8
            // method — cookie values cannot show this).
            expect(await sessionIdOf(fresh)).not.toBe(pendingId);
            expect(
                inspect('key-exists', `--session=${pendingId}`),
                'SEV-1 (SEC-04): the pre-challenge session id survived the second privilege boundary',
            ).toBe('0');

            // POSITIVE CONTROL: fully signed in now.
            expect(
                (
                    await fresh.get('/api/staff/settings', {
                        headers: { Accept: 'application/json' },
                    })
                ).status(),
            ).toBe(200);
            await fresh.dispose();

            // ── Recovery-code path (AC-03: works once). ──────────────────────
            const lostPhone = await freshContext(baseURL!);
            expect((await login(lostPhone)).status()).toBe(401);

            const recoveryToken = decodeURIComponent((await cookieValue(lostPhone, 'XSRF-TOKEN'))!);
            const viaRecovery = await lostPhone.post('/login/two-factor', {
                headers: { Accept: 'application/json', 'X-XSRF-TOKEN': recoveryToken },
                data: { recovery_code: recovery_codes[0] },
            });
            expect(viaRecovery.status(), 'a recovery code must sign in (US-02)').toBe(200);
            expect(
                ((await viaRecovery.json()) as { recovery_codes_remaining: number })
                    .recovery_codes_remaining,
                'FR-07: the response reports the remaining count',
            ).toBe(7);
            await lostPhone.dispose();
        } finally {
            // Leave the fixture user password-only for every other test.
            inspect('clear-2fa', `--email=${PRIMARY_EMAIL}`);
        }
    });

    test('logout ends the session for subsequent requests', async ({ baseURL }) => {
        const ctx = await freshContext(baseURL!);

        await login(ctx);
        expect(
            (
                await ctx.get('/api/staff/settings', { headers: { Accept: 'application/json' } })
            ).status(),
        ).toBe(200);

        // The token is read from the jar as it stands NOW — not re-fetched via
        // csrfToken(), which issues a fresh GET and can rotate the token between
        // reading it and using it.
        const token = decodeURIComponent((await cookieValue(ctx, 'XSRF-TOKEN'))!);
        const out = await ctx.post('/logout', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        });
        expect(out.status(), 'logout was refused').toBe(200);

        const afterLogout = await ctx.get('/api/staff/settings', {
            headers: { Accept: 'application/json' },
        });

        expect(afterLogout.status(), 'the session outlived an explicit logout').not.toBe(200);

        await ctx.dispose();
    });
});

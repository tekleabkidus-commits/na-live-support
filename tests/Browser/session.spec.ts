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
import { APIRequestContext, expect, request, test } from '@playwright/test';

const PASSWORD = 'browser-fixture-password';
const PRIMARY_EMAIL = 'agent@fixture.example';
const HINT_COOKIE = 'na_tenant_hint';

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

    // FALSIFIED AND FOUND WANTING — kept, renamed, and marked, because the honest
    // record is worth more than the green tick.
    //
    // With `regenerate()` deliberately removed this test STILL PASSED. The reason
    // is subtle and worth stating: Laravel ENCRYPTS the session cookie with a
    // fresh IV on every response, so the cookie VALUE differs between two
    // responses even when the underlying session id is identical. Comparing
    // cookie values therefore proves nothing about regeneration.
    //
    // Proving SEC-04 needs the session ID, not the cookie — i.e. a server-side
    // assertion (the old id's Redis key is gone and a new one exists) rather than
    // a client-side one. That is the next session's work; it is recorded as
    // UNPROVEN in traceability, not counted.
    test.skip('SEC-04: the session id is regenerated when the password is accepted', async ({
        baseURL,
    }) => {
        const ctx = await freshContext(baseURL!);

        // Establish a guest session first — this is the id an attacker plants.
        await csrfToken(ctx);
        const sessionCookieName = (await ctx.storageState()).cookies.find((c) =>
            c.name.endsWith('-session'),
        )?.name;
        expect(sessionCookieName, 'no session cookie was issued to a guest').toBeTruthy();

        const beforeLogin = await cookieValue(ctx, sessionCookieName!);
        expect(beforeLogin, 'guest session cookie missing').toBeTruthy();

        const loginResponse = await login(ctx);
        expect(loginResponse.status()).toBe(200);

        const afterLogin = await cookieValue(ctx, sessionCookieName!);

        expect(
            afterLogin,
            'SEV-1: the session cookie did not change across the privilege boundary — a planted ' +
                'pre-login id is now an authenticated session (SEC-04 fixation)',
        ).not.toBe(beforeLogin);

        await ctx.dispose();
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

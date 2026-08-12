// Doc 02 step 8 — the password lifecycle over REAL transports.
//
// ─────────────────────────────────────────────────────────────────────────────
// WHAT ONLY THIS SUITE CAN PROVE. The pest flow tests fake the mailer and
// capture the token from the Mailable object — which proves the flow, but not
// that a real email LEAVES through the queued `mail` family (ADR 0007) and
// carries a link a person can actually click. Here the token travels the whole
// production path: Horizon worker → SMTP → Mailpit's inbox → this test reads
// the message over Mailpit's API exactly as a person reads their mail, clicks
// the link over HTTPS, and sets the password with a real CSRF token from a
// real cookie jar. FR-16's "all sessions revoked" is then observed the only
// way it matters: a browser that WAS signed in, isn't.
//
// STATE DISCIPLINE. These journeys MUTATE credentials, so they use dedicated
// fixture accounts (reset-target@, invited-fixture@ — never the primary the
// other specs log in with), and every journey ends with a fixture-repair
// affordance (restore-password / make-invited) — NOT with a reverse journey,
// because FR-15's reuse history correctly refuses changing back to a
// remembered password. Serial, for the same shared-Redis reasons
// session.spec.ts documents.
// ─────────────────────────────────────────────────────────────────────────────
import { execFileSync } from 'node:child_process';

import { APIRequestContext, expect, request, test } from '@playwright/test';

const PASSWORD = 'browser-fixture-password';
const RESET_EMAIL = 'reset-target@fixture.example';
const INVITED_EMAIL = 'invited-fixture@fixture.example';
const MAILPIT_URL = process.env.MAILPIT_URL ?? 'http://localhost:8025';

// UNIQUE PER RUN, deliberately: FR-15's reuse history is a real table that
// persists across suite runs, and a fixed "new" password would be REFUSED as
// a reuse on run two — the policy working, failing the test for the wrong
// reason. Restoration goes through the restore-password repair affordance for
// the same reason: the flow itself (correctly) refuses to change BACK.
const RUN_STAMP = Date.now().toString(36);
const RESET_PASSWORD_NEW = `A-Browser-Chosen-${RUN_STAMP}`;
const CHANGE_PASSWORD_NEW = `A-Mid-Shift-${RUN_STAMP}`;
const INVITE_PASSWORD_NEW = `An-Invitees-Own-${RUN_STAMP}`;

function seedFixtures(): void {
    // Self-seeding (advisor ruling, 2026-08-12): the fresh-DB battery proved
    // this spec had assumed ANOTHER file's beforeAll (or CI's pre-suite seed
    // step) had already created the fixture users. Idempotent, so repeated
    // runs across files are free.
    execFileSync('docker', [
        'compose',
        '-f',
        'docker/compose.yaml',
        'exec',
        '-T',
        'app',
        'php',
        'artisan',
        'db:seed',
        '--class=Database\\Seeders\\BrowserFixtureSeeder',
        '--force',
    ]);
}

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

async function freshContext(baseURL: string): Promise<APIRequestContext> {
    return request.newContext({ baseURL, ignoreHTTPSErrors: true });
}

async function cookieValue(ctx: APIRequestContext, name: string): Promise<string | undefined> {
    const { cookies } = await ctx.storageState();
    return cookies.find((c) => c.name === name)?.value;
}

async function csrfToken(ctx: APIRequestContext): Promise<string> {
    await ctx.get('/', { headers: { Accept: 'text/html' } });
    const token = await cookieValue(ctx, 'XSRF-TOKEN');
    expect(token, 'no XSRF-TOKEN cookie was issued').toBeTruthy();

    return decodeURIComponent(token!);
}

async function login(ctx: APIRequestContext, email: string, password: string) {
    const token = await csrfToken(ctx);

    return ctx.post('/login', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        data: { email, password },
    });
}

/** A signed-in probe: 200 alive, anything else dead (the FR-12 suite's idiom). */
async function sessionAlive(ctx: APIRequestContext): Promise<boolean> {
    const probe = await ctx.get('/api/staff/security/sessions', {
        headers: { Accept: 'application/json' },
    });

    return probe.status() === 200;
}

async function clearMailbox(mailpit: APIRequestContext): Promise<void> {
    await mailpit.delete('/api/v1/messages');
}

/**
 * Poll Mailpit for the newest message to `email` and extract the action link.
 * The wait is real: the mail crosses Horizon's `mail` queue and SMTP first,
 * which is exactly the path this suite exists to prove.
 */
async function actionLinkFor(
    mailpit: APIRequestContext,
    email: string,
    path: '/reset-password' | '/invitation',
): Promise<string> {
    for (let attempt = 0; attempt < 60; attempt++) {
        const search = await mailpit.get(
            `/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`,
        );
        const body = (await search.json()) as { messages?: { ID: string }[] };

        const newestId = body.messages?.[0]?.ID;
        if (newestId) {
            const message = (await (await mailpit.get(`/api/v1/message/${newestId}`)).json()) as {
                HTML?: string;
                Text?: string;
            };

            const haystack = `${message.HTML ?? ''}\n${message.Text ?? ''}`;
            const match = haystack.match(
                new RegExp(`https://[^"'\\s<]*${path.slice(1)}\\?token=[0-9a-f]{64}`),
            );

            if (match) return match[0];
        }

        await new Promise((resolve) => setTimeout(resolve, 500));
    }

    throw new Error(
        `no ${path} link arrived for ${email} within 30s — is the Horizon mail supervisor consuming the queue (ADR 0007)?`,
    );
}

/** One full FR-16 reset pass: request → email → set. Returns nothing; asserts inline. */
async function resetPasswordVia(
    baseURL: string,
    mailpit: APIRequestContext,
    email: string,
    newPassword: string,
): Promise<void> {
    await clearMailbox(mailpit);

    const requester = await freshContext(baseURL);
    const token = await csrfToken(requester);
    const requested = await requester.post('/forgot-password', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
        data: { email },
    });
    expect(requested.status(), 'the reset request was refused').toBe(200);

    const link = await actionLinkFor(mailpit, email, '/reset-password');

    // EC-03 for real: a DIFFERENT browser (fresh jar) opens the link.
    const redeemer = await freshContext(baseURL);
    const form = await redeemer.get(link, { headers: { Accept: 'text/html' } });
    expect(form.status(), 'the emailed link did not render the form').toBe(200);

    const redeemToken = await csrfToken(redeemer);
    const plaintext = new URL(link).searchParams.get('token');
    const submitted = await redeemer.post('/reset-password', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': redeemToken },
        data: {
            token: plaintext,
            password: newPassword,
            password_confirmation: newPassword,
        },
    });
    expect(submitted.status(), 'setting the new password failed').toBe(200);

    await requester.dispose();
    await redeemer.dispose();
}

test.beforeAll(() => {
    seedFixtures();
});

test.describe('password lifecycle (Doc 02 FR-16, FR-17, FR-14, FS-02)', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(() => {
        inspect('clear-throttle');
    });

    test('FR-16: the emailed reset link works end to end and kills the live session', async ({
        baseURL,
    }) => {
        const mailpit = await request.newContext({ baseURL: MAILPIT_URL });

        // A LIVE session that must die with the reset (FR-16's "all sessions
        // revoked"), plus its positive control while the password is intact.
        const victimSession = await freshContext(baseURL!);
        expect(
            (await login(victimSession, RESET_EMAIL, PASSWORD)).status(),
            'fixture login failed',
        ).toBe(200);
        expect(
            await sessionAlive(victimSession),
            'positive control: the session must work before the reset',
        ).toBe(true);

        await resetPasswordVia(baseURL!, mailpit, RESET_EMAIL, RESET_PASSWORD_NEW);

        // The session that existed before the reset is DEAD — observed as a
        // browser observes it, not as a Redis inspection.
        expect(await sessionAlive(victimSession), 'FR-16: the pre-reset session survived').toBe(
            false,
        );

        // Old password dead, new password works.
        inspect('clear-throttle');
        const withOld = await freshContext(baseURL!);
        expect(
            (await login(withOld, RESET_EMAIL, PASSWORD)).status(),
            'the OLD password still logs in',
        ).toBe(422);
        const withNew = await freshContext(baseURL!);
        expect(
            (await login(withNew, RESET_EMAIL, RESET_PASSWORD_NEW)).status(),
            'the NEW password was refused',
        ).toBe(200);

        // Fixture repair (see RUN_STAMP's comment for why not a second reset),
        // then the published credential provably works again.
        expect(inspect('restore-password', `--email=${RESET_EMAIL}`)).toBe('1');
        inspect('clear-throttle');
        const restored = await freshContext(baseURL!);
        expect(
            (await login(restored, RESET_EMAIL, PASSWORD)).status(),
            'the fixture password was not restored',
        ).toBe(200);

        await Promise.all([
            victimSession.dispose(),
            withOld.dispose(),
            withNew.dispose(),
            restored.dispose(),
            mailpit.dispose(),
        ]);
    });

    test('FR-17/EC-04: the invited fixture activates through a resent emailed link, then returns to the invited state', async ({
        baseURL,
    }) => {
        const mailpit = await request.newContext({ baseURL: MAILPIT_URL });
        await clearMailbox(mailpit);

        // Invited means: cannot log in, with the generic refusal (SEC-01).
        const before = await freshContext(baseURL!);
        expect(
            (await login(before, INVITED_EMAIL, PASSWORD)).status(),
            'an invited account must refuse login',
        ).toBe(422);

        // EC-04's self-service resend is the production path that mints the
        // link — no admin surface exists yet, and none is needed for this.
        const requester = await freshContext(baseURL!);
        const token = await csrfToken(requester);
        expect(
            (
                await requester.post('/invitation/resend', {
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': token },
                    data: { email: INVITED_EMAIL },
                })
            ).status(),
        ).toBe(200);

        const link = await actionLinkFor(mailpit, INVITED_EMAIL, '/invitation');

        const redeemer = await freshContext(baseURL!);
        expect((await redeemer.get(link, { headers: { Accept: 'text/html' } })).status()).toBe(200);

        const redeemToken = await csrfToken(redeemer);
        const submitted = await redeemer.post('/invitation', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': redeemToken },
            data: {
                token: new URL(link).searchParams.get('token'),
                password: INVITE_PASSWORD_NEW,
                password_confirmation: INVITE_PASSWORD_NEW,
            },
        });
        expect(submitted.status(), 'the invited person could not set their password').toBe(200);

        // The account is now an ordinary one.
        inspect('clear-throttle');
        const activated = await freshContext(baseURL!);
        expect(
            (await login(activated, INVITED_EMAIL, INVITE_PASSWORD_NEW)).status(),
            'the activated account was refused',
        ).toBe(200);

        // Fixture repair: back to invited for the next run.
        expect(inspect('make-invited', `--email=${INVITED_EMAIL}`)).toBe('1');

        await Promise.all([
            before.dispose(),
            requester.dispose(),
            redeemer.dispose(),
            activated.dispose(),
            mailpit.dispose(),
        ]);
    });

    test('FR-14: an authenticated change keeps the changing session and kills the sibling', async ({
        baseURL,
    }) => {
        const changer = await freshContext(baseURL!);
        const sibling = await freshContext(baseURL!);
        expect((await login(changer, RESET_EMAIL, PASSWORD)).status(), 'changer login failed').toBe(
            200,
        );
        expect((await login(sibling, RESET_EMAIL, PASSWORD)).status(), 'sibling login failed').toBe(
            200,
        );

        // Positive controls, both alive.
        expect(await sessionAlive(changer)).toBe(true);
        expect(await sessionAlive(sibling)).toBe(true);

        const token = await cookieValue(changer, 'XSRF-TOKEN');
        const changed = await changer.put('/api/staff/security/password', {
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token!) },
            data: {
                current_password: PASSWORD,
                password: CHANGE_PASSWORD_NEW,
                password_confirmation: CHANGE_PASSWORD_NEW,
            },
        });
        expect(changed.status(), 'the password change was refused').toBe(200);

        // FR-14, both halves: the sibling died, the prover survived.
        expect(await sessionAlive(sibling), 'FR-14: the sibling session survived the change').toBe(
            false,
        );
        expect(await sessionAlive(changer), 'FR-14: the changing session must survive').toBe(true);

        // Fixture repair — the flow itself (correctly) refuses changing BACK
        // to a remembered password, which is FR-15 doing its job.
        expect(inspect('restore-password', `--email=${RESET_EMAIL}`)).toBe('1');

        await Promise.all([changer.dispose(), sibling.dispose()]);
    });
});

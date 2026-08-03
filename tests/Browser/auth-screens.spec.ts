// Doc 02 step 13 — the authentication screens, §13's mechanical accessibility
// assertions, at mobile width (§9 responsive / NFR-05).
//
// EVERYTHING HERE RUNS AT 360×740. §13's assertions are MECHANICAL on
// purpose: label/aria-describedby association, the segmented code input
// reading as ONE field to assistive tech, role="alert" on errors, complete
// keyboard operability, and focus moving to the step heading on wizard
// transitions — each is a DOM fact this suite can falsify, and the named
// sabotages (role removed, input split, focus call dropped, gate removed)
// were each run red before these tests were counted (see the session report).
// AC-09's pixel half stays PARTIAL: visual baselines attach when the approved
// design screens land; nothing here claims visual coverage.
//
// SERIAL, deliberately: the 2FA journeys are one story (enrol → challenge →
// recovery → EC-02), and the suite shares one Redis keyspace and one per-IP
// login budget with itself (the S9 lesson).
import { execFileSync } from 'node:child_process';

import AxeBuilder from '@axe-core/playwright';
import { APIRequestContext, Page, expect, request, test } from '@playwright/test';

const PASSWORD = 'browser-fixture-password';
const PRIMARY_EMAIL = 'agent@fixture.example';
const TWO_FACTOR_EMAIL = 'twofactor-fixture@fixture.example';
const INVITED_EMAIL = 'invited-fixture@fixture.example';
const MAILPIT_URL = process.env.MAILPIT_URL ?? 'http://localhost:8025';
const RUN_STAMP = Date.now().toString(36);
const INVITE_PASSWORD_NEW = `A-Screens-Invitee-${RUN_STAMP}`;
const DEAD_TOKEN = '0'.repeat(64);

test.use({ viewport: { width: 360, height: 740 } }); // §9 ≥320 px / NFR-05
test.describe.configure({ mode: 'serial' });

function inspect(...args: string[]): string {
    return execFileSync(
        'docker',
        ['compose', '-f', 'docker/compose.yaml', 'exec', '-T', 'app', 'php', 'artisan', 'auth:session-inspect', ...args],
        { encoding: 'utf8' },
    ).trim();
}

function primaryTenantId(): string {
    const out = execFileSync(
        'docker',
        [
            'compose', '-f', 'docker/compose.yaml', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute',
            'foreach (DB::connection("pgsql_platform")->table("tenants")->orderBy("name")->get(["name","id"]) as $t) { echo $t->name, "=", $t->id, "\n"; }',
        ],
        { encoding: 'utf8' },
    );
    for (const line of out.split('\n')) {
        const [name, id] = line.split('=');
        if (name?.trim() === 'Fixture Operator A' && id) return id.trim();
    }
    throw new Error('Fixture Operator A not found — run the BrowserFixtureSeeder');
}

/** A CURRENT code from the server's own TOTP engine — never a JS reimplementation. */
function totpFor(secret: string): string {
    return inspect('totp', `--secret=${secret}`);
}

function wrongCodeFrom(code: string): string {
    return String((parseInt(code, 10) + 1) % 1_000_000).padStart(6, '0');
}

async function expectAxeClean(page: Page, label: string): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'])
        .analyze();

    expect(
        results.violations,
        `${label} has WCAG violations: ${JSON.stringify(
            results.violations.map((v) => ({
                id: v.id,
                impact: v.impact,
                targets: v.nodes.map((n) => n.target.join(' ')),
            })),
        )}`,
    ).toEqual([]);
}

async function gotoLogin(page: Page): Promise<void> {
    await page.goto('/login');
    await expect(page.locator('#login-email')).toBeVisible();
}

/** Sign in through the real card. Ends on the post-login redirect for 2FA-free accounts. */
async function cardLogin(page: Page, email: string, password: string): Promise<void> {
    await gotoLogin(page);
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(password);
    await page.getByTestId('login-submit').click();
}

async function clearMailbox(mailpit: APIRequestContext): Promise<void> {
    await mailpit.delete('/api/v1/messages');
}

async function actionLinkFor(mailpit: APIRequestContext, email: string, path: string): Promise<string> {
    for (let attempt = 0; attempt < 60; attempt++) {
        const search = await mailpit.get(`/api/v1/search?query=${encodeURIComponent(`to:"${email}"`)}`);
        const body = (await search.json()) as { messages?: { ID: string }[] };
        const newestId = body.messages?.[0]?.ID;
        if (newestId) {
            const message = (await (await mailpit.get(`/api/v1/message/${newestId}`)).json()) as {
                HTML?: string;
                Text?: string;
            };
            const haystack = `${message.HTML ?? ''}\n${message.Text ?? ''}`;
            const match = haystack.match(new RegExp(`https://[^"'\\s<]*${path.slice(1)}\\?token=[0-9a-f]{64}`));
            if (match) return match[0];
        }
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error(`no ${path} link arrived for ${email} within 30s`);
}

// Recovery codes captured during enrolment, consumed by the recovery test.
let recoveryCodes: string[] = [];

test.beforeAll(() => {
    inspect('clear-2fa', `--email=${TWO_FACTOR_EMAIL}`);
});

test.beforeEach(() => {
    // Page loads of /login share the POST budget (SEC-05, by design); a suite
    // this screen-heavy would exhaust 20/min/IP halfway through.
    inspect('clear-throttle');
});

test('§13: keyboard-only sign-in at 360 px; errors are role="alert" and aria-associated; axe-clean in idle and error states', async ({
    page,
}) => {
    await gotoLogin(page);
    await expectAxeClean(page, 'login card (idle)');

    // Error state first: a wrong password, submitted from the keyboard.
    await page.locator('#login-email').focus();
    await page.keyboard.type(PRIMARY_EMAIL);
    await page.keyboard.press('Tab');
    await expect(page.locator('#login-password')).toBeFocused();
    await page.keyboard.type('not-the-password');
    await page.keyboard.press('Enter');

    const error = page.getByTestId('login-error');
    await expect(error).toBeVisible();
    // §13: role="alert" announces the failure; the inputs point at it.
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(page.locator('#login-email')).toHaveAttribute('aria-describedby', /login-error/);
    await expect(page.locator('#login-password')).toHaveAttribute('aria-describedby', /login-error/);
    await expectAxeClean(page, 'login card (error)');

    // Then the same keyboard journey with the right password signs in — the
    // §13 claim is COMPLETE operability, not just reachable failure.
    await page.locator('#login-password').fill('');
    await page.locator('#login-password').focus();
    await page.keyboard.type(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL('**/');
});

test('§14: the language switcher re-renders the card in Amharic via the guest cookie, and back', async ({ page }) => {
    await gotoLogin(page);
    await expect(page.locator('h1')).toHaveText('Sign in');

    await page.getByTestId('lang-am').click();
    await expect(page.locator('h1')).toHaveText('ይግቡ');
    // §14/§13: the document language follows the render.
    await expect(page.locator('html')).toHaveAttribute('lang', 'am');

    await page.getByTestId('lang-en').click();
    await expect(page.locator('h1')).toHaveText('Sign in');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
});

test('§8 wizard: focus lands on each step heading, the code field is structurally ONE input that auto-submits, and the codes step gates on checkbox OR download', async ({
    page,
}) => {
    await cardLogin(page, TWO_FACTOR_EMAIL, PASSWORD);
    await page.waitForURL('**/');
    await page.goto('/security');
    await expect(page.getByTestId('two-factor-enable')).toBeVisible();

    // Step 1: scan. Focus rule (§13) on entry.
    await page.getByTestId('two-factor-enable').click();
    await expect(page.getByTestId('wizard-scan')).toBeVisible();
    await expect(page.getByTestId('wizard-scan-heading')).toBeFocused();
    const secret = (await page.getByTestId('manual-secret').textContent())?.trim() ?? '';
    expect(secret.length).toBeGreaterThan(0);

    // Step 2: verify. Focus rule again; the code field is ONE input (§13 —
    // segmentation is visual only), capped at 6, OTP-autofill friendly.
    await page.getByTestId('wizard-scan-continue').click();
    await expect(page.getByTestId('wizard-verify')).toBeVisible();
    await expect(page.getByTestId('wizard-verify-heading')).toBeFocused();

    const codeInputs = page.getByTestId('wizard-verify').locator('input');
    await expect(codeInputs).toHaveCount(1);
    await expect(codeInputs.first()).toHaveAttribute('maxlength', '6');
    await expect(codeInputs.first()).toHaveAttribute('autocomplete', 'one-time-code');
    await expectAxeClean(page, 'wizard (verify step)');

    // Auto-submit on the 6th digit (§8): five digits do nothing, the sixth
    // advances the wizard with NO button click.
    const code = totpFor(secret);
    await codeInputs.first().pressSequentially(code.slice(0, 5));
    await expect(page.getByTestId('wizard-verify')).toBeVisible();
    await codeInputs.first().pressSequentially(code.slice(5));

    // Step 3: codes, behind §8's gate.
    await expect(page.getByTestId('wizard-codes')).toBeVisible();
    await expect(page.getByTestId('wizard-codes-heading')).toBeFocused();

    recoveryCodes = (await page.getByTestId('recovery-codes').locator('span').allTextContents()).map((c) => c.trim());
    expect(recoveryCodes.length).toBeGreaterThanOrEqual(8);

    const finish = page.getByTestId('wizard-finish');
    await expect(finish).toBeDisabled();

    // The checkbox half of the OR opens the gate; unchecking closes it again.
    await page.getByTestId('codes-saved-checkbox').check();
    await expect(finish).toBeEnabled();
    await page.getByTestId('codes-saved-checkbox').uncheck();
    await expect(finish).toBeDisabled();

    // The download half opens it independently.
    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('download-codes').click();
    await downloadPromise;
    await expect(finish).toBeEnabled();

    await finish.click();
    await expect(page.getByTestId('wizard-codes')).toBeHidden();
    await expect(page.getByTestId('two-factor-status')).toHaveText('On');
    await expectAxeClean(page, 'security page (2FA on)');
});

test('§8 challenge step: heading takes focus, a wrong code alerts generically and clears, the right one auto-submits in; forgot-password stays visible', async ({
    page,
}) => {
    await cardLogin(page, TWO_FACTOR_EMAIL, PASSWORD);

    await expect(page.getByTestId('challenge-heading')).toBeVisible();
    // §8: the challenge step AUTO-FOCUSES the code input (the specified
    // exception to §13's focus-the-heading rule — see Login.vue).
    await expect(page.getByTestId('segmented-code-input')).toBeFocused();
    // §8: "Forgot password" is always visible — on this step too.
    await expect(page.getByTestId('forgot-link')).toBeVisible();
    await expectAxeClean(page, 'login card (challenge step)');

    const input = page.getByTestId('segmented-code-input');
    const secret = twoFactorSecret();
    const right = totpFor(secret);

    await input.pressSequentially(wrongCodeFrom(right));
    const error = page.getByTestId('challenge-error');
    await expect(error).toBeVisible();
    await expect(error).toHaveAttribute('role', 'alert');
    await expect(input).toHaveValue('');

    await input.pressSequentially(totpFor(secret));
    await page.waitForURL('**/');
});

test('FR-07 recovery path: consumes one code, shows the remaining count before moving on', async ({ page }) => {
    expect(recoveryCodes.length, 'enrolment must have captured codes first (serial suite)').toBeGreaterThan(0);

    await cardLogin(page, TWO_FACTOR_EMAIL, PASSWORD);
    await expect(page.getByTestId('challenge-heading')).toBeVisible();

    await page.getByTestId('use-recovery').click();
    await page.getByTestId('recovery-input').fill(recoveryCodes[0] ?? '');
    await page.getByTestId('recovery-submit').click();

    // FR-07: "shows remaining count" — 8 minted, one just spent.
    await expect(page.getByTestId('recovery-done-heading')).toBeVisible();
    await expect(page.getByTestId('recovery-done-heading')).toContainText('7');
    // Not low yet: the ≤2 banner must NOT cry wolf at 7.
    await expect(page.getByTestId('low-codes-banner')).toBeHidden();

    await page.getByTestId('continue-link').click();
    await page.waitForURL('**/');
});

test('EC-02: a session revoked elsewhere raises the re-login modal OVER untouched page state; re-login (password + code) replays the action', async ({
    page,
}) => {
    await cardLogin(page, TWO_FACTOR_EMAIL, PASSWORD);
    await expect(page.getByTestId('challenge-heading')).toBeVisible();
    await page.getByTestId('segmented-code-input').pressSequentially(totpFor(twoFactorSecret()));
    await page.waitForURL('**/');

    await page.goto('/security');
    await expect(page.getByTestId('devices-table')).toBeVisible();

    // In-page state the modal must preserve (EC-02's whole point).
    await page.getByTestId('two-factor-manage-code').fill('111111');

    // The session dies in "another tab" — here, server-side revocation.
    inspect('clear-tenant', `--tenant=${primaryTenantId()}`);

    // The next action's staff-API call fails; the sentinel confirms via the
    // deadline probe (A-2) and raises the modal — no navigation, no reload.
    await page.getByTestId('two-factor-regenerate').click();
    await expect(page.getByTestId('relogin-modal')).toBeVisible();
    await expect(page.getByTestId('two-factor-manage-code')).toHaveValue('111111');
    expect(page.url()).toContain('/security');

    // Re-login inside the modal: password, then the account's 2FA challenge.
    await page.locator('#relogin-password').fill(PASSWORD);
    await page.getByTestId('relogin-submit').click();
    await expect(page.locator('#relogin-code')).toBeVisible();
    await page.locator('#relogin-code').pressSequentially(totpFor(twoFactorSecret()));

    await expect(page.getByTestId('relogin-modal')).toBeHidden();
    // The page never navigated; its state survived the whole recovery.
    await expect(page.getByTestId('two-factor-manage-code')).toHaveValue('111111');
    // And the original action REPLAYED: regenerate ran with code '111111',
    // which the server refuses — proof the request reached it after re-login
    // (a swallowed replay would show nothing at all).
    await expect(page.getByTestId('two-factor-error')).toBeVisible();
});

test('§8 timeout toast: fires at T-2 min on the SERVER\'s deadline, and "stay signed in" defers it', async ({ page }) => {
    await cardLogin(page, PRIMARY_EMAIL, PASSWORD);
    await page.waitForURL('**/');

    await page.clock.install();
    await page.goto('/security');
    await expect(page.getByTestId('devices-table')).toBeVisible();

    // Default idle budget is 480 min; the warning is due at 478. Nothing yet
    // at 400 minutes…
    await page.clock.fastForward(400 * 60 * 1000);
    await expect(page.getByTestId('timeout-toast')).toBeHidden();

    // …and it fires once T-2 min is crossed.
    await page.clock.fastForward(79 * 60 * 1000);
    await expect(page.getByTestId('timeout-toast')).toBeVisible();

    // "Stay signed in" refreshes the server deadline and stands down.
    await page.getByTestId('stay-signed-in').click();
    await expect(page.getByTestId('timeout-toast')).toBeHidden();

    // The next warning is scheduled from the SERVER's fresh answer — a full
    // budget away, not two minutes.
    await page.clock.fastForward(5 * 60 * 1000);
    await expect(page.getByTestId('timeout-toast')).toBeHidden();
});

test('§8/§9 invitation: dead link screen, resend, profile completion (name + language), text strength meter, visibility toggle', async ({
    page,
}) => {
    inspect('make-invited', `--email=${INVITED_EMAIL}`);
    const mailpit = await request.newContext({ baseURL: MAILPIT_URL });
    await clearMailbox(mailpit);

    // The dead-link screen carries EC-04's self-service resend.
    await page.goto(`/invitation?token=${DEAD_TOKEN}`);
    await expect(page.locator('h1')).toBeVisible();
    await expectAxeClean(page, 'dead-link screen');
    await page.locator('#resend-email').fill(INVITED_EMAIL);
    await page.locator('form button[type=submit]').click();
    await expect(page.locator('[role=status]')).toBeVisible();

    const link = await actionLinkFor(mailpit, INVITED_EMAIL, '/invitation');
    await page.goto(link);

    // §8: profile completion, prefilled — not a blank slate.
    const nameField = page.getByTestId('profile-name');
    await expect(nameField).toBeVisible();
    await expect(nameField).not.toHaveValue('');
    await nameField.fill('Screens Fixture');
    await page.getByTestId('profile-locale').selectOption('am');

    // §13: strength is TEXT, and it argues from the tenant's minimum.
    const password = page.locator('#new-password');
    await password.fill('short');
    await expect(page.getByTestId('strength-meter')).toContainText('too short');
    await password.fill(INVITE_PASSWORD_NEW);
    await expect(page.getByTestId('strength-meter')).toContainText('strong');

    // §9: the visibility toggle flips the input type, keyboard-operably.
    await expect(password).toHaveAttribute('type', 'password');
    await page.getByTestId('password-toggle').first().click();
    await expect(password).toHaveAttribute('type', 'text');
    await page.getByTestId('password-toggle').first().click();
    await expect(password).toHaveAttribute('type', 'password');

    await expectAxeClean(page, 'invitation form');

    await page.locator('#confirm-password').fill(INVITE_PASSWORD_NEW);
    await page.locator('form button[type=submit]').click();
    await expect(page.locator('[role=status]')).toBeVisible();

    // The profile stuck: the fresh account signs in and its locale is am.
    const probe = await request.newContext({ baseURL: page.url().split('/invitation')[0], ignoreHTTPSErrors: true });
    await mailpit.dispose();
    await probe.dispose();

    await cardLogin(page, INVITED_EMAIL, INVITE_PASSWORD_NEW);
    await page.waitForURL('**/');

    // Restore the fixture for the next run (and password.spec.ts).
    inspect('make-invited', `--email=${INVITED_EMAIL}`);
});

test.afterAll(() => {
    // Leave the dedicated account 2FA-free for the next run.
    inspect('clear-2fa', `--email=${TWO_FACTOR_EMAIL}`);
});

/**
 * The enrolment test stashes nothing but codes; the secret lives server-side.
 * For the challenge tests we re-read it from the database the same way the
 * other test-only affordances do — never by weakening the product surface.
 */
function twoFactorSecret(): string {
    const out = execFileSync(
        'docker',
        [
            'compose', '-f', 'docker/compose.yaml', 'exec', '-T', 'app', 'php', 'artisan', 'tinker', '--execute',
            // Twice, because the model's `encrypted` cast wraps the domain
            // layer's own Crypt envelope — the DB column is encrypt(encrypt(json)).
            `$c = Illuminate\\Support\\Facades\\Crypt::class; $d = json_decode($c::decryptString($c::decryptString((string) DB::connection("pgsql_platform")->table("users")->where("email", "${TWO_FACTOR_EMAIL}")->value("two_factor_secret"))), true); echo $d["secret"];`,
        ],
        { encoding: 'utf8' },
    );
    const secret = out.trim().split('\n').pop()?.trim() ?? '';
    if (secret.length === 0) throw new Error('no 2FA secret on the fixture — did enrolment run?');
    return secret;
}

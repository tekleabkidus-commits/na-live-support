import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * S07 step 11 — the composer. AC-05's HEURISTIC: 50 scripted reply-intent
 * sequences into note mode, each asserting the differentiation checks (the
 * computed surface contrast delta + the label assertions — BR-02's
 * non-themeable floor). FR-20's state renders (normal / closing / expired
 * options card / conversation-blocked from REAL conversation data;
 * paused/blocked_by_user resolve in the same computed, seam-prop-driven
 * until Doc 05's connection linkage reaches the thread payload — stated in
 * BUILD-STATUS). FR-21 split button + rollback + one-tap retry against the
 * REAL matrix. FR-22 draft chip. FR-16 collision strip + send race
 * (sabotage #8's target) via the scripted hook — the same entry points the
 * realtime feed will call.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

function artisan(...args: string[]): void {
    try {
        execFileSync(
            'docker',
            ['compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml', 'exec', '-T', 'app', 'php', 'artisan', ...args],
            { stdio: 'pipe' },
        );
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(`${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`);
    }
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openThread(page: Page, rowText: string, view: string | null = null): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    if (view !== null) {
        await page.locator(`[data-view="${view}"]`).first().click();
    }
    await page.locator('[data-ticket]', { hasText: rowText }).locator('[data-role="row-open"]').click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('AC-05, THE 50-SEQUENCE HEURISTIC: every scripted reply-intent flip into note mode lands the FULL differentiation — surface color DELTA, dashed border, the banner, and the send label flips to "Add note"', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');

    const surface = page.getByTestId('composer-surface');
    const replyBg = await surface.evaluate((el) => getComputedStyle(el).backgroundColor);
    const replyLabel = await page.getByTestId('composer-send').textContent();
    expect(replyLabel?.trim()).toBe('Send reply');

    for (let sequence = 1; sequence <= 50; sequence++) {
        // The reply-intent shape: type as if replying, then flip to note.
        await page.getByTestId('composer-input').fill(`reply intent ${sequence}`);
        await page.getByTestId('mode-note').click();

        // Differentiation, EVERY time (BR-02's floor): the surface color
        // CHANGED, the dashed border is present, the banner labels the mode,
        // the send button says "Add note".
        const noteBg = await surface.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(noteBg, `sequence ${sequence}: no background delta`).not.toBe(replyBg);
        const borderStyle = await surface.evaluate((el) => getComputedStyle(el).borderTopStyle);
        expect(borderStyle, `sequence ${sequence}: border not dashed`).toBe('dashed');
        await expect(page.getByTestId('note-mode-banner'), `sequence ${sequence}`).toBeVisible();
        await expect(page.getByTestId('composer-send')).toHaveText('Add note');

        await page.getByTestId('mode-reply').click();
        await expect(page.getByTestId('composer-send')).toHaveText('Send reply');
    }
});

test('FR-17/FR-21: a NOTE sends through the note door (bubble renders as a note); a reply through Send & Pending flips the ticket; the ILLEGAL then-edge reports the rollback strip with the one-tap retry', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');

    // Note path.
    await page.getByTestId('mode-note').click();
    await page.getByTestId('composer-input').fill('E2E internal note — never delivered');
    await page.getByTestId('composer-send').click();
    await expect(page.locator('[data-role="note-banner"]').last()).toBeVisible();

    // Reply + Send & Pending (open → pending_customer: legal).
    await page.getByTestId('mode-reply').click();
    await page.getByTestId('composer-input').fill('Need one more detail.');
    await page.getByTestId('split-toggle').click();
    await page.getByTestId('send-pending').click();
    await expect(page.getByTestId('composer-input')).toHaveValue('');

    // Second reply + Send & Resolve (pending_customer → resolved: ILLEGAL) —
    // the reply sends, the rollback strip renders, the retry waits.
    await page.getByTestId('composer-input').fill('Actually, resolving.');
    await page.getByTestId('split-toggle').click();
    await page.getByTestId('send-resolve').click();
    await expect(page.getByTestId('transition-rollback')).toBeVisible();
    await expect(page.getByTestId('transition-retry')).toBeVisible();
});

test('FR-20: the CLOSING strip renders on the seeded closing-window thread; FR-22: typing raises the draft chip and persists the draft server-side', async ({
    page,
}) => {
    await openThread(page, 'Kidist Haile', 'unassigned');

    await expect(page.getByTestId('composer')).toHaveAttribute('data-state', 'closing');
    await expect(page.getByTestId('closing-strip')).toBeVisible();

    await page.getByTestId('composer-input').fill('half-typed reply that should persist');
    await expect(page.getByTestId('draft-chip')).toBeVisible();
    // The debounced PATCH lands server-side.
    await expect
        .poll(async () =>
            page.evaluate(async () => {
                const response = await fetch(window.location.origin + '/inbox/list?view=unassigned', { headers: { Accept: 'application/json' } });
                const body = (await response.json()) as { rows: { number: number; has_draft: boolean }[] };
                return body.rows.find((row) => row.number === 4802)?.has_draft ?? false;
            }),
        )
        .toBe(true);
});

test('FR-16 (sabotage #8\'s proof): the collision strip renders on a colleague-typing signal — their draft NEVER shows — and a colleague reply raises the NON-BLOCKING send-race confirm; "send anyway" sends', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');

    // The scripted feed: the SAME entry point the realtime typing signal
    // will call.
    await page.evaluate(() => (window as unknown as { __inboxComposer: { typing: (n: string | null) => void } }).__inboxComposer.typing('Sara Bekele'));
    const strip = page.getByTestId('collision-strip');
    await expect(strip).toBeVisible();
    await expect(strip).toContainText('Sara Bekele is replying…');
    await expect(strip).not.toContainText('draft'); // their draft is never shown

    await page.evaluate(() => (window as unknown as { __inboxComposer: { typing: (n: string | null) => void } }).__inboxComposer.typing(null));
    await expect(strip).toHaveCount(0);

    // Send race: colleague replied while this agent typed.
    await page.getByTestId('composer-input').fill('my reply, racing');
    await page.evaluate(() => (window as unknown as { __inboxComposer: { colleagueReplied: (n: string) => void } }).__inboxComposer.colleagueReplied('Sara Bekele'));
    await page.getByTestId('composer-send').click();
    const race = page.getByTestId('race-dialog');
    await expect(race).toBeVisible();
    await expect(race).toContainText('Sara Bekele just replied — send anyway?');
    await page.getByTestId('race-send').click();
    await expect(page.getByTestId('composer-input')).toHaveValue('');
});

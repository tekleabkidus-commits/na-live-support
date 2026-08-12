import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * B1-S01 — the inbox shell at desktop (S07 step 8).
 * Doc 07 FR-01 (four regions, persisted sizes), FR-03 (top bar + the
 * health dot/banner consuming step 3 — the Doc 05 FS-01 closing row, R-25
 * ink-ramp banner), §9 (density toggle, fixed tab order + badge dots via
 * the Doc 08/09 seam fixtures), §13/B12-A01 AC-02 (landmarks + skip links:
 * "Screen-reader landmark list names R1–R4. First tab press on a cold load
 * reveals the skip link."), AC-01 (persisted sizes/collapse restore across
 * sessions), and the COUNTERSIGNED PREFS RIDER (4+5 acceptance): Read Only
 * degrades to session-local prefs, silent refusal, zero error toasts.
 *
 * Functional-state + a11y coverage; NO pixel baselines (inherited row 12).
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const READ_ONLY_EMAIL = 'readonly-fixture@fixture.example';
const V1440 = { width: 1440, height: 900 };

function artisan(...args: string[]): void {
    try {
        execFileSync(
            'docker',
            [
                'compose',
                '-f',
                'docker/compose.yaml',
                '-f',
                'docker/compose.dev.yaml',
                'exec',
                '-T',
                'app',
                'php',
                'artisan',
                ...args,
            ],
            { stdio: 'pipe' },
        );
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(
            `${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`,
        );
    }
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page, email: string = AGENT_EMAIL): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}

async function axeClean(page: Page, ctx: string): Promise<void> {
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(r.violations, `${ctx}: ${JSON.stringify(r.violations.map((v) => v.id))}`).toEqual([]);
}

for (const theme of ['light', 'dark'] as const) {
    test(`FR-01/FR-03: four regions + top bar render at 1440 (${theme}); landmarks name R1–R4; health dot shows the seeded degraded state`, async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await setTheme(page, theme);
        await page.goto('/inbox');
        await dismissCoachMarks(page);

        // The four regions (FR-01 at ≥1280).
        await expect(page.getByTestId('views-rail')).toBeVisible();
        await expect(page.getByTestId('list-region')).toBeVisible();
        await expect(page.getByTestId('thread-region')).toBeVisible();
        await expect(page.getByTestId('context-region')).toBeVisible();

        // B12-A01 AC-02: "Screen-reader landmark list names R1–R4."
        await expect(page.getByRole('navigation', { name: 'Views' })).toBeVisible();
        await expect(page.getByRole('region', { name: 'Ticket list' })).toBeVisible();
        await expect(page.getByRole('main', { name: 'Conversation' })).toBeVisible();
        await expect(page.getByRole('complementary', { name: 'Ticket context' })).toBeVisible();

        // FR-03, the frame's order: search (⌘K), New ticket, health, bell,
        // status, profile.
        await expect(page.getByTestId('global-search')).toBeVisible();
        await expect(page.getByTestId('new-ticket')).toBeVisible();
        await expect(page.getByTestId('notification-bell')).toBeVisible();
        await expect(page.getByTestId('agent-status')).toBeVisible();
        await expect(page.getByTestId('profile-menu')).toBeVisible();

        // The seeded degraded endpoint rolls up (Doc 05 FR-29 → FR-03 dot)…
        const indicator = page.getByTestId('health-indicator');
        await expect(indicator).toHaveAttribute('data-health-worst', 'degraded');

        // …and the banner opens ON OPEN (FR-03), listing the endpoint.
        await indicator.click();
        await expect(page.getByTestId('health-banner')).toBeVisible();
        await expect(page.getByTestId('health-banner')).toContainText('telegram:fixture_brand_bot');

        // The seven system views, with counts, and the seeded saved views.
        for (const view of [
            'my_open',
            'unassigned',
            'mentions',
            'following',
            'sla_at_risk',
            'expiring_windows',
            'rescue',
        ]) {
            await expect(page.locator(`[data-view="${view}"]`)).toBeVisible();
        }
        await expect(page.getByTestId('views-rail')).toContainText('Fixture urgent open');
        await expect(page.getByTestId('views-rail')).toContainText('Fixture VIP pending');

        await axeClean(page, `shell 1440 ${theme}`);
    });
}

test('B12-A01 AC-02: first tab press on a cold load reveals the skip link, and it jumps PAST the rail into the list', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    // Hydration must complete before the FIRST Tab means anything — the
    // full-suite run caught the press racing the Vue mount.
    await expect(page.getByTestId('views-rail')).toBeVisible();

    await page.keyboard.press('Tab');
    const skip = page.getByTestId('skip-to-list');
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();

    await page.keyboard.press('Enter');
    await expect(page.getByTestId('list-region')).toBeFocused();
});

test('§9: the three context tabs keep fixed order; the Doc 08/09 seam fixtures drive the badge dots with §13 labels', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);

    // Without the seam override: tabs render, order fixed, no dots.
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    const tabs = page.getByRole('tab');
    await expect(tabs).toHaveText(['Ticket', 'Customer', 'Copilot']);
    await expect(page.getByTestId('badge-customer')).toHaveCount(0);
    await expect(page.getByTestId('badge-copilot')).toHaveCount(0);

    // Tab panels are SLOTS that state their seam honestly.
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('panel-customer')).toContainText('Doc 08');
    await page.getByTestId('tab-copilot').click();
    await expect(page.getByTestId('panel-copilot')).toContainText('Doc 09');

    // With the seam fixtures loaded (local/ci override): both dots render,
    // labelled, never colour alone.
    await page.goto('/inbox?seam_badges=1');
    await expect(page.getByTestId('badge-customer')).toBeVisible();
    await expect(page.getByTestId('badge-copilot')).toBeVisible();
    await expect(page.getByRole('img', { name: 'Customer tab has an alert' })).toBeVisible();
    await expect(page.getByRole('img', { name: 'Copilot has a new suggestion' })).toBeVisible();
});

test('AC-01: rail collapse, list width (keyboard resize — §13) and density persist, and RESTORE ACROSS SESSIONS', async ({
    page,
    browser,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);

    // Collapse the rail (56 px icons).
    await page.getByTestId('rail-collapse').click();
    await expect(page.getByTestId('views-rail')).toHaveAttribute('data-collapsed', 'true');

    // Resize the list with the KEYBOARD (the drag equivalent §13 requires):
    // 4 × 16 px = 360 → 424.
    const handle = page.getByTestId('list-resize-handle');
    await handle.focus();
    for (let i = 1; i <= 4; i++) {
        await page.keyboard.press('ArrowRight');
        // Each step awaited: a fixed burst can outrun the re-render and
        // swallow a press.
        await expect(handle).toHaveAttribute('aria-valuenow', String(360 + 16 * i));
    }

    // Density → compact (§9: persisted).
    await page.getByTestId('profile-menu').click();
    await page.getByTestId('density-compact').click();
    await expect(page.getByTestId('inbox-workspace')).toHaveAttribute('data-density', 'compact');

    // Give the last PATCH its round-trip before tearing the page down.
    await expect
        .poll(async () => {
            const r = await page.request.get('/inbox/prefs');
            const body = (await r.json()) as {
                prefs?: { density?: string; regions?: { list_width?: number } };
            };
            return `${body.prefs?.density}/${body.prefs?.regions?.list_width}`;
        })
        .toBe('compact/424');

    // A NEW browser context = a new session: server prefs must restore all
    // three choices (AC-01's "restore across sessions").
    const fresh = await browser.newContext({ ignoreHTTPSErrors: true });
    const page2 = await fresh.newPage();
    await login(page2);
    await page2.setViewportSize(V1440);
    await page2.goto('/inbox');
    await expect(page2.getByTestId('views-rail')).toHaveAttribute('data-collapsed', 'true');
    await expect(page2.getByTestId('list-resize-handle')).toHaveAttribute('aria-valuenow', '424');
    await expect(page2.getByTestId('inbox-workspace')).toHaveAttribute('data-density', 'compact');

    // Leave the shared fixture account in the default layout for other tests.
    await page2.getByTestId('rail-collapse').click();
    await page2.getByTestId('profile-menu').click();
    await page2.getByTestId('density-comfortable').click();
    const restoreHandle = page2.getByTestId('list-resize-handle');
    await restoreHandle.focus();
    for (let i = 1; i <= 4; i++) {
        await page2.keyboard.press('ArrowLeft');
        await expect(restoreHandle).toHaveAttribute('aria-valuenow', String(424 - 16 * i));
    }
    await expect
        .poll(async () => {
            const r = await page2.request.get('/inbox/prefs');
            const body = (await r.json()) as { prefs?: { density?: string } };
            return body.prefs?.density;
        })
        .toBe('comfortable');
    await fresh.close();
});

test('THE COUNTERSIGNED PREFS RIDER: Read Only degrades to session-local prefs — silent refusal, ZERO error toasts, nothing server-persisted', async ({
    page,
    browser,
}) => {
    await login(page, READ_ONLY_EMAIL);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');

    // The workspace READS fine (tickets.view is the audience key).
    await expect(page.getByTestId('views-rail')).toBeVisible();

    // The 403 the store is about to swallow is DESIGNED — watch for it, and
    // for any toast that must never appear.
    const refusal = page.waitForResponse(
        (r) => r.url().includes('/inbox/prefs') && r.status() === 403,
    );
    // Guard AFTER the waiter is armed: the Read Only coach dismissal is
    // itself a prefs write, so its designed 403 is the first one the store
    // swallows — the waiter catches whichever prefs write refuses first.
    await dismissCoachMarks(page);
    await page.getByTestId('profile-menu').click();
    await page.getByTestId('density-compact').click();
    await refusal;

    // The choice APPLIED (session-local)…
    await expect(page.getByTestId('inbox-workspace')).toHaveAttribute('data-density', 'compact');
    // …with zero error surfaces (the rider's exact bar).
    await expect(page.getByRole('alert')).toHaveCount(0);

    // Same tab, reload: sessionStorage carries the session-local choice.
    await page.reload();
    await expect(page.getByTestId('inbox-workspace')).toHaveAttribute('data-density', 'compact');

    // A NEW session: the choice is GONE (nothing reached the server) —
    // "remembers its choices for the session and forgets them after".
    const fresh = await browser.newContext({ ignoreHTTPSErrors: true });
    const page2 = await fresh.newPage();
    await login(page2, READ_ONLY_EMAIL);
    await page2.goto('/inbox');
    await expect(page2.getByTestId('inbox-workspace')).toHaveAttribute(
        'data-density',
        'comfortable',
    );
    await fresh.close();
});

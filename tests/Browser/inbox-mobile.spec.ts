import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * B1-S01 — the FR-02 breakpoint ladder below 1280 (S07 step 8):
 *   1024–1279  R4 becomes an overlay drawer
 *   768–1023   R1 collapses to icons PERMANENTLY
 *   <768       two-level navigation (List ↔ Thread), R4 = bottom sheet
 *              with the SAME three tabs, Views = modal sheet from header
 * Functional-state + a11y coverage; NO pixel baselines (inherited row 12).
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

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

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
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

test('FR-02 @ 1024–1279: R4 is an overlay drawer, not a fixed panel', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 1100, height: 800 });
    await page.goto('/inbox');

    await expect(page.getByTestId('context-region')).toHaveCount(0);
    const toggle = page.getByTestId('context-drawer-toggle');
    await expect(toggle).toBeVisible();

    await toggle.click();
    await expect(page.getByTestId('context-drawer')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Copilot' })).toBeVisible();
});

test('FR-02 @ 768–1023: the rail is icons PERMANENTLY — the collapse control cannot expand it', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 900, height: 800 });
    await page.goto('/inbox');

    const rail = page.getByTestId('views-rail');
    await expect(rail).toHaveAttribute('data-collapsed', 'true');

    // FR-02: "collapses to icons permanently" — the toggle is inert here.
    await page.getByTestId('rail-collapse').click();
    await expect(rail).toHaveAttribute('data-collapsed', 'true');
});

for (const theme of ['light', 'dark'] as const) {
    test(`FR-02 @ 375 (${theme}): two-level navigation — List ↔ Thread, Views modal sheet, R4 bottom sheet with the same three tabs`, async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize({ width: 375, height: 812 });
        await setTheme(page, theme);
        await page.goto('/inbox');

        // Level 1: the list screen; no thread on screen.
        await expect(page.getByTestId('list-region')).toBeVisible();
        await expect(page.getByTestId('thread-region')).toHaveCount(0);

        // Views = modal sheet from the header.
        await page.getByTestId('mobile-views-trigger').click();
        const viewsSheet = page.getByTestId('mobile-views-sheet');
        await expect(viewsSheet).toBeVisible();
        await expect(viewsSheet.locator('[data-view="my_open"]')).toBeVisible();
        await page.getByTestId('views-sheet-backdrop').click({ position: { x: 10, y: 10 } });
        await expect(viewsSheet).toHaveCount(0);

        // Level 2: the thread screen replaces the list.
        await page.getByTestId('mobile-open-thread').click();
        await expect(page.getByTestId('thread-region')).toBeVisible();
        await expect(page.getByTestId('list-region')).toHaveCount(0);

        // R4 = bottom sheet, SAME three tabs in the same fixed order.
        await page.getByTestId('mobile-context-trigger').click();
        await expect(page.getByTestId('mobile-context-sheet')).toBeVisible();
        await expect(page.getByRole('tab')).toHaveText(['Ticket', 'Customer', 'Copilot']);

        await axeClean(page, `mobile 375 ${theme}`);

        // Back to level 1.
        await page.getByTestId('context-sheet-backdrop').click({ position: { x: 10, y: 10 } });
        await page.getByTestId('mobile-back').click();
        await expect(page.getByTestId('list-region')).toBeVisible();
    });
}

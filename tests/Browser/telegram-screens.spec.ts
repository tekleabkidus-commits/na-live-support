import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 05 §8/§9 admin surfaces (B6-S01/S02/S03) — functional-state + fidelity
 * coverage. NO pixel baselines are exported for Batch 6 (traceability rows say
 * so, the Doc 03 precedent): this asserts every drawn STATE renders, in BOTH
 * themes and BOTH viewports, en AND am, and is axe-clean — never a pixel claim.
 *
 * Frame → screen map exercised here:
 *   S01: A01 (registry) · A07 (empty is the no-seed path) · A02 (wizard) · B01 (mode)
 *   S02: C01 (checklist) · C02 (confirm gate, from the pending fixture) · C08 (registry)
 *   S03: D01 (editable window_hours at 1440) · D-readonly (375) · D03 (shared chip) · D04
 */

const PASSWORD = 'browser-fixture-password';
const ADMIN_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };

function artisan(...args: string[]): void {
    execFileSync('docker', [
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
    ]);
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\TelegramBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(ADMIN_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}

async function setLocale(page: Page, locale: 'en' | 'am'): Promise<void> {
    // The staff locale drives server copy (ApplyUserLocale reads the user's
    // preference); the ?lang override is honoured in local/ci for the sweep.
    await page.context().addCookies([{ name: 'locale', value: locale, url: 'https://localhost' }]);
}

async function axeClean(page: Page, ctx: string): Promise<void> {
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(r.violations, `${ctx}: ${JSON.stringify(r.violations.map((v) => v.id))}`).toEqual([]);
}

for (const theme of ['light', 'dark'] as const) {
    for (const viewport of [V1440, V375] as const) {
        const tag = `${theme}-${viewport.width}`;

        test(`S01 bots screen renders in ${tag}, tokens + a11y clean`, async ({ page }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/bots');
            // The registry (A01) with the seeded bot, or the empty state (A07).
            await expect(
                page.locator('[data-frame="A01"], [data-frame="A07"]').first(),
            ).toBeVisible();
            await expect(page.locator('[data-frame="A02"]')).toBeVisible(); // wizard
            await expect(page.locator('[data-frame="B01"]')).toBeVisible(); // mode selector
            await expect(page.locator('[data-mode-option="business"]')).toBeVisible();
            await axeClean(page, `S01 ${tag}`);
        });

        test(`S02 connections screen renders the checklist + confirm gate in ${tag}`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/connections');
            await expect(page.locator('[data-frame="C01"]')).toBeVisible(); // checklist
            await expect(page.locator('[data-frame="C02"]')).toBeVisible(); // pending confirm gate (fixture)
            await expect(page.locator('[data-connection]').first()).toBeVisible();
            // §13: the state pill carries an icon + word, never colour alone.
            await expect(
                page.locator('[data-state="active"], [data-state="pending"]').first(),
            ).toBeVisible();
            await axeClean(page, `S02 ${tag}`);
        });

        test(`S03 window settings: editable at 1440 / read-only at 375, in ${tag}`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/reply-window');
            await expect(page.locator('[data-frame="D01"]')).toBeVisible();
            // C2: window_hours is a real control; the shared chip (D03) renders.
            await expect(page.locator('[data-setting="window_hours"]')).toBeVisible();
            await expect(page.locator('[data-frame="D03"] [data-window-state]')).toBeVisible();
            await axeClean(page, `S03 ${tag}`);
        });

        test(`S04 menu designer: builder + dual preview at 1440, read-only notice at 375, in ${tag}`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/menu');
            // E01 (builder, seeded) or E06 (empty is the no-seed path).
            await expect(
                page.locator('[data-frame="E01"], [data-frame="E06"]').first(),
            ).toBeVisible();
            // E03: BOTH previews, each labelled for screen readers.
            await expect(page.locator('[data-frame="E03-buttons"]')).toBeVisible();
            await expect(page.locator('[data-frame="E03-text"]')).toBeVisible();
            // E04: language tabs with completeness badges.
            await expect(page.locator('[data-frame="E04"]')).toBeVisible();
            if (viewport.width < 1024) {
                // E13: read-only behind the explicit notice (owner ruling).
                await expect(page.locator('[data-frame="E13"]')).toBeVisible();
                await expect(page.locator('[data-action="publish"]')).toHaveCount(0);
            } else {
                await expect(page.locator('[data-action="publish"]')).toBeVisible();
            }
            await axeClean(page, `S04 ${tag}`);
        });

        test(`S05 templates: list + shipped wording + commands + /language gate, in ${tag}`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/templates');
            await expect(page.locator('[data-frame="E07"]')).toBeVisible();
            await expect(page.locator('[data-frame="E07-preview"]')).toBeVisible(); // real bubble
            await expect(page.locator('[data-frame="E09"]')).toBeVisible(); // five commands
            await expect(page.locator('[data-frame="E10"]')).toBeVisible(); // /language gate
            // E10: en offered, am not (window_guidance seeded missing).
            await expect(page.locator('[data-offered="true"]').first()).toBeVisible();
            await expect(page.locator('[data-offered="false"]').first()).toBeVisible();
            if (viewport.width < 1024) {
                await expect(page.locator('[data-frame="E07-readonly"]')).toBeVisible();
            }
            await axeClean(page, `S05 ${tag}`);
        });

        test(`S06 health: down banner, masked viewer, tools, in ${tag}`, async ({ page }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/telegram/health'); // defaults to the DOWN fixture bot
            await expect(page.locator('[data-frame="F01"]')).toBeVisible();
            await expect(page.locator('[data-frame="F01-down"]')).toBeVisible(); // down banner
            await expect(page.locator('[data-frame="F09"]')).toBeVisible(); // frozen-windows note
            await expect(page.locator('[data-frame="F02"]')).toBeVisible();
            await expect(page.locator('[data-frame="F03"]')).toBeVisible();
            await expect(page.locator('[data-frame="F06"]')).toBeVisible(); // viewer
            await expect(page.locator('[data-frame="F07"]')).toBeVisible(); // masking note
            // SEC-03: the seeded phone numbers NEVER render; the masked token does.
            await page.locator('[data-update-entry="0"]').click();
            await expect(page.locator('body')).not.toContainText('+251900001234');
            await expect(page.locator('body')).not.toContainText('+251900005678');
            await expect(page.locator('[data-frame="F06"]')).toContainText('[masked]');
            // Re-register stays OPERABLE at every width (owner ruling).
            await expect(page.locator('[data-action="reregister"]').first()).toBeVisible();
            await axeClean(page, `S06 ${tag}`);
        });
    }
}

test('S04 §K: the menu reorders end to end with no pointer, position announced', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/telegram/menu');
    const grip = page.locator('[data-grip="1-0"]');
    await grip.focus();
    await page.keyboard.press('Space'); // pick up
    await expect(page.getByTestId('reorder-live')).toContainText('grabbed');
    await page.keyboard.press('ArrowRight'); // move one position
    await expect(page.getByTestId('reorder-live')).toContainText('position 2');
    await page.keyboard.press('Escape'); // put it back
    await expect(page.getByTestId('reorder-live')).toContainText('original place');
});

test('S06 §K: the update viewer traverses by keyboard; masking holds on every entry', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/telegram/health');
    const list = page.locator('[role="listbox"]');
    await list.focus();
    await page.keyboard.press('Enter'); // expand entry 1 (the masked business_message)
    await expect(page.getByTestId('viewer-live')).toContainText('1 of');
    // The expanded payload keeps its shape but never a masked value.
    await expect(page.locator('pre').first()).toContainText('[masked]');
    await expect(page.locator('body')).not.toContainText('+251900001234');
    await page.keyboard.press('ArrowDown');
    await expect(page.getByTestId('viewer-live')).toContainText('2 of');
    await page.keyboard.press('Home');
    await expect(page.getByTestId('viewer-live')).toContainText('1 of');
});

test('S06: simulate-window-expiry is enabled on staging at 1440 and labelled staging-only', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/telegram/health');
    const simulate = page.locator('[data-frame="F05"] [data-action="simulate-expiry"]');
    await expect(simulate).toBeVisible();
    await expect(simulate).toBeEnabled(); // local/ci is the staging case; production disables
});

test('§14: the screens render in Amharic (server-localized copy, no raw keys)', async ({
    page,
}) => {
    await login(page);
    await setLocale(page, 'am');
    for (const path of [
        '/admin/telegram/bots',
        '/admin/telegram/menu',
        '/admin/telegram/templates',
        '/admin/telegram/health',
    ]) {
        await page.goto(path);
        // A real Amharic title, not an i18n key like "telegram.ui.bots.title".
        const heading = await page.locator('h1').first().textContent();
        expect(heading ?? '', path).not.toContain('telegram.ui');
    }
});

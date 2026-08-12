import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Doc 04 step 9b — the FS-05 dead-letter ops view in the real browser
 * (ruling R-17: the session's one class-2 design fill, browser-proven):
 *
 *  - the Super Admin fixture sees the parked item, its FULL payload behind
 *    the toggle, and re-drives it through the inline confirmation;
 *  - the Read Only fixture (conversations.view but no tickets.reply) sees
 *    the same list with NO re-drive control (A-6's split, DOM-level);
 *  - §13 posture: axe-clean, light and dark.
 */
function artisan(...args: string[]): void {
    execFileSync('docker', [
        'compose',
        '-f',
        'docker/compose.yaml',
        'exec',
        '-T',
        'app',
        'php',
        'artisan',
        ...args,
    ]);
}

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

const PASSWORD = 'browser-fixture-password';
const ADMIN_EMAIL = 'agent@fixture.example';
const READER_EMAIL = 'readonly-fixture@fixture.example';

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function expectAxeClean(page: Page, context: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(
        results.violations,
        `${context}: ${JSON.stringify(results.violations.map((v) => v.id))}`,
    ).toEqual([]);
}

test.beforeAll(() => {
    // Self-seeding (advisor ruling, 2026-08-12): DeadLetterBrowserSeeder
    // throws 'Run BrowserFixtureSeeder first' on a fresh DB — the fixture
    // tenant and users must be this file's own responsibility, not an
    // accident of which spec ran earlier.
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
});

test.describe('FS-05 — parked list + re-drive', () => {
    test('operator sees the parked item with its full payload and re-drives it through the confirmation', async ({
        page,
    }) => {
        artisan('db:seed', '--class=Database\\Seeders\\DeadLetterBrowserSeeder', '--force');

        await login(page, ADMIN_EMAIL);
        await page.goto('/conversations/dead-letters');

        const table = page.getByTestId('dead-letters-table');
        await expect(table).toBeVisible();

        // Select the SEEDED row — parked rows are never deleted (grant physics),
        // so a dev stack may hold other genuine parks alongside the fixture.
        const row = page
            .locator('[data-testid^="dead-letter-row-"]')
            .filter({ hasText: 'browser-fixture' })
            .filter({ hasText: 'InvalidArgumentException' })
            .first();
        await expect(row).toContainText('fakechan/browser-fixture');

        const rowId = (await row.getAttribute('data-testid'))!.replace('dead-letter-row-', '');

        // The FULL payload behind the toggle (FS-05's "with full payload").
        await page.getByTestId(`dead-letter-payload-toggle-${rowId}`).click();
        const payload = page.getByTestId(`dead-letter-payload-${rowId}`);
        await expect(payload).toContainText('"poison": true');
        await expect(payload).toContainText('@fixture_deadletter');

        await expectAxeClean(page, 'dead-letters list (light)');

        // Re-drive through the inline confirmation — never a browser dialog.
        await page.getByTestId(`dead-letter-redrive-${rowId}`).click();
        await expect(page.getByTestId(`dead-letter-confirm-${rowId}`)).toBeVisible();
        await page.getByTestId(`dead-letter-confirm-yes-${rowId}`).click();

        // The row leaves the parked list (redriven rows are history, not work).
        await expect(page.locator(`[data-testid="dead-letter-row-${rowId}"]`)).toHaveCount(0, {
            timeout: 10_000,
        });
    });

    test('Read Only sees the list but NO re-drive control (A-6 split, DOM half)', async ({
        page,
    }) => {
        artisan('db:seed', '--class=Database\\Seeders\\DeadLetterBrowserSeeder', '--force');

        await login(page, READER_EMAIL);
        await page.goto('/conversations/dead-letters');

        await expect(page.getByTestId('dead-letters-table')).toBeVisible();
        await expect(page.locator('[data-testid^="dead-letter-redrive-"]')).toHaveCount(0);
    });

    test('dark theme is axe-clean too', async ({ page }) => {
        artisan('db:seed', '--class=Database\\Seeders\\DeadLetterBrowserSeeder', '--force');

        await page.emulateMedia({ colorScheme: 'dark' });
        await login(page, ADMIN_EMAIL);
        await page.goto('/conversations/dead-letters');
        await expect(page.getByTestId('dead-letters-table')).toBeVisible();
        await expectAxeClean(page, 'dead-letters list (dark)');
    });
});

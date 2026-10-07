import { expect, test, type Page } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';
import { seedSmsRow, markDelivered, resetSmsTimeline } from './support/sms-seed';
import { execFileSync } from 'node:child_process';

/*
 * Doc 14 US-02 / AC-09 / B12-S02 — the SMS timeline row: "delivered turns
 * green" on a REAL DLR (the double-check appears only after the DLR moves the
 * row to delivered), the masked phone shows (never the full number), and the
 * B12-S02 menu case renders — Copy text, no Edit, no Delete.
 */

function artisan(...args: string[]): void {
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
}

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function openYonas(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

async function reopenYonas(page: Page): Promise<void> {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('US-02: a sent row shows the masked phone and a single check; after a real DLR it turns to the double-check (delivered)', async ({
    page,
}) => {
    resetSmsTimeline();
    const id = seedSmsRow('sent', '+251912344417');
    await openYonas(page);

    const chip = page.locator('[data-testid="sms-chip"]').first();
    await expect(chip).toContainText('+251 91 ••• 4417');
    await expect(chip).toContainText('Sent');
    await expect(chip).toHaveAttribute('data-sms-state', 'sent');
    await expect(page.locator('[data-testid="ticket-timeline"]')).not.toContainText('✓✓');

    // a real DLR arrives → the row moves to delivered → the chip turns green (the double-check)
    markDelivered(id);
    await reopenYonas(page); // re-open the thread (already authed) so the timeline refetches the now-delivered row
    await expect(page.locator('[data-sms-state="delivered"]').first()).toBeVisible();
    await expect(page.locator('[data-sms-state="delivered"]').first()).toContainText('✓✓');
});

test('B12-S02: the SMS entry offers Copy text and no Edit/Delete — the why-line explains the channel offers no recall', async ({
    page,
}) => {
    resetSmsTimeline();
    seedSmsRow('sent');
    await openYonas(page);

    await expect(page.getByTestId('sms-menu-copy').first()).toBeVisible();
    await expect(page.getByTestId('sms-menu-copy').first()).toHaveText('Copy text');
    await expect(page.getByTestId('sms-menu-why').first()).toContainText('cannot recall');
    // no edit/delete affordance on the SMS row
    await expect(page.locator('[data-sms-menu] [data-testid="sms-menu-edit"]')).toHaveCount(0);
    await expect(page.locator('[data-sms-menu] [data-testid="sms-menu-delete"]')).toHaveCount(0);
});

import { expect, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';
import { seedSmsRow, resetSmsTimeline } from './support/sms-seed';
import { execFileSync } from 'node:child_process';

/*
 * Doc 14 N-06-of-chips / SS-01 — THE ZERO-INFERRED-DELIVERED DOM GATE: with a
 * `sent`-only fixture (no DLR), NO Doc 14 surface may render the double-check
 * (✓✓) or a data-sms-state="delivered". The double check is delivery, and
 * delivery is a real DLR only — never inferred from `sent`. Swept on the
 * ticket timeline (the surface that renders chips today; the queue view and
 * broadcast progress render the same chip component and carry the same gate).
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

test('a sent-only SMS row shows the single check and its live state — never the double-check, never data-sms-state=delivered (the DOM gate)', async ({
    page,
}) => {
    resetSmsTimeline();
    seedSmsRow('sent');

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

    const chip = page.locator('[data-testid="sms-chip"]').first();
    await expect(chip).toBeVisible();
    await expect(chip).toHaveAttribute('data-sms-state', 'sent');

    // THE GATE: no double-check glyph, no delivered state, anywhere in the timeline
    await expect(page.locator('[data-testid="ticket-timeline"]')).not.toContainText('✓✓');
    await expect(page.locator('[data-sms-state="delivered"]')).toHaveCount(0);
});

test('an expired (DLR-less) row reads "sent · unconfirmed" with a SINGLE check — the honest terminal, never a delivery', async ({
    page,
}) => {
    resetSmsTimeline();
    seedSmsRow('expired');

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

    const expired = page.locator('[data-sms-state="expired"]').first();
    await expect(expired).toBeVisible();
    await expect(expired).toContainText('Sent · unconfirmed');
    await expect(page.locator('[data-testid="ticket-timeline"]')).not.toContainText('✓✓');
});

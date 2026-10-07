import { expect, test, type Page } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';
import { execFileSync } from 'node:child_process';

/*
 * Doc 14 FR-02 / US-02 / EC-10 / §8 — the expired-window SMS re-engagement
 * card in the composer: with a phone on file and a gateway, one click queues
 * (the queued line); a second click inside the window says "already sent N
 * min ago" (EC-10); with no gateway the card degrades to phone-guidance-only
 * (§8 "SMS is off until a gateway exists"); with no phone the card is absent.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

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

/**
 * The scenario is seeded by a TEST-ONLY artisan fixture (real PHP — no inline
 * tinker escaping): the #4820 conversation goes to the expired window, a
 * non-covering quiet-hours window is set (so a re-engagement QUEUES rather
 * than holding overnight), and the phone identity / an active gateway / a
 * recent already-sent re-engagement are toggled per the flags. The card shows
 * only when the window is expired AND a phone exists AND
 * GatewayAvailability::exists() (an active gateway with a credential_ref) holds.
 */
function prepareExpired(withGateway: boolean, withPhone: boolean, duplicate = false): void {
    const flags: string[] = [];
    if (withGateway) flags.push('--gateway');
    if (withPhone) flags.push('--phone');
    if (duplicate) flags.push('--duplicate');
    artisan('sms:fixture-expired-card', ...flags);
}

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}
async function openYonas(page: Page): Promise<void> {
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test('one click queues the re-engagement SMS (the queued line appears)', async ({ page }) => {
    prepareExpired(true, true);
    await login(page);
    await openYonas(page);

    await expect(page.getByTestId('expired-sms')).toBeVisible();
    await page.getByTestId('expired-sms').click();
    await expect(page.getByTestId('expired-sms-queued')).toBeVisible();
});

test('EC-10: with a re-engagement already sent inside the window, a click says "already sent N min ago"', async ({
    page,
}) => {
    // --duplicate pre-seeds a window_reengagement row already sent inside the 10-min window → the next attempt is a duplicate
    prepareExpired(true, true, true);
    await login(page);
    await openYonas(page);

    await expect(page.getByTestId('expired-sms')).toBeVisible();
    await page.getByTestId('expired-sms').click();
    await expect(page.getByTestId('expired-sms-duplicate')).toBeVisible();
    await expect(page.getByTestId('expired-sms-duplicate')).toContainText('ago');
});

test('§8: no gateway -> the card degrades to phone-guidance-only (no send button)', async ({
    page,
}) => {
    prepareExpired(false, true);
    await login(page);
    await openYonas(page);
    await expect(page.getByTestId('expired-sms')).toHaveCount(0);
});

test('no phone on file -> the card is absent (the drawn absence)', async ({ page }) => {
    prepareExpired(true, false);
    await login(page);
    await openYonas(page);
    await expect(page.getByTestId('expired-sms')).toHaveCount(0);
});

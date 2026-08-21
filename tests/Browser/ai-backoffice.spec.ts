import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * Doc 09 FR-18 / AC-08 — the kill-switch grid (oversized role=switch toggles,
 * red-when-off, "OFF since {time} by {actor}", confirmation) and the AC-08
 * BANNER: absent while on, present in the workspace once a switch is off,
 * gone again after the flip back. Thresholds: simulate → publish unlocks.
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

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
test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\AiBrowserSeeder', '--force');
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
    await page.setViewportSize(V1440);
}

test('AC-08 bannered + FR-18 grid: no banner while on; flip chatbot/telegram OFF behind the confirmation → the toggle is red with "OFF since … by …" and the workspace shows the banner; flip back → banner gone', async ({
    page,
}) => {
    await login(page);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ai-killswitch-banner')).toHaveCount(0);

    await page.goto('/admin/ai/kill-switches');
    const toggle = page.getByTestId('switch-chatbot-telegram-all');
    await expect(toggle).toHaveAttribute('role', 'switch');
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    const box = await toggle.boundingBox();
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44); // oversized (§13 target size)
    await toggle.click();
    await expect(page.getByTestId('killswitch-confirm')).toBeVisible();
    await page.getByTestId('killswitch-reason').fill('browser drill');
    await page.getByTestId('killswitch-confirm-yes').click();
    await expect(toggle).toHaveAttribute('aria-checked', 'false');
    await expect(page.getByTestId('since-chatbot').first()).toContainText('OFF since');
    await expect(page.getByTestId('since-chatbot').first()).toContainText('by');

    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ai-killswitch-banner')).toBeVisible();
    await expect(page.getByTestId('ai-killswitch-banner')).toContainText('chatbot');

    await page.goto('/admin/ai/kill-switches');
    await page.getByTestId('switch-chatbot-telegram-all').click();
    await page.getByTestId('killswitch-confirm-yes').click();
    await expect(page.getByTestId('switch-chatbot-telegram-all')).toHaveAttribute(
        'aria-checked',
        'true',
    );
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ai-killswitch-banner')).toHaveCount(0);
});

test('FR-11/BR-07 UI: publish stays disabled until a simulation of these exact values ran; the simulation renders as a text table with deltas', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/ai/thresholds');
    await expect(page.getByTestId('thresholds-publish')).toBeDisabled();
    await page.getByTestId('simulate').click();
    await expect(page.getByTestId('simulation-results')).toBeVisible();
    await expect(page.getByTestId('delta-eligible')).toBeVisible();
    await expect(page.getByTestId('thresholds-publish')).toBeEnabled();
    await page.getByTestId('slider-handoff_below').fill('0.35');
    await expect(page.getByTestId('thresholds-publish')).toBeDisabled(); // values moved → the token no longer matches
});

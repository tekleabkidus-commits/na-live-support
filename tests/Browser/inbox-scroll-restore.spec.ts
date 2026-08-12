import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * FR-12's second half (S07 step 9): scroll-position restoration on
 * back-navigation — the pane remembers scrollTop per view and restores it
 * when the view remounts. (The cursor/limit/next_cursor half is proven
 * server-side in ListEndpointTest — C-05 disjoint/exhaustive/ordered pages.)
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

test('FR-12: the list position survives back-navigation — scroll deep, leave, return, land where you were', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1440, height: 700 }); // force overflow
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    await expect(page.locator('[data-ticket]').first()).toBeVisible();

    const scroller = page.getByTestId('ticket-list');
    await scroller.evaluate((el) => {
        el.scrollTop = 400;
    });
    await expect.poll(async () => scroller.evaluate((el) => el.scrollTop)).toBeGreaterThan(300);

    // Back-navigation: full page reload (unload → remount, the harshest
    // shape — an in-app back is the same remount path).
    await page.reload();
    await page.locator('[data-view="unassigned"]').first().click();
    await expect(page.locator('[data-ticket]').first()).toBeVisible();

    await expect
        .poll(async () => page.getByTestId('ticket-list').evaluate((el) => el.scrollTop))
        .toBeGreaterThan(300);
});

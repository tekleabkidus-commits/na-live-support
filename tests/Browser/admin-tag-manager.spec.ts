import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * R-22's class-2 fill (S07 step 14) — the tag rename/merge surface, walked
 * whole on the real machinery: the inventory from the unnest query, the
 * settings-row rename with the J08 consequence stated BEFORE save, the
 * irreversible-merge weight (typed phrase, the verb on the primary), the
 * result strip carrying T-07's count, and the J19 read-only viewport law.
 * The scene ticket is #4801 ({withdrawal,vip,telebirr}) — the only tagged
 * canon row, so the rewrite's blast radius is exactly one ticket and the
 * seeder heal restores it.
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

function ticket4801Tags(): string {
    return execFileSync(
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
            'tinker',
            '--execute',
            'echo (string) DB::connection("pgsql_platform")->table("tickets")->where("number", 4801)->value("tags");',
        ],
        { encoding: 'utf8' },
    ).trim();
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

test.afterAll(() => {
    // The heal restores #4801's tag canon ({withdrawal,vip,telebirr}).
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('R-22 THE RENAME THEN THE MERGE: consequence before save, the verb on the primary, the typed gate only on the irreversible merge, the count on the result strip, the DB converged', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/admin/tags');

    // The inventory serves the scene's truth.
    await expect(page.locator('[data-tag-row="telebirr"]')).toBeVisible();
    await expect(page.locator('[data-tag-row="telebirr"]')).toContainText('1 tickets');

    // RENAME (no existing target → no phrase gate, the rename verb).
    await page.locator('[data-tag="telebirr"]').click();
    await page.getByTestId('tag-to').fill('payments');
    await expect(page.getByTestId('rewrite-consequence')).toContainText('1 tickets');
    await expect(page.getByTestId('merge-phrase')).toHaveCount(0);
    await expect(page.getByTestId('rewrite-confirm')).toHaveText('Rename tag');
    await page.getByTestId('rewrite-confirm').click();

    await expect(page.getByTestId('rewrite-result')).toContainText('1 tickets rewritten');
    await expect(page.locator('[data-tag-row="payments"]')).toBeVisible();
    await expect(page.locator('[data-tag-row="telebirr"]')).toHaveCount(0);
    expect(ticket4801Tags()).toContain('payments');

    // MERGE (target exists → the notice, the typed phrase, the red verb).
    await page.locator('[data-tag="vip"]').click();
    await page.getByTestId('tag-to').fill('withdrawal');
    await expect(page.getByTestId('merge-notice')).toContainText('cannot be split apart');
    await expect(page.getByTestId('rewrite-confirm')).toHaveText('Merge tags');
    await expect(page.getByTestId('rewrite-confirm')).toBeDisabled();
    await page.getByTestId('merge-phrase').fill('withdrawal');
    await expect(page.getByTestId('rewrite-confirm')).toBeEnabled();
    await page.getByTestId('rewrite-confirm').click();

    await expect(page.getByTestId('rewrite-result')).toContainText('1 tickets rewritten');
    await expect(page.locator('[data-tag-row="vip"]')).toHaveCount(0);
    const tags = ticket4801Tags();
    expect(tags).toContain('withdrawal');
    expect(tags).not.toContain('vip');
    // The merge never doubles the target (the engine's DISTINCT).
    expect(tags.split('withdrawal').length - 1).toBe(1);
});

test('R-22 J19: under 1024 the surface is read-only — the notice shows and no rename affordance renders', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/admin/tags');

    await expect(page.locator('[data-frame="J19-readonly"]')).toBeVisible();
    await expect(page.locator('[data-tag-row="withdrawal"]')).toBeVisible();
    await expect(page.locator('[data-action="rename"]')).toHaveCount(0);
});

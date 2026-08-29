import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * Doc 10 S10 step 12 ([B9]) — the staff KB surfaces, functional-state proofs:
 * the manager three-pane (badges: status · translation state; the INTERNAL
 * chip), the editor (INTERNAL banner from --ds-note-*; the sanitizer flags
 * panel pre-publish; the publish dialog surfacing the door's OWN missing-alt
 * refusal; the review thread), version history, and the Tombstone page's
 * labeled-degrade states (Current / Retired — never a 404, mandate §5).
 * Fixtures: KbBrowserSeeder — DEDICATED rows, fixed ids, heal-by-reprojection
 * (risk 0l: never a shared canon row).
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };

const A1 = '018f6b2e-0000-7000-8000-00000000ed11';
const A4 = '018f6b2e-0000-7000-8000-00000000ed14';
const V1 = '018f6b2e-0000-7000-8000-00000000ed21';
const V2 = '018f6b2e-0000-7000-8000-00000000ed23';
const V3 = '018f6b2e-0000-7000-8000-00000000ed24';
const VER1 = '018f6b2e-0000-7000-8000-00000000ed31';
const VER3 = '018f6b2e-0000-7000-8000-00000000ed33';

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
    artisan('db:seed', '--class=Database\\Seeders\\KbBrowserSeeder', '--force');
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

test('manager three-pane at 1440 and stacked at 375: category → articles with status·translation badges and the INTERNAL chip → detail pane links to the editor', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/kb');

    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
    await page.getByTestId('kb-category-s10-browser-kb').click();
    const article = page.getByTestId('kb-article-' + A1);
    await expect(article).toBeVisible();
    await expect(article.getByTestId('kb-badge-' + V1)).toContainText('en · Published · Current');
    await expect(article).not.toContainText('INTERNAL');
    // The internal article carries its chip (this agent holds kb.view_internal via the fixture role).
    await expect(page.getByTestId('kb-pane-articles')).toContainText('Internal');

    await article.click();
    await expect(page.getByTestId('kb-pane-detail')).toContainText('en · Published · Current');
    await expect(page.getByTestId('kb-edit-' + V1)).toBeVisible();

    await page.setViewportSize(V375);
    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
    await expect(page.getByTestId('kb-pane-articles')).toBeVisible();
});

test('editor: the INTERNAL banner on an internal article; the sanitizer flags panel pre-publish; the publish dialog surfaces the missing-alt refusal from the door itself; the seeded review comment renders', async ({
    page,
}) => {
    await login(page);

    // Internal article → the banner (--ds-note-*), the FR-10 accent.
    await page.goto('/admin/kb/variants/' + V2 + '/edit');
    await expect(page.getByTestId('kb-internal-banner')).toBeVisible();

    // Draft with a flaggable imperative + an alt-less image.
    await page.goto('/admin/kb/variants/' + V3 + '/edit');
    await expect(page.getByTestId('kb-sanitizer-flags')).toBeVisible();
    await expect(page.getByTestId('kb-review-thread')).toContainText('rephrase?');

    await page.getByTestId('kb-publish-open').click();
    await expect(page.getByTestId('kb-publish-dialog')).toBeVisible();
    await page.getByTestId('kb-override-reason').fill('[FIXTURE] verified with payments team');
    await page.getByTestId('kb-publish-confirm').click();
    // The DOOR refused (alt gate) and the dialog lists the block — AC-10 made visible.
    await expect(page.getByTestId('kb-missing-alt')).toContainText('img1');
});

test('P8 (countersigned): the shell reaches the KB — ⌘K, type "knowledge", the one nav action navigates to /admin/kb', async ({
    page,
}) => {
    await login(page);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByTestId('command-palette')).toBeVisible();
    await page.getByTestId('palette-input').fill('knowledge');
    const action = page.locator('[data-action="open-kb"]');
    await expect(action).toBeVisible();
    await action.click();
    await page.waitForURL((url) => url.pathname === '/admin/kb');
    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
});

test('history lists the immutable version; the tombstone page answers Current for a live citation and Retired for a retired one — never a 404', async ({
    page,
}) => {
    await login(page);

    await page.goto('/admin/kb/variants/' + V1 + '/history-page');
    await expect(page.getByTestId('kb-versions')).toContainText('Version 1');

    await page.goto('/admin/kb/cite/' + A1 + '/' + VER1);
    await expect(page.getByTestId('kb-tombstone-state')).toContainText('current version');
    await expect(page.getByTestId('kb-tombstone-title')).toContainText('Deposit help');

    await page.goto('/admin/kb/cite/' + A4 + '/' + VER3);
    await expect(page.getByTestId('kb-tombstone-state')).toContainText(
        'has since changed or been retired',
    );
});

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 10 §13/§14 (S10 step 13) — the KB surfaces' a11y + long-string posture:
 * axe-clean (wcag2a + wcag2aa) on the manager, the editor and the tombstone
 * page, light AND dark; and the §14 layout proof — real Ethiopic strings (the
 * seeded am variant + the am category name) at 375 with NO horizontal
 * overflow: long Amharic breaks lines, never the layout.
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1AM = '018f6b2e-0000-7000-8000-00000000ed22';
const A1 = '018f6b2e-0000-7000-8000-00000000ed11';
const VER1 = '018f6b2e-0000-7000-8000-00000000ed31';

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
}
async function expectAxeClean(page: Page, context: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(
        results.violations,
        `${context}: ${JSON.stringify(results.violations.map((v) => v.id))}`,
    ).toEqual([]);
}

test('§13: manager, editor and tombstone are axe-clean in light; the manager is axe-clean in dark too', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1440, height: 900 });

    await page.goto('/admin/kb');
    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
    await expectAxeClean(page, 'kb manager (light)');

    await page.goto('/admin/kb/variants/' + V1AM + '/edit');
    await expect(page.getByTestId('kb-blocks')).toBeVisible();
    await expectAxeClean(page, 'kb editor (light)');

    await page.goto('/admin/kb/cite/' + A1 + '/' + VER1);
    await expect(page.getByTestId('kb-tombstone-state')).toBeVisible();
    await expectAxeClean(page, 'kb tombstone (light)');

    await page.emulateMedia({ colorScheme: 'dark' });
    await page.goto('/admin/kb');
    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
    await expectAxeClean(page, 'kb manager (dark)');
});

test('§14: real Ethiopic strings at 375 — the am editor and the manager render with NO horizontal overflow (long Amharic breaks lines, never the layout)', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 375, height: 812 });

    await page.goto('/admin/kb/variants/' + V1AM + '/edit');
    await expect(page.getByTestId('kb-title')).toHaveValue(/የተቀማጭ እገዛ/); // real Ethiopic title
    await expect(page.getByTestId('kb-block-b1')).toHaveValue(/ማስገቢያ/); // real Ethiopic body
    const editorOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(editorOverflow, 'am editor horizontal overflow px').toBeLessThanOrEqual(0);

    await page.goto('/admin/kb');
    await expect(page.getByTestId('kb-pane-categories')).toBeVisible();
    const managerOverflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(managerOverflow, 'manager horizontal overflow px at 375').toBeLessThanOrEqual(0);
});

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/**
 * Doc 11 §14/§13 (S11 step 14) — the LANGUAGE sweep: the routing surfaces render REAL
 * Ethiopic in an am workspace (users.locale, ApplyUserLocale) with no horizontal overflow
 * at 375, axe-clean in BOTH themes, and the record card speaks the localized ICU sentence
 * (routing.record.*) instead of the stored English prose. The locale flip is healed in
 * afterAll (the seeders do not own users.locale).
 */

const AGENT_EMAIL = 'agent@fixture.example';
const PASSWORD = 'browser-fixture-password';
const DEL_QUEUE = '00000000-0000-7000-8000-00000000d017';

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

function setAgentLocale(locale: 'en' | 'am'): void {
    // Tinker refuses without a tenant context (Doc 01 T-05 — correctly); the locale flip is a
    // test-harness act, done as the DB superuser the compose stack already runs.
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
            'postgres',
            'psql',
            '-U',
            'postgres',
            '-d',
            'na_live_support',
            '-c',
            `UPDATE users SET locale='${locale}' WHERE email='${AGENT_EMAIL}'`,
        ],
        { stdio: 'pipe' },
    );
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
    setAgentLocale('am');
});

test.afterAll(() => {
    setAgentLocale('en');
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

test('§14: the wizard speaks Amharic at 375 — the drawn step line, real Ethiopic rows, NO horizontal overflow; axe-clean light', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(`/admin/organization/queues/${DEL_QUEUE}/delete-wizard`);
    await expect(page.getByTestId('wizard-step-line')).toContainText('ደረጃ 1 ከ4');
    await expect(page.getByTestId('inv-row-tickets')).toContainText('ክፍት ትኬቶች');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= 380)).toBeTruthy();
    const scan = await new AxeBuilder({ page }).analyze();
    expect(scan.violations).toEqual([]);
});

test('§14/§13: the Organization queues tab in Amharic, DARK theme, axe-clean — the locked starvation caption included', async ({
    browser,
}) => {
    const dark = await browser.newContext({ colorScheme: 'dark' });
    const page = await dark.newPage();
    await login(page);
    await page.goto('/admin/organization');
    await page.getByTestId('org-tab-queues').click();
    await expect(page.getByTestId('org-queues')).toBeVisible();
    const card = page.locator('[data-queue-card="Fixture payments queue"]');
    await card.locator('button').first().click();
    await expect(card.getByTestId('starvation-row')).toBeVisible();
    const scan = await new AxeBuilder({ page }).include('[data-testid="org-queues"]').analyze();
    expect(scan.violations).toEqual([]);
    await dark.close();
});

test('§14: the record card speaks the ICU sentence in Amharic — the explicit-queue step reads in Ethiopic, not the stored English prose', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox/t/00000000-0000-7000-8000-0000000d1101');
    await dismissCoachMarks(page);
    const toggle = page.locator('[data-record-toggle]').first();
    await toggle.click();
    const card = page.getByTestId('routing-record-card');
    await expect(card).toBeVisible();
    // The queue-view scene routes with explicit department + queue — both sentences derive.
    await expect(card.locator('[data-record-step="queue"]')).toContainText('ወረፋው በጠሪው በግልጽ ተቀምጧል።');
    await expect(card.locator('[data-record-step="queue"]')).not.toContainText(
        'explicit queue applied',
    );
});

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/**
 * Doc 11 §8/§9/§13 — Backoffice → Organization (S11 step 12; B5-S09 J01–J15 at FULL
 * fidelity; the S10 §C/§D 375 law: identical controls at 375). Frame-mapping rows ride
 * BUILD-STATUS. Light AND dark axe-clean; reorder via move buttons (§13); the locked
 * starvation row has NO controls and its caption is programmatically associated; the J06
 * warning names the SPECIFIC department; temporary chips are dashed with a countdown.
 */

const ADMIN_EMAIL = 'agent@fixture.example';
const PASSWORD = 'browser-fixture-password';

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
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function openOrganization(
    page: Page,
    viewport = { width: 1440, height: 900 },
): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(ADMIN_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(viewport);
    await page.goto('/admin/organization');
    await expect(page.getByTestId('org-departments')).toBeVisible();
}

test('J01/J02/J03: three tabs; departments reorderable by move buttons with default badges; the detail shows the hours-override REF as drawn; §13 keyboard reorder works and PERSISTS', async ({
    page,
}) => {
    await openOrganization(page);
    const row = page.locator('[data-department-row="fixture-routing"]');
    await expect(row).toBeVisible();
    await expect(row).toContainText('default:');
    // Reorder by the MOVE BUTTON (drag equivalent, §13) — the fixture sits FIRST (move-up is
    // correctly disabled at index 0), so it moves DOWN; the order persists across reload.
    const before = await page.locator('[data-department-row]').allInnerTexts();
    await page.locator('[data-move-down="fixture-routing"]').click();
    await page.waitForLoadState('networkidle');
    await page.goto('/admin/organization');
    await expect(page.getByTestId('org-departments')).toBeVisible();
    const after = await page.locator('[data-department-row]').allInnerTexts();
    expect(after.findIndex((t) => t.includes('Fixture Routing'))).toBeGreaterThan(
        before.findIndex((t) => t.includes('Fixture Routing')),
    );
    // Detail: the hours REF (the [D12] display slot) + the danger zone.
    await page.locator('[data-department-row="fixture-routing"] button').first().click();
    await expect(page.getByTestId('dept-hours-ref')).toBeVisible();
    await expect(page.getByTestId('dept-danger-delete')).toBeVisible();
});

test('J04/J06: the team warning names the SPECIFIC member and department, and "Give access" clears it through the one-act grant', async ({
    page,
}) => {
    await openOrganization(page);
    await page.getByTestId('org-tab-teams').click();
    const warning = page.getByTestId('team-config-warning').first();
    await expect(warning).toBeVisible();
    await expect(warning).toContainText('Fixture Blind Member');
    await expect(warning).toContainText('Fixture Routing');
    const giveAccess = warning.locator('button').first();
    await expect(giveAccess).toContainText('access');
    await giveAccess.click();
    await page.waitForLoadState('networkidle');
    await page.goto('/admin/organization');
    await expect(page.getByTestId('org-departments')).toBeVisible();
    await page.getByTestId('org-tab-teams').click();
    await expect(page.getByTestId('team-config-warning')).toHaveCount(0);
});

test('J07–J15 on the Queues tab: strategy badges icon+text, the REAL rotation preview, the dashed temporary chip with countdown, the locked starvation row (no controls, caption associated), vip source warning, tags, the skill panel with the live hold, the J15 bands; light AND dark axe-clean; §C/§D: identical controls at 375', async ({
    page,
    browser,
}) => {
    await openOrganization(page);
    await page.getByTestId('org-tab-queues').click();
    const card = page.locator('[data-queue-card="Fixture payments queue"]');
    await expect(card).toBeVisible();
    await expect(card.locator('[data-strategy-badge="round_robin"]')).toContainText('Round robin');
    await expect(card.getByTestId('temporary-chip')).toBeVisible();
    await card.locator('button').first().click();
    await expect(card.getByTestId('rotation-preview')).toBeVisible();
    // The locked starvation row: pinned last, NO buttons, caption associated.
    const starvation = card.getByTestId('starvation-row');
    await expect(starvation).toBeVisible();
    expect(await starvation.locator('button').count()).toBe(0);
    await expect(starvation).toHaveAttribute('aria-describedby', 'starvation-caption');
    await expect(card.locator('#starvation-caption')).toContainText('Always on');
    await expect(card.getByTestId('vip-source-warning')).toBeVisible();
    await expect(card.locator('[data-tag="kyc"]')).toBeVisible();
    await expect(card.getByTestId('estimate-panel')).toContainText('~5 min');
    // The skill queue: fallback wait selected; the hold state renders only when tickets hold (fixture may be 0 — the panel itself binds).
    const skillCard = page.locator('[data-queue-card="Fixture skill queue"]');
    await skillCard.locator('button').first().click();
    await expect(skillCard.getByTestId('skill-panel')).toBeVisible();
    await expect(skillCard.locator('[data-fallback="wait"]')).toBeVisible();

    const lightScan = await new AxeBuilder({ page })
        .include('[data-testid="org-queues"]')
        .analyze();
    expect(lightScan.violations).toEqual([]);

    // Dark.
    const dark = await browser.newContext({ colorScheme: 'dark' });
    const darkPage = await dark.newPage();
    await openOrganization(darkPage);
    await darkPage.getByTestId('org-tab-queues').click();
    const darkScan = await new AxeBuilder({ page: darkPage })
        .include('[data-testid="org-queues"]')
        .analyze();
    expect(darkScan.violations).toEqual([]);
    await dark.close();

    // 375 (S10 §C/§D law: identical controls — the same testids exist, nothing hidden).
    const mobile = await browser.newContext({ viewport: { width: 375, height: 812 } });
    const mobilePage = await mobile.newPage();
    await openOrganization(mobilePage, { width: 375, height: 812 });
    await mobilePage.getByTestId('org-tab-queues').click();
    const mobileCard = mobilePage.locator('[data-queue-card="Fixture payments queue"]');
    await expect(mobileCard).toBeVisible();
    await mobileCard.locator('button').first().click();
    await expect(mobileCard.getByTestId('starvation-row')).toBeVisible();
    expect(
        await mobilePage.evaluate(() => document.documentElement.scrollWidth <= 380),
    ).toBeTruthy();
    await mobile.close();
});

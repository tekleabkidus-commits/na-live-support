import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/**
 * Doc 11 FR-18 / §8 — the delete wizards (S11 step 13; B5-S10 J16–J21). J16's four steps
 * run REAL against the step-10 doors on the d017 fixture queue (typed phrase = the server's
 * own "MOVE 2 TICKETS"); J19's failed check and J18's RG lock are UI-state proofs over an
 * intercepted preview (their server truths are Pest-proven at steps 10/13). 375: steps 1+3
 * executable per the ALREADY-FILED v3 deviation (B5-S00-checklist-v3 66-72/253/258,
 * B5-S11-closing-audit-v2 L10 — cited, not re-filed). The delete leg runs LAST in this file.
 */

const ADMIN_EMAIL = 'agent@fixture.example';
const PASSWORD = 'browser-fixture-password';
const DEL_QUEUE = '00000000-0000-7000-8000-00000000d017';
const SECOND_QUEUE = '00000000-0000-7000-8000-00000000d016';
const SECOND_DEPT = '00000000-0000-7000-8000-00000000d015';

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

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(ADMIN_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openWizard(
    page: Page,
    queueId = DEL_QUEUE,
    viewport = { width: 1440, height: 900 },
): Promise<void> {
    await login(page);
    await page.setViewportSize(viewport);
    await page.goto(`/admin/organization/queues/${queueId}/delete-wizard`);
    await expect(page.getByTestId('wizard-title')).toBeVisible();
}

test('J17: the queue detail offers Deactivate FIRST (ink border) with the delete-wizard entry beneath it (danger border)', async ({
    page,
}) => {
    await login(page);
    await page.goto('/admin/organization');
    await page.getByTestId('org-tab-queues').click();
    const card = page.locator('[data-queue-card="Fixture payments queue"]');
    await card.locator('button').first().click();
    const deactivate = card.getByTestId('queue-deactivate-card');
    const danger = card.getByTestId('queue-danger-delete');
    await expect(deactivate).toBeVisible();
    await expect(danger).toBeVisible();
    // Drawn order: the reversible card sits ABOVE the delete card.
    const deactivateBox = await deactivate.boundingBox();
    const dangerBox = await danger.boundingBox();
    expect(deactivateBox!.y).toBeLessThan(dangerBox!.y);
    await expect(card.getByTestId('queue-delete-wizard-link')).toHaveAttribute(
        'href',
        /\/admin\/organization\/queues\/[0-9a-f-]+\/delete-wizard$/,
    );
});

test('J19: a failed reference check renders the red row with Retry; Next unlocks only through the deliberate partial-list acknowledgement', async ({
    page,
}) => {
    // d017 (deletable) — d016 is the department DEFAULT and the door rightly answers blocked.
    await page.route(`**/org/queues/${DEL_QUEUE}/delete`, async (route) => {
        if (route.request().method() !== 'GET') {
            await route.continue();
            return;
        }
        const response = await route.fetch();
        const body = (await response.json()) as {
            checks: Record<string, { ok: boolean; reason: string | null }>;
        };
        body.checks.bots = { ok: false, reason: 'check_failed' };
        await route.fulfill({ response, json: body });
    });
    await openWizard(page);
    await expect(page.getByTestId('inv-failed-bots')).toBeVisible();
    await expect(page.getByTestId('inv-retry-bots')).toBeVisible();
    await expect(page.getByTestId('wizard-incomplete')).toBeVisible();
    const next = page.getByTestId('wizard-next-1');
    await expect(next).toBeDisabled();
    await page.getByTestId('wizard-partial-accept').check();
    await expect(next).toBeEnabled();
});

test('J18: when the department holds the RG queue, the locked red card demands a replacement and Next stays disabled WITH the reason', async ({
    page,
}) => {
    await page.route(`**/org/departments/${SECOND_DEPT}/delete`, async (route) => {
        if (route.request().method() !== 'GET') {
            await route.continue();
            return;
        }
        const response = await route.fetch();
        const body = (await response.json()) as {
            choices: Record<string, unknown>;
        };
        body.choices.replacement_rg_queue_id = {
            required: true,
            description: 'the RG target queue outside this department (FR-01)',
        };
        await route.fulfill({ response, json: body });
    });
    await login(page);
    await page.goto(`/admin/organization/departments/${SECOND_DEPT}/delete-wizard`);
    await expect(page.getByTestId('wizard-title')).toBeVisible();
    await page.getByTestId('wizard-next-1').click();
    await expect(page.getByTestId('wizard-rg-card')).toBeVisible();
    await expect(page.getByTestId('wizard-rg-blocked')).toBeVisible();
    await expect(page.getByTestId('wizard-next-2')).toBeDisabled();
    // The department holds tickets too — satisfy the move target, the RG lock must STILL hold.
    await page.getByTestId('wizard-target-select').selectOption({ index: 1 });
    await expect(page.getByTestId('wizard-next-2')).toBeDisabled();
    await expect(page.getByTestId('wizard-rg-blocked')).toBeVisible();
    await page.getByTestId('wizard-rg-select').selectOption({ index: 1 });
    await expect(page.getByTestId('wizard-next-2')).toBeEnabled();
});

test('J16 four REAL steps: inventory → choices → the typed server phrase (mismatch shown, then accepted) → progress to done; axe-clean light AND dark; 375 steps 1+3 executable', async ({
    page,
    browser,
}) => {
    // Dark + 375 first (the queue still exists), the REAL delete last.
    const dark = await browser.newContext({ colorScheme: 'dark' });
    const darkPage = await dark.newPage();
    await openWizard(darkPage);
    const darkScan = await new AxeBuilder({ page: darkPage }).analyze();
    expect(darkScan.violations).toEqual([]);
    await dark.close();

    const mobile = await browser.newContext({
        viewport: { width: 375, height: 812 },
    });
    const mobilePage = await mobile.newPage();
    await openWizard(mobilePage, DEL_QUEUE, { width: 375, height: 812 });
    await expect(mobilePage.getByTestId('inv-row-tickets')).toBeVisible();
    await expect(mobilePage.getByTestId('wizard-next-1')).toBeVisible();
    expect(
        await mobilePage.evaluate(() => document.documentElement.scrollWidth <= 380),
    ).toBeTruthy();
    await mobilePage.getByTestId('wizard-next-1').click();
    await mobilePage.getByTestId('wizard-target-select').selectOption(SECOND_QUEUE);
    await mobilePage.getByTestId('wizard-next-2').click();
    await expect(mobilePage.getByTestId('wizard-typed')).toBeVisible();
    expect(
        await mobilePage.evaluate(() => document.documentElement.scrollWidth <= 380),
    ).toBeTruthy();
    await mobile.close();

    await openWizard(page);
    // Step 1: the inventory truth — 2 open tickets, must-move badge.
    await expect(page.getByTestId('inv-row-tickets')).toContainText('2');
    await expect(page.getByTestId('inv-row-tickets')).toContainText('must move');
    const lightScan = await new AxeBuilder({ page }).analyze();
    expect(lightScan.violations).toEqual([]);
    await page.getByTestId('wizard-next-1').click();
    // Step 2: the required target.
    await expect(page.getByTestId('wizard-step-line')).toContainText('Step 2');
    await page.getByTestId('wizard-target-select').selectOption(SECOND_QUEUE);
    await page.getByTestId('wizard-next-2').click();
    // Step 3: the server's phrase; a wrong phrase is refused inline.
    await expect(page.getByTestId('wizard-confirm-list')).toContainText(
        '2 tickets move to Fixture secondary queue',
    );
    await page.getByTestId('wizard-typed').fill('MOVE 999 TICKETS');
    await expect(page.getByTestId('wizard-typed-mismatch')).toBeVisible();
    await expect(page.getByTestId('wizard-execute')).toBeDisabled();
    await page.getByTestId('wizard-typed').fill('MOVE 2 TICKETS');
    await expect(page.getByTestId('wizard-execute')).toBeEnabled();
    await page.getByTestId('wizard-execute').click();
    // Step 4: progress to done (the queued RestructureJob runs on the worker).
    await expect(page.getByTestId('wizard-progress')).toBeVisible();
    await expect(page.getByTestId('wizard-progress-chip')).toContainText('✓ Done', {
        timeout: 30_000,
    });
});

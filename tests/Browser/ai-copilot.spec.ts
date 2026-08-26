import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * Doc 09 S09 step 12d — the Copilot tab + composer AI actions (FR-14/FR-15/
 * FR-16, BR-01, §13) through the REAL workspace, against the deterministic
 * replay provider (AiBrowserSeeder routes the fixture tenant to it; no live
 * vendor is reachable from a browser run). Also the countersigned packet's
 * obligations: the NO-SLOT fallback (a Read Only user — no ai.copilot.use —
 * sees the placeholder span + the slot text, exactly as before the packet),
 * and the AC-08 banner absent while every switch is on.
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const READ_ONLY_EMAIL = 'readonly-fixture@fixture.example';
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

async function login(page: Page, email = AGENT_EMAIL): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openThread(page: Page, email = AGENT_EMAIL): Promise<void> {
    await login(page, email);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Copilot Fixture Player' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('FR-14: the Copilot tab renders chips (text, ✦ accent), the summary region, KB/similar/macro regions; Suggest → a card with confidence AS TEXT → Insert puts the draft in the composer, focus returns to the textarea, and the send carries the artifact id (FR-16)', async ({
    page,
}) => {
    await openThread(page);
    await page.getByTestId('tab-copilot').click();
    const tab = page.getByTestId('copilot-tab');
    await expect(tab).toBeVisible();
    await expect(page.getByTestId('copilot-chips')).toContainText('withdrawals');
    for (const region of [
        'copilot-summary',
        'copilot-card',
        'copilot-kb',
        'copilot-similar',
        'copilot-macros',
    ]) {
        await expect(page.getByTestId(region)).toBeVisible();
    }
    // §13: every section is a labelled region.
    expect(
        await page.locator('[data-testid="copilot-tab"] section[aria-label]').count(),
    ).toBeGreaterThanOrEqual(5);

    await page.getByTestId('copilot-regenerate').click(); // reads "Suggest" before a card exists
    await expect(page.getByTestId('card-text')).toContainText('2,500 ETB withdrawal', {
        timeout: 15_000,
    });
    await expect(page.getByTestId('card-confidence')).toContainText('%');
    const sendRequest = page.waitForRequest(
        (req) =>
            req.url().includes('/inbox/thread/') &&
            req.url().endsWith('/reply') &&
            req.method() === 'POST',
    );
    await page.getByTestId('copilot-insert').click();
    await expect(page.getByTestId('composer-input')).toHaveValue(/2,500 ETB withdrawal/);
    await expect(page.getByTestId('composer-input')).toBeFocused();
    await page.getByTestId('composer-send').click();
    const req = await sendRequest;
    const body = req.postDataJSON() as { ai_artifact_id?: string; body: string };
    expect(body.ai_artifact_id).toMatch(/^[0-9a-f-]{36}$/);
    expect(body.body).toContain('2,500 ETB withdrawal');
});

test('FR-15: the composer AI actions render in the slot (placeholder gone); Improve on tagged staff text → a card → Insert replaces the composer text; a second card is Discarded', async ({
    page,
}) => {
    await openThread(page);
    await expect(page.getByTestId('ai-actions')).toBeVisible();
    await expect(page.getByTestId('ai-slot')).toHaveCount(0);
    // Staff text, fixture-tagged (the non-production egress guard's bar) and digit-true to the replay recording.
    await page
        .getByTestId('composer-input')
        .fill('[FIXTURE] BROWSER-IMPROVE-FIXTURE we check your 2,500 ETB withdrawal');
    await page.getByTestId('ai-action-improve').click();
    await expect(page.getByTestId('ai-action-text')).toContainText('2,500 ETB', {
        timeout: 20_000,
    });
    await page.getByTestId('ai-action-insert').click();
    await expect(page.getByTestId('composer-input')).toHaveValue(/2,500 ETB/);
    await expect(page.getByTestId('composer-input')).toBeFocused();
    // A second card (the replay needs its marker again), discarded.
    await page
        .getByTestId('composer-input')
        .fill('[FIXTURE] BROWSER-IMPROVE-FIXTURE second pass 2,500 ETB');
    await page.getByTestId('ai-action-improve').click();
    await expect(page.getByTestId('ai-action-text')).toBeVisible({ timeout: 20_000 });
    await page.getByTestId('ai-action-discard').click();
    await expect(page.getByTestId('ai-action-card')).toHaveCount(0);
});

test('COUNTERSIGNED OBLIGATIONS: the NO-SLOT fallback — a Read Only user sees the placeholder ai-slot span and the Copilot slot text exactly as before; and no kill-switch banner renders while every switch is on', async ({
    page,
}) => {
    await login(page, READ_ONLY_EMAIL);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ai-killswitch-banner')).toHaveCount(0);
    // Read Only's scope sees the unassigned rows.
    await page.locator('[data-view="unassigned"]').first().click();
    await page
        .locator('[data-ticket]', { hasText: 'Kidist Haile' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
    await expect(page.getByTestId('ai-slot')).toBeVisible();
    await expect(page.getByTestId('ai-actions')).toHaveCount(0);
    await page.getByTestId('tab-copilot').click();
    await expect(page.getByTestId('panel-copilot')).toBeVisible();
    await expect(page.getByTestId('copilot-tab')).toHaveCount(0);
});

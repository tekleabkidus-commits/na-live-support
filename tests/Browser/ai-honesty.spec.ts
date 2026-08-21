import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * Doc 09 AC-05's DOM clause (FR-19c): every AI-authored message carries the
 * AI marker — accent AND a text label, never colour alone; FR-15: the
 * translation marker with the agent's original, ABSENT on a message whose
 * channel_meta has no ai_translation; the Copilot cards carry the ✦ accent.
 * Plus Doc 08 EC-03's chip (countersigned packet 1): present for the
 * deleted-account fixture, absent for the live control.
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
}
async function openThread(page: Page, rowText: string, view: string | null = null): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    if (view !== null) await page.locator(`[data-view="${view}"]`).first().click();
    await page
        .locator('[data-ticket]', { hasText: rowText })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('AC-05 DOM: the AI message carries data-role=ai-marker with NON-EMPTY text and the AI accent; a customer message carries none; the translated agent message carries ai-translation with the original, the AI message does not', async ({
    page,
}) => {
    await openThread(page, 'አበበ ታደሰ');
    const bubble = (text: string) =>
        page
            .locator('[data-role="body"]', { hasText: text })
            .first()
            .locator('xpath=ancestor::*[contains(@class,"max-w-")][1]');
    const aiBubble = bubble('S09-BROWSER [FIXTURE] Hi!');
    await expect(aiBubble.locator('[data-role="ai-marker"]')).toBeVisible();
    expect(
        (await aiBubble.locator('[data-role="ai-marker"]').textContent())?.trim().length,
    ).toBeGreaterThan(1); // text, never colour alone
    const customerBubble = bubble('S09-BROWSER-COPILOT');
    await expect(customerBubble.locator('[data-role="ai-marker"]')).toHaveCount(0);
    const aiBg = await aiBubble.evaluate((el) => getComputedStyle(el).backgroundColor);
    const customerBg = await customerBubble.evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(aiBg).not.toBe(customerBg);

    const translated = bubble('S09-BROWSER የ2,500');
    const marker = translated.locator('[data-role="ai-translation"]');
    await expect(marker).toHaveCount(1);
    await expect(marker).toHaveAttribute('title', /2,500 ETB withdrawal/);
    await marker.getByRole('button').click();
    await expect(translated.locator('[data-role="ai-translation-original"]')).toContainText(
        'We are checking your 2,500 ETB withdrawal now.',
    );
    // ABSENT where channel_meta carries no ai_translation: the AI message and the customer message.
    await expect(aiBubble.locator('[data-role="ai-translation"]')).toHaveCount(0);
    await expect(customerBubble.locator('[data-role="ai-translation"]')).toHaveCount(0);
});

test('AC-05 DOM: the Copilot card and chips carry the ✦ accent label (text)', async ({ page }) => {
    await openThread(page, 'አበበ ታደሰ');
    await page.getByTestId('tab-copilot').click();
    await expect(page.getByTestId('copilot-chips')).toContainText('✦');
    await expect(page.getByTestId('copilot-card')).toContainText('✦');
});

test('Doc 08 EC-03 chip (packet 1): chip-inactive renders for the deleted-account fixture and ONLY there', async ({
    page,
}) => {
    await openThread(page, 'Deleted Account Fixture', 'unassigned');
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('chip-inactive')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('chip-inactive')).toHaveAttribute('title', /3/);

    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    await page
        .locator('[data-ticket]', { hasText: 'Live Account Fixture' })
        .locator('[data-role="row-open"]')
        .click();
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('field-display_name')).toContainText('Live Account Fixture', {
        timeout: 10_000,
    });
    await expect(page.getByTestId('chip-inactive')).toHaveCount(0);
});

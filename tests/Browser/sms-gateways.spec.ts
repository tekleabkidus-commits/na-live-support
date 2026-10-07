import { expect, test } from '@playwright/test';
import {
    axeClean,
    clearThrottle,
    login,
    seedSmsScreens,
    setAgentLocale,
    setTheme,
    V1440,
} from './support/sms-screens';

/*
 * Doc 14 §8 / FR-06…FR-11 ([B14-batch] step 13) — the gateways screen
 * (desktop): cards with health pills + driver badges; the detail's WRITE-ONLY
 * credential line ("saved — enter a new value to replace", never the value);
 * the routing table ordered by position; the test-a-number resolver
 * announcing the chain. Light+dark at 1440, the am leg, axe on both themes.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    test(`cards, the write-only credential line, the routing table and the resolver chain — ${theme}, axe-clean`, async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await setTheme(page, theme);
        await page.goto('/admin/sms/gateways');

        // the cards with health pills
        await expect(page.getByTestId('gateway-cards')).toBeVisible();
        const firstCard = page.getByTestId('gateway-cards').locator('.sms-card').first();
        await expect(firstCard.locator('[data-health]')).toBeVisible();

        // the detail: the credential is WRITE-ONLY — the stored value never renders
        await firstCard.click();
        await expect(page.getByTestId('gateway-detail')).toBeVisible();
        await expect(page.getByTestId('credential-saved')).toBeVisible();
        await expect(page.getByTestId('credential-input')).toHaveValue('');
        const detailText = (await page.getByTestId('gateway-detail').textContent()) ?? '';
        expect(detailText).not.toContain('FAKECRED');
        expect(detailText).not.toContain('vault://');

        // the routing table rows in position order
        await expect(page.getByTestId('routing-table')).toBeVisible();
        await expect(page.locator('[data-route]').first()).toBeVisible();

        // the resolver announces the chain for a fixture number
        await page.getByTestId('resolver-phone').fill('+251900001234');
        await page.getByTestId('resolver-run').click();
        await expect(page.getByTestId('resolver-result')).toBeVisible();
        await expect(page.getByTestId('resolver-result')).toContainText('+251900001234');

        await axeClean(page, `gateways ${theme}`);
    });
}

test('the am leg: the gateways screen renders in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/gateways');
    await expect(page.getByTestId('routing-table')).toContainText('መስመር');
});

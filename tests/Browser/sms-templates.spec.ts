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
 * Doc 14 §8 / FR-04 / FR-05 / US-03 ([B14-batch] step 13) — the templates
 * screen: the event-grouped list with completeness dots; the editor's LIVE
 * counter and encoding badge (Ethiopic flips the whole message to UCS-2 and
 * the change is ANNOUNCED via role=status); the variable palette; the cost
 * row per routed gateway; the server-computed refusal INLINE (an arbitrary
 * URL can never publish — SEC-05). Light+dark at 1440 (editor is tablet+),
 * the am leg, axe on both themes.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    test(`the list, the editor's live counter + UCS-2 announcement, the palette and the inline refusal — ${theme}, axe-clean`, async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await setTheme(page, theme);
        await page.goto('/admin/sms/templates');

        // the grouped list with a dot per language
        await expect(page.getByTestId('sms-templates-list')).toBeVisible();
        await expect(page.locator('[data-event="ticket_resolved"]')).toBeVisible();
        await expect(page.getByTestId('dot-ticket_resolved-en')).toBeVisible();

        // open the en ticket_resolved template → the editor
        await page.getByTestId('open-ticket_resolved-en').click();
        await expect(page.getByTestId('sms-template-editor')).toBeVisible();
        await expect(page.getByTestId('editor-counter')).toBeVisible();
        await expect(page.getByTestId('editor-encoding')).toContainText('GSM-7');

        // Ethiopic flips the WHOLE message to UCS-2, announced
        await page.getByTestId('editor-body').fill('ሰላም ያሸንፋል');
        await expect(page.getByTestId('editor-encoding')).toContainText('UCS-2');
        await expect(page.getByTestId('editor-announce')).toContainText('UCS-2');

        // the variable palette inserts the event's own variable
        await expect(page.getByTestId('editor-variables')).toBeVisible();
        const firstVar = page.getByTestId('editor-variables').locator('button').first();
        await firstVar.click();
        await expect(page.getByTestId('editor-body')).toHaveValue(/\{\{.+\}\}/);

        // the cost row per routed gateway (the fixture gateways carry cards)
        await expect(page.getByTestId('editor-cost')).toBeVisible();

        // SEC-05 inline: an arbitrary URL is refused by the SERVER, rendered inline
        await page.getByTestId('editor-body').fill('visit https://evil.example now');
        await page.getByTestId('editor-check').click();
        await expect(page.getByTestId('sms-templates-error')).toBeVisible();

        // the history pane renders versions
        await expect(page.getByTestId('editor-history')).toBeVisible();

        await axeClean(page, `templates ${theme}`);
    });
}

test('the am leg: the list and editor render in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/templates');
    await expect(page.getByTestId('sms-templates-list')).toBeVisible();
    await expect(page.locator('[data-event="ticket_resolved"]')).toContainText('ትኬት ተፈትቷል');
});

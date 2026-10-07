import { expect, test } from '@playwright/test';
import {
    axeClean,
    login,
    seedSmsScreens,
    setAgentLocale,
    setTheme,
    V1440,
    V375,
    clearThrottle,
} from './support/sms-screens';

/*
 * Doc 14 §9 ([B14-batch] step 13) — queue & history, PHONE-READABLE: the
 * lifecycle rows as definition lists with MASKED phones everywhere (SEC-02),
 * the state filter re-entering through the server, and the held view. Both
 * themes at 1440 AND 375, the am leg, axe.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    for (const viewport of [V1440, V375] as const) {
        const tag = `${theme}-${viewport.width}`;

        test(`the log rows, masking and the held view — ${tag}, axe-clean`, async ({ page }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/sms/queue');

            await expect(page.getByTestId('queue-log')).toBeVisible();
            await expect(page.locator('[data-sms-row]').first()).toBeVisible();

            // SEC-02: every phone on the page is masked — the full fixture digits never render
            const text = (await page.getByTestId('sms-queue').textContent()) ?? '';
            expect(text).toContain('•');
            expect(text).not.toMatch(/\+2519000071\d\d/);

            // the held view exists (empty is a drawn state)
            await expect(page.getByTestId('held-view')).toBeVisible();

            await axeClean(page, `queue ${tag}`);
        });
    }
}

test('the state filter re-enters through the server: failed shows only failed rows', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/queue?state=failed');
    await expect(page.locator('[data-sms-row]').first()).toBeVisible();
    const states = await page
        .locator('[data-sms-row]')
        .evaluateAll((els) => els.map((el) => el.getAttribute('data-sms-row')));
    expect(states.length).toBeGreaterThan(0);
    expect(states.every((s) => s === 'failed')).toBe(true);
});

test('the am leg: the queue renders in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V375);
    await page.goto('/admin/sms/queue');
    await expect(page.getByTestId('held-view')).toContainText('ለጸጥታ ሰዓታት ተይዘዋል');
});

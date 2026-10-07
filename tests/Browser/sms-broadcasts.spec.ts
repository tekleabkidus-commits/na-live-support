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
 * Doc 14 §14 / FR-17 / FR-18 / BR-01 ([B14-batch] step 13) — broadcasts: the
 * builder walks template pin → audience with the LIVE count → the cost
 * preview from the SAME query → the guards summary; NO free-text field exists
 * anywhere on the screen (BR-01 — asserted structurally: no textarea at all);
 * launch opens the progress drawer with counters and cancel. Light+dark at
 * 1440 (builder is desktop), the am leg, axe.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    test(`the builder: pin → live count → cost preview → guards; BR-01 no free-text — ${theme}, axe-clean`, async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await setTheme(page, theme);
        await page.goto('/admin/sms/broadcasts');

        await expect(page.getByTestId('broadcast-list')).toBeVisible();
        await page.getByTestId('broadcast-new').click();
        await expect(page.getByTestId('broadcast-builder')).toBeVisible();

        // BR-01 structural: the whole screen carries NO textarea and no free-text body field
        expect(await page.locator('textarea').count()).toBe(0);

        // pin the published service announcement + name the broadcast
        await page.getByTestId('builder-name').fill(`[FIXTURE] shutdown notice ${theme}`);
        await page.getByTestId('builder-template').selectOption({ index: 0 });
        await page.getByTestId('builder-draft').click();

        // the LIVE audience count answers (the preview door — the SAME query the chunker runs)
        await expect(page.getByTestId('builder-count')).toBeVisible();
        await expect(page.getByTestId('builder-cost')).toBeVisible();
        await expect(page.getByTestId('builder-guards')).toBeVisible();

        await axeClean(page, `broadcasts builder ${theme}`);
    });
}

test('launch opens the progress drawer with the counters and the cancel door', async ({ page }) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/broadcasts');

    await page.getByTestId('broadcast-new').click();
    const name = `[FIXTURE] launch run ${Date.now()}`;
    await page.getByTestId('builder-name').fill(name);
    await page.getByTestId('builder-template').selectOption({ index: 0 });
    await page.getByTestId('builder-draft').click();
    await expect(page.getByTestId('builder-count')).toBeVisible();

    await page.getByTestId('builder-launch').click();
    await expect(page.getByTestId('broadcasts-notice')).toBeVisible();

    // the progress drawer: counters + cost
    await page.getByTestId(`broadcast-open-${name}`).click();
    await expect(page.getByTestId('broadcast-progress')).toBeVisible();
    await expect(page.getByTestId('progress-counts')).toBeVisible();
    await expect(page.getByTestId('progress-cost')).toBeVisible();
});

test('the am leg: the broadcasts screen renders in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/broadcasts');
    await expect(page.getByTestId('bo-readnote')).toContainText('ነጻ-ጽሁፍ መስክ የለም');
});

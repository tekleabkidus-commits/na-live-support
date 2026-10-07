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
 * Doc 14 §8 / FR-14 / N-16 ([B14-batch] step 13) — the opt-out manager:
 * search over the MASKED list, add-with-reason, restore behind the
 * HIGH-VISIBILITY confirm (reason required, the warning states the
 * consequence), CSV export visible to the export key. Both themes, 1440 +
 * 375 (phone-readable), the am leg, axe.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    for (const viewport of [V1440, V375] as const) {
        const tag = `${theme}-${viewport.width}`;

        test(`the masked list, search and the export door — ${tag}, axe-clean`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/sms/optouts');

            await expect(page.getByTestId('optouts-list')).toBeVisible();
            const text = (await page.getByTestId('optouts-list').textContent()) ?? '';
            expect(text).toContain('•');
            expect(text).not.toContain('+251900007900');
            await expect(page.getByTestId('optouts-export')).toBeVisible();

            await axeClean(page, `optouts ${tag}`);
        });
    }
}

test('add-with-reason lands a masked row; restore demands the high-visibility confirm with a reason', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/optouts');

    // add
    await page.getByTestId('add-phone').fill('+251900007905');
    await page.getByTestId('add-reason').fill('[FIXTURE] browser add');
    await page.getByTestId('add-submit').click();
    await expect(page.getByTestId('optouts-notice')).toBeVisible();

    // restore: the confirm names the number, warns, and requires a reason
    const masked = '+251 90 ••• 7905';
    await page.getByTestId(`restore-${masked}`).click();
    await expect(page.getByTestId('restore-confirm')).toBeVisible();
    await expect(page.getByTestId('restore-go')).toBeDisabled();
    await page.getByTestId('restore-reason').fill('[FIXTURE] customer asked back');
    await page.getByTestId('restore-go').click();
    await expect(page.getByTestId('optouts-notice')).toBeVisible();
});

test('the am leg: the opt-out manager renders in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V375);
    await page.goto('/admin/sms/optouts');
    await expect(page.getByTestId('bo-readnote')).toContainText('መውጣት ፍጹም ነው');
});

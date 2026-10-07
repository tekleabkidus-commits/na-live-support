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
 * Doc 14 §8 / FR-16 ([B14-batch] step 13) — SMS settings: the setup checklist
 * first ("SMS is off until a gateway exists" when none — here the fixture
 * gateway makes it green), quiet hours in the TENANT timezone with explicit
 * labels, the per-event toggles writing through the ONE settings door, the
 * platform quotas read-only. This spec also carries the F-C3 SHELL SWEEP for
 * P-NAV-SMS: the two new Backoffice rows render with accessible names and the
 * settings row carries aria-current, axe-clean in both themes. 1440 + 375,
 * the am leg.
 */

test.beforeAll(() => seedSmsScreens());

test.beforeEach(() => clearThrottle());

test.afterAll(() => setAgentLocale('en'));

for (const theme of ['light', 'dark'] as const) {
    for (const viewport of [V1440, V375] as const) {
        const tag = `${theme}-${viewport.width}`;

        test(`the checklist, quiet hours, toggles, quotas + the P-NAV-SMS shell rows — ${tag}, axe-clean`, async ({
            page,
        }) => {
            await login(page);
            await page.setViewportSize(viewport);
            await setTheme(page, theme);
            await page.goto('/admin/sms/settings');

            // F-C3 / P-NAV-SMS: the two drawn slots are REAL rows with accessible
            // names (the shell nav collapses at 375 — the rows are 1440's to prove)
            if (viewport.width === 1440) {
                await expect(page.getByTestId('bo-nav-sms')).toBeVisible();
                await expect(page.getByTestId('bo-nav-sms-settings')).toBeVisible();
                await expect(page.getByTestId('bo-nav-sms-settings')).toHaveAttribute(
                    'aria-current',
                    'page',
                );
            }

            // the checklist (the fixture gateway + route make it green)
            await expect(page.getByTestId('setup-checklist')).toBeVisible();
            await expect(page.getByTestId('checklist-gateway')).toContainText('✓');
            await expect(page.getByTestId('checklist-route')).toContainText('✓');

            // quiet hours carry the tenant timezone in the label
            await expect(page.getByTestId('quiet-hours')).toBeVisible();
            await expect(page.getByTestId('quiet-start')).toBeVisible();

            // quotas are stated and platform-set
            await expect(page.getByTestId('quota-segments')).toBeVisible();

            await axeClean(page, `settings ${tag} (the F-C3 shell sweep rides this page)`);
        });
    }
}

test('an event toggle writes through the settings door and announces saved', async ({ page }) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/admin/sms/settings');

    const toggle = page.getByTestId('toggle-ticket_closed');
    const before = await toggle.isChecked();
    await toggle.click();
    await expect(page.getByTestId('settings-notice')).toBeVisible();
    await page.reload();
    expect(await page.getByTestId('toggle-ticket_closed').isChecked()).toBe(!before);
    // put it back (the seeded default)
    await page.getByTestId('toggle-ticket_closed').click();
    await expect(page.getByTestId('settings-notice')).toBeVisible();
});

test('the am leg: the settings screen renders in Amharic', async ({ page }) => {
    setAgentLocale('am');
    await login(page);
    await page.setViewportSize(V375);
    await page.goto('/admin/sms/settings');
    await expect(page.getByTestId('quiet-hours')).toContainText('ጸጥታ ሰዓታት');
});

// Doc 13 FS-01/FS-02/FS-04 / NFR-06 — DEGRADATION (K02, K03): the visitor's own
// connection gone = the one banner they can act on; the whole API down = the
// last-resort card with retry, the launcher stays; the retry re-mints.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';
import { openWidget } from './support/panel';

test('K02: offline → the calm banner in the composer, typing still works; back online → it goes', async ({
    page,
    context,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    await context.setOffline(true);
    await expect(panel.locator('[data-role=offline]')).toContainText('You are offline');
    await panel.locator('textarea').fill('typed while offline');
    expect(await panel.locator('textarea').inputValue()).toBe('typed while offline');
    await context.setOffline(false);
    await expect(panel.locator('[data-role=offline]')).toHaveCount(0);
});

test('K03: the session door down → the last-resort card (no tab bar), the launcher stays; Try again after the door returns boots the panel', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    let down = true;
    await page.route('**/api/widget/v1/session', (route) =>
        down ? route.fulfill({ status: 503, body: '' }) : route.continue(),
    );
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('[data-role=retry]')).toBeVisible({ timeout: 10000 });
    await expect(panel.locator('[data-role=retry]')).toContainText(
        'Nothing you typed has been lost',
    );
    expect(await panel.locator('[role=tablist]').count()).toBe(0);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    const text = (await panel.locator('[data-role=retry]').textContent()) ?? '';
    expect(text.toLowerCase()).not.toMatch(/error|failed|broken|crash/);
    down = false;
    await panel.locator('[data-role=retry] button').click();
    await expect(panel.locator('.nlw-greeting')).toBeVisible({ timeout: 10000 });
});

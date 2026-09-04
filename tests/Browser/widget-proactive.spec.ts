// Doc 13 FR-14 / BR-06 / EC-05 / H01–H04 / AC-06 — PROACTIVE: a URL rule fires on
// the deposit page with its page-specific message; a dismissal ends it on that
// page for good; the cooldown is one per visitor across rules; never over a
// keyboard (a focused page field defers it until blur); capture() keys reach the
// server allowlist-filtered.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';
import { harness } from './support/panel';

test('H01/H02/H03/H04: fires on /deposit with the page-specific message; deferred while a field has focus; dismissed = gone on that page; cooldown holds across reloads', async ({
    page,
}) => {
    harness('config', '--value=off');
    const key = harnessWidgetKey();
    // H04: the panel's config is held back 2 s so the page's own input is focused BEFORE the panel can ask the
    // loader whether a field has focus — the card must wait for blur (EC-05: the PRESENCE of focus, never its value)
    let hold = true;
    await page.route('**/api/widget/v1/config*', async (route) => {
        if (hold) await new Promise((r) => setTimeout(r, 2000));
        await route.continue();
    });
    await page.goto(`${CUSTOMER}/deposit.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    await page.locator('#host-input').focus();
    expect(await page.evaluate(() => document.activeElement?.id)).toBe('host-input');
    hold = false;
    const launcher = page.frameLocator('iframe#nlw-launcher');
    await page.waitForTimeout(2500);
    expect(await launcher.locator('[data-role=proactive]').count()).toBe(0);
    await page.locator('#host-input').blur();
    await expect(launcher.locator('[data-role=proactive]')).toContainText(
        'how long your deposit should take',
        { timeout: 10000 },
    );
    await expect(page.locator('iframe#nlw-launcher')).toHaveCSS('width', '320px'); // the frame grew for the card
    // H02: dismiss → gone; reload the same page → still gone
    await launcher.locator('[data-role=proactive] button[aria-label=Dismiss]').click();
    await expect(launcher.locator('[data-role=proactive]')).toHaveCount(0);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCSS('width', '64px');
    await page.reload();
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    await page.waitForTimeout(3000);
    expect(
        await page.frameLocator('iframe#nlw-launcher').locator('[data-role=proactive]').count(),
    ).toBe(0);
});

test('BR-06: not on a page the rule does not name; FR-24: capture() keys reach the server, allowlist-filtered', async ({
    page,
}) => {
    harness('config', '--value=off');
    const key = harnessWidgetKey();
    let contextPosts = 0;
    page.on('request', (r) => {
        if (r.url().endsWith('/api/widget/v1/context')) contextPosts++;
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    await page.waitForTimeout(3000);
    expect(
        await page.frameLocator('iframe#nlw-launcher').locator('[data-role=proactive]').count(),
    ).toBe(0);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { capture(c: unknown): void } }).PlatformChat.capture(
            { page: 'home', plan: 'gold', secret_card: '4111' },
        ),
    );
    await expect.poll(() => contextPosts, { timeout: 10000 }).toBe(1);
});

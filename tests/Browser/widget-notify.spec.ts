// Doc 13 FR-17 / BR-03 / I01 / EC-09 — notifications are EARNED: no permission
// request on load, the widget's own question only after the first sent message,
// the browser dialog only on yes; sabotage (j) lands here.
import { expect, test } from '@playwright/test';
import { openWidget, sendFromPanel } from './support/panel';

test('BR-03: Notification.requestPermission is never called on load or open; the widget asks first after the first message; the browser is asked only on yes', async ({
    page,
}) => {
    await page.addInitScript(() => {
        const w = window as unknown as {
            __permissionCalls: number;
            Notification: { requestPermission: () => Promise<string> };
        };
        w.__permissionCalls = 0;
        // The browser's decision is the fixture's: an UNDECIDED permission (headless Chromium reports 'denied' by default).
        Object.defineProperty(Notification, 'permission', {
            get: () => 'default',
            configurable: true,
        });
        const orig = Notification.requestPermission.bind(Notification);
        Notification.requestPermission = async () => {
            w.__permissionCalls++;
            return orig();
        };
    });
    const { panel } = await openWidget(page);
    const calls = () =>
        page
            .frames()
            .find((f) => f.url().includes('frame=panel'))!
            .evaluate(() => (window as unknown as { __permissionCalls: number }).__permissionCalls);
    await page.waitForTimeout(1500);
    expect(await calls()).toBe(0); // never on load / open
    expect(await panel.locator('[data-role=notify-prompt]').count()).toBe(0);
    await sendFromPanel(panel, 'notify: first message');
    await expect(panel.locator('[data-role=notify-prompt]')).toHaveCount(1); // the widget's OWN question, now
    expect(await calls()).toBe(0); // still no browser dialog
    await panel.locator('[data-role=notify-prompt] button', { hasText: 'Not now' }).click();
    await expect(panel.locator('[data-role=notify-prompt]')).toHaveCount(0);
    expect(await calls()).toBe(0);
});

test('EC-09: with no Notification API at all (a WebView) nothing asks and nothing breaks', async ({
    page,
}) => {
    await page.addInitScript(() => {
        delete (window as unknown as { Notification?: unknown }).Notification;
    });
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'notify: no api');
    await page.waitForTimeout(800);
    expect(await panel.locator('[data-role=notify-prompt]').count()).toBe(0);
});

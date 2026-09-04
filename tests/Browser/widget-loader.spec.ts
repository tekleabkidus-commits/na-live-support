// Doc 13 step 7 — THE LOADER on the HARNESS customer origin (FR-01/FR-02/T-07/
// EC-01/EC-02/NFR-05/NFR-06). Every case is a real cross-origin page load: the
// snippet is fetched from the platform origin, the two iframes are the platform
// origin, the host is the customer origin. Written at 27 % battery; RUN on wall
// power (R-D13-6) — the first charging window's second leg after the owed
// frame-ancestors control.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

const API = ['open', 'close', 'openHelp', 'identify', 'setLanguage', 'on', 'capture'];

test('FR-01/FR-02: the snippet mounts two fixed-position iframes and nothing else; window.PlatformChat carries exactly the seven methods and queues until the panel is ready', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    const styles = await page
        .locator('iframe#nlw-launcher')
        .evaluate((el) => getComputedStyle(el).position);
    expect(styles).toBe('fixed');
    const api = await page.evaluate(() =>
        Object.keys((window as unknown as { PlatformChat: Record<string, unknown> }).PlatformChat)
            .filter((k) => !k.startsWith('__'))
            .sort(),
    );
    expect(api).toEqual([...API].sort());
    // queue-until-ready: a call before `ready` does not throw and is replayed — the panel opens.
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    await expect
        .poll(async () =>
            page
                .locator('iframe#nlw-panel')
                .evaluate((el) => (el as HTMLIFrameElement).getAttribute('aria-hidden')),
        )
        .not.toBe('true');
});

test('T-07: history hooks are namespaced wrappers and RESTORABLE — unmount() puts the originals back and removes both iframes and both globals', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    const wrapped = await page.evaluate(
        () =>
            history.pushState !==
            (window as unknown as { __harnessPush: History['pushState'] }).__harnessPush,
    );
    expect(wrapped).toBe(true);
    await page.evaluate(() =>
        (
            window as unknown as { PlatformChat: { __nlw: { unmount(): void } } }
        ).PlatformChat.__nlw.unmount(),
    );
    const after = await page.evaluate(() => ({
        push:
            history.pushState ===
            (window as unknown as { __harnessPush: History['pushState'] }).__harnessPush,
        replace:
            history.replaceState ===
            (window as unknown as { __harnessReplace: History['replaceState'] }).__harnessReplace,
        api: 'PlatformChat' in window,
        flag: '__nlwLoaded' in window,
        frames: document.querySelectorAll('iframe').length,
    }));
    expect(after).toEqual({ push: true, replace: true, api: false, flag: false, frames: 0 });
});

test('EC-01: a second snippet is a no-op — still two iframes, one console warning, no second API', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    const warnings: string[] = [];
    page.on('console', (m) => {
        if (m.type() === 'warning') warnings.push(m.text());
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}&twice=1`);
    await expect(page.locator('iframe')).toHaveCount(2);
    await expect
        .poll(() => warnings.filter((w) => w.includes('[PlatformChat] already installed')).length)
        .toBe(1);
});

test('NFR-05: below the support matrix (no BroadcastChannel) the loader does not mount — silently; the host page is untouched', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.addInitScript(() => {
        delete (window as unknown as { BroadcastChannel?: unknown }).BroadcastChannel;
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.waitForTimeout(800);
    expect(await page.locator('iframe').count()).toBe(0);
    expect(await page.locator('#host-input').inputValue()).toBe('FIXTURE host value');
});

test('NFR-06: an unknown key (a dead widget) leaves the host page intact — no iframe, no thrown error, the page keeps working', async ({
    page,
}) => {
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`${CUSTOMER}/host.html?key=fixture.nosuchkey`);
    await page.waitForTimeout(800);
    expect(await page.locator('iframe').count()).toBe(0);
    expect(errors).toEqual([]);
    expect(await page.locator('#host-input').inputValue()).toBe('FIXTURE host value');
});

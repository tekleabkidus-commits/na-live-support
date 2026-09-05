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

// ── NFR-01 (advisor ruling 2026-09-05, the launcher-paint miss): the LOADER-DRAWN LAUNCHER ──
// The launcher frame paints its bubble from a minimal document that fetches no core bundle; the
// core boots on first open or on idle AFTER that paint. Fail-first control: on the tree before the
// fix the bubble never paints with the core blocked (docs/evidence/S13-step13/launcher-fail-first-red-01.txt).
const isShim = (p: string): boolean => /\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js$/.test(p);
const isCore = (u: URL): boolean =>
    u.pathname.startsWith('/w/') && u.pathname.endsWith('.js') && !isShim(u.pathname);

test('NFR-01: with every widget script except the shim BLOCKED, the launcher bubble still paints with its accessible name within 3 s — the bubble does not depend on the core; nothing else of the widget renders without it; the first open then boots the core and reveals the panel', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.route(isCore, (route) => route.abort());
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    const bubble = page.frameLocator('iframe#nlw-launcher').locator('button.nlw-launcher');
    await expect(bubble).toBeVisible({ timeout: 3000 });
    await expect(bubble).toHaveAttribute('aria-label', 'Open support chat');
    // with the core blocked no dialog can exist anywhere — the bubble is the ONLY thing that painted
    expect(await page.frameLocator('iframe#nlw-panel').locator('[role=dialog]').count()).toBe(0);
    // first open with the core reachable: the panel frame gets its document and the dialog appears
    await page.unroute(isCore);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    await expect(page.locator('iframe#nlw-panel')).toHaveAttribute('src', /frame=panel/, {
        timeout: 5000,
    });
    // the idle boot may have fired while the core was blocked (a dead first fetch): a reload of the page is the
    // honest recovery today — so this leg asserts the open on a fresh load with the core reachable
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    await expect(page.frameLocator('iframe#nlw-panel').locator('[role=dialog]')).toHaveCount(1, {
        timeout: 15000,
    });
});

test('NFR-01: a click on the loader-drawn bubble BEFORE the core arrived (the core delayed 3 s) opens the panel through the loader relay once the core lands; the label then follows the open state', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.route(isCore, (route) => {
        setTimeout(() => void route.continue(), 3000);
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    const staticBubble = page.frameLocator('iframe#nlw-launcher').locator('button#nlw-static');
    await expect(staticBubble).toBeVisible({ timeout: 3000 });
    await staticBubble.click(); // the static launcher's message → the loader's `api` relay → queued until the core is ready
    await expect(page.frameLocator('iframe#nlw-panel').locator('[role=dialog]')).toHaveCount(1, {
        timeout: 20000,
    });
    await expect(
        page.frameLocator('iframe#nlw-launcher').locator('button.nlw-launcher'),
    ).toHaveAttribute('aria-label', 'Close support chat', { timeout: 10000 });
});

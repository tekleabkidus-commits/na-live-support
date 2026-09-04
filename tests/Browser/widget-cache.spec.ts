// Doc 13 FR-08 / NFR-03 — THE CACHE, both states: on, a returning visitor's
// thread paints from IndexedDB before the first cursor page; off (the operator's
// `widget.cache.content`), nothing is ever stored and the store is purged.
import { expect, test } from '@playwright/test';
import { CUSTOMER } from './support/harness';
import { harness, openWidget, sendFromPanel } from './support/panel';

const keys = (page: import('@playwright/test').Page) =>
    page
        .frames()
        .find((f) => f.url().includes('frame=panel'))!
        .evaluate(
            () =>
                new Promise<string[]>((resolve) => {
                    const req = indexedDB.open('nlw', 1);
                    req.onupgradeneeded = () => req.result.createObjectStore('thread');
                    req.onsuccess = () => {
                        const r = req.result
                            .transaction('thread', 'readonly')
                            .objectStore('thread')
                            .getAllKeys();
                        r.onsuccess = () => resolve((r.result as IDBValidKey[]).map(String));
                        r.onerror = () => resolve([]);
                    };
                    req.onerror = () => resolve([]);
                }),
        );

test('cache ON: the thread is stored content-lite and paints on reload BEFORE the first cursor page answers', async ({
    page,
}) => {
    harness('setting', '--key=widget.cache.content', '--value=true');
    const { panel, key } = await openWidget(page);
    await sendFromPanel(panel, 'cache: remember me');
    await expect.poll(() => keys(page), { timeout: 5000 }).toHaveLength(1);
    // Hold the first cursor page: the cached thread must already be on screen.
    await page.route('**/api/widget/v1/messages*', async (route) => {
        await new Promise((r) => setTimeout(r, 2500));
        await route.continue();
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const p2 = page.frameLocator('iframe#nlw-panel');
    await p2.locator('#nlw-tab-messages').click();
    await expect(p2.locator('[data-message][data-author=customer]')).toHaveCount(1, {
        timeout: 2000,
    });
    await expect(p2.locator('[data-message]').first()).not.toContainText('token');
});

test('cache OFF: nothing is stored and an existing store is purged', async ({ page }) => {
    harness('setting', '--key=widget.cache.content', '--value=false');
    try {
        const { panel } = await openWidget(page);
        await sendFromPanel(panel, 'cache: forget me');
        await page.waitForTimeout(1500);
        expect(await keys(page)).toHaveLength(0);
    } finally {
        harness('setting', '--key=widget.cache.content', '--value=true');
    }
});

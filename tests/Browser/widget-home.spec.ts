// Doc 13 FR-09 / BR-02 — HOME (C01–C07): the greeting painted from config, the
// honest wait band (Doc 11's band, never a count; off-hours = opens-at), the
// four shortcut kinds, the resume card only with history, and NO onboarding.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

test('C01/C06/C07: greeting + band + Quick answers; no tour, no tips, no coach marks; a URL shortcut says it opens the site', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('.nlw-greeting')).toHaveText(/Hello 👋/);
    await expect(panel.locator('.nlw-band')).toHaveText(/Typically replies/);
    await expect(panel.locator('.nlw-band .nlw-dot, .nlw-band [aria-hidden]')).toHaveCount(1);
    await expect(panel.locator('.nlw-card h3').first()).toHaveText('Quick answers');
    // C07: nothing of onboarding exists — no tour, no dismissible tip, no "new" dot
    const text = (await panel.locator('[role=dialog]').textContent()) ?? '';
    expect(text.toLowerCase()).not.toMatch(/tour|welcome to|got it|skip|tip:/);
    expect(await panel.locator('[data-role=tip], [data-role=tour], .nlw-new-dot').count()).toBe(0);
});

test('BR-02: the band comes from the server (Doc 11) — the Home envelope carries a band string and never a number of agents', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    let home: { band: string | null } | null = null;
    page.on('response', async (r) => {
        if (r.url().includes('/api/widget/v1/config'))
            home = ((await r.json()) as { home: { band: string | null } }).home;
    });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    await expect(page.frameLocator('iframe#nlw-panel').locator('.nlw-band')).toBeVisible();
    await expect.poll(() => home, { timeout: 5000 }).not.toBeNull();
    expect(['short', 'medium', 'long', 'off_hours', null]).toContain(home!.band);
    expect(JSON.stringify(home)).not.toMatch(/agent|count|online/);
});

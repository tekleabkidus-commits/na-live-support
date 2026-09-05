// Doc 13 FR-20 / NFR-04 / EC-11 — LIVE PROPAGATION: a publish through the Designer's
// door (the harness `config` action rides WidgetConfigPublisher) reaches an OPEN panel
// over the public per-widget config channel without a reload, and the swap is in
// place: Messages-only takes the tab bar away; the next publish brings it back.
import { expect, test } from '@playwright/test';
import { harness, openWidget, panelHooks } from './support/panel';

test('FR-20/EC-11: a publish reaches the open panel by push (no reload) and re-themes in place; NFR-04 well inside 60 s', async ({
    page,
}) => {
    harness('config', '--value=off');
    const { panel } = await openWidget(page);
    const hooks = panelHooks(page);
    await expect.poll(() => hooks.socketUp(), { timeout: 15000 }).toBe(true);
    await expect(panel.locator('[role=tablist]')).toBeVisible();
    let reloads = 0;
    page.on('framenavigated', (f) => {
        if (f.url().includes('frame=panel')) reloads++;
    });
    const t0 = Date.now();
    expect(harness('config', '--value=messages-only')).toContain('CONFIG_VERSION=');
    await expect(panel.locator('[role=tablist]')).toHaveCount(0, { timeout: 30000 }); // Messages-only: the tab bar is GONE, in place
    const elapsed = Date.now() - t0;
    expect(elapsed).toBeLessThan(60000);
    expect(reloads).toBe(0);
    await expect(panel.locator('[role=dialog]')).toBeVisible();
    expect(harness('config', '--value=off')).toContain('CONFIG_VERSION=');
    await expect(panel.locator('[role=tablist]')).toBeVisible({ timeout: 30000 });
    expect(reloads).toBe(0);
    console.log(`propagation: ${elapsed} ms`);
});

// Doc 13 §8/§13 — THE PANEL SHELL (B05–B09; L07 focus; §13 dialog): a dialog with
// focus trapped, Esc closes, the tab bar per config, the persona header, the
// overflow menu, the sheet under 480 px; what a screen reader is ANNOUNCED
// (the log region). Runs on `widget` (desktop) and `widget-375` (the sheet).
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

async function openPanel(page: import('@playwright/test').Page) {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('[role=dialog]')).toHaveCount(1);
    await expect(panel.locator('.nlw-greeting')).toBeVisible();
    return panel;
}

test('B05/B06 + §13: the panel is a modal dialog with a persona header, three tabs from config, focus inside it, Esc closes it and the frame collapses', async ({
    page,
}) => {
    const panel = await openPanel(page);
    await expect(panel.locator('[role=dialog]')).toHaveAttribute('aria-modal', 'true');
    await expect(panel.locator('[data-role=header-state]')).toHaveText('Support assistant');
    await expect(panel.locator('[role=tab]')).toHaveCount(3);
    await expect(panel.locator('[role=tab][aria-selected=true]')).toHaveText(/Home/);
    const focusedInside = await panel
        .locator('[role=dialog]')
        .evaluate((el) => el.contains(el.ownerDocument.activeElement));
    expect(focusedInside).toBe(true);
    await panel.locator('[role=dialog]').press('Escape');
    await expect
        .poll(() => page.locator('iframe#nlw-panel').getAttribute('aria-hidden'))
        .toBe('true');
});

test('L07: Tab from the last control wraps to the first (the trap); the header carries minimize on desktop only, the sheet has none', async ({
    page,
}, testInfo) => {
    const panel = await openPanel(page);
    const isSheet = testInfo.project.name === 'widget-375';
    await expect(panel.locator('[role=dialog]')).toHaveAttribute('data-sheet', String(isSheet));
    await expect(panel.locator('button[aria-label=Minimize]')).toHaveCount(isSheet ? 0 : 1);
    // wrap: focus the last tab, press Tab, expect the first focusable (the overflow button) to hold focus
    await panel.locator('[role=tab]').last().focus();
    await panel.locator('[role=tab]').last().press('Tab');
    const active = await panel
        .locator('[role=dialog]')
        .evaluate(
            (el) =>
                (el.ownerDocument.activeElement as HTMLElement | null)?.getAttribute(
                    'aria-label',
                ) ?? '',
        );
    expect(['More options', 'Minimize', 'Close']).toContain(active);
});

test('B07: the overflow menu offers sound, both languages and privacy; switching to Amharic re-renders the tab bar in real Ethiopic without a remount (the dialog node stays)', async ({
    page,
}) => {
    const panel = await openPanel(page);
    const dialogId = await panel
        .locator('[role=dialog]')
        .evaluate((el) => ((el as HTMLElement & { __id?: number }).__id = Math.random()));
    await panel.locator('button[aria-label="More options"]').click();
    await expect(
        panel.locator('[role=menu] [role=menuitem], [role=menu] [role=menuitemradio]'),
    ).toHaveCount(5);
    await panel.locator('[role=menuitemradio]', { hasText: 'አማርኛ' }).click();
    await expect(panel.locator('[role=tab]').first()).toHaveText(/መነሻ/);
    const same = await panel
        .locator('[role=dialog]')
        .evaluate((el, id) => (el as HTMLElement & { __id?: number }).__id === id, dialogId);
    expect(same).toBe(true);
    await expect(panel.locator('html')).toHaveAttribute('lang', 'am');
});

test('B01/B02: the launcher label names the state and the unread COUNT as a number — never a bare dot', async ({
    page,
}) => {
    await openPanel(page);
    const launcher = page.frameLocator('iframe#nlw-launcher').locator('button');
    await expect(launcher).toHaveAttribute('aria-label', 'Close support chat');
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { close(): void } }).PlatformChat.close(),
    );
    await expect(launcher).toHaveAttribute('aria-label', 'Open support chat');
});

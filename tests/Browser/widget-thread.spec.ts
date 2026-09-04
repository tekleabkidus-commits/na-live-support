// Doc 13 FR-10/FR-12 + AC-01 (the DOM half) — THE THREAD (D02–D18): a send lands
// as the visitor's own bubble with a TRUTHFUL glyph — ✓ on the 202, ✓✓ only
// once the server's cursor page carries the row; the day separator; the log
// region announces; the empty state shows the chat shortcuts first.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

test('D18 → D02: empty state, then a sent message earns ✓✓ only when the server has it; the log region announces; Today separates', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await panel.locator('#nlw-tab-messages').click();
    await expect(panel.locator('[data-role=empty] strong')).toHaveText('No messages yet');
    const box = panel.locator('textarea');
    await box.fill('ገንዘቤ አልደረሰኝም — where is my withdrawal?');
    await box.press('Enter');
    const own = panel.locator('[data-message][data-author=customer]');
    await expect(own).toHaveCount(1);
    // AC-01: the double check appears ONLY after the cursor page carries the row (data-delivery flips to delivered).
    await expect(own).toHaveAttribute('data-delivery', 'delivered', { timeout: 15000 });
    await expect(own.locator('[data-role=delivery]')).toHaveText('✓✓');
    await expect(panel.locator('.nlw-day').first()).toHaveText('Today');
    await expect(panel.locator('[data-role=log]')).toContainText('You wrote: ገንዘቤ አልደረሰኝም');
    // A reload restores the same conversation (FR-08 continuity): the bubble is back from the server.
    await page.reload();
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    await page.frameLocator('iframe#nlw-panel').locator('#nlw-tab-messages').click();
    await expect(
        page.frameLocator('iframe#nlw-panel').locator('[data-message][data-author=customer]'),
    ).toHaveCount(1);
});

test('G01: Shift+Enter is a newline, Enter sends; the composer never sends an empty message (the send button is disabled)', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await panel.locator('#nlw-tab-messages').click();
    await expect(panel.locator('button[aria-label=Send]')).toBeDisabled();
    const box = panel.locator('textarea');
    await box.fill('line one');
    await box.press('Shift+Enter');
    await box.type('line two');
    expect(await box.inputValue()).toBe('line one\nline two');
    await expect(panel.locator('button[aria-label=Send]')).toBeEnabled();
});

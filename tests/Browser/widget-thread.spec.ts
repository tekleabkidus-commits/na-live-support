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
    // AC-01's TRUTH TEST (sabotage (h) lands here): hold every cursor page back for 3 s after the send —
    // while the server's row is unseen the glyph must be a SINGLE check; ✓✓ may appear only once the
    // page carries the row.
    let hold = true;
    await page.route('**/api/widget/v1/messages*', async (route) => {
        if (hold && route.request().method() === 'GET')
            await new Promise((r) => setTimeout(r, 3000));
        await route.continue();
    });
    const box = panel.locator('textarea');
    await box.fill('[FIXTURE] ገንዘቤ አልደረሰኝም — where is my withdrawal?');
    await box.press('Enter');
    const own = panel.locator('[data-message][data-author=customer]');
    await expect(own).toHaveCount(1);
    await expect(own).toHaveAttribute('data-delivery', 'sent', { timeout: 3000 });
    await page.waitForTimeout(1500);
    await expect(own).toHaveAttribute('data-delivery', 'sent'); // still one check: no page has carried the row yet
    await expect(own.locator('[data-role=delivery]')).toHaveText('✓');
    hold = false;
    await expect(own).toHaveAttribute('data-delivery', 'delivered', { timeout: 20000 });
    await expect(own.locator('[data-role=delivery]')).toHaveText('✓✓');
    await expect(panel.locator('.nlw-day').first()).toHaveText('Today');
    await expect(panel.locator('[data-role=log]')).toContainText(
        'You wrote: [FIXTURE] ገንዘቤ አልደረሰኝም',
    );
    // D02–D07 on the real pipeline: the mock AI answers non-JSON → Doc 09 hands off → the AI's handoff message
    // renders with the violet accent AND the words "AI assistant" (the label is what discloses, §8/N-08).
    const ai = panel.locator('[data-message][data-author=ai]').first();
    await expect(ai).toBeVisible({ timeout: 30000 });
    await expect(ai.locator('.nlw-label')).toContainText('AI assistant');
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

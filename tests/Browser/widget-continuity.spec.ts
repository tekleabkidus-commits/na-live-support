// Doc 13 FR-08 / F12 / Doc 02 EC-09 — CONTINUITY: the conversation follows the
// visitor across three pages of the operator's site without a reconnect or a
// fresh visitor; the session refresh answers 200 (the same visitor), never 201.
import { expect, test } from '@playwright/test';
import { CUSTOMER } from './support/harness';
import { openWidget, sendFromPanel } from './support/panel';

test('F12: home → /deposit → /payments — the same conversation, the same visitor (200 on refresh), no flicker of a fresh thread', async ({
    page,
}) => {
    const sessions: number[] = [];
    page.on('response', (r) => {
        if (r.url().endsWith('/api/widget/v1/session')) sessions.push(r.status());
    });
    const { panel, key } = await openWidget(page);
    await sendFromPanel(panel, 'continuity: page one');
    expect(sessions).toEqual([201]); // the first page mints
    for (const path of ['deposit.html', 'payments.html']) {
        await page.goto(`${CUSTOMER}/${path}?key=${encodeURIComponent(key)}`);
        await page.evaluate(() =>
            (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
        );
        const p = page.frameLocator('iframe#nlw-panel');
        await p.locator('#nlw-tab-messages').click();
        await expect(p.locator('[data-message][data-author=customer]')).toHaveCount(1, {
            timeout: 10000,
        });
        await expect(p.locator('[data-message][data-author=customer]').first()).toContainText(
            'continuity: page one',
        );
    }
    expect(sessions).toEqual([201, 200, 200]); // refreshed twice as the SAME visitor
});

// Doc 13 §8 / Doc 12 FR-25 / BR-04 / D16–D17 — CSAT inline, then the thread
// simply carries on: the resolve arms the survey (Doc 12's consumer), the star
// row renders as a radiogroup, a star posts the score, the composer still works.
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test('D16/D17: the star row arrives after resolve; a star scores; the visitor keeps typing in the same thread — no reset, no wall', async ({
    page,
}) => {
    let csat = 0;
    page.on('response', (r) => {
        if (r.url().includes('/api/widget/v1/csat') && r.status() < 300) csat++;
    });
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'csat: thanks, it arrived');
    harness('handoff');
    harness('agent-join');
    harness('staff-reply', '--text=Glad it arrived — anything else?');
    await expect(panel.locator('[data-message][data-author=agent]')).toHaveCount(1, {
        timeout: 15000,
    });
    harness('resolve');
    const stars = panel.locator('[data-role=csat] [role=radio]');
    await expect(stars).toHaveCount(5, { timeout: 20000 });
    await expect(panel.locator('[data-role=csat]')).toContainText('How did we do?');
    await stars.nth(4).click();
    await expect.poll(() => csat, { timeout: 10000 }).toBe(1);
    // BR-04: the same conversation continues — no "start a new chat", the composer sends
    await sendFromPanel(panel, 'one more thing — can I change my method?');
    await expect(panel.locator('[data-message][data-author=customer]')).toHaveCount(2);
    expect(await panel.locator('[role=dialog]').textContent()).not.toMatch(
        /new chat|chat has ended|start over/i,
    );
});

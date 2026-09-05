// Doc 13 FS-04 / K04 (B3-S09): Doc 09's chatbot is OFF for the webchat surface (its FS-01) → the widget shows the
// straight-to-queue experience — no AI bubble, no error, the queue card; `capabilities.chatbot` answers false.
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test('K04 / FS-04: with the chatbot switched off the visitor goes straight to the queue — no AI bubble, no failure word, the queue card', async ({
    page,
}) => {
    test.setTimeout(90_000);
    expect(harness('chatbot-off')).toContain('chatbot-off');
    try {
        const { panel } = await openWidget(page);
        const frame = () => page.frames().find((f) => f.url().includes('frame=panel'))!;
        await expect
            .poll(
                () =>
                    frame().evaluate(() =>
                        (
                            window as unknown as { __nlwPanel: { chatbot(): boolean | null } }
                        ).__nlwPanel.chatbot(),
                    ),
                { timeout: 15000 },
            )
            .toBe(false); // capabilities.chatbot answers the switch
        await sendFromPanel(panel, 'K04 drill: where is my withdrawal?');
        expect(harness('handoff')).toContain('TICKET=');
        await expect(panel.locator('[data-role=queue]')).toBeVisible({ timeout: 30000 });
        await page.waitForTimeout(4000);
        // Doc 09's kill-switch path emits its disclosure + the handoff TEMPLATE (never a generated answer): the drawn
        // "handoff template + queue card". Nothing here answers the question, nothing says the bot is down.
        const aiBubbles = await panel
            .locator('[data-message][data-author=ai] .nlw-bubble')
            .allTextContents();
        expect(aiBubbles.length).toBeLessThanOrEqual(1);
        for (const b of aiBubbles) {
            expect(b).toMatch(/member of our team|a person|hand/i); // the template
            expect(b).not.toMatch(/withdrawal/i); // no answer to the question
        }
        // no failure vocabulary on anything the platform wrote (the visitor's own words are theirs)
        const platformText = (
            await panel
                .locator(
                    '[data-message]:not([data-author=customer]), [data-role=queue], [data-role=log]',
                )
                .allInnerTexts()
        ).join(' ');
        expect(platformText).not.toMatch(/error|fail|unavailable|down|broken/i);
        await expect(panel.locator('textarea')).toBeVisible(); // the visitor keeps typing into the same thread (BR-04)
    } finally {
        harness('chatbot-on');
    }
});

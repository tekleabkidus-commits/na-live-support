// Doc 13 B3-S05 F11 — "17:59 becomes 18:00 mid-sentence": the hours boundary is NOT a cliff. A visitor in a live thread
// keeps the thread and the composer when the calendar closes under them (the offline gate is for visitors with no
// thread and no bot); the next config read carries `hours.open = false` and the Home band says so — nothing resets.
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test('F11: the calendar closes mid-conversation — the thread and the composer stay; the band tells the truth; a staff reply still lands', async ({
    page,
}) => {
    test.setTimeout(120_000);
    harness('hours-open');
    harness('config', '--value=off');
    try {
        const { panel } = await openWidget(page);
        await sendFromPanel(panel, 'one more question before you go');
        expect(harness('hours-close')).toContain('hours-close');
        harness('config', '--value=off'); // a publish pushes the config frame → the panel re-reads the envelope (hours.open now false)
        await expect
            .poll(
                async () =>
                    page
                        .frames()
                        .find((f) => f.url().includes('frame=panel'))!
                        .evaluate(() =>
                            (
                                window as unknown as { __nlwPanel: { hoursOpen(): boolean | null } }
                            ).__nlwPanel.hoursOpen(),
                        ),
                { timeout: 30000 },
            )
            .toBe(false);
        await panel.locator('#nlw-tab-messages').click();
        await expect(panel.locator('textarea')).toBeVisible(); // no cliff: the composer is still there
        await expect(panel.locator('[data-message][data-author=customer]')).toContainText(
            'one more question',
        );
        expect(await panel.locator('[data-role=offline]').count()).toBe(0);
        expect(harness('handoff')).toContain('TICKET=');
        harness('agent-join');
        harness('staff-reply', "--text=[FIXTURE] Go ahead — I'm with you until this is finished.");
        await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
            'until this is finished',
            { timeout: 20000 },
        );
        await panel.locator('#nlw-tab-home').click();
        await expect(panel.locator('.nlw-band')).toHaveAttribute('data-band', 'off_hours');
        harness('resolve');
    } finally {
        harness('hours-open');
    }
});

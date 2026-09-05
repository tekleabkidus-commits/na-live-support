// Doc 13 FR-17 / B3-S07 I02–I03 — "Three channels, and when they go quiet": with the page UNATTENDED a staff reply
// fires all three (a Notification, the host title counter, one sound); with the thread visible and focused — silence.
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test('I02/I03: unattended → notification + title counter + one sound; attended → none of the three', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await page.addInitScript(() => {
        const w = window as unknown as {
            __notes: number;
            __tones: number;
            Notification: unknown;
            AudioContext: unknown;
        };
        w.__notes = 0;
        w.__tones = 0;
        class FakeNotification {
            static permission = 'granted';
            static requestPermission = async (): Promise<string> => 'granted';
            onclick: (() => void) | null = null;
            constructor(public title: string) {
                w.__notes++;
            }
        }
        (window as unknown as { Notification: unknown }).Notification = FakeNotification;
        const Real = (window as unknown as { AudioContext: new () => AudioContext }).AudioContext;
        (window as unknown as { AudioContext: unknown }).AudioContext = class extends Real {
            createOscillator(): OscillatorNode {
                w.__tones++;
                return super.createOscillator();
            }
        };
    });
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'attended state');
    expect(harness('handoff')).toContain('TICKET=');
    harness('agent-join');
    const frame = () => page.frames().find((f) => f.url().includes('frame=panel'))!;
    const counts = () =>
        frame().evaluate(() => ({
            notes: (window as unknown as { __notes: number }).__notes,
            tones: (window as unknown as { __tones: number }).__tones,
        }));
    const titleBefore = await page.title();

    // I03: attended — the panel is open on Messages, the page focused and visible
    await panel.locator('#nlw-tab-messages').click();
    harness('staff-reply', '--text=[FIXTURE] attended reply');
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'attended reply',
        { timeout: 20000 },
    );
    await page.waitForTimeout(800);
    expect(await counts()).toEqual({ notes: 0, tones: 0 });
    expect(await page.title()).toBe(titleBefore);

    // I02: unattended — the document reports no focus and a hidden tab (what a background tab reports)
    await frame().evaluate(() => {
        Object.defineProperty(document, 'hasFocus', { value: () => false, configurable: true });
        Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    });
    harness('staff-reply', '--text=[FIXTURE] unattended reply');
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'unattended reply',
        { timeout: 20000 },
    );
    await expect.poll(async () => (await counts()).notes, { timeout: 10000 }).toBe(1);
    expect((await counts()).tones).toBe(1);
    await expect.poll(() => page.title(), { timeout: 10000 }).toMatch(/^\(\d+\)/); // the host title counter (the one declared host write)
    harness('resolve');
});

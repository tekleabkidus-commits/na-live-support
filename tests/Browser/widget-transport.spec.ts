// Doc 13 T-04 / NFR-02 / NFR-03 / AC-08 / K01 — THE TRANSPORT: the leader tab's
// socket is the wake-up and the cursor page the record; no polling while the
// socket is healthy; a staff reply arrives as one frame → one sync → one
// receipt; the DRILL: the socket dies mid-chat → polling ≤ 10 s → a reply still
// lands → the socket returns → polling stops; and K01: nothing on screen says
// any of it happened.
import { expect, test } from '@playwright/test';
import { harness, openWidget, panelHooks, sendFromPanel } from './support/panel';

test("NFR-03 + FR-10: the socket subscribes on the visitor's own channels, polls nothing while healthy, and a staff reply arrives by push with exactly one wake-up sync and one receipt", async ({
    page,
}) => {
    let gets = 0;
    let receipts = 0;
    page.on('request', (r) => {
        if (r.url().includes('/api/widget/v1/messages') && r.method() === 'GET') gets++;
        if (r.url().includes('/api/widget/v1/receipts')) receipts++;
    });
    const frames: string[] = [];
    page.on('websocket', (ws) => ws.on('framereceived', (f) => frames.push(String(f.payload))));
    const { panel } = await openWidget(page);
    const hooks = panelHooks(page);
    await sendFromPanel(panel, 'transport: first message');
    await expect.poll(() => hooks.socketUp(), { timeout: 10000 }).toBe(true);
    expect(await hooks.isLeader()).toBe(true);
    expect(await hooks.polling()).toBe(false); // NFR-03: the polling timer is OFF while the socket is healthy
    const g0 = gets;
    const f0 = frames.filter((f) => f.includes('webchat.')).length;
    await page.waitForTimeout(12000);
    // Every GET in the window is a push-driven wake-up (Doc 09's handoff pushes its own frames) — never a cadence.
    expect(gets - g0).toBeLessThanOrEqual(frames.filter((f) => f.includes('webchat.')).length - f0);
    expect(await hooks.polling()).toBe(false);
    const g1 = gets;
    const r1 = receipts;
    harness('staff-reply', '--text=Push me, harness');
    await expect(panel.locator('[data-message][data-author=agent]')).toHaveCount(1, {
        timeout: 10000,
    });
    expect(frames.some((f) => f.includes('webchat.message'))).toBe(true);
    await page.waitForTimeout(1500);
    expect(gets - g1).toBe(1); // exactly one wake-up sync for the reply
    expect(receipts - r1).toBe(1); // exactly one receipt for the inbound row
    await expect(panel.locator('[data-role=log]')).toContainText('Push me, harness');
});

test('AC-08 drill + K01: the socket is dropped mid-chat → polling within 10 s (no banner, no spinner) → a staff reply still lands → the socket reconnects → polling stops', async ({
    page,
}) => {
    let gets = 0;
    page.on('request', (r) => {
        if (r.url().includes('/api/widget/v1/messages') && r.method() === 'GET') gets++;
    });
    const { panel } = await openWidget(page);
    const hooks = panelHooks(page);
    await sendFromPanel(panel, 'drill: hello');
    await expect.poll(() => hooks.socketUp(), { timeout: 10000 }).toBe(true);
    const g0 = gets;
    await hooks.dropSocket();
    await expect.poll(() => hooks.socketUp(), { timeout: 3000 }).toBe(false);
    await expect.poll(() => hooks.polling(), { timeout: 10000 }).toBe(true); // polling resumed ≤ 10 s
    await expect.poll(() => gets - g0, { timeout: 10000 }).toBeGreaterThan(0);
    // K01: transport state is never surfaced — no banner, no spinner, no "reconnecting"
    expect(await panel.locator('[data-role=transport], .nlw-banner').count()).toBe(0);
    harness('staff-reply', '--text=Landed while polling');
    await expect(panel.locator('[data-message][data-author=agent]')).toHaveCount(1, {
        timeout: 15000,
    });
    await expect.poll(() => hooks.socketUp(), { timeout: 20000 }).toBe(true); // exponential reconnect, resubscribed
    await expect.poll(() => hooks.polling(), { timeout: 3000 }).toBe(false); // polling stopped again
});

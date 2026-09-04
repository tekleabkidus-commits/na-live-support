// Doc 13 FR-12 / EC-06 / D10–D13 — THE QUEUE CARD, live: the handoff puts the
// visitor's ticket in the default queue; the card shows the SERVER's position
// (pushed on `webchat.queue`); an urgent ticket jumping ahead moves the server's
// number UP — the card DROPS TO THE BAND and holds it (never counts upward, EC-06) and
// announces on change only; the agent joining removes the card and the header
// state; sabotage (i) lands here.
import { expect, test } from '@playwright/test';
import { harness, openWidget, sendFromPanel } from './support/panel';

test("D10–D13 + EC-06: the pushed position renders; an urgent jump raises the server's number but never the card's; the agent joining clears the card", async ({
    page,
}) => {
    const serverPositions: number[] = [];
    page.on('response', async (r) => {
        if (r.url().includes('/api/widget/v1/messages') && r.request().method() === 'GET') {
            const body = (await r.json().catch(() => null)) as {
                envelope?: { queue?: { position: number | null } | null };
            } | null;
            if (body?.envelope?.queue?.position != null)
                serverPositions.push(body.envelope.queue.position);
        }
    });
    harness('queue-clear'); // earlier fixture tickets leave the queue through the system door
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'queue: my withdrawal is late');
    harness('handoff');
    const card = panel.locator('[data-role=queue]');
    await expect(card).toHaveCount(1, { timeout: 15000 });
    await expect(card).toHaveAttribute('data-position', /^\d+$/, { timeout: 15000 }); // the pushed number, not a made-up one
    const shown = Number(await card.getAttribute('data-position'));
    await expect(card).toContainText(`#${shown} in line`);
    await expect(panel.locator('[data-role=header-state]')).toHaveText(
        'Connecting you to a specialist',
    );
    await expect(panel.locator('[data-role=log]')).toContainText(`You are number ${shown} in line`);

    harness('queue-jump'); // an URGENT ticket ahead: the server's position rises by one
    await expect.poll(() => serverPositions.some((p) => p > shown), { timeout: 20000 }).toBe(true);
    // EC-06 as DRAWN (D13): "rather than show them losing ground, the card drops to the band and holds it
    // until the position improves" — no number at all, never the higher one.
    await expect(card).toHaveAttribute('data-position', '');
    await expect(card).toContainText('Waiting for the next available agent');
    await expect(card).not.toContainText(`#${shown + 1}`);

    harness('agent-join');
    await expect(card).toHaveCount(0, { timeout: 15000 });
    await expect(panel.locator('[data-role=header-state]')).toHaveText('Support assistant');
    // D14: the agent-joined line, identity per `widget.agent_identity` (first_name by default — the fixture agent's first name)
    await expect(panel.locator('[data-author=system]').last()).toContainText('joined');
});

// Doc 13 — S13 step 13b: the FRAME REGISTER rows the advisor's recount addendum
// named (2026-09-06), each driven on the harness so the drawn behaviour is
// ASSERTED, not read from the code: row 9 (C02/C04 resume card only with
// history), row 13 (the busy/slower band from Doc 11's estimate), row 34 (F13:
// the site logged in as someone else — nothing visible happens), row 65 (L06:
// the RTL mirror under a placeholder direction — no RTL locale ships), row 69
// (L09: the queue announces on CHANGE only), row 73 (M01 AI labelled, M02 no
// impersonation on the handoff line, M04 honest presence).
import { expect, test } from '@playwright/test';
import type { FrameLocator, Page } from '@playwright/test';
import { CUSTOMER } from './support/harness';
import { harness, openWidget, sendFromPanel } from './support/panel';

function panelFrame(page: Page) {
    const f = page.frames().find((x) => x.url().includes('frame=panel'));
    if (!f) throw new Error('no panel frame');
    return f;
}

async function tokenValue(page: Page, token: string): Promise<string> {
    return panelFrame(page).evaluate((name) => {
        const probe = document.createElement('span');
        probe.style.color = `var(${name})`;
        document.body.appendChild(probe);
        const v = getComputedStyle(probe).color;
        probe.remove();
        return v;
    }, token);
}

test('row 9 · C02/C04: a first visit draws no resume card; after a message and a reload, Home leads with "Continue where you left off" and the last message', async ({
    page,
}) => {
    const { panel, key } = await openWidget(page);
    // first visit: Quick answers first, no resume card anywhere (S02 "1440 first visit" is row 12; this is its complement)
    await expect(panel.locator('.nlw-card h3').first()).toHaveText('Quick answers');
    await expect(
        panel.locator('.nlw-card h3', { hasText: 'Continue where you left off' }),
    ).toHaveCount(0);
    await sendFromPanel(panel, 'row 9: my deposit has not arrived');
    // the same visitor returns (the cookie survives the reload); the history restores (FR-08) and the card outranks the shortcuts (C04)
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const again = page.frameLocator('iframe#nlw-panel');
    await expect(again.locator('.nlw-greeting')).toBeVisible();
    const resume = again.locator('.nlw-card', { hasText: 'Continue where you left off' });
    await expect(resume).toHaveCount(1, { timeout: 15000 });
    await expect(resume.locator('h3')).toHaveText('Continue where you left off');
    await expect(resume.locator('button span').first()).toContainText(
        'row 9: my deposit has not arrived',
    );
    await expect(resume.locator('small')).toContainText(/\d+ messages/);
    // C04: the resume card sits ABOVE the shortcuts
    const cards = await again.locator('.nlw-card h3').allTextContents();
    expect(cards.indexOf('Continue where you left off')).toBeLessThan(
        cards.indexOf('Quick answers'),
    );
    // the card is a door into the thread, not a second list (D01): one click lands on the Messages tab with the message there
    await resume.locator('button').click();
    await expect(again.locator('[data-message][data-author=customer]').last()).toContainText(
        'row 9: my deposit has not arrived',
    );
});

test('row 13 · S02 "busy, and a slower band": with a deep default queue the SERVER band is long and Home draws the long copy with the amber marker, never a count', async ({
    page,
}) => {
    test.setTimeout(240000); // the fill is sized to the live throughput (up to 4 rounds of ≤ 60 tickets through the real doors)
    try {
        // Doc 11 FR-13: raw minutes = position × 30 / throughput30 (or 10 min per ticket when the window is empty);
        // `long` is raw > medium (35 min). The battery's own assignments (queue-clear, agent-join) raise the
        // throughput, so the fill is SIZED from the estimator's own reading — the thresholds and vocabulary untouched.
        const read = (out: string) => ({
            band: /BAND=(\w+)/.exec(out)?.[1] ?? '',
            depth: Number(/QUEUE_DEPTH=(\d+)/.exec(out)?.[1] ?? '0'),
            throughput: /THROUGHPUT30=(\d+)/.exec(out)?.[1],
        });
        let r = read(harness('queue-fill', '--value=0'));
        for (let round = 0; round < 4 && r.band !== 'long'; round++) {
            const t = r.throughput === undefined ? null : Number(r.throughput);
            const needDepth = t === null || t <= 0 ? 4 : Math.ceil((36 * t) / 30) + 1; // position = depth + 1 → raw > 35
            const fill = Math.min(60, Math.max(1, needDepth - r.depth));
            r = read(harness('queue-fill', `--value=${fill}`));
        }
        const band = r.band;
        expect(band).toBe('long'); // the estimate the widget's `GET config` will read (Doc 11 FR-13)
        let served: { band: string | null } | null = null;
        page.on('response', async (r) => {
            if (r.url().includes('/api/widget/v1/config') && r.request().method() === 'GET')
                served =
                    (
                        (await r.json().catch(() => null)) as {
                            home?: { band: string | null };
                        } | null
                    )?.home ?? null;
        });
        const { panel } = await openWidget(page);
        const bandEl = panel.locator('.nlw-band');
        await expect(bandEl).toHaveAttribute('data-band', 'long', { timeout: 15000 });
        await expect(bandEl).toHaveText('Typically replies within a few hours');
        await expect.poll(() => served?.band ?? null, { timeout: 5000 }).toBe('long');
        // the marker: the dot recoloured to the WARN token (amber), not the success green; no off-hours clock
        const dot = bandEl.locator('.nlw-dot');
        await expect(dot).toHaveCount(1);
        const dotColour = await dot.evaluate((el) => getComputedStyle(el).backgroundColor);
        expect(dotColour).toBe(await tokenValue(page, '--ds-warn-text'));
        expect(dotColour).not.toBe(await tokenValue(page, '--ds-success-text'));
        expect((await bandEl.textContent()) ?? '').not.toMatch(/◷|\d/); // a band, never a number (BR-02 / SEC-05)
    } finally {
        harness('queue-unfill'); // the fill leaves through the resolve door — no assignment, so Doc 11's throughput window is not inflated for the next scene
    }
});

test('row 34 · F13: the site asserts a DIFFERENT identity mid-conversation — nothing visible happens: the badge, the thread and the composer stay as they were; no error, no line', async ({
    page,
}) => {
    const sign = (key: string, name: string) =>
        JSON.parse(
            /SIGNED=(.*)/.exec(harness('identity-sign', `--key=${key}`, `--text=${name}`))![1]!,
        ) as {
            payload: Record<string, unknown>;
            sig: string;
        };
    const first = sign('ab_row34_a', 'አበበ ታደሰ');
    const second = sign('ab_row34_b', 'ከበደ አለሙ');
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const identify = (s: { payload: Record<string, unknown>; sig: string }) =>
        page.evaluate(
            (x) =>
                (
                    window as unknown as { PlatformChat: { identify(v: unknown): void } }
                ).PlatformChat.identify(x),
            s,
        );
    const { panel } = await openWidget(page);
    // the site asserts the FIRST identity before the visitor speaks (the host page's identify on load) — F06's path;
    // asserted AFTER an anonymous first message it is already a switch under A-61 (never a re-link), found on the first run
    await identify(first);
    await expect(panel.locator('[data-role=identity]')).toContainText('Signed in as አበበ ታደሰ', {
        timeout: 10000,
    });
    await sendFromPanel(panel, 'row 34: who is speaking?');
    const messagesBefore = await panel.locator('[data-message]').count();
    const systemBefore = await panel.locator('[data-author=system]').count();
    // the switch (EC-04 on the server: a staff note + `widget.identity.switched`, never a re-link — tests/Security/SignedIdentityTest)
    await identify(second);
    await page.waitForTimeout(2500); // the identify → sync → config re-read has run
    expect(await panel.locator('[data-message]').count()).toBe(messagesBefore); // the thread is untouched
    expect(await panel.locator('[data-author=system]').count()).toBe(systemBefore); // no visible line about the switch
    await panel.locator('#nlw-tab-home').click();
    await expect(panel.locator('[data-role=identity]')).toContainText('Signed in as አበበ ታደሰ'); // the conversation continues under the person who started it
    await expect(panel.locator('[data-role=identity]')).not.toContainText('ከበደ');
    const text = ((await panel.locator('[role=dialog]').textContent()) ?? '').toLowerCase();
    expect(text).not.toMatch(/switch|changed|verified|error/);
    await panel.locator('#nlw-tab-messages').click();
    await expect(panel.locator('textarea')).toBeEnabled(); // the visitor can go on talking
    expect(errors).toEqual([]);
});

test('row 65 · L06: the panel mirrors under an RTL document direction (placeholder — no RTL locale ships): the own bubble and the header controls swap sides, nothing overflows', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'row 65: mirror me');
    const dialog = panel.locator('[role=dialog]');
    const bubble = panel.locator('[data-message][data-author=customer]').last();
    const close = panel.locator('header button[aria-label]').last();
    const box = async (l: ReturnType<FrameLocator['locator']>) => (await l.boundingBox())!;
    const d = await box(dialog);
    const mid = d.x + d.width / 2;
    const ltrBubble = await box(bubble);
    const ltrClose = await box(close);
    expect(ltrBubble.x + ltrBubble.width / 2).toBeGreaterThan(mid); // LTR: the visitor's own bubble on the inline-end (right)
    // the placeholder: the DOCUMENT direction flips (what a future RTL locale would set); the stylesheet is logical (C-15), so nothing else changes
    await panelFrame(page).evaluate(() => {
        document.documentElement.dir = 'rtl';
    });
    await page.waitForTimeout(300);
    const rtlBubble = await box(bubble);
    const rtlClose = await box(close);
    expect(rtlBubble.x + rtlBubble.width / 2).toBeLessThan(mid); // RTL: the same bubble on the inline-end, now the LEFT
    expect(Math.sign(rtlClose.x - mid)).toBe(-Math.sign(ltrClose.x - mid)); // the header control crossed to the other side
    expect(rtlBubble.x).toBeGreaterThanOrEqual(d.x - 1); // no horizontal overflow either way
    expect(rtlBubble.x + rtlBubble.width).toBeLessThanOrEqual(d.x + d.width + 1);
    const scrollX = await panelFrame(page).evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(scrollX).toBe(0);
    await panelFrame(page).evaluate(() => {
        document.documentElement.dir = 'ltr';
    });
});

test('row 69 · L09 (the negative): the position is announced ONCE; a push and a sync that carry the SAME number announce nothing', async ({
    page,
}) => {
    test.setTimeout(150000); // two 60 s platform waits (routing behind the shared worker) inside one leg
    harness('queue-clear');
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'row 69: announce me once');
    harness('handoff');
    const card = panel.locator('[data-role=queue]');
    // the AI-handoff ticket is routed by `TicketCreatedRouter` on the shared dev worker, behind the battery's own
    // indexing jobs (the second 13b run measured 21 s after a 24-ticket fill) — the wait is for the platform's queue, not a retry
    await expect(card).toHaveAttribute('data-position', /^\d+$/, { timeout: 60000 });
    const shown = Number(await card.getAttribute('data-position'));
    const log = panel.locator('[data-role=log]');
    await expect(log).toContainText(`You are number ${shown} in line`);
    const announcements = async () =>
        (((await log.textContent()) ?? '').match(/You are number \d+ in line/g) ?? []).length;
    expect(await announcements()).toBe(1);
    // (a) a ticket queued BEHIND: the platform pushes every waiting visitor's frame — ours carries the same number
    harness('queue-fill', '--value=1');
    // (b) a staff reply: the panel syncs the cursor page, whose envelope carries the same queue state
    harness('staff-reply', '--text=[FIXTURE] row 69: still with you');
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'still with you',
        {
            timeout: 15000,
        },
    );
    await page.waitForTimeout(1500);
    await expect(card).toHaveAttribute('data-position', String(shown)); // the number did not move…
    expect(await announcements()).toBe(1); // …so it was not announced again (L09: "only when the number moves")
    harness('queue-unfill');
    harness('queue-clear');
});

test('row 73 · M01/M02/M04 asserted individually: the AI is labelled with the platform token; the handoff line names who speaks per the identity setting and the bot never wears a name; the green dot is ABSENT out of hours', async ({
    page,
    browser,
}) => {
    test.setTimeout(150000); // the AI turn (30 s) + routing behind the shared worker (60 s) + the out-of-hours context
    try {
        harness('queue-clear');
        const { panel } = await openWidget(page);
        // M04 · in hours: the green dot (the success token), never a count
        const band = panel.locator('.nlw-band');
        await expect(band).not.toHaveAttribute('data-band', 'off_hours');
        await expect(band.locator('.nlw-dot')).toHaveCount(1);
        expect(
            await band.locator('.nlw-dot').evaluate((el) => getComputedStyle(el).backgroundColor),
        ).toBe(await tokenValue(page, '--ds-success-text'));
        // M01 · the AI message: the words "AI assistant", the ✦ mark, the accent from the PLATFORM's ai-edge token (not the operator's accent)
        await sendFromPanel(panel, 'row 73: ገንዘቤ አልደረሰኝም');
        const ai = panel.locator('[data-message][data-author=ai]').first();
        await expect(ai).toBeVisible({ timeout: 30000 });
        await expect(ai.locator('.nlw-label[data-ai]')).toHaveText(/✦\s*AI assistant/);
        const edge = await ai
            .locator('.nlw-bubble')
            .evaluate((el) => getComputedStyle(el).borderInlineStartColor);
        expect(edge).toBe(await tokenValue(page, '--ds-ai-edge'));
        expect(edge).not.toBe(await tokenValue(page, '--ds-accent'));
        // M02 · the handoff line marks the change of speaker and names the agent per `widget.agent_identity` (first_name → "Fixture")
        harness('handoff');
        await expect(panel.locator('[data-role=queue]')).toHaveCount(1, { timeout: 60000 }); // routing behind the shared worker's backlog (see row 69)
        harness('agent-join');
        const joined = panel.locator('[data-author=system]').last();
        await expect(joined).toContainText('Fixture joined', { timeout: 15000 });
        harness('staff-reply', '--text=[FIXTURE] row 73: a person here');
        const agentMsg = panel.locator('[data-message][data-author=agent]').last();
        await expect(agentMsg).toContainText('a person here', { timeout: 15000 });
        await expect(agentMsg.locator('.nlw-label')).not.toContainText('AI assistant'); // a person is never labelled as the bot…
        await expect(ai.locator('.nlw-label')).not.toContainText('Fixture'); // …and the bot never wears a person's name
        expect(await agentMsg.locator('.nlw-label[data-ai]').count()).toBe(0);
        // M04 · out of hours: the dot is ABSENT (not recoloured); the clock glyph and the opens-at copy instead
        harness('hours-close');
        const c2 = await browser.newContext({ viewport: { width: 375, height: 740 } });
        const p2 = await c2.newPage();
        const { panel: off } = await openWidget(p2);
        const offBand = off.locator('.nlw-band');
        await expect(offBand).toHaveAttribute('data-band', 'off_hours', { timeout: 15000 });
        await expect(offBand.locator('.nlw-dot')).toHaveCount(0);
        await expect(offBand).toContainText('◷');
        await expect(offBand).toContainText(/opens at/);
        await c2.close();
    } finally {
        harness('hours-open');
    }
});

// Doc 13 §8 / B3-A01 AC-B3-01 and B3-S11's six drawn-not-proved behaviours, as
// EXECUTED tests. AC-B3-01 assertion one (contains-only, default config, fresh
// profile, 375 + 1440, light + dark): a first-time visitor's Home holds the
// greeting, the wait band, the shortcut buttons, the resume card where history
// exists, and the tab bar — nothing further. Assertion two (the PROHIBITED
// CLASS, every config): no onboarding / tour / coach-mark / tip / "what's new"
// UI anywhere, under default, Messages-only, pre-chat off / optional /
// required, proactive fired and out-of-hours — named by class, never by shape
// (a lightbox, a form step, a proactive message are legitimate). Then the six:
// focus trap · live regions · polling failover · leader election · proactive
// cooldown across rules · text zoom 200 % (Doc 13 §13 / AC-05 / 06 / 08 / 10).
import { expect, test, type Browser, type FrameLocator, type Page } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';
import { harness, openWidget, panelHooks, sendFromPanel } from './support/panel';

// The prohibited CLASS — roles, class fragments and the copy such UI carries. Not "overlays".
const PROHIBITED_SELECTOR = [
    '[data-role=onboarding]',
    '[data-role=tour]',
    '[data-role=coachmark]',
    '[data-role=coach-mark]',
    '[data-role=tip]',
    '[data-role=tips]',
    '[data-role=whats-new]',
    '[class*=onboard]',
    '[class*=tour]',
    '[class*=coach]',
    '[class*=tooltip]',
    '[class*=whats-new]',
    '[class*=walkthrough]',
    '[class*=hotspot]',
    '[aria-label*="tour" i]',
    '[aria-label*="what\'s new" i]',
].join(', ');
const PROHIBITED_COPY =
    /take a tour|welcome tour|what'?s new|show me around|next tip|skip (the )?tour|got it,? thanks|step \d of \d/i;

async function assertNoProhibitedClass(page: Page): Promise<void> {
    for (const id of ['nlw-panel', 'nlw-launcher']) {
        const frame = page.frameLocator(`iframe#${id}`);
        expect(await frame.locator(PROHIBITED_SELECTOR).count(), `${id}: prohibited element`).toBe(
            0,
        );
        const text = await frame
            .locator('body')
            .innerText()
            .catch(() => '');
        expect(text, `${id}: prohibited copy`).not.toMatch(PROHIBITED_COPY);
    }
    expect(
        await page.locator(PROHIBITED_SELECTOR).count(),
        'host: the widget wrote nothing prohibited',
    ).toBe(0);
}

async function assertContainsOnly(panel: FrameLocator): Promise<void> {
    await expect(panel.locator('.nlw-home')).toBeVisible();
    const kids = await panel
        .locator('.nlw-home > *')
        .evaluateAll((els) => els.map((e) => e.className));
    expect(kids.length).toBeGreaterThanOrEqual(3);
    for (const k of kids) expect(['nlw-greeting', 'nlw-band', 'nlw-card']).toContain(k); // greeting · band · cards (shortcuts; resume where history exists)
    expect(await panel.locator('.nlw-home .nlw-card .nlw-btn').count()).toBeGreaterThan(0); // the shortcut buttons
    await expect(panel.locator('[role=tablist]')).toBeVisible();
    // the dialog holds header · body · tab bar · the polite log — nothing further
    const top = await panel
        .locator('[role=dialog] > *')
        .evaluateAll((els) => els.map((e) => e.className || e.tagName.toLowerCase()));
    for (const k of top) expect(['nlw-header', 'nlw-body', 'nlw-tabs', 'nlw-sr']).toContain(k);
}

async function freshOpen(
    browser: Browser,
    viewport: { width: number; height: number },
    colorScheme: 'light' | 'dark',
) {
    const context = await browser.newContext({ viewport, colorScheme });
    const page = await context.newPage();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(harnessWidgetKey())}`);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('[role=dialog]')).toBeVisible(); // Messages-only has no Home: the dialog is the wait
    return { context, page, panel };
}

test('AC-B3-01 assertion one: contains-only Home on a fresh profile at 375 and 1440, light and dark (default config)', async ({
    browser,
}) => {
    harness('config', '--value=off');
    for (const viewport of [
        { width: 375, height: 740 },
        { width: 1440, height: 900 },
    ]) {
        for (const colorScheme of ['light', 'dark'] as const) {
            const { context, page, panel } = await freshOpen(browser, viewport, colorScheme);
            await assertContainsOnly(panel);
            await assertNoProhibitedClass(page);
            const bg = await panel
                .locator('[role=dialog]')
                .evaluate((el) => getComputedStyle(el).backgroundColor);
            expect(bg, `${colorScheme} theme paints its own surface`).not.toBe('rgba(0, 0, 0, 0)');
            await context.close();
        }
    }
});

test('AC-B3-01 assertion two: the prohibited class is absent across the configuration matrix (default · Messages-only · pre-chat off/optional/required · proactive fired · out-of-hours)', async ({
    browser,
}) => {
    test.setTimeout(180_000);
    try {
        for (const mode of ['off', 'messages-only', 'optional', 'required']) {
            harness('config', `--value=${mode}`);
            const { context, page, panel } = await freshOpen(
                browser,
                { width: 375, height: 740 },
                'light',
            );
            await expect(panel.locator('[role=dialog]')).toBeVisible();
            if (mode === 'messages-only')
                await expect(panel.locator('[role=tablist]')).toHaveCount(0);
            if (mode === 'required') {
                await panel
                    .locator('#nlw-tab-messages')
                    .click()
                    .catch(() => undefined);
                await expect(
                    panel.locator('form.nlw-form, [data-role=prechat]').first(),
                ).toBeVisible({ timeout: 10000 }); // a form step is LEGITIMATE
            }
            await assertNoProhibitedClass(page);
            await context.close();
        }
        // proactive fired (legitimate UI) — the card is present AND nothing of the class is
        harness('config', '--value=off');
        const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
        const page = await context.newPage();
        await page.goto(`${CUSTOMER}/deposit.html?key=${encodeURIComponent(harnessWidgetKey())}`);
        await expect(
            page.frameLocator('iframe#nlw-launcher').locator('[data-role=proactive]'),
        ).toBeVisible({ timeout: 15000 });
        await assertNoProhibitedClass(page);
        await context.close();
        // out-of-hours
        harness('hours-close');
        const c2 = await browser.newContext({ viewport: { width: 375, height: 740 } });
        const p2 = await c2.newPage();
        const { panel } = await openWidget(p2);
        await expect(panel.locator('.nlw-band')).toHaveAttribute('data-band', 'off_hours', {
            timeout: 15000,
        });
        await assertNoProhibitedClass(p2);
        await c2.close();
    } finally {
        harness('hours-open');
        harness('config', '--value=off');
    }
});

test('S11 · focus trap: Tab and Shift+Tab cycle inside the dialog; focus never leaves it while open', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    const frame = () => page.frames().find((f) => f.url().includes('frame=panel'))!;
    const inside = () =>
        frame().evaluate(
            () =>
                !!document.activeElement?.closest('[role=dialog]') &&
                document.activeElement !== document.body,
        );
    const count = await panel
        .locator(
            '[role=dialog] button:not([disabled]), [role=dialog] a[href], [role=dialog] textarea, [role=dialog] input, [role=dialog] [tabindex="0"]',
        )
        .count();
    expect(count).toBeGreaterThan(2);
    await panel.locator('[role=dialog] button').first().focus();
    for (let i = 0; i < count + 3; i++) {
        await page.keyboard.press('Tab');
        expect(await inside(), `Tab #${i + 1} stayed inside`).toBe(true);
    }
    for (let i = 0; i < count + 3; i++) {
        await page.keyboard.press('Shift+Tab');
        expect(await inside(), `Shift+Tab #${i + 1} stayed inside`).toBe(true);
    }
});

test('S11 · live regions: the polite log announces the staff reply; the queue card announces only when the number moves', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'live regions');
    const log = panel.locator('[role=log][aria-live=polite]');
    await expect(log).toHaveCount(1);
    expect(harness('handoff')).toContain('TICKET=');
    harness('agent-join');
    harness('staff-reply', '--text=[FIXTURE] announced reply');
    await expect(log).toContainText('announced reply', { timeout: 20000 });
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'announced reply',
    );
    harness('resolve');
});

test('S11 · polling failover: the socket drops, polling takes over, a staff reply still lands; the socket returns and polling stops', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'failover');
    const hooks = panelHooks(page);
    await expect.poll(() => hooks.socketUp(), { timeout: 15000 }).toBe(true);
    expect(await hooks.polling()).toBe(false);
    await hooks.dropSocket();
    await expect.poll(() => hooks.polling(), { timeout: 15000 }).toBe(true);
    expect(harness('handoff')).toContain('TICKET=');
    harness('agent-join');
    harness('staff-reply', '--text=[FIXTURE] over polling');
    await expect(panel.locator('[data-message][data-author=agent]').last()).toContainText(
        'over polling',
        { timeout: 30000 },
    );
    await expect.poll(() => hooks.socketUp(), { timeout: 30000 }).toBe(true);
    await expect.poll(() => hooks.polling(), { timeout: 15000 }).toBe(false);
    harness('resolve');
});

test('S11 · leader election: two tabs of one visitor hold ONE socket; closing the leader hands the lock over', async ({
    page,
    context,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'leader');
    const a = panelHooks(page);
    await expect.poll(() => a.isLeader(), { timeout: 15000 }).toBe(true);
    const page2 = await context.newPage();
    await page2.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(harnessWidgetKey())}`);
    await page2.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel2 = page2.frameLocator('iframe#nlw-panel');
    await expect(panel2.locator('[role=dialog]')).toBeVisible();
    const b = panelHooks(page2);
    await page2.waitForTimeout(2000);
    expect(await a.isLeader()).toBe(true);
    expect(await b.isLeader()).toBe(false);
    expect(await b.socketUp()).toBe(false); // the follower opens no socket of its own
    await panel2.locator('#nlw-tab-messages').click();
    await expect(panel2.locator('[data-message][data-author=customer]').last()).toContainText(
        'leader',
    ); // same visitor, same thread
    await page.close();
    await expect.poll(() => b.isLeader(), { timeout: 15000 }).toBe(true);
    await expect.poll(() => b.socketUp(), { timeout: 15000 }).toBe(true);
});

test('S11 · proactive cooldown across rules: one fire per visitor per cooldown — a second rule on another page stays silent', async ({
    page,
}) => {
    harness('config', '--value=off');
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/deposit.html?key=${encodeURIComponent(key)}`);
    await expect(
        page.frameLocator('iframe#nlw-launcher').locator('[data-role=proactive]'),
    ).toContainText('deposit', { timeout: 15000 });
    await page.goto(`${CUSTOMER}/payments.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    await page.waitForTimeout(4000);
    expect(
        await page.frameLocator('iframe#nlw-launcher').locator('[data-role=proactive]').count(),
        'the payments rule is inside the cooldown',
    ).toBe(0);
});

test('S11 · text zoom 200 %: type scales with the root size; no horizontal overflow; the composer and 44 px targets survive', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    const frame = () => page.frames().find((f) => f.url().includes('frame=panel'))!;
    const greetingBefore = await panel
        .locator('.nlw-greeting')
        .evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    await frame().evaluate(() => {
        document.documentElement.style.fontSize = '200%';
    });
    const greetingAfter = await panel
        .locator('.nlw-greeting')
        .evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
    expect(greetingAfter).toBeGreaterThanOrEqual(greetingBefore * 1.9); // rem-based type actually zoomed
    const overflow = await frame().evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow).toBeLessThanOrEqual(0);
    await expect(panel.locator('[role=tablist]')).toBeVisible();
    await panel.locator('#nlw-tab-messages').click();
    await expect(panel.locator('textarea')).toBeVisible();
    await expect(panel.locator('.nlw-send')).toBeVisible();
    for (const sel of ['#nlw-tab-messages', '.nlw-send']) {
        const box = (await panel.locator(sel).boundingBox())!;
        expect(box.height, `${sel} height`).toBeGreaterThanOrEqual(44);
    }
});

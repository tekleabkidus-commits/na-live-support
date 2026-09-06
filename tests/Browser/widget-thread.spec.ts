// Doc 13 FR-10/FR-12 + AC-01 (the DOM half) — THE THREAD (D02–D18): a send lands
// as the visitor's own bubble with a TRUTHFUL glyph — ✓ on the 202, ✓✓ only
// once the server's cursor page carries the row; the day separator; the log
// region announces; the empty state shows the chat shortcuts first.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';
import { harness, openWidget, sendFromPanel } from './support/panel';

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

// S13 step 13b (advisor main recount) — FR-10 / BR-04: the plan's staff-split
// continuity assertion, EXECUTED. A real Doc 06 split (harness `split`, the
// permission-checked door) moves staff work onto a linked ticket; the visitor's
// thread stays ONE continuous list — no boundary marker, no system line, no word
// of it — and the split door's own internal note never surfaces (N-04's shape).
// Staff/AI content also renders INERT here (SEC-04's thread half): a hostile
// staff reply is text, never markup.
test('FR-10/BR-04: a staff split changes nothing the visitor sees — one continuous list, no boundary marker; the hostile staff reply renders inert', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'continuity leg: my withdrawal is late');
    await sendFromPanel(panel, 'continuity leg: it was 2,500 birr');
    harness('handoff');
    harness(
        'staff-reply',
        '--text=[FIXTURE] first staff reply <img src=x onerror="window.__nlwPwned=1"> <b>bold?</b>',
    );
    const hostile = panel.locator('[data-message][data-author=agent]').last();
    await expect(hostile).toContainText('first staff reply', { timeout: 20000 });
    // SEC-04: the markup is TEXT — no element materializes from staff content, no handler runs
    await expect(hostile).toContainText('<img src=x onerror="window.__nlwPwned=1"> <b>bold?</b>');
    expect(await panel.locator('[data-message] img, [data-message] b').count()).toBe(0);
    const pwned = await page
        .frames()
        .find((f) => f.url().includes('frame=panel'))!
        .evaluate(() => (window as unknown as { __nlwPwned?: number }).__nlwPwned);
    expect(pwned).toBeUndefined();
    const messagesBefore = await panel.locator('[data-message]').count();
    const systemBefore = await panel.locator('[data-author=system]').count();
    // THE SPLIT — Doc 06's door, on the newest message
    expect(harness('split')).toContain('SPLIT=');
    harness('staff-reply', '--text=[FIXTURE] second staff reply — same thread');
    const after = panel.locator('[data-message][data-author=agent]').last();
    await expect(after).toContainText('second staff reply — same thread', { timeout: 20000 });
    // ONE continuous list: every earlier bubble still present, in the one thread element,
    // exactly one message added, no system line, no marker or divider of any kind.
    expect(await panel.locator('[data-role=thread]').count()).toBe(1);
    await expect(panel.locator('[data-message][data-author=customer]').first()).toContainText(
        'my withdrawal is late',
    );
    await expect(panel.locator('[data-message][data-author=customer]').nth(1)).toContainText(
        'it was 2,500 birr',
    );
    expect(await panel.locator('[data-message]').count()).toBe(messagesBefore + 1);
    expect(await panel.locator('[data-author=system]').count()).toBe(systemBefore);
    const text = ((await panel.locator('[role=dialog]').textContent()) ?? '').toLowerCase();
    expect(text).not.toMatch(/split|linked|ticket|moved/); // no word of the mechanics (BR-04); the door's note stays internal
});

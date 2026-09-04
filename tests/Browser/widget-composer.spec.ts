// Doc 13 FR-16 / EC-08 / EC-10 / B3-S06 G01–G10 — THE COMPOSER AND THE ATTACH PATH:
// one line growing to five, Enter sends and Shift+Enter breaks; the attach menu
// with voice ABSENT (the platform flag is off on the harness); a pasted screenshot
// and a dropped file land as a card with a thumbnail, a size and a remove; the
// keyboard-only picker; progress with cancel; the cap checked BEFORE a byte moves
// (EC-08); the soft split at max_text told to the customer (EC-10); a blocked
// visitor sees the paused copy (G02), never a failure word.
import { Buffer } from 'node:buffer';
import { expect, test, type FrameLocator, type Page } from '@playwright/test';
import { pdfBytes, pngBytes } from './support/media';
import { harness, openWidget, sendFromPanel } from './support/panel';

async function pasteFile(
    page: Page,
    panel: FrameLocator,
    bytes: Buffer,
    name: string,
    mime: string,
): Promise<void> {
    await panel.locator('#nlw-tab-messages').click();
    await panel.locator('textarea').focus();
    const frame = page.frames().find((f) => f.url().includes('frame=panel'))!;
    await frame.evaluate(
        ({ b64, name, mime }) => {
            const bin = atob(b64);
            const arr = new Uint8Array(bin.length);
            for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
            const dt = new DataTransfer();
            dt.items.add(new File([arr], name, { type: mime }));
            document.querySelector('textarea')!.dispatchEvent(
                new ClipboardEvent('paste', {
                    clipboardData: dt,
                    bubbles: true,
                    cancelable: true,
                }),
            );
        },
        { b64: bytes.toString('base64'), name, mime },
    );
}

test('G01: one line grows to five and no further; Enter sends; Shift+Enter breaks the line', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    const ta = panel.locator('textarea');
    const h1 = (await ta.boundingBox())!.height;
    await ta.focus();
    for (let i = 0; i < 8; i++) {
        await ta.pressSequentially('[FIXTURE] line ' + i);
        await ta.press('Shift+Enter');
    }
    expect((await ta.inputValue()).split('\n').length).toBe(9); // Shift+Enter broke lines, nothing sent
    expect(await panel.locator('[data-message][data-author=customer]').count()).toBe(0);
    const h8 = (await ta.boundingBox())!.height;
    expect(h8).toBeGreaterThan(h1 * 2);
    const cap = await ta.evaluate((el) => {
        const cs = getComputedStyle(el);
        return (
            5 * parseFloat(cs.lineHeight) +
            parseFloat(cs.paddingTop) +
            parseFloat(cs.paddingBottom) +
            parseFloat(cs.borderTopWidth) +
            parseFloat(cs.borderBottomWidth)
        );
    });
    expect(h8).toBeLessThanOrEqual(cap + 1); // the five-line ceiling; the sixth line scrolls
    expect(await ta.evaluate((el) => el.scrollHeight > el.clientHeight)).toBe(true);
    await ta.press('Enter');
    await expect(panel.locator('[data-message][data-author=customer]')).toHaveCount(1);
    await expect(ta).toHaveValue('');
    expect((await ta.boundingBox())!.height).toBeLessThanOrEqual(h1 + 1); // collapsed back
});

test('G05: the attach menu offers Photo and Document with the size line; Voice is ABSENT (platform flag off), never greyed', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    await panel.locator('[data-role=attach-start]').click();
    const menu = panel.locator('[data-role=attach-menu]');
    await expect(menu).toBeVisible();
    await expect(menu.locator('[role=menuitem]')).toHaveCount(2);
    await expect(menu).toContainText('Photo');
    await expect(menu).toContainText('Document');
    await expect(menu).not.toContainText('Voice');
    expect(await menu.locator('[disabled], [aria-disabled=true]').count()).toBe(0);
    await expect(menu).toContainText('Up to');
});

test.setTimeout(120_000);
test('G06/D15: a pasted screenshot is a card with a thumbnail, a size and Remove; sent, it is the photo bubble that opens the themed lightbox; Esc closes it', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await pasteFile(page, panel, pngBytes(), '', 'image/png');
    const card = panel.locator('[data-role=attachment]');
    await expect(card).toBeVisible();
    await expect(card).toContainText('Pasted screenshot');
    await expect(card).toHaveAttribute('data-state', 'ready', { timeout: 15000 });
    await expect(card.locator('img')).toBeVisible();
    await expect(card).toContainText(/\d+ (B|KB)/); // the size
    await expect(card.locator('button[aria-label=Remove]')).toBeVisible();
    await expect(panel.locator('.nlw-send')).toBeEnabled(); // a photo needs no caption
    await panel.locator('.nlw-send').click();
    await expect(card).toHaveCount(0);
    const msg = panel.locator('[data-message][data-author=customer]').last();
    await expect(msg).toHaveAttribute('data-delivery', 'delivered', { timeout: 15000 });
    // the file is checked before anyone sees it (SEC-04): 'Checking the file…' while pending, the thumbnail once clean
    await expect(msg.locator('[data-role=media-state], [data-role=media]').first()).toBeVisible();
    await expect(msg.locator('[data-role=media] img')).toBeVisible({ timeout: 45000 });
    const src = await msg.locator('[data-role=media] img').getAttribute('src');
    expect(src).toMatch(/^\/api\/widget\/v1\/media\//); // same-origin, cookie-free stream
    await msg.locator('[data-role=media]').click();
    const box = panel.locator('[data-role=lightbox]');
    await expect(box).toBeVisible();
    await expect(box.locator('img')).toBeVisible();
    await panel.locator('[role=dialog]').first().press('Escape');
    await expect(box).toHaveCount(0);
    await expect(panel.locator('[role=dialog]').first()).toBeVisible(); // Esc closed the lightbox, not the widget
});

test('G07/G08: a dropped file and the keyboard-only picker both land as the same card; a document sends as a document card', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    // G08: keyboard-only — the attach button opens the menu, the picker input is the fallback
    await panel.locator('[data-role=attach-start]').focus();
    await panel.locator('[data-role=attach-start]').press('Enter');
    await expect(panel.locator('[data-role=attach-menu]')).toBeVisible();
    await panel
        .locator('input[type=file]')
        .setInputFiles({ name: 'statement.pdf', mimeType: 'application/pdf', buffer: pdfBytes() });
    const card = panel.locator('[data-role=attachment]');
    await expect(card).toContainText('statement.pdf');
    await expect(card).toHaveAttribute('data-state', 'ready', { timeout: 15000 });
    await panel.locator('textarea').fill('[FIXTURE] my statement');
    await panel.locator('textarea').press('Enter');
    const msg = panel.locator('[data-message][data-author=customer]').last();
    await expect(msg).toHaveAttribute('data-delivery', 'delivered', { timeout: 15000 });
    await expect(msg).toContainText('my statement');
    await expect(msg.locator('[data-role=media-state], [data-role=media]').first()).toBeVisible();
    // G07: a drop on the composer takes the same path
    const frame = page.frames().find((f) => f.url().includes('frame=panel'))!;
    await frame.evaluate((b64) => {
        const bin = atob(b64);
        const arr = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
        const dt = new DataTransfer();
        dt.items.add(new File([arr], 'dropped.png', { type: 'image/png' }));
        document
            .querySelector('form.nlw-composer')!
            .dispatchEvent(
                new DragEvent('drop', { dataTransfer: dt, bubbles: true, cancelable: true }),
            );
    }, pngBytes(16, 16).toString('base64'));
    await expect(card).toContainText('dropped.png');
    await expect(card.locator('img')).toBeVisible();
    await card.locator('button[aria-label=Remove], button[aria-label=Cancel]').click();
    await expect(card).toHaveCount(0);
});

test('G09: progress with cancel — cancelling mid-upload removes the card and nothing is sent', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await page.route('**/api/widget/v1/media', async (route) => {
        await new Promise((r) => setTimeout(r, 4000)); // a slow link
        await route.continue();
    });
    await pasteFile(page, panel, pngBytes(256, 256), 'slow.png', 'image/png');
    const card = panel.locator('[data-role=attachment]');
    await expect(card).toHaveAttribute('data-state', 'uploading');
    await expect(card.locator('progress')).toBeVisible();
    await expect(card).toContainText(' of ');
    await card.locator('button[aria-label=Cancel]').click();
    await expect(card).toHaveCount(0);
    await page.waitForTimeout(4500);
    expect(await panel.locator('[data-message][data-author=customer]').count()).toBe(0);
    await expect(panel.locator('.nlw-send')).toBeDisabled();
});

test('EC-08: the cap is checked BEFORE a byte moves — an over-cap file is refused with the friendly limit and no upload request is made', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    let uploads = 0;
    page.on('request', (r) => {
        if (r.url().includes('/api/widget/v1/media') && r.method() === 'POST') uploads++;
    });
    await panel.locator('input[type=file]').setInputFiles({
        name: 'huge.pdf',
        mimeType: 'application/pdf',
        buffer: Buffer.alloc(26 * 1024 * 1024, 0x20),
    });
    const card = panel.locator('[data-role=attachment]');
    await expect(card).toHaveAttribute('data-state', 'refused');
    await expect(card).toContainText('too large');
    await expect(card).toContainText('25 MB');
    await expect(card).toContainText('Nothing was uploaded');
    expect(uploads).toBe(0);
    await expect(panel.locator('.nlw-send')).toBeDisabled();
    await card.locator('button[aria-label=Remove]').click();
    await expect(card).toHaveCount(0);
});

test('EC-10: text past max_text is told "it will go as 2 parts" and sends as two messages, in order', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    const unit = '[FIXTURE] part-of-a-long-message ';
    const text = unit.repeat(Math.ceil(4300 / unit.length)); // > 4000 (the harness max_text), every part carries the tag
    await panel.locator('textarea').fill(text);
    await expect(panel.locator('[data-role=split-notice]')).toContainText('2 parts');
    await panel.locator('textarea').press('Enter');
    await expect(panel.locator('[data-message][data-author=customer]')).toHaveCount(2);
    await expect(panel.locator('[data-message][data-author=customer]').nth(1)).toHaveAttribute(
        'data-delivery',
        'delivered',
        { timeout: 20000 },
    );
    await expect(panel.locator('[data-role=split-notice]')).toHaveCount(0);
    const bodies = await panel
        .locator('[data-message][data-author=customer] .nlw-bubble')
        .allTextContents();
    const [first = '', second = ''] = bodies;
    expect(first.length).toBeLessThanOrEqual(4000);
    expect(first.length + second.length).toBeGreaterThanOrEqual(text.trim().length - 2);
});

test('G02: a blocked visitor sees the paused copy and no composer — no failure vocabulary (the gate sweeps it), nothing else changes', async ({
    page,
}) => {
    const { panel } = await openWidget(page);
    await sendFromPanel(panel, 'before the block');
    expect(harness('block')).toContain('STATE=blocked');
    await page.reload();
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const p2 = page.frameLocator('iframe#nlw-panel');
    await p2.locator('#nlw-tab-messages').click();
    await expect(p2.locator('[data-role=paused]')).toBeVisible({ timeout: 10000 });
    await expect(p2.locator('textarea')).toHaveCount(0);
    await expect(p2.locator('[data-role=paused]')).not.toContainText(/block|error|fail|denied/i);
    await expect(p2.locator('[data-message][data-author=customer]')).toContainText(
        'before the block',
    ); // history stays readable
    harness('unblock');
});

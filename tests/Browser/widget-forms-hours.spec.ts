// Doc 13 FR-06 / FR-15 / Doc 12 FR-20 — PRE-CHAT (F03–F04) and HOURS (F09–F10):
// optional skips, required blocks (no tab bar beside a form), the answers ride
// the FIRST message; out of hours with the bot off the offline form replaces the
// composer and lands a REAL ticket with the masked phone shown back.
import { expect, test } from '@playwright/test';
import { harness, openWidget } from './support/panel';

test('F03: optional pre-chat — no tab bar, Skip opens the thread; F04: required blocks until name + phone; a refusal is inline with the field named', async ({
    page,
}) => {
    harness('config', '--value=required');
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    const form = panel.locator('[data-role=prechat]');
    await expect(form).toHaveAttribute('data-required', 'true', { timeout: 10000 });
    expect(await panel.locator('[role=tablist]').count()).toBe(0); // the tab bar is suppressed beside a form
    expect(await form.locator('button', { hasText: 'Skip' }).count()).toBe(0);
    await form.locator('button[type=submit]').click();
    await expect(form.locator('[data-field=name] .nlw-error')).toContainText('needed to continue');
    await form.locator('#nlw-f-name').fill('አበበ ታደሰ');
    await form.locator('#nlw-f-phone').fill('0912344417');
    await form.locator('button[type=submit]').click();
    await expect(panel.locator('textarea')).toBeVisible({ timeout: 10000 });
    await expect(panel.locator('[role=tablist]')).toHaveCount(1);
    // the answers ride the first message's envelope (STOP-5C) — the server's consumer lands them as staff-collected fields
    let formSeen = false;
    page.on('request', (r) => {
        if (r.url().endsWith('/api/widget/v1/messages') && r.method() === 'POST')
            formSeen = formSeen || (r.postData() ?? '').includes('"form"');
    });
    await panel.locator('textarea').fill('[FIXTURE] my withdrawal is late');
    await panel.locator('textarea').press('Enter');
    await expect.poll(() => formSeen).toBe(true);
});

test('F03 optional: Skip goes straight to the thread', async ({ page }) => {
    harness('config', '--value=optional');
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-messages').click();
    const form = panel.locator('[data-role=prechat]');
    await expect(form).toHaveAttribute('data-required', 'false', { timeout: 10000 });
    await form.locator('button', { hasText: 'Skip' }).click();
    await expect(panel.locator('textarea')).toBeVisible();
});

test('F09/F10: closed hours with the bot off → the offline form instead of the composer; the receipt cites the ticket and the MASKED phone', async ({
    page,
}) => {
    harness('config', '--value=off');
    harness('hours-close');
    try {
        const { panel } = await openWidget(page);
        await expect(panel.locator('.nlw-band')).toContainText('opens at');
        await panel.locator('#nlw-tab-messages').click();
        const form = panel.locator('[data-role=offline-form]');
        await expect(form).toBeVisible({ timeout: 10000 });
        expect(await panel.locator('textarea.nlw-composer, .nlw-composer').count()).toBe(0);
        await form.locator('#nlw-f-name').fill('አበበ ታደሰ');
        await form.locator('#nlw-f-phone').fill('+251912344417');
        await form.locator('#nlw-f-story').fill('[FIXTURE] 2,500 ብር አውጥቼ ነበር፣ አልደረሰኝም።');
        await form.locator('button[type=submit]').click();
        const receipt = panel.locator('[data-role=offline-receipt]');
        await expect(receipt).toBeVisible({ timeout: 15000 });
        await expect(receipt).toContainText(/ticket #\d+/);
        await expect(receipt).toContainText('+251 •• ••• 4417');
        expect(await receipt.textContent()).not.toContain('912344417');
    } finally {
        harness('hours-open');
    }
});

// S13 step 13b (advisor main recount) — SEC-04's form half, EXECUTED: a Doc 12
// form carrying a FILE field (harness `config --value=file-prechat`, minted
// through Doc 12's own doors) renders the field INERT in the widget — no input
// of any kind, no upload path (uploads belong to the composer under the
// adapter's capability, G05); the form still submits without it.
test('SEC-04: a form file field renders inert — no input, no upload control; the form completes without it', async ({
    page,
}) => {
    harness('config', '--value=file-prechat');
    try {
        const { panel } = await openWidget(page);
        await panel.locator('#nlw-tab-messages').click();
        const form = panel.locator('[data-role=prechat]');
        await expect(form).toBeVisible();
        const fileField = form.locator('[data-field=screenshot]');
        await expect(fileField).toContainText('Screenshot');
        await expect(fileField.locator('[data-role=file-inert]')).toHaveText(
            'Attachments are shared in the chat, not on this form.',
        );
        // INERT: not an upload control, not a text box, nothing interactive
        expect(await fileField.locator('input, select, textarea, button').count()).toBe(0);
        expect(await form.locator('input[type=file]').count()).toBe(0);
        // the form still works without the field: name in, continue, thread opens
        await form.locator('[data-field=name] input').fill('[FIXTURE] Abebe');
        await form.locator('button[type=submit]').click(); // labeled per mode ('Start the chat' under optional)
        await expect(panel.locator('textarea')).toBeVisible({ timeout: 10000 });
    } finally {
        harness('config', '--value=off');
    }
});

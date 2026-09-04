// Doc 10 FR-18 / Doc 13 §8 — THE HELP TAB (E01–E07): browse → search (debounced) →
// article (reader) → feedback; the start-a-chat footer pinned on every screen;
// K05 index down = one line, chat still works; E07 the language-fallback notice.
import { expect, test } from '@playwright/test';
import { harness, openWidget } from './support/panel';

test('E01/E02: browse lists the published article; search finds it as you type (debounced, highlighted); the start-a-chat footer is pinned', async ({
    page,
}) => {
    harness('kb-seed');
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-help').click();
    const help = panel.locator('[data-role=help]');
    await expect(help).toHaveAttribute('data-view', 'browse');
    await expect(panel.locator('[data-role=help-footer]')).toContainText('Start a chat');
    await expect(
        help.locator('.nlw-btn', { hasText: 'How long do withdrawals take' }).first(),
    ).toBeVisible({ timeout: 10000 });
    await help.locator('input[type=search]').fill('withdrawals');
    await expect(help).toHaveAttribute('data-view', 'search');
    await expect(help.locator('.nlw-hit').first()).toContainText('withdrawals', { timeout: 10000 });
    await expect(help.locator('.nlw-hit mark').first()).toHaveText(/withdrawals/i);
    await expect(panel.locator('[data-role=help-footer]')).toContainText('Start a chat');
});

// OWED on P-KB-VIEWED (docs/evidence/S13-step9/PACKETS.md): the article door answers 500 live — Doc 10's
// view event is published outside a transaction. This test and E07 stay RED until the packet lands.
test('E03/E04: the reader shows the blocks; 👍 lands; the footer wording shifts on an article', async ({
    page,
}) => {
    harness('kb-seed');
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-help').click();
    const help = panel.locator('[data-role=help]');
    let feedback = 0;
    page.on('response', (r) => {
        if (r.url().includes('/kb/feedback') && r.status() === 201) feedback++;
    });
    await help
        .locator('.nlw-btn', { hasText: 'How long do withdrawals take' })
        .first()
        .click({ timeout: 10000 });
    await expect(help).toHaveAttribute('data-view', 'article');
    await expect(help.locator('.nlw-article h2')).toContainText('How long do withdrawals take');
    await expect(help.locator('.nlw-article p').first()).toContainText('within 15 minutes');
    await expect(panel.locator('[data-role=help-footer]')).toContainText('This did not answer it');
    await help.locator('[data-role=feedback] button[aria-label="👍"]').click();
    await expect.poll(() => feedback).toBe(1);
    await expect(help.locator('[data-role=feedback]')).toContainText('Thanks for telling us');
});

test('E07: an Amharic visitor reading an English-only article sees the fallback notice; the article still renders', async ({
    page,
}) => {
    harness('kb-seed');
    const { panel } = await openWidget(page);
    await panel.locator('button[aria-label="More options"]').click();
    await panel.locator('[role=menuitemradio]', { hasText: 'አማርኛ' }).click();
    await panel.locator('#nlw-tab-help').click();
    const help = panel.locator('[data-role=help]');
    await help
        .locator('.nlw-btn', { hasText: 'How long do withdrawals take' })
        .first()
        .click({ timeout: 10000 });
    await expect(help.locator('[data-role=language-fallback]')).toBeVisible();
    await expect(help.locator('.nlw-article h2')).toContainText('How long do withdrawals take');
});

test('K05 / FS-02: the search index unreachable → one calm line, the chat still works', async ({
    page,
}) => {
    await page.route('**/api/widget/v1/kb/search*', (route) =>
        route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({ unavailable: true, hits: [], start_chat: true }),
        }),
    );
    const { panel } = await openWidget(page);
    await panel.locator('#nlw-tab-help').click();
    await panel.locator('[data-role=help] input[type=search]').fill('withdrawals');
    await expect(panel.locator('[data-role=index-down]')).toContainText('chat still works');
    await panel.locator('[data-role=help-footer] button').click();
    await expect(panel.locator('textarea')).toBeVisible();
});

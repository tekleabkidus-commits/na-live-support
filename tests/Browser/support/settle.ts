import { expect, type Page } from '@playwright/test';

/**
 * R-D11-1 wait hardening (S11 step 0.3, advisor countersign 2026-08-30 — the
 * STOP-F diagnosis): the 0i pair failed FIRST-ATTEMPT on the same shape twice
 * over — a list read or click issued while the inbox list was still
 * re-keying its rows (a view switch, a seeded projection, the FR-11 batched
 * resort all landing at once), so Playwright saw "element was detached from
 * the DOM, retrying" until the test timeout, or read `order[0]` before the
 * first fetch had landed. Both are SETUP/WAIT defects, not product defects.
 *
 * settleList() is the shared answer, in the coach.ts idiom: setup-only, it
 * acts BEFORE a test's assertions and changes none of them. It waits for
 * (a) the store to be on the expected view (the g/u shortcut lands
 * asynchronously), (b) at least one row to be rendered, and (c) the row
 * ORDER to be identical across two consecutive reads 500 ms apart — the
 * resort cadence is 5 s batched (FR-11), so two stable reads mean no
 * re-key is in flight.
 */
export async function settleList(page: Page, viewKey?: string): Promise<void> {
    if (viewKey !== undefined) {
        await expect
            .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey), {
                timeout: 10_000,
            })
            .toBe(viewKey);
    }
    await expect(page.locator('[data-ticket]').first()).toBeVisible({ timeout: 10_000 });
    await expect
        .poll(
            async () => {
                const first = await page.evaluate(() => window.__inboxList.state().order.join(','));
                await page.waitForTimeout(500);
                const second = await page.evaluate(() =>
                    window.__inboxList.state().order.join(','),
                );
                return first === second;
            },
            { timeout: 15_000, intervals: [250, 500, 1000] },
        )
        .toBe(true);
}

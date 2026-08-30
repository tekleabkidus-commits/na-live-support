import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { settleList } from './support/settle';

/*
 * S07 step 13 — B2-S01's mobile interactions (Doc 07 §8, Doc 06 §9's 25
 * cap) + EC-03's dual-tab drafts.
 *
 * Swipes are POINTER drags — the same pointer-event code path a touch
 * device drives (`touch-action: pan-y` keeps vertical scrolling native).
 * The three drawn swipe-claim states execute in order: revealing (the 92 px
 * zone), committed ("Claimed by you" flash), the undo strip. The actions
 * sheet's rows are real doors. Pull-to-refresh triggers on RELEASE — never
 * a resort under the thumb. Selection mode long-presses in, caps at 25
 * with the drawn amber strip.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V375 = { width: 375, height: 812 };
const RUN = Date.now().toString(36);

function artisan(...args: string[]): void {
    try {
        execFileSync(
            'docker',
            [
                'compose',
                '-f',
                'docker/compose.yaml',
                '-f',
                'docker/compose.dev.yaml',
                'exec',
                '-T',
                'app',
                'php',
                'artisan',
                ...args,
            ],
            { stdio: 'pipe' },
        );
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(
            `${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`,
        );
    }
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxShiftSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openMobileUnassigned(page: Page): Promise<void> {
    await login(page);
    await page.setViewportSize(V375);
    await page.goto('/inbox');
    if (
        await page
            .getByTestId('coach-card')
            .isVisible()
            .catch(() => false)
    ) {
        await page.getByTestId('coach-skip').click();
    }
    await page.getByTestId('mobile-views-trigger').click();
    await page.locator('[data-view="unassigned"]').first().click();
    await expect
        .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey))
        .toBe('unassigned');
    await expect(page.locator('[data-ticket]').first()).toBeVisible();
}

async function dragRow(page: Page, ticketId: string, dx: number, holdMs = 0): Promise<void> {
    const row = page.locator(`[data-ticket="${ticketId}"]`);
    const box = await row.boundingBox();
    if (!box) throw new Error('row not visible');
    const startX = box.x + box.width / 2;
    const y = box.y + box.height / 2;
    await page.mouse.move(startX, y);
    await page.mouse.down();
    if (holdMs > 0) {
        await page.waitForTimeout(holdMs);
    }
    if (dx !== 0) {
        await page.mouse.move(startX + dx / 2, y, { steps: 4 });
        await page.mouse.move(startX + dx, y, { steps: 4 });
    }
    await page.mouse.up();
}

test('B2-S01 SWIPE-RIGHT CLAIM, three drawn states: the zone reveals mid-drag, commit claims through the REAL door, the undo strip returns it to the queue', async ({
    page,
}) => {
    await openMobileUnassigned(page);
    const ticketId = await page.evaluate(() => window.__inboxList.state().order[0] ?? null);
    expect(ticketId).not.toBeNull();
    const rowNumber = await page.evaluate(
        (id) => String((window.__inboxList.row(id as string) ?? {}).number ?? ''),
        ticketId,
    );

    // Mid-drag: the revealing state (the 92 px claim zone).
    const row = page.locator(`[data-ticket="${ticketId}"]`);
    const box = await row.boundingBox();
    if (!box) throw new Error('row not visible');
    await page.mouse.move(box.x + 30, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + 30 + 40, box.y + box.height / 2, { steps: 3 });
    await expect(page.getByTestId('swipe-claim-zone')).toBeVisible();
    // Below the commit threshold: release snaps back silently.
    await page.mouse.up();
    await expect(page.getByTestId('claim-undo-strip')).toBeHidden();

    // Full swipe: committed — claim lands through the door, the undo strip
    // names the ticket, and the row leaves Unassigned.
    await dragRow(page, ticketId as string, 140);
    await expect(page.getByTestId('claim-undo-strip')).toBeVisible();
    await expect(page.getByTestId('claim-undo-strip')).toContainText(rowNumber);
    await expect
        .poll(
            async () =>
                await page.evaluate(
                    (id) => window.__inboxList.state().order.includes(id as string),
                    ticketId,
                ),
            { timeout: 10_000 },
        )
        .toBe(false);

    // Undo: back to the queue through the unassign door.
    await page.getByTestId('claim-undo').click();
    await expect
        .poll(
            async () =>
                await page.evaluate(
                    (id) => window.__inboxList.state().order.includes(id as string),
                    ticketId,
                ),
            { timeout: 10_000 },
        )
        .toBe(true);
});

test('B2-S01 SWIPE-LEFT ACTIONS SHEET: the drawn rows on real doors — Resolve removes the ticket from the open family', async ({
    page,
}) => {
    await openMobileUnassigned(page);
    const ticketId = await page.evaluate(() => window.__inboxList.state().order[0] ?? null);
    expect(ticketId).not.toBeNull();

    await dragRow(page, ticketId as string, -140);
    await expect(page.getByTestId('mobile-actions-sheet')).toBeVisible();
    await expect(page.getByTestId('sheet-resolve')).toBeVisible();
    await expect(page.getByTestId('sheet-pending')).toBeVisible();
    await expect(page.getByTestId('sheet-priority-urgent')).toBeVisible();

    await page.getByTestId('sheet-resolve').click();
    await expect(page.getByTestId('mobile-actions-sheet')).toBeHidden();
    await expect
        .poll(
            async () =>
                await page.evaluate(
                    (id) => window.__inboxList.state().order.includes(id as string),
                    ticketId,
                ),
            { timeout: 10_000 },
        )
        .toBe(false);
});

test('B2-S01 PULL-TO-REFRESH: pulling past the threshold shows the release strip; RELEASE refreshes — nothing resorts under the thumb', async ({
    page,
}) => {
    await openMobileUnassigned(page);
    const orderBefore = await page.evaluate(() => window.__inboxList.state().order.join(','));

    const listBox = await page.getByTestId('ticket-list').boundingBox();
    if (!listBox) throw new Error('list not visible');
    const x = listBox.x + listBox.width / 2;
    await page.mouse.move(x, listBox.y + 20);
    await page.mouse.down();
    await page.mouse.move(x, listBox.y + 110, { steps: 5 });
    // Held mid-pull: the release strip shows, the ORDER has not moved.
    await expect(page.getByTestId('pull-release-strip')).toBeVisible();
    expect(await page.evaluate(() => window.__inboxList.state().order.join(','))).toBe(orderBefore);
    await page.mouse.up();
    // Released: the refresh runs and settles.
    await expect(page.getByTestId('pull-release-strip')).toBeHidden();
    await expect(page.getByTestId('pull-refreshing-strip')).toBeHidden({ timeout: 10_000 });
    await expect(page.locator('[data-ticket]').first()).toBeVisible();
});

test('B2-S01 SELECTION MODE: long-press enters it, Select all stops at the 25 cap with the drawn amber strip, ✕ exits clean', async ({
    page,
}) => {
    await openMobileUnassigned(page);
    const ticketId = await page.evaluate(() => window.__inboxList.state().order[0] ?? null);
    expect(ticketId).not.toBeNull();

    // Long-press (hold, no movement) → selection mode with the row selected.
    await dragRow(page, ticketId as string, 0, 700);
    await expect(page.getByTestId('mobile-selection-bar')).toBeVisible();
    await expect(page.getByTestId('mobile-selection-bar')).toContainText('1');

    // Select all → capped at 25, the drawn strip appears.
    await page.getByTestId('mobile-select-all').click();
    await expect(page.getByTestId('mobile-selection-bar')).toContainText('25');
    await expect(page.getByTestId('mobile-cap-strip')).toBeVisible();
    expect(
        (await page.evaluate(() => window.__inboxList.state().selected)).length,
    ).toBeLessThanOrEqual(25);

    // ✕ exits and clears.
    await page.getByTestId('mobile-selection-exit').click();
    await expect(page.getByTestId('mobile-selection-bar')).toBeHidden();
    expect(await page.evaluate(() => window.__inboxList.state().selected)).toEqual([]);
});

test('EC-03 DUAL-TAB DRAFTS: the later writer wins across tabs with the "another device" toast, and a user\'s own typing NEVER self-triggers the collision strip', async ({
    context,
    page,
}) => {
    // Tab A and tab B, same session, same thread.
    await login(page);
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox');
    if (
        await page
            .getByTestId('coach-card')
            .isVisible()
            .catch(() => false)
    ) {
        await page.getByTestId('coach-skip').click();
    }
    // Any open thread works — the FIRST my_open row, same one in both tabs
    // (fixture rows get consumed across local runs; the id pins the pair).
    // R-D11-1 (S11 step 0.3): the store's order is empty until the first
    // fetch lands — wait for a rendered, settled list before reading it
    // (the recorded 0i signature: order[0] read as null on first attempt).
    await settleList(page);
    const target = await page.evaluate(() => window.__inboxList.state().order[0] ?? null);
    expect(target).not.toBeNull();
    await page.locator(`[data-ticket="${target}"]`).locator('[data-role="row-open"]').click();
    await expect(page.getByTestId('composer')).toBeVisible();

    const pageB = await context.newPage();
    await pageB.setViewportSize({ width: 1440, height: 900 });
    await pageB.goto('/inbox');
    await settleList(pageB);
    await pageB.locator(`[data-ticket="${target}"]`).locator('[data-role="row-open"]').click();
    await expect(pageB.getByTestId('composer')).toBeVisible();

    // Type in A → the debounced autosave broadcasts → B converges (LWW)
    // with the Doc 04 EC-06 toast.
    const draft = `dual tab draft ${RUN}`;
    await page.getByTestId('composer-input').fill(draft);
    await expect(pageB.getByTestId('composer-input')).toHaveValue(draft, { timeout: 10_000 });
    await expect(pageB.getByTestId('draft-synced-toast')).toBeVisible();

    // EC-03's second clause: the user's OWN other tab never reads as a
    // colleague — no collision strip anywhere.
    await expect(pageB.getByTestId('collision-strip')).toBeHidden();
    await expect(page.getByTestId('collision-strip')).toBeHidden();

    await pageB.close();
});

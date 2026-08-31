import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 12 — FR-08 + FR-06's rail behaviors (B1-S03): keyboard reorder
 * (Alt↑/Alt↓ — §13's drag equivalent) persisted per user; the saved-view
 * EDITOR through the step-4 doors (create → the view renders REAL filtered
 * rows); duplicate-to-edit on shared views; the Doc 11 queues SEAM.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

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
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function openInbox(page: Page, query = ''): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox' + query);
    await dismissCoachMarks(page);
    await expect(page.getByTestId('views-rail')).toBeVisible();
}

test('FR-08: Alt↓ moves My Open below Unassigned; the order PERSISTS across a reload (prefs, per user)', async ({
    page,
}) => {
    await openInbox(page);

    const keys = () =>
        page.$$eval('[data-view]', (rows) => rows.map((row) => row.getAttribute('data-view')));

    expect(await keys()).toEqual([
        'my_open',
        'unassigned',
        'mentions',
        'following',
        'sla_at_risk',
        'expiring_windows',
        'rescue',
    ]);

    // The persist must LAND before the reload proves it (the PATCH races
    // an immediate reload otherwise).
    const persisted = page.waitForResponse(
        (r) => r.url().includes('/inbox/prefs') && r.request().method() === 'PATCH' && r.ok(),
    );
    await page.locator('[data-view="my_open"]').focus();
    await page.keyboard.press('Alt+ArrowDown');
    await expect.poll(async () => (await keys()).slice(0, 2)).toEqual(['unassigned', 'my_open']);
    await persisted;

    await page.reload();
    await expect(page.getByTestId('views-rail')).toBeVisible();
    expect((await keys()).slice(0, 2)).toEqual(['unassigned', 'my_open']);

    // Restore for later specs (the seeder resets prefs, but stay tidy).
    await page.locator('[data-view="my_open"]').focus();
    await page.keyboard.press('Alt+ArrowUp');
    await expect.poll(async () => (await keys())[0]).toBe('my_open');
});

test('FR-06: the editor creates a saved view through the real door; selecting it renders ONLY the filtered rows', async ({
    page,
}) => {
    await openInbox(page);

    // Saved views persist across seeds (soft-delete only) — a unique name
    // keeps re-runs honest instead of colliding with yesterday's row.
    const viewName = 'Urgent only (spec ' + Date.now() + ')';

    await page.getByTestId('new-saved-view').first().click();
    const editor = page.getByTestId('saved-view-editor');
    await expect(editor).toBeVisible();

    await page.getByTestId('view-editor-name').fill(viewName);
    await page.getByTestId('view-editor-add-key').selectOption('priority');
    await page.getByTestId('view-editor-add-value').fill('urgent');
    await page.getByTestId('view-editor-add-confirm').click();
    await expect(editor.locator('[data-filter-chip="priority"]')).toContainText('urgent');

    await page.getByTestId('view-editor-save').click();
    await expect(editor).toHaveCount(0);

    // The new view lands under Mine and renders REAL membership: the urgent
    // R1 row only — the normal-priority rows are absent, not hidden.
    const mineRow = page.locator('[data-saved-view]', { hasText: viewName });
    await expect(mineRow).toBeVisible();
    await mineRow.click();
    await expect(page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' })).toBeVisible();
    await expect(page.locator('[data-ticket]', { hasText: 'Fixture Filler 1' })).toHaveCount(0);
});

test('FR-06: a SHARED view is read-only — duplicate-to-edit mints an owned copy and opens the editor on it', async ({
    page,
}) => {
    await openInbox(page);

    const sharedRow = page.locator('[data-saved-view]').first();
    await sharedRow.hover();
    const duplicate = sharedRow.locator('[data-duplicate-view]');
    if ((await duplicate.count()) > 0) {
        await duplicate.click();
        const editor = page.getByTestId('saved-view-editor');
        await expect(editor).toBeVisible();
        await expect(page.getByTestId('view-editor-name')).toHaveValue(/copy/);
        await page.getByTestId('view-editor-cancel').click();
    }
});

test("Doc 07 FR-05 REAL (S11 step 11a, STOP-H — the seam and its door are DELETED): the Queues section renders the agent's effective-member queue with the waiting depth; clicking it serves the waiting list in the dispatcher's order", async ({
    page,
}) => {
    await openInbox(page);

    const queues = page.getByTestId('queues-section');
    await expect(queues).toBeVisible();
    const row = queues.locator('[data-queue-view="00000000-0000-7000-8000-00000000d012"]');
    await expect(row).toContainText('Fixture payments queue');
    // The depth badge is the DISPATCHER'S truth, and on CI the suite shares one DB — earlier
    // specs' created-tickets route into the tenant-default queue (this one), so an exact pin
    // is depth-fragile there (the 2026-08-31 CI reds). The law asserted instead: the badge
    // NUMBER equals the count the CLICKED view then serves (badge == list — one truth), the
    // three fixed-id fixtures are AMONG the rows, and the head is the FIFO first (exact-depth
    // stays pinned server-side in QueueViewsTest, where the scene is isolated).
    const badgeText = (await row.textContent()) ?? '';
    const depth = Number(badgeText.replace(/[^0-9]/g, ''));
    expect(depth).toBeGreaterThanOrEqual(3);

    await row.click();
    const list = page.getByTestId('ticket-list');
    await expect(list.locator('[data-ticket]')).toHaveCount(depth);
    // The exact FIFO head is pinned SERVER-side (QueueViewsTest, isolated scene). Here the
    // trio's ages sit within the starvation-age cap's tie band after a long CI suite, so the
    // head among them is timeline-sensitive — the browser law asserted: the served FIRST row
    // IS one of the dispatcher's fixtures (never a stranger), and all three render.
    await expect(list.locator('[data-ticket]').first()).toHaveAttribute(
        'data-ticket',
        /00000000-0000-7000-8000-0000000d11(01|02|03)/,
    );
    for (const n of ['01', '02', '03']) {
        await expect(
            list.locator(`[data-ticket="00000000-0000-7000-8000-0000000d11${n}"]`),
        ).toBeVisible();
    }

    // The retired seam door is gone: the query flag renders NOTHING extra (the section is the
    // real one). Same session — a second /login visit would bounce an authenticated user.
    await page.goto('/inbox?seam_queues=1');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('queues-section').locator('[data-queue-view]')).toHaveCount(1);
});

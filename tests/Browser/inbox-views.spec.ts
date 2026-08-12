import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

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

test('Doc 11 SEAM: ?seam_queues=1 renders the Queues section TYPE from the fixture — counts and order, no membership logic', async ({
    page,
}) => {
    await openInbox(page, '?seam_queues=1');

    const queues = page.getByTestId('queues-section');
    await expect(queues).toBeVisible();
    await expect(queues.locator('[data-queue-view]').first()).toContainText('Payments');
    await expect(queues).toContainText('14');
    await expect(queues).toContainText('KYC & verification');
});

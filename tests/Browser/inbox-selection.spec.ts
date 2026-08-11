import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * FR-13 + EC-02 (S07 step 9): checkbox toggle, shift-range, "select all in
 * view" minting the T-05 SERVER token (the shown count is the mint's server
 * truth), selection SURVIVING a live resort, and EC-02's "no longer in
 * view" tray on the bulk bar — a selected row that leaves the view stays
 * selected in the tray until deselected (execution's drift report stays the
 * server's, proven in SelectionTokensTest).
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

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openUnassigned(page: Page): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await page.locator('[data-view="unassigned"]').first().click();
    // Unassigned-only content proves the switch LANDED (the refetch is async).
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
}

test('FR-13: checkbox toggle + shift-range select; the count is honest; selection SURVIVES a live resort (by id, by construction)', async ({
    page,
}) => {
    await openUnassigned(page);

    const boxes = page.locator('[data-role="row-select"]');
    await boxes.nth(0).click();
    await expect(page.getByTestId('bulk-count')).toHaveText('1 selected');

    // Shift-range from row 0 to row 3 → 4 selected.
    await boxes.nth(3).click({ modifiers: ['Shift'] });
    await expect(page.getByTestId('bulk-count')).toHaveText('4 selected');

    // A live resort (scripted): the selection set is UNCHANGED.
    const selectedBefore = await page.evaluate(() => window.__inboxList.state().selected);
    await page.evaluate((id) => {
        window.__inboxList.applyDelta({ op: 'patch', id, sort_key: 1, patch: {} });
        window.__inboxList.flush();
    }, selectedBefore[3] ?? '');
    const selectedAfter = await page.evaluate(() => window.__inboxList.state().selected);
    expect([...selectedAfter].sort()).toEqual([...selectedBefore].sort());
    await expect(page.getByTestId('bulk-count')).toHaveText('4 selected');

    await page.getByTestId('clear-selection').click();
    await expect(page.getByTestId('bulk-bar')).toHaveCount(0);
});

test('FR-13/T-05: "select all in view" mints the SERVER token — the shown count is the mint\'s count, not a client tally', async ({
    page,
}) => {
    await openUnassigned(page);

    const total = Number(
        await page
            .getByTestId('list-total')
            .textContent()
            .then((text) => text?.match(/\d+/)?.[0] ?? '0'),
    );
    expect(total).toBeGreaterThan(1);

    await page.locator('[data-role="row-select"]').first().click();
    await page.getByTestId('select-all-in-view').click();

    // The bulk count flips to the SERVER count from the mint response.
    await expect(page.getByTestId('bulk-count')).toHaveText(`${total} selected`);

    await page.getByTestId('clear-selection').click();
});

test('EC-02: a selected row that LEAVES the view stays selected in the "no longer in view" tray until deselected there', async ({
    page,
}) => {
    await openUnassigned(page);

    const first = page.locator('[data-role="row-select"]').first();
    const firstId = await page.locator('[data-ticket]').first().getAttribute('data-ticket');
    await first.click();
    await expect(page.getByTestId('bulk-count')).toHaveText('1 selected');

    // The scripted stream removes that row from the view; the batch lands.
    await page.evaluate((id) => {
        window.__inboxList.applyDelta({ op: 'remove', id: id ?? '' });
        window.__inboxList.flush();
    }, firstId);

    // Gone from the list — but STILL SELECTED, in the tray.
    await expect(page.locator(`[data-ticket="${firstId}"]`)).toHaveCount(0);
    await expect(page.getByTestId('drift-tray')).toBeVisible();
    await expect(page.getByTestId('drift-tray')).toContainText('No longer in view (1)');
    await expect(page.getByTestId('bulk-count')).toHaveText('1 selected');

    // Deselect from the tray → the bar empties.
    await page.locator(`[data-tray-id="${firstId}"]`).click();
    await expect(page.getByTestId('bulk-bar')).toHaveCount(0);
});

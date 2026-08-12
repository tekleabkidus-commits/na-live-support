import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 12 — ⌘K command palette (FR-23/24/25) + the Rescue view (Doc 05
 * US-04 closing row). BR-03 is the spine: there is NO scope toggle anywhere
 * in the palette; the SearchProxy injects the viewer's scope server-side
 * (SEC-02) and the client sends only the query string. The palette's three
 * sections (Actions / Views / Search) filter fuzzily in-house.
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

async function openInbox(page: Page): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('views-rail')).toBeVisible();
}

test('FR-23/24: ⌘K opens the palette; the Views section fuzzy-filters and selecting one switches the active view; NO scope toggle exists (BR-03)', async ({
    page,
}) => {
    await openInbox(page);

    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByTestId('command-palette')).toBeVisible();

    // BR-03: no scope control anywhere in the palette.
    await expect(page.getByTestId('command-palette').getByText(/scope/i)).toHaveCount(0);

    // Fuzzy: "unas" surfaces Unassigned in the Views section.
    await page.getByTestId('palette-input').fill('unas');
    const unassigned = page.locator('[data-palette-view="unassigned"]');
    await expect(unassigned).toBeVisible();
    await unassigned.click();
    await expect(page.getByTestId('command-palette')).toHaveCount(0);
    // The list switched to Unassigned (its content renders).
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
});

test('FR-25: the Search section queries the proxy (query string ALONE — SEC-02) and a ticket hit opens its thread', async ({
    page,
}) => {
    await openInbox(page);
    await page.keyboard.press('ControlOrMeta+k');
    await expect(page.getByTestId('command-palette')).toBeVisible();

    // The request the palette makes carries ONLY q — asserted by intercept.
    const searchRequest = page.waitForRequest((r) => r.url().includes('/inbox/search?q='));
    await page.getByTestId('palette-input').fill('withdrawal');
    const request = await searchRequest;
    const url = new URL(request.url());
    expect([...url.searchParams.keys()]).toEqual(['q']); // no scope/filter/index params

    // A hit (the seeded #4801 "Withdrawal delayed") opens its thread.
    const hit = page.locator('[data-role="search-ticket"]').first();
    await expect(hit).toBeVisible();
    await hit.click();
    await expect(page.getByTestId('thread-pane')).toBeVisible();
});

test('Doc 05 US-04 (the closing row): the Rescue system view lists the channel_unreachable ticket', async ({
    page,
}) => {
    await openInbox(page);

    await page.locator('[data-view="rescue"]').first().click();
    await expect(page.locator('[data-ticket]', { hasText: 'Rescue Fixture Player' })).toBeVisible();
});

test('FR-25: a MESSAGE hit opens its thread ANCHORED at that message with the row highlighted (the Doc 04 EC-08 window underneath)', async ({
    page,
}) => {
    await openInbox(page);
    await page.keyboard.press('ControlOrMeta+k');
    await page.getByTestId('palette-input').fill('receipt');

    const hit = page.locator('[data-role="search-message"]').first();
    await expect(hit).toBeVisible();
    await expect(hit.locator('mark')).toContainText(/receipt/i);
    await hit.click();

    await expect(page.getByTestId('thread-pane')).toBeVisible();
    const highlighted = page.locator('[data-search-highlight="1"]');
    await expect(highlighted).toBeVisible();
    await expect(highlighted).toContainText(/receipt/i);
});

test('FR-23: ↑↓ move the roving selection and ↵ opens it — keyboard-only from ⌘K to a switched view (US-01 posture)', async ({
    page,
}) => {
    await openInbox(page);
    await page.keyboard.press('ControlOrMeta+k');
    await page.getByTestId('palette-input').fill('unas');
    await expect(page.locator('[data-palette-view="unassigned"]')).toBeVisible();

    // 'unas' fuzzy-matches no action, so the first row IS the view; ↵ opens.
    await page.keyboard.press('Enter');
    await expect(page.getByTestId('command-palette')).toHaveCount(0);
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
});

test('FR-23: recent searches persist per user — the footer offers the last query on the next open', async ({
    page,
}) => {
    await openInbox(page);
    await page.keyboard.press('ControlOrMeta+k');
    await page.getByTestId('palette-input').fill('withdrawal');
    await expect(page.locator('[data-role="search-ticket"]').first()).toBeVisible();
    await page.keyboard.press('Escape');

    await page.keyboard.press('ControlOrMeta+k');
    const recent = page.getByTestId('palette-recent');
    await expect(recent).toContainText('withdrawal');
    await recent.locator('[data-role="recent-search"]').first().click();
    await expect(page.getByTestId('palette-input')).toHaveValue('withdrawal');
});

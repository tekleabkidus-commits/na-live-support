import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 12 — FR-24's in-view filter bar: chips COMPOSE WITHIN the current
 * view's base membership (the server ANDs — never replaces), the header
 * total tells the FILTERED truth (BR-01), and clear-all restores the view.
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

async function openInbox(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('views-rail')).toBeVisible();
}

test('FR-24: the High-priority chip composes WITHIN Unassigned — normal rows leave, the total tells the filtered truth, clear-all restores', async ({
    page,
}) => {
    await openInbox(page);

    await page.locator('[data-view="unassigned"]').first().click();
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
    const unfilteredTotal = await page.getByTestId('list-total').textContent();

    // Compose: priority ∈ {urgent, high} WITHIN unassigned (which holds
    // only normal-priority fixtures) → the list empties; the total is the
    // FILTERED count, never the view's (BR-01: the header never lies).
    await page.locator('[data-filter="priority"]').click();
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toHaveCount(0);
    await expect(page.getByTestId('list-total')).toContainText('0');
    // Base membership still governs: the urgent-but-ASSIGNED R1 must NOT
    // leak in (filters narrow the view, never widen it).
    await expect(page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' })).toHaveCount(0);

    await page.getByTestId('filter-clear-all').click();
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
    await expect(page.getByTestId('list-total')).toHaveText(unfilteredTotal ?? '');
});

test('FR-24: on My Open the same chip KEEPS the matching rows — the urgent R1 and the high Yonas stay, the normal FR-20 fixtures leave', async ({
    page,
}) => {
    await openInbox(page);

    await expect(page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' })).toBeVisible();
    await expect(page.locator('[data-ticket]', { hasText: 'Paused Conn Player' })).toBeVisible();

    await page.locator('[data-filter="priority"]').click();
    await expect(page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' })).toBeVisible();
    await expect(page.locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })).toBeVisible();
    await expect(page.locator('[data-ticket]', { hasText: 'Paused Conn Player' })).toHaveCount(0);
    await expect(page.getByTestId('list-total')).toContainText('2');
});

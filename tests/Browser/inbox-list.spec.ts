import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * FR-09 — the row anatomy (S07 step 9), against rows the REAL ListProjector
 * wrote from canonical fixtures:
 *  · line 1: channel glyph, the Doc 05 EC-04 dual-channel badge + the
 *    older-activity warning (the closing row's list half), customer name,
 *    number, SLA pill (Doc 12 seam) / WindowChip (Doc 05 — the SHARED
 *    component) whichever is most urgent, relative updated-at
 *  · line 2: snippet with Doc 06 EC-11's LOCALIZED media placeholder,
 *    language chip, sentiment dot (Doc 09 seam), priority dot, assignee
 *    avatar / unassigned ring, tag overflow, draft pencil, unread bold+count
 * FR-10: the sort override select, persisted per view (prefs.sort_overrides).
 * Functional-state + a11y; NO pixel baselines (inherited row 12).
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

test('FR-09 line 1+2, My Open: SLA pill outranks the window, unread bold + count from the viewer marker, language chip, sentiment + priority dots, tag overflow, assignee avatar', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);

    const row = page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' });
    await expect(row).toBeVisible();
    await expect(row.locator('[data-role="sla-pill"]')).toContainText('SLA breached');
    await expect(row.locator('[data-role="unread-count"]')).toHaveText('2');
    await expect(row.locator('[data-role="language-chip"]')).toHaveText('AM');
    await expect(row.locator('[data-role="sentiment-dot"]')).toHaveAttribute(
        'aria-label',
        'Sentiment: negative',
    );
    await expect(row.locator('[data-role="priority-dot"]')).toHaveAttribute(
        'aria-label',
        'Priority: urgent',
    );
    await expect(row.locator('[data-role="tag-overflow"]')).toContainText('withdrawal +2');
    await expect(row.locator('[data-role="assignee-avatar"]')).toBeVisible();
    await expect(row.getByText('#4801')).toBeVisible();

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(axe.violations, JSON.stringify(axe.violations.map((v) => v.id))).toEqual([]);
});

test('FR-09 Unassigned: EC-11 photo placeholder (localized, never a storage key), WindowChip consumed, draft pencil, unassigned ring — and the Doc 05 EC-04 pair: two badges, ONE older-activity warning', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();

    const kidist = page.locator('[data-ticket]', { hasText: 'Kidist Haile' });
    await expect(kidist).toBeVisible();
    await expect(kidist.locator('[data-role="snippet"]')).toHaveText('📷 Photo');
    await expect(kidist.getByText(/^Window \d/).first()).toBeVisible(); // the SHARED WindowChip, closing
    await expect(kidist.locator('[data-role="draft-pencil"]')).toBeVisible();
    await expect(kidist.locator('[data-role="unassigned-ring"]')).toBeVisible();

    // EC-04: both of the hybrid customer's rows carry the badge; exactly the
    // STALE one warns.
    const hybridRows = page.locator('[data-ticket]', { hasText: 'Hana Girma' });
    await expect(hybridRows).toHaveCount(2);
    await expect(page.locator('[data-role="dual-channel-badge"]')).toHaveCount(2);
    await expect(page.locator('[data-role="older-activity-warning"]')).toHaveCount(1);
    await expect(
        page.getByRole('img', {
            name: 'Older activity — this customer is now active in another conversation',
        }),
    ).toBeVisible();
});

test('FR-10: the sort override reorders (priority serves urgent first) and PERSISTS per view across a fresh session (prefs.sort_overrides)', async ({
    page,
    browser,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    // Unassigned-only content proves the switch LANDED (the refetch is async).
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();

    await page.getByTestId('sort-select').selectOption('priority');
    await expect(page.getByTestId('sort-select')).toHaveValue('priority');
    await expect(page.locator('[data-ticket]').first()).toBeVisible();

    // Give the prefs PATCH its round-trip.
    await expect
        .poll(async () => {
            const response = await page.request.get('/inbox/prefs');
            const body = (await response.json()) as {
                prefs?: { sort_overrides?: Record<string, string> };
            };
            return body.prefs?.sort_overrides?.unassigned;
        })
        .toBe('priority');

    // A fresh session: the override is still this view's order.
    const fresh = await browser.newContext({ ignoreHTTPSErrors: true });
    const page2 = await fresh.newPage();
    await login(page2);
    await page2.setViewportSize(V1440);
    await page2.goto('/inbox');
    await dismissCoachMarks(page2);
    await page2.locator('[data-view="unassigned"]').first().click();
    await expect(page2.getByTestId('sort-select')).toHaveValue('priority');

    // Restore the default for other specs.
    await page2.getByTestId('sort-select').selectOption('');
    await expect
        .poll(async () => {
            const response = await page2.request.get('/inbox/prefs');
            const body = (await response.json()) as {
                prefs?: { sort_overrides?: Record<string, string> };
            };
            return body.prefs?.sort_overrides?.unassigned ?? 'cleared';
        })
        .toBe('cleared');
    await fresh.close();
});

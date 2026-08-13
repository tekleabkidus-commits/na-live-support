import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 14 — B1-S04's merge journey, drawn whole and run against the
 * real machinery: select the Hana pair (Doc 05 EC-04's same-customer
 * fixtures) → the BulkBar's Merge → the dialog with the SERVER-COMPUTED
 * "What happens" → execute → the sources close with permanent banners.
 * EC-12 rides the same journey as B12-A01 AC-08's executed form: a draft
 * typed in a SOURCE thread before the merge SURVIVES it (drafts key by
 * conversation — structural), and D12's banner opens the FINAL survivor.
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

function ticketField(number: number, column: string): string {
    return execFileSync(
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
            'tinker',
            '--execute',
            `echo json_encode(DB::connection("pgsql_platform")->table("tickets")->where("number", ${number})->value("${column}"));`,
        ],
        { encoding: 'utf8' },
    ).trim();
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

test.afterAll(() => {
    // The heal restores the Hana pair's canon (statuses, merged_into).
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function openUnassigned(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    await expect(page.locator('[data-ticket]', { hasText: 'Hana Girma' }).first()).toBeVisible();
}

test('THE MERGE JOURNEY + EC-12 + D12: a draft typed in the source survives the merge; the dialog renders the machinery-computed preview; the sources close with a permanent banner that opens the FINAL survivor', async ({
    page,
}) => {
    const run = Date.now().toString(36);
    await openUnassigned(page);

    // EC-12 setup: a draft in the FIRST Hana thread (the eventual source or
    // target — either way it must survive; drafts key by conversation).
    const hanaRows = page.locator('[data-ticket]', { hasText: 'Hana Girma' });
    await hanaRows.first().locator('[data-role="row-open"]').click();
    await expect(page.getByTestId('composer')).toBeVisible();
    const draftText = `merge-survivor draft ${run}`;
    await page.getByTestId('composer-input').fill(draftText);
    await expect(page.getByTestId('draft-chip')).toBeVisible();
    // The panel lags the thread (its doors fetch separately) — reading the
    // number before it settles branded the WRONG thread as drafted.
    await expect(page.getByTestId('ticket-panel-number')).toHaveText(/^#\d+$/);
    const openedNumber = await page.getByTestId('ticket-panel-number').textContent();

    // Select BOTH Hana rows → the BulkBar's Merge appears.
    await hanaRows.nth(0).locator('[data-role="row-select"]').check();
    await hanaRows.nth(1).locator('[data-role="row-select"]').check();
    await page.getByTestId('bulk-merge').click();

    // The dialog: same-customer truth, the target card, the computed panel.
    await expect(page.getByTestId('merge-dialog')).toBeVisible();
    await expect(page.getByTestId('merge-dialog')).toContainText('Hana Girma');
    await expect(page.getByTestId('merge-target-card')).toBeVisible();
    await expect(page.getByTestId('merge-what-happens')).toContainText('Reversible for 24 hours');
    // Two tickets = one source: no phrase gate (the drawn rule is >2).
    await expect(page.getByTestId('merge-phrase')).toHaveCount(0);
    await page.getByTestId('merge-confirm').click();
    await expect(page.getByTestId('merge-dialog')).toHaveCount(0);

    // The machinery's truth: exactly one of the pair carries merged_into.
    await expect
        .poll(() => {
            const a = ticketField(4803, 'merged_into');
            const b = ticketField(4899, 'merged_into');
            return [a, b].filter((v) => v !== 'null').length;
        })
        .toBe(1);
    const sourceNumber = ticketField(4803, 'merged_into') !== 'null' ? 4803 : 4899;
    const survivorNumber = sourceNumber === 4803 ? 4899 : 4803;
    // Non-vacuous by construction: the drafted thread IS one of the pair,
    // so exactly one EC-12 branch below must run.
    expect([`#${sourceNumber}`, `#${survivorNumber}`]).toContain(openedNumber);

    // EC-12 + D12: the SOURCE thread — reached by deep link — still holds
    // the draft when it was the drafted one, and always shows the banner.
    const sourceId = ticketField(sourceNumber, 'id').replaceAll('"', '');
    await page.goto(`/inbox/t/${sourceId}`);
    await expect(page.getByTestId('merged-banner')).toBeVisible();
    await expect(page.getByTestId('merged-banner')).toContainText(`#${survivorNumber}`);
    if (openedNumber === `#${sourceNumber}`) {
        await expect(page.getByTestId('composer-input')).toHaveValue(draftText);
    }

    // D12's affordance: the banner opens the FINAL survivor in place.
    await page.getByTestId('merged-open-survivor').click();
    await expect(page.getByTestId('ticket-panel-number')).toHaveText(`#${survivorNumber}`);

    // EC-12's other half: when the DRAFTED thread was the survivor, the
    // draft is here instead — either way it survived the merge.
    if (openedNumber === `#${survivorNumber}`) {
        await expect(page.getByTestId('composer-input')).toHaveValue(draftText);
    }
});

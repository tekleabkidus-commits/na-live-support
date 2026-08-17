import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 08 FR-20/EC-08/FS-01 — the directory (S08 step 12; B4-S06 G01–G09).
 * G01 table + filters + bulk sentence · EC-08 the collision pair told apart
 * in place · G03 the five-filter bar for the viewer WITHOUT the RG verdict,
 * with the SAME header count · G04 the historical-username hit explaining
 * itself · G05 Amharic search · G06 the labeled degrade (UI half — the
 * server half is DirectoryTest's dead-port drill; the banner renders off
 * the door's own `degraded: true`, intercepted here) · G09 the honest
 * empty state · G02 375 cards + the dialled phone search.
 */

const PASSWORD = 'browser-fixture-password';
const RG_VIEWER = 'customers-agent@fixture.example';
const NO_RG_VIEWER = 'customers-norg@fixture.example';

function artisan(...args: string[]): string {
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
            ...args,
        ],
        { stdio: 'pipe' },
    ).toString();
}

test.beforeAll(() => {
    artisan('auth:session-inspect', 'clear-throttle');
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\CustomerBrowserSeeder', '--force');
});

async function login(page: Page, email: string = RG_VIEWER): Promise<void> {
    artisan('auth:session-inspect', 'clear-throttle');
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('G01/EC-08: the table — scope count in the header, the collision pair told apart by short id, the blocked badge, identities with @username and the NATIONAL phone, the bulk bar limited to tags and export', async ({
    page,
}) => {
    await login(page);
    await page.goto('/customers');
    await expect(page.getByTestId('directory-page')).toBeVisible();

    // The header count ("N in your scope").
    await expect(page.getByTestId('directory-total')).toBeVisible();
    expect((await page.getByTestId('directory-total').innerText()).trim()).toMatch(/\d/);

    const table = page.getByTestId('directory-table');
    await expect(table).toBeVisible();

    // The dev directory holds hundreds of fixture customers, so every row
    // assertion drives through ITS OWN filter — which is the filter proof.
    // Tag filter → the enriched scene row, identities as reachable values.
    await page.getByTestId('filter-tag').fill('vip');
    await page.getByTestId('filter-tag').blur();
    const abebeRow = table.locator('tr', { hasText: 'አበበ ታደሰ' }).first();
    await expect(abebeRow).toBeVisible();
    await expect(abebeRow).toContainText('@fixture_abebe_t');
    await expect(abebeRow).toContainText('091 234 4417'); // national, never +251 (§14/I08)

    // EC-08: BOTH same-name rows visible, each with its short id.
    await page.getByTestId('filter-tag').fill('name-collision');
    await page.getByTestId('filter-tag').blur();
    const collisions = table.locator('tr', { hasText: 'አቤል ተስፋዬ' });
    await expect(collisions).toHaveCount(2);
    await expect(collisions.nth(0).getByTestId('row-short-id')).toBeVisible();
    await expect(collisions.nth(1).getByTestId('row-short-id')).toBeVisible();

    // The blocked filter → the blocked row wearing its badge.
    await page.getByTestId('filter-tag').fill('');
    await page.getByTestId('filter-tag').blur();
    await page.getByTestId('filter-blocked').selectOption('1');
    await expect(
        table.locator('tr', { hasText: 'Blocked Fixture' }).getByTestId('row-blocked-badge'),
    ).toBeVisible();
    await page.getByTestId('filter-clear-all').click();

    // The RG filter EXISTS for this viewer (G03's counterpart).
    await expect(page.getByTestId('filter-rg')).toBeVisible();

    // The bulk bar: tags and export only, and it says so.
    await page.getByTestId('filter-tag').fill('vip');
    await page.getByTestId('filter-tag').blur();
    await table
        .locator('tr', { hasText: 'አበበ ታደሰ' })
        .first()
        .locator('input[type=checkbox]')
        .check();
    const bulkBar = page.getByTestId('directory-bulk-bar');
    await expect(bulkBar).toBeVisible();
    await expect(bulkBar.getByTestId('bulk-tag-input')).toBeVisible();
    await expect(bulkBar.getByTestId('bulk-export')).toBeVisible();
    await expect(bulkBar).toContainText('one at a time, on purpose');

    // §13: axe over the whole filled page.
    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .include('[data-testid="directory-page"]')
        .analyze();
    expect(axe.violations).toEqual([]);
});

test('G03: the viewer WITHOUT customers.rg_flag.view gets FIVE filters — no RG control, no gap, no dot anywhere — and the SAME header count', async ({
    page,
    browser,
}) => {
    await login(page, NO_RG_VIEWER);
    await page.goto('/customers');
    await expect(page.getByTestId('directory-table')).toBeVisible();

    // Five controls, not six: the RG filter does not exist here.
    await expect(page.getByTestId('filter-has-open')).toBeVisible();
    await expect(page.getByTestId('filter-language')).toBeVisible();
    await expect(page.getByTestId('filter-country')).toBeVisible();
    await expect(page.getByTestId('filter-tag')).toBeVisible();
    await expect(page.getByTestId('filter-blocked')).toBeVisible();
    await expect(page.getByTestId('filter-rg')).toHaveCount(0);

    // The flagged customer is findable and renders with NO trace — and the
    // whole page never speaks the phrase this viewer has no verdict for.
    await page.getByTestId('directory-search').fill('fixture_dawit_b');
    await expect(page.getByTestId('directory-results')).toContainText('Dawit Bekele');
    expect(await page.locator('body').innerText()).not.toContain('Responsible');
    await page.getByTestId('directory-search').fill('');
    await expect(page.getByTestId('directory-table')).toBeVisible();

    // "the count at the top of the page is the same for both viewers" —
    // read BOTH inside this test, polled: the profile spec's merge arc runs
    // in a parallel worker and a mid-arc tombstone legitimately moves the
    // count for a few seconds (both viewers converge to the same number).
    const rgContext = await browser.newContext();
    const rgPage = await rgContext.newPage();
    await login(rgPage);
    await rgPage.goto('/customers');
    await expect
        .poll(
            async () => {
                await rgPage.reload();
                await page.reload();
                const rgTotal = (await rgPage.getByTestId('directory-total').innerText()).trim();
                const noRgTotal = (await page.getByTestId('directory-total').innerText()).trim();
                return rgTotal === noRgTotal ? 'equal' : `${rgTotal} vs ${noRgTotal}`;
            },
            { timeout: 20_000 },
        )
        .toBe('equal');
    await rgContext.close();
});

test("G04: searching an old username finds the customer AND says which name matched — 'now' naming the current one", async ({
    page,
}) => {
    await login(page);
    await page.goto('/customers');
    await page.getByTestId('directory-search').fill('fixture_abebe1994');

    const results = page.getByTestId('directory-results');
    await expect(results).toContainText('አበበ ታደሰ');
    const matched = results.locator('li', { hasText: 'አበበ ታደሰ' }).getByTestId('matched-line');
    await expect(matched).toContainText('@fixture_abebe1994');
    await expect(matched).toContainText('@fixture_abebe_t');
    await expect(matched).toContainText('2026-06-02'); // "used until" — the history stamp
});

test('G05: Amharic search matches on its own terms — the first part of a name finds its holder', async ({
    page,
}) => {
    await login(page);
    await page.goto('/customers');
    await page.getByTestId('directory-search').fill('አበበ');
    await expect(page.getByTestId('directory-results')).toContainText('አበበ ታደሰ');
});

test('G06 (UI half): a degraded answer renders the reduced-mode banner naming what is missing, and hits say how they matched — the SERVER half is the pest dead-port drill', async ({
    page,
}) => {
    await login(page);
    await page.goto('/customers');
    // The banner renders off the door's own honest label; the door's
    // behavior under a dead Meilisearch is DirectoryTest's FS-01 proof.
    await page.route('**/api/staff/customers/search*', (route) =>
        route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                degraded: true,
                hits: [
                    {
                        id: '00000000-0000-7000-8000-000000000001',
                        display_name: 'Degrade Fixture',
                        matched: {
                            on: 'name',
                            value: 'Degrade Fixture',
                            used_until: null,
                            current_username: null,
                        },
                    },
                ],
            }),
        }),
    );
    await page.getByTestId('directory-search').fill('degrade');

    const banner = page.getByTestId('directory-degraded');
    await expect(banner).toBeVisible();
    await expect(banner).toContainText('names and phone numbers only');
    await expect(banner).toContainText('not searchable while this lasts');
    await expect(page.getByTestId('matched-line').first()).toContainText('matched on name');
});

test('G09: nobody matches — the empty state says WHAT was searched and across how many, and the clear button restores the table', async ({
    page,
}) => {
    await login(page);
    await page.goto('/customers');
    await page.getByTestId('directory-search').fill('zz-nobody-matches-zz');

    const empty = page.getByTestId('directory-search-empty');
    await expect(empty).toBeVisible();
    await expect(empty).toContainText('zz-nobody-matches-zz');
    await expect(empty).toContainText('player ID'); // the searched-fields sentence
    await page.getByTestId('directory-clear-search').click();
    await expect(page.getByTestId('directory-table')).toBeVisible();
});

test('G02 (375): the table becomes cards — who, how to reach them, open count, how long ago — and a phone typed AS DIALLED finds its holder', async ({
    page,
}) => {
    await page.setViewportSize({ width: 375, height: 720 });
    await login(page);
    await page.goto('/customers');

    // Cards, not the seven-column table (it exists for wider screens, hidden).
    await expect(page.getByTestId('directory-cards')).toBeVisible();
    await expect(page.getByTestId('directory-table')).toBeHidden();
    await page.getByTestId('filter-tag').fill('vip');
    await page.getByTestId('filter-tag').blur();
    const abebeCard = page
        .getByTestId('directory-cards')
        .locator('li', { hasText: 'አበበ ታደሰ' })
        .first();
    await expect(abebeCard).toBeVisible();

    // Typed as dialled (0912…), matched against the stored +2519….
    await page.getByTestId('directory-search').fill('0912344417');
    const results = page.getByTestId('directory-results');
    await expect(results).toContainText('አበበ ታደሰ');
    await expect(results.getByTestId('matched-line').first()).toContainText('matched on phone');
});

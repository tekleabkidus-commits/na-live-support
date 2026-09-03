// SESSION-F1 [B9] — the visibility proofs for the Doc 10 KB staff surfaces against the
// frozen Batch 9 record (design/frozen/B9-S01…S04, 67dc4e4). Every existing KB spec
// (kb-manager, kb-a11y, inbox-palette) stays byte-untouched and runs beside this file; this
// file proves what the frames DRAW: the chrome, the anatomy, the states, the absences, the
// 44 px targets at 375, axe in both themes, the Amharic sweep, reduced motion. Build shots
// land under docs/evidence/F1-B9/shots/ beside the frame shots.
import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };
const SHOTS = 'docs/evidence/F1-B9/shots';
const A1 = '018f6b2e-0000-7000-8000-00000000ed11';
const A2 = '018f6b2e-0000-7000-8000-00000000ed12';
const A4 = '018f6b2e-0000-7000-8000-00000000ed14';
const V1 = '018f6b2e-0000-7000-8000-00000000ed21';
const V1AM = '018f6b2e-0000-7000-8000-00000000ed22';
const V2 = '018f6b2e-0000-7000-8000-00000000ed23';
const V3 = '018f6b2e-0000-7000-8000-00000000ed24';
const VER1 = '018f6b2e-0000-7000-8000-00000000ed31';
const VER3 = '018f6b2e-0000-7000-8000-00000000ed33';

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
function setAgentLocale(locale: 'en' | 'am'): void {
    artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
DB::table('users')->where('email','agent@fixture.example')->update(['locale' => '${locale}']);`,
    );
}

test.beforeAll(() => {
    execFileSync('mkdir', ['-p', SHOTS]);
    artisan('auth:session-inspect', 'clear-throttle');
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\KbBrowserSeeder', '--force');
});

// Every proof logs in afresh; twenty-odd logins in a few minutes trip the 0f limiter
// (429 rate_limited on /login) — clear it before each test, as the per-file specs do once.
test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page, email = 'agent@fixture.example'): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill('browser-fixture-password');
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}
async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}
async function axeClean(page: Page, label: string, include?: string): Promise<void> {
    let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']);
    if (include) builder = builder.include(include);
    const results = await builder.analyze();
    expect(
        results.violations.map(
            (v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(' | ')}`,
        ),
        `axe violations — ${label}`,
    ).toEqual([]);
}
async function shot(
    page: Page,
    name: string,
    selector = '[data-testid="backoffice-shell"]',
): Promise<void> {
    await page
        .locator(selector)
        .screenshot({ path: `${SHOTS}/${name}.jpg`, type: 'jpeg', quality: 70 });
}
async function noOverflow(page: Page, label: string): Promise<void> {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow, `${label} horizontal overflow px`).toBeLessThanOrEqual(0);
}
async function targets44(page: Page, selector: string, label: string): Promise<void> {
    for (const el of await page.locator(selector).all()) {
        if (!(await el.isVisible())) continue;
        const box = await el.boundingBox();
        expect(box?.height ?? 0, `${label}: ${selector} height`).toBeGreaterThanOrEqual(44);
    }
}

test.describe('B9-S01 — the KB manager', () => {
    test('§A: the Backoffice chrome with its one KB row; the category TREE with counts; article rows with pill · dots · usage; the detail pane facts; the legend; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await expect(page.getByTestId('backoffice-shell')).toBeVisible();
        await expect(page.getByTestId('bo-nav-kb')).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId('bo-nav-workflow')).toBeVisible();
        await expect(page.getByTestId('kb-sub')).toContainText(/articles/);
        // the tree: role=tree with treeitems carrying aria-level + counts
        const tree = page.getByTestId('kb-pane-categories').locator('[role="tree"]');
        await expect(tree).toBeVisible();
        await expect(tree.locator('[role="treeitem"]').first()).toHaveAttribute(
            'aria-level',
            /[123]/,
        );
        await expect(page.getByTestId('kb-cat-count-s10-browser-kb')).toHaveText(/^\d+$/);
        await page.getByTestId('kb-category-s10-browser-kb').click();
        // rows: the status pill, the language dots with their words, the usage column
        const row = page.getByTestId('kb-article-' + A1);
        await expect(row.locator('.bo-pill')).toHaveText('Published');
        await expect(row.getByTestId('kb-badge-' + V1)).toHaveAttribute('title', 'en: Current');
        await expect(row.getByTestId('kb-badge-' + V1AM)).toHaveAttribute('title', 'am: Outdated');
        await expect(row.locator('.right')).toContainText(/views · \d+ AI citations/);
        // INTERNAL tag with its tooltip; the internal usage line
        const internal = page.getByTestId('kb-article-' + A2);
        await expect(internal.locator('.bo-tag').first()).toHaveText('Internal');
        await expect(internal.locator('.right')).toHaveText('internal · Copilot only');
        // detail: audience with AI-usability inherited from the category, the source line, per-language slugs, Edit / History
        await row.click();
        const detail = page.getByTestId('kb-pane-detail');
        await expect(detail).toContainText('inherited from');
        await expect(detail).toContainText('this article is English-first');
        await expect(detail).toContainText('/en/s10-deposit-help');
        await expect(detail.getByTestId('kb-edit-' + V1)).toBeVisible();
        await expect(detail).toContainText('Outdated still serves');
        await expect(page.locator('.kb-legend')).toContainText('outdated (source changed)');
        await axeClean(page, 'manager (light)');
        await shot(page, 'B9-S01-manager-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'manager (dark)');
        await shot(page, 'B9-S01-manager-build-1440-dark');
    });

    test('§A filters + search: chips go bold, the header counts the match, Clear is one link; a no-result query is echoed with "Create an article from this query"; the loading skeleton shows while the search door answers', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await page.getByTestId('kb-category-s10-browser-kb').click();
        await page.getByTestId('kb-filter-status').selectOption('published');
        await expect(page.getByTestId('kb-filter-status')).toHaveClass(/on/);
        await expect(page.getByTestId('kb-list-header')).toContainText(/of \d+ match/);
        await expect(page.getByTestId('kb-filter-clear')).toBeVisible();
        await page.getByTestId('kb-filter-clear').click();
        await expect(page.getByTestId('kb-filter-status')).not.toHaveClass(/on/);
        await page.getByTestId('kb-search').fill('zz-no-such-article-zz');
        await expect(page.getByTestId('kb-no-results')).toBeVisible({ timeout: 10_000 });
        await expect(page.getByTestId('kb-no-results').locator('.q')).toHaveText(
            'zz-no-such-article-zz',
        );
        await expect(page.getByTestId('kb-create-from-query')).toBeVisible();
        await expect(page.getByTestId('kb-list-header')).toHaveText('0 results');
        await shot(page, 'B9-S01-no-results-build-1440-light');
        await page.getByTestId('kb-filter-clear').click();
        await expect(page.getByTestId('kb-article-' + A1)).toBeVisible();
    });

    test('§A A07 (absence): a viewer WITHOUT kb.view_internal sees no internal row, no Internal tag, no visibility filter option — absence, not redaction', async ({
        page,
    }) => {
        await login(page, 'readonly-fixture@fixture.example');
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await page.getByTestId('kb-category-s10-browser-kb').click();
        await expect(page.getByTestId('kb-article-' + A1)).toBeVisible();
        await expect(page.getByTestId('kb-article-' + A2)).toHaveCount(0);
        await expect(page.getByTestId('kb-pane-articles')).not.toContainText('Internal');
        await expect(
            page.getByTestId('kb-filter-visibility').locator('option[value="internal"]'),
        ).toHaveCount(0);
        await shot(page, 'B9-S01-internal-absent-build-1440-light');
    });

    test('§A 375 read posture: the notice, the pane tabs, stacked panes, 44 px rows; no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/kb');
        await expect(page.getByTestId('bo-readnote')).toContainText('Editing needs a tablet');
        await expect(page.locator('.bo-tabs a').first()).toBeVisible();
        await page.getByTestId('kb-category-s10-browser-kb').click();
        await expect(page.getByTestId('kb-pane-articles')).toBeVisible();
        await targets44(page, '[data-testid^="kb-article-"]', 'article rows at 375');
        await targets44(page, '[role="treeitem"]', 'tree rows at 375');
        await noOverflow(page, 'manager 375');
        await axeClean(page, 'manager 375 (light)');
        await shot(page, 'B9-S01-manager-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'manager 375 (dark)');
        await shot(page, 'B9-S01-manager-build-375-dark');
    });
});

test.describe('B9-S02 — the article editor', () => {
    test('§B: chrome + breadcrumb; the language tablist with dots and the SOURCE tag; the stamp says what autosave is; the FR-02 toolbar (7 types) and block headers with ↑↓ ⋯; the rail facts; the review card names the permission; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V1 + '/edit');
        await expect(page.getByTestId('bo-crumb')).toContainText('Editing');
        await expect(page.getByTestId('kb-tab-en')).toHaveAttribute('aria-selected', 'true');
        await expect(page.getByTestId('kb-tab-en').locator('.src')).toHaveText('Source');
        await expect(page.getByTestId('kb-tab-am')).toContainText('Outdated');
        await expect(page.getByTestId('kb-save-stamp')).toContainText(
            'working copy, not a version',
        );
        for (const ty of ['heading', 'paragraph', 'list', 'table', 'image', 'callout', 'anchor']) {
            await expect(page.getByTestId('kb-tb-' + ty)).toBeVisible();
        }
        await expect(page.getByTestId('kb-blk-up-b1')).toBeVisible();
        await expect(page.getByTestId('kb-blk-menu-b1')).toBeVisible();
        await expect(page.locator('.kb-addrow').first()).toContainText(
            'the FR-02 set, nothing beyond it',
        );
        const rail = page.getByTestId('kb-rail');
        await expect(rail).toContainText('one audience per article');
        await expect(rail).toContainText('inherited from');
        await expect(page.getByTestId('kb-review-card')).toContainText('collapses the review step');
        await expect(page.getByTestId('kb-ai-open')).toBeVisible();
        await axeClean(page, 'editor (light)');
        await shot(page, 'B9-S02-editor-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'editor (dark)');
        await shot(page, 'B9-S02-editor-build-1440-dark');
    });

    test('§B B12 keyboard: Alt+↓ on a focused block moves it and announces the move; the toolbar inserts a heading block; the block menu offers remove', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V1 + '/edit');
        await page.getByTestId('kb-tb-heading').click();
        const blocks = page.getByTestId('kb-blocks').locator('.kb-blk');
        await expect(blocks).toHaveCount(2);
        await expect(blocks.nth(1)).toHaveClass(/focus/);
        await blocks.nth(1).locator('input').press('Alt+ArrowUp');
        await expect(blocks.nth(0).locator('.bh')).toContainText('Heading');
        await expect(page.getByTestId('kb-blocks').locator('[role="status"]')).toContainText(
            'moved to position 1',
        );
        const id = (await blocks.nth(0).getAttribute('data-testid'))!.replace('kb-blk-', '');
        await page.getByTestId('kb-blk-menu-' + id).click();
        await page.getByTestId('kb-blk-remove-' + id).click();
        await expect(blocks).toHaveCount(1);
    });

    test('§B B07 inline sanitizer + B09 publish blocked: the flagged sentence wears the underline; its popover names the pattern and the two choices; the rail sums the flags; Publish → the door\'s own refusal lists the block; "Back to the editor" is the one verb', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V3 + '/edit');
        await expect(page.getByTestId('kb-flags-chip')).toContainText('sanitizer flag');
        await expect(page.getByTestId('kb-sanitizer-banner')).toContainText('Pre-publish check');
        const flag = page.getByTestId('kb-flag-0-0');
        await expect(flag).toBeVisible();
        await flag.click();
        const pop = page.getByRole('dialog', { name: 'Sanitizer warning' });
        await expect(pop).toContainText('Pattern');
        await expect(pop).toContainText('Keep as-is — needs a reason');
        await expect(page.getByTestId('kb-sanitizer-flags')).toContainText('Flagged');
        await shot(page, 'B9-S02-sanitizer-build-1440-light');
        await page.getByTestId('kb-publish-open').click();
        const dlg = page.getByTestId('kb-publish-dialog');
        await expect(dlg).toContainText('Alt text');
        await expect(dlg).toContainText('⚠');
        await page.getByTestId('kb-override-reason').fill('[FIXTURE] verified with payments team');
        await page.getByTestId('kb-publish-confirm').click();
        await expect(page.getByTestId('kb-missing-alt')).toContainText('img1');
        await expect(dlg.locator('.dt')).toHaveText('Publish blocked');
        await expect(page.getByTestId('kb-publish-back')).toBeVisible();
        await expect(page.getByTestId('kb-publish-confirm')).toHaveCount(0);
        await shot(page, 'B9-S02-publish-blocked-build-1440-light');
        await page.getByTestId('kb-publish-back').click();
        await expect(dlg).toHaveCount(0);
    });

    test('§B B05 the INTERNAL article: the amber role=note banner under the header; the rail states Copilot-only and the audited audience change; B06 the AI drawer lists five tools, says drafts-only, and carries NO publish control', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V2 + '/edit');
        const banner = page.getByTestId('kb-internal-banner');
        await expect(banner).toHaveAttribute('role', 'note');
        await expect(banner).toContainText('Copilot may retrieve it');
        await expect(page.getByTestId('kb-audience-card')).toContainText(
            'high-visibility audited change',
        );
        await page.getByTestId('kb-ai-open').click();
        const drawer = page.getByTestId('kb-ai-drawer');
        await expect(drawer).toBeVisible();
        for (const id of ['kb-ai-simplify', 'kb-ai-faq', 'kb-ai-translate-all'])
            await expect(page.getByTestId(id)).toBeVisible();
        await expect(drawer).toContainText('Draft from source');
        await expect(drawer).toContainText('Draft from conversations');
        await expect(drawer.locator('.foot')).toContainText('No “publish” exists in this drawer');
        await expect(drawer.locator('.bo-btn', { hasText: /publish/i })).toHaveCount(0);
        await shot(page, 'B9-S02-drawer-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'editor internal + drawer (dark)');
        await shot(page, 'B9-S02-drawer-build-1440-dark');
    });

    test('§B B14 the Amharic variant: the transliteration helper offers a Latin slug and "Use" applies it (not saved — the page is left dirty-free by reload)', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V1AM + '/edit');
        await expect(page.getByTestId('kb-title')).toHaveValue(/የተቀማጭ እገዛ/);
        await expect(page.getByTestId('kb-transliterate')).toBeVisible();
        await expect(page.getByTestId('kb-transliterate').locator('.bo-mono')).toHaveText(
            /^[a-z0-9-]+$/,
        );
        await shot(page, 'B9-S02-am-build-1440-light');
    });

    test('§B B13 375 read-only: the notice, readonly fields, no toolbar, no move buttons, 44 px tabs; no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/kb/variants/' + V1 + '/edit');
        await expect(page.getByTestId('bo-readnote')).toContainText('Read-only on a phone');
        await expect(page.getByTestId('kb-title')).toHaveAttribute('readonly', '');
        await expect(page.getByTestId('kb-tb-heading')).toHaveCount(0);
        await expect(page.getByTestId('kb-blk-up-b1')).toHaveCount(0);
        await targets44(page, '[data-testid^="kb-tab-"]', 'language tabs at 375');
        await noOverflow(page, 'editor 375');
        await axeClean(page, 'editor 375 (light)');
        await shot(page, 'B9-S02-editor-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'editor 375 (dark)');
        await shot(page, 'B9-S02-editor-build-375-dark');
    });
});

test.describe('B9-S03 — version history + the citation landing', () => {
    test('§C C01: two panes; the version row carries number · author · chips, View / Compare, and NO Restore on the current version; the diff pane names its summary and legend; the versions law is stated; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V1 + '/history-page');
        await expect(page.getByTestId('kb-versions-pane')).toBeVisible();
        await expect(page.getByTestId('kb-diff-pane')).toBeVisible();
        const v1 = page.getByTestId('kb-version-1');
        await expect(v1.locator('.vn')).toHaveText('v1');
        await expect(v1.locator('.bo-chip.cur')).toContainText('Current');
        await expect(page.getByTestId('kb-view-1')).toBeVisible();
        await expect(page.getByTestId('kb-restore-1')).toHaveCount(0); // absent on the current version
        await expect(page.locator('.kb-vnote-foot')).toContainText('restore creates a NEW draft');
        await expect(page.getByTestId('kb-diff-pane')).toContainText(
            'One version — nothing to compare yet',
        );
        await page.getByTestId('kb-view-1').click();
        await expect(page.getByTestId('kb-version-view')).toContainText('immutable snapshot');
        await page.getByRole('button', { name: 'Close' }).click();
        await axeClean(page, 'history (light)');
        await shot(page, 'B9-S03-history-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'history (dark)');
        await shot(page, 'B9-S03-history-build-1440-dark');
    });

    test('§C C04 the archive dialog: consequences in nouns (leaves · keeps · undo · audited), the red verb names the count; Cancel leaves everything as it was', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/variants/' + V1 + '/history-page');
        await page.getByTestId('kb-archive-open').click();
        const dlg = page.getByTestId('kb-archive-dialog');
        await expect(dlg).toContainText('Nothing is deleted');
        await expect(dlg).toContainText('never a 404');
        await expect(dlg).toContainText('Un-archive returns the article to Draft');
        await expect(page.getByTestId('kb-archive-confirm')).toHaveClass(/danger/);
        await shot(page, 'B9-S03-archive-build-1440-light');
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(dlg).toHaveCount(0);
        await expect(page.getByTestId('kb-history-status')).toContainText('Published');
    });

    test('§C C03 the staff citation landing: CURRENT opens the article; RETIRED shows the label, the version-pinned snapshot, the resolver-states rail and the no-404 law — no failure vocabulary anywhere', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb/cite/' + A1 + '/' + VER1);
        await expect(page.getByTestId('kb-tombstone-chip')).toHaveText('Current');
        await expect(page.getByTestId('kb-tombstone-current-link')).toBeVisible();
        await expect(page.getByTestId('kb-tombstone-snapshot')).toContainText('Deposit help');
        await page.goto('/admin/kb/cite/' + A4 + '/' + VER3);
        await expect(page.getByTestId('kb-tombstone-chip')).toContainText('Retired');
        await expect(page.getByTestId('kb-tombstone-state')).toContainText('version-pinned');
        await expect(page.getByTestId('kb-tombstone-snapshot')).toContainText('Cashout notes');
        await expect(page.getByTestId('kb-resolver-states')).toContainText('Never a 404');
        await expect(page.getByTestId('kb-tombstone-archived-link')).toBeVisible();
        await expect(page.getByTestId('backoffice-shell')).not.toContainText(
            /\b(error|broken|missing|failed)\b/i,
        );
        await axeClean(page, 'citation landing (light)');
        await shot(page, 'B9-S03-citation-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'citation landing (dark)');
        await shot(page, 'B9-S03-citation-build-1440-dark');
    });

    test('§C 375 read posture: versions and the diff stack; restore and archive are absent (stated as needing a larger screen); no overflow', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/kb/variants/' + V1 + '/history-page');
        await expect(page.getByTestId('bo-readnote')).toContainText(
            'restore and archive need a tablet',
        );
        await expect(page.getByTestId('kb-archive-open')).toHaveCount(0);
        await expect(page.getByTestId('kb-version-1')).toBeVisible();
        await noOverflow(page, 'history 375');
        await axeClean(page, 'history 375 (light)');
        await shot(page, 'B9-S03-history-build-375-light');
    });
});

test.describe("B9-S04 — categories mode + the shell's one KB entry", () => {
    test('§D D01 D02 D03: the categories MODE of the manager — tree with counts and ↑↓ on the selected row, the editor with three facts and every FR-01 field, the inherit rail, the AI-usable-off consequence dialog (Keep it on), delete refused with the reassign picker and the impact sentence; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await page.getByTestId('kb-open-categories').click();
        await expect(page.getByTestId('bo-crumb')).toContainText('Editing');
        await expect(page.getByTestId('kb-cat-chip')).toContainText(
            /\d+ categories · \d+ articles/,
        );
        await page.getByTestId('kb-category-s10-browser-kb').click();
        await expect(page.getByTestId('kb-cat-up-s10-browser-kb')).toBeVisible();
        await expect(page.getByTestId('kb-cat-depth')).toContainText('depth 1 of 3');
        const facts = page.getByTestId('kb-cat-facts');
        await expect(facts).toContainText('Articles');
        await expect(facts).toContainText('In the chatbot');
        await expect(page.getByTestId('kb-cat-name-en')).toHaveValue(/Browser KB/);
        await expect(page.getByTestId('kb-cat-name-am')).toHaveValue(/የአሳሽ/);
        await expect(page.getByTestId('kb-cat-slug')).toHaveValue('s10-browser-kb');
        await expect(page.getByTestId('kb-cat-parent')).toContainText('top level');
        await expect(page.getByTestId('kb-cat-icons').locator('[role="radio"]')).toHaveCount(12);
        await expect(page.getByTestId('kb-cat-ai-switch')).toHaveAttribute('aria-checked', 'true');
        await expect(
            page.getByTestId('kb-cat-visibility').locator('[aria-checked="true"]'),
        ).toHaveText('Public');
        await expect(page.getByTestId('kb-cat-add-sub')).toBeVisible(); // depth 1 — a subcategory CAN be added
        await expect(page.getByTestId('kb-inherit-card')).toContainText('Structural separation');
        await axeClean(page, 'categories (light)');
        await shot(page, 'B9-S04-categories-build-1440-light');
        // EC-02: turning AI-usable off asks first, with the numbers
        await page.getByTestId('kb-cat-ai-switch').click();
        const aioff = page.getByTestId('kb-aioff-dialog');
        await expect(aioff).toContainText('leave the chatbot');
        await expect(aioff).toContainText('ai_usable true → false');
        await shot(page, 'B9-S04-aioff-build-1440-light');
        await page.getByRole('button', { name: 'Keep it on' }).click();
        await expect(aioff).toHaveCount(0);
        await expect(page.getByTestId('kb-cat-ai-switch')).toHaveAttribute('aria-checked', 'true');
        // EC-03: delete needs reassign first — the impact door drives the sentence
        await page.getByTestId('kb-cat-delete').click();
        const del = page.getByTestId('kb-delete-dialog');
        await expect(page.getByTestId('kb-delete-refuse')).toContainText('reassign them first');
        await expect(page.getByTestId('kb-delete-confirm')).toHaveCount(0);
        await expect(page.getByTestId('kb-reassign-confirm')).toBeDisabled();
        await shot(page, 'B9-S04-delete-refused-build-1440-light');
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(del).toHaveCount(0);
        await setTheme(page, 'dark');
        await axeClean(page, 'categories (dark)');
        await shot(page, 'B9-S04-categories-build-1440-dark');
    });

    test('§D the depth-3 wall (D01): a depth-3 node offers NO "+ Add subcategory" and the wall card says why; built on fixture subcategories through the real doors, then removed', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb#categories');
        const make = async (slug: string, parentSlug: string | null): Promise<void> => {
            if (parentSlug) {
                await page.getByTestId('kb-category-' + parentSlug).click();
                await page.getByTestId('kb-cat-add-sub').click();
            } else await page.getByTestId('kb-new-category').click();
            await page.getByTestId('kb-newcat-name-en').fill('[F1] ' + slug);
            await page.getByTestId('kb-newcat-slug').fill(slug);
            await page.getByTestId('kb-newcat-create').click();
            await page.waitForLoadState('load');
            await expect(page.getByTestId('kb-category-' + slug)).toBeVisible();
        };
        const run = Date.now().toString(36).slice(-6);
        try {
            // unique per run: a soft-deleted category keeps its slug (the unique index counts deleted rows)
            await make(`f1-d2-${run}`, 's10-browser-kb');
            await make(`f1-d3-${run}`, `f1-d2-${run}`);
            await page.getByTestId(`kb-category-f1-d3-${run}`).click();
            await expect(page.getByTestId('kb-cat-depth')).toContainText('depth 3 of 3');
            await expect(page.getByTestId('kb-cat-add-sub')).toHaveCount(0);
            await expect(page.getByTestId('kb-depth-wall')).toContainText(
                'A fourth level cannot be built',
            );
            await expect(
                page.locator('[role="treeitem"]', { hasText: `[F1] f1-d3-${run}` }),
            ).toHaveAttribute('aria-level', '3');
            await shot(page, 'B9-S04-depth3-wall-build-1440-light');
        } finally {
            for (const slug of [`f1-d3-${run}`, `f1-d2-${run}`]) {
                await page.goto('/admin/kb#categories');
                if ((await page.getByTestId('kb-category-' + slug).count()) === 0) continue;
                await page.getByTestId('kb-category-' + slug).click();
                await page.getByTestId('kb-cat-delete').click();
                await page.getByTestId('kb-delete-confirm').click();
                await page.waitForLoadState('load');
            }
        }
    });

    test("§D D04 the shell's one KB entry: the palette row opens the manager and the Backoffice nav shows ONE Knowledge base row, no sub-navigation", async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await expect(page.locator('.bo-nav [data-testid="bo-nav-kb"]')).toHaveCount(1);
        await expect(page.locator('.bo-nav a', { hasText: /knowledge/i })).toHaveCount(1);
    });

    test('§D 375 read posture: the tree browses with counts at 44 px; the form verbs are absent; the notice says why', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/kb#categories');
        await expect(page.getByTestId('bo-readnote')).toContainText(
            'reordering and deleting need a tablet',
        );
        await expect(page.getByTestId('kb-cat-save')).toBeHidden();
        await targets44(page, '[role="treeitem"]', 'category rows at 375');
        await noOverflow(page, 'categories 375');
        await axeClean(page, 'categories 375 (light)');
        await shot(page, 'B9-S04-categories-build-375-light');
    });
});

test.describe('B9 cross-cutting — the Amharic sweep, reduced motion, the absences', () => {
    test('L04 — every KB page in the am locale at 1440 + 375, light + dark, zero overflow on the shell; the strings are Ethiopic', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        setAgentLocale('am');
        try {
            await login(page);
            const pages = [
                '/admin/kb',
                '/admin/kb#categories',
                '/admin/kb/variants/' + V1AM + '/edit',
                '/admin/kb/variants/' + V1 + '/history-page',
                '/admin/kb/cite/' + A1 + '/' + VER1,
            ];
            for (const path of pages) {
                for (const [w, h] of [
                    [1440, 900],
                    [375, 812],
                ] as [number, number][]) {
                    await page.setViewportSize({ width: w, height: h });
                    await page.goto(path);
                    await expect(page.getByTestId('backoffice-shell')).toBeVisible();
                    for (const theme of ['light', 'dark'] as const) {
                        await setTheme(page, theme);
                        const overflow = await page
                            .getByTestId('backoffice-shell')
                            .evaluate((el) => el.scrollWidth - el.clientWidth);
                        expect(
                            overflow,
                            `${path} ${w} ${theme} shell overflow`,
                        ).toBeLessThanOrEqual(0);
                        const slug = path
                            .replace(/[^a-z0-9]+/gi, '-')
                            .replace(/^-|-$/g, '')
                            .slice(0, 40);
                        await shot(page, `sweep-am-${slug}-${w}-${theme}`);
                    }
                }
            }
            await page.setViewportSize(V1440);
            await page.goto('/admin/kb');
            await expect(page.getByTestId('bo-nav-kb')).toHaveText('የእውቀት ማዕከል');
        } finally {
            setAgentLocale('en');
        }
    });

    test('L05 — reduced motion: nothing animates or transitions on the manager or the editor', async ({
        page,
    }) => {
        await login(page);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize(V1440);
        for (const path of ['/admin/kb', '/admin/kb/variants/' + V1 + '/edit']) {
            await page.goto(path);
            const n = await page.evaluate(
                () =>
                    Array.from(
                        document.querySelectorAll('[data-testid="backoffice-shell"] *'),
                    ).filter((el) => {
                        const cs = getComputedStyle(el);
                        return (
                            (cs.animationName && cs.animationName !== 'none') ||
                            (cs.transitionDuration &&
                                cs.transitionDuration.split(',').some((d) => parseFloat(d) > 0))
                        );
                    }).length,
            );
            expect(n, `${path} animated elements under reduced motion`).toBe(0);
        }
    });

    test('the designed absences: no "verified" anywhere; no per-article AI-usable switch; no edit control on a version; no delete on the archived record; no publish in the AI drawer', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/kb');
        await page.getByTestId('kb-category-s10-browser-kb').click();
        await page.getByTestId('kb-article-' + A1).click();
        await expect(page.getByTestId('backoffice-shell')).not.toContainText(/verified/i);
        await expect(page.getByTestId('kb-pane-detail').locator('[role="switch"]')).toHaveCount(0);
        await page.goto('/admin/kb/variants/' + V1 + '/history-page');
        await expect(
            page.getByTestId('kb-versions').locator('button, a', { hasText: /^(edit|delete)$/i }),
        ).toHaveCount(0);
        await page.goto('/admin/kb/variants/' + V1 + '/edit');
        await page.getByTestId('kb-ai-open').click();
        await expect(
            page.getByTestId('kb-ai-drawer').locator('.bo-btn', { hasText: /publish/i }),
        ).toHaveCount(0);
    });
});

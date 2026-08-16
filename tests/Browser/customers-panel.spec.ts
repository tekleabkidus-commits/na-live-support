import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * Doc 08 — the Customer panel in its slot (S08 step 10; B4-S01's 12 frames,
 * B4-S05 E01/E03's badge/banner pair, AC-08, AC-B4-03).
 *
 * Frame-mapped: every assertion cites its frame id. No pixel baselines exist
 * for Batch 4 — fidelity is proven by these frame-mapped assertions
 * (the traceability rows say so plainly).
 *
 * Runs under the accepted-flakes law: full artifacts are captured on the
 * FIRST run of this spec; every red is triaged against 0i before being
 * believed.
 */

const PASSWORD = 'browser-fixture-password';
const RG_VIEWER = 'customers-agent@fixture.example';
const NO_RG_VIEWER = 'customers-norg@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };

function artisan(...args: string[]): string {
    return execFileSync(
        'docker',
        ['compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml', 'exec', '-T', 'app', 'php', 'artisan', ...args],
        { stdio: 'pipe' },
    ).toString();
}

// Scene conversation ids, resolved once from the seeder's fixed chat ids.
const conversations: Record<string, string> = {};
const conv = (key: string): string => conversations[key] ?? '';

test.beforeAll(() => {
    artisan('auth:session-inspect', 'clear-throttle'); // ten logins per run — the 0f limiter is real
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\CustomerBrowserSeeder', '--force');
    const out = artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
foreach (['chat-s08-abebe','chat-s08-kidist','chat-s08-dawit','chat-s08-blocked'] as $chat) {
    echo $chat.'='.DB::table('conversations')->where('external_chat_id',$chat)->value('id').PHP_EOL;
    echo $chat.'-customer='.DB::table('conversations')->where('external_chat_id',$chat)->value('customer_id').PHP_EOL;
}`,
    );
    for (const line of out.split('\n')) {
        const [key, value] = line.trim().split('=');
        if (key?.startsWith('chat-') && value) conversations[key] = value;
    }
});

async function login(page: Page, email: string): Promise<void> {
    artisan('auth:session-inspect', 'clear-throttle');
    await page.goto('/login');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openCustomerTab(page: Page, conversation: string): Promise<void> {
    await page.goto(`/inbox/c/${conversation}`);
    await dismissCoachMarks(page);
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('customer-panel-fill')).toBeVisible();
}

function announcer(page: Page) {
    return page.getByTestId('panel-announcer');
}

test('A01–A11 (1440): the enriched panel — name, sourced chips, tags, pinned note, identities with provenance, betting card with the A06 hint, History, recent tickets — AC-08\'s order and lazy sections', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-abebe'));

    // Header (A01): staff-set name, language chip WITH its source, country flag.
    await expect(page.getByTestId('field-display_name')).toHaveText('አበበ ታደሰ');
    await expect(page.getByTestId('chip-language')).toContainText('am');
    await expect(page.getByTestId('chip-language')).toContainText('staff');
    await expect(page.getByTestId('chip-country')).toContainText('Ethiopia');
    await expect(page.getByTestId('tags-row')).toContainText('vip');
    await expect(page.getByTestId('pinned-note')).toContainText('confirm withdrawals by voice');

    // Identities with provenance ALWAYS (FR-05).
    await expect(page.getByTestId('customer-panel-fill')).toContainText('@fixture_abebe_t');
    await expect(page.getByTestId('customer-panel-fill')).toContainText('from telegram');
    await expect(page.getByTestId('customer-panel-fill')).toContainText('added by staff');

    // Betting card (FR-16): values + Staff-collected + the A06 hint,
    // counts only — the label is informative, the hint never a name.
    const betting = page.getByTestId('betting-card');
    await expect(betting).toContainText('Staff-collected');
    await expect(betting).toContainText('abebe_bet');
    await expect(betting).toContainText('NB-40219');
    await expect(page.getByTestId('duplicate-hint')).toContainText('also on 2 other profiles');
    await expect(page.getByTestId('duplicate-hint')).not.toContainText('Dup Fixture');

    // History = the rollups card; recent tickets its own section (T-07 lazy).
    await expect(page.getByTestId('rollups-card')).toContainText('tickets');
    await expect(page.getByTestId('tickets-card')).toContainText('#8801');

    // Files tab: the clean item lists; the infected one shows its status.
    await page.getByTestId('tab-files').click();
    await expect(page.getByTestId('files-section')).toContainText('photo');
    await expect(page.getByTestId('files-section')).toContainText('infected');

    // §13: axe over the filled panel.
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).include('[data-testid="customer-panel-fill"]').analyze();
    expect(axe.violations).toEqual([]);
});

test('A03: the username-history popover is keyboard-reachable and lists both earlier names with the search note', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-abebe'));

    const trigger = page.getByRole('button', { name: '2 earlier names' });
    await trigger.focus();
    await page.keyboard.press('Enter');
    const popover = page.locator('[data-testid^="history-popover-"]');
    await expect(popover).toBeVisible();
    await expect(popover).toContainText('@fixture_abebe1994');
    await expect(popover).toContainText('@fixture_abebe_old');
    await expect(popover).toContainText('Search finds this customer');
});

test('A04: copy-on-click is a BUTTON and the confirmation is SPOKEN — the live region receives it, not only the chip', async ({ page, context }) => {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-abebe'));

    const copy = page.locator('[data-testid^="copy-"]').first();
    await expect(copy).toHaveRole('button');
    await copy.click();
    await expect(page.locator('[data-testid^="copied-"]')).toContainText('Copied');
    await expect(announcer(page)).toContainText('Copied'); // SPOKEN, not just shown
});

async function fiveMoments(page: Page): Promise<void> {
    const field = page.getByTestId('field-display_name');

    // 1 — focus announces label + current value.
    await field.focus();
    await expect(announcer(page)).toContainText('Name, አበበ ታደሰ');

    // 2 — entering edit mode is announced.
    await page.keyboard.press('Enter');
    await expect(announcer(page)).toContainText('Editing Name');

    // 3 — Escape cancels and says so.
    await page.keyboard.press('Escape');
    await expect(announcer(page)).toContainText('Cancelled. Name is አበበ ታደሰ');

    // 4 — saving announces outcome + the new provenance.
    await field.focus();
    await page.keyboard.press('Enter');
    await page.getByTestId('edit-display_name').fill('አበበ ታደሰ ✎');
    await page.keyboard.press('Enter');
    await expect(announcer(page)).toContainText('Saved. staff, you, just now');
    await expect(page.getByTestId('provenance-now')).toContainText('staff · you · just now');

    // 5 — a failed save announces the failure and the reverted value.
    await page.route('**/api/staff/customers/*/profile', (route) => route.abort());
    await page.getByTestId('field-display_name').focus();
    await page.keyboard.press('Enter');
    await page.getByTestId('edit-display_name').fill('never lands');
    await page.keyboard.press('Enter');
    await expect(announcer(page)).toContainText('Save failed. Name reverted to');
    await page.unroute('**/api/staff/customers/*/profile');

    // restore the canonical name for the next run (self-heal via the door).
    await page.getByTestId('field-display_name').focus();
    await page.keyboard.press('Enter');
    await page.getByTestId('edit-display_name').fill('አበበ ታደሰ');
    await page.keyboard.press('Enter');
}

test('AC-B4-03 (1440): the five announce moments, spoken once each, in order, without re-reading the card', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-abebe'));
    await fiveMoments(page);
});

test('AC-B4-03 (375): the SAME cycle in the sheet — B14\'s identical controls, the same contract spoken', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V375);
    await page.goto(`/inbox/c/${conv('chat-s08-abebe')}`);
    await dismissCoachMarks(page);
    // The mobile thread screen opens the context SHEET via its own control
    // (B2-S01); the Customer tab lives inside the same shared fill.
    await page.getByRole('button', { name: 'Open ticket context' }).click();
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('customer-panel-fill')).toBeVisible();
    // Identical controls, not a reduced set (B14): betting card + tabs present.
    await expect(page.getByTestId('betting-card')).toBeVisible();
    await fiveMoments(page);
});

test('B4-S05 E01/E03: the RG banner + tab dot for the PERMITTED viewer; ABSENCE — no dot, no banner, no rg key — for the viewer without the verdict', async ({ page }) => {
    // E01: the permitted viewer sees the slate banner and the tab dot.
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await page.goto(`/inbox/c/${conv('chat-s08-dawit')}`);
    await dismissCoachMarks(page);
    await expect(page.getByTestId('badge-customer')).toBeVisible();
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('customer-rg-banner')).toBeVisible();
    await expect(page.getByTestId('customer-rg-banner')).toHaveRole('note');

    // E03: absence, not redaction — same thread, the viewer without the key.
    await page.context().clearCookies();
    await login(page, NO_RG_VIEWER);
    await page.goto(`/inbox/c/${conv('chat-s08-dawit')}`);
    await dismissCoachMarks(page);
    await expect(page.getByTestId('tab-customer')).toBeVisible();
    await expect(page.getByTestId('badge-customer')).toHaveCount(0); // the dot IS the channel
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('customer-panel-fill')).toBeVisible();
    await expect(page.getByTestId('customer-rg-banner')).toHaveCount(0);
});

test('§8: the BLOCKED banner is drawn (red family) and the tab dot stays dark — blocked is not a dot state (the part-2 ruling, recorded)', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await page.goto(`/inbox/c/${conv('chat-s08-blocked')}`);
    await dismissCoachMarks(page);
    await expect(page.getByTestId('badge-customer')).toHaveCount(0);
    await page.getByTestId('tab-customer').click();
    await expect(page.getByTestId('customer-blocked-banner')).toBeVisible();
    await expect(page.getByTestId('customer-blocked-banner')).toContainText('blocked');
});

test('A13/A14: a shell is a real customer — short-id head, guidance not apology; empty notes/files state their emptiness', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-kidist'));

    await expect(page.getByTestId('shell-head')).toContainText('Customer #');
    await expect(page.getByTestId('shell-head')).toContainText('No name yet');
    await expect(page.getByTestId('shell-guidance')).toContainText('only know their Telegram');
    await expect(page.getByTestId('customer-panel-fill')).toContainText('@fixture_kidist_h');

    await page.getByTestId('tab-files').click();
    await expect(page.getByTestId('files-empty')).toBeVisible();
    await page.getByTestId('tab-notes').click();
    await expect(page.getByTestId('notes-empty')).toBeVisible();
});

test('A15: one section fails, the rest survive — the tickets section shows its own error + retry while identities stay alive', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await page.route('**/panel/tickets', (route) => route.abort());
    await openCustomerTab(page, conv('chat-s08-abebe'));

    await expect(page.getByTestId('section-error-tickets')).toContainText("Couldn't load");
    await expect(page.getByTestId('section-error-tickets')).toContainText('Everything else on this profile is fine.');
    await expect(page.getByTestId('customer-panel-fill')).toContainText('@fixture_abebe_t'); // the rest survived

    // Retry heals exactly this section.
    await page.unroute('**/panel/tickets');
    await page.getByRole('button', { name: 'Try again' }).click();
    await expect(page.getByTestId('tickets-card')).toContainText('#8801');
});

test('A16: two agents, one field — the later write LANDS and the loser is told by name, with both edits in the audit trail', async ({ page }) => {
    await login(page, RG_VIEWER);
    await page.setViewportSize(V1440);
    await openCustomerTab(page, conv('chat-s08-abebe'));

    // The stale-tab shape: another write lands through the API AFTER this
    // panel loaded its expectation stamp.
    const customerId = conv('chat-s08-abebe-customer');
    const setupStatus = await page.evaluate(
        async ({ id }) => {
            const token = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
            const r = await fetch(`/api/staff/customers/${id}/profile`, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': token },
                body: JSON.stringify({ country: 'KE' }),
            });
            return r.status;
        },
        { id: customerId },
    );
    expect(setupStatus).toBe(200); // the racing write must land or the scene is void

    // Now the panel's own (stale-stamped) edit — it LANDS, and the toast tells.
    await page.getByTestId('chip-country').click();
    await page.getByTestId('edit-country').fill('ET');
    await page.keyboard.press('Enter');

    await expect(page.getByTestId('conflict-toast')).toBeVisible();
    await expect(page.getByTestId('conflict-toast')).toContainText('saved this field a second before you');
    await expect(page.getByTestId('conflict-toast')).toContainText('audit trail');
    await expect(page.getByTestId('chip-country')).toContainText('Ethiopia'); // the later write won
});

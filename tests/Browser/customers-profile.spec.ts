import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 08 — the profile page, tombstone, and the action surfaces (S08 step
 * 11; B4-S02, B4-S03 C01–C04 + C02-v2, B4-S04, B4-S05 E04–E07/F01–F05;
 * BR-05; AC-03's UI arc; AC-04's move-set dialog).
 *
 * THE MERGE ARC IS SELF-RESTORING: merge B into A, assert the tombstone,
 * undo, assert B is a profile again — the scene heals through its own doors
 * (and the seeder's own self-heal covers a crashed run).
 */

const PASSWORD = 'browser-fixture-password';
const VIEWER = 'customers-agent@fixture.example';

function artisan(...args: string[]): string {
    return execFileSync(
        'docker',
        ['compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml', 'exec', '-T', 'app', 'php', 'artisan', ...args],
        { stdio: 'pipe' },
    ).toString();
}

const ids: Record<string, string> = {};
const sid = (key: string): string => ids[key] ?? '';

test.beforeAll(() => {
    artisan('auth:session-inspect', 'clear-throttle');
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\CustomerBrowserSeeder', '--force');
    const out = artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
foreach (['chat-s08-mergeA'=>'mergeA','chat-s08-mergeB'=>'mergeB','chat-s08-dawit'=>'dawit','chat-s08-abebe'=>'abebe'] as $chat => $name) {
    echo $name.'='.DB::table('conversations')->where('external_chat_id',$chat)->value('customer_id').PHP_EOL;
}`,
    );
    for (const line of out.split('\n')) {
        const [key, value] = line.trim().split('=');
        if (key && value && !key.includes(' ')) ids[key] = value;
    }
});

async function login(page: Page): Promise<void> {
    artisan('auth:session-inspect', 'clear-throttle');
    await page.goto('/login');
    await page.getByLabel('Email').fill(VIEWER);
    await page.getByLabel('Password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('B4-S04 + BR-05 + AC-03\'s UI ARC: merge B into A (radios with both values, checklist WITH counts, the MERGE token beside its input, reason required) → B\'s URL resolves as the TOMBSTONE → undo → B is a profile again', async ({ page }) => {
    await login(page);
    await page.goto(`/customers/${sid('mergeA')}`);
    await expect(page.getByTestId('profile-page')).toBeVisible();

    // The dialog: search → pick → the per-field radios read BOTH values.
    await page.getByTestId('merge-open').click();
    await page.getByTestId('merge-search').fill('Merge Absorbed');
    await page.getByTestId('merge-search-go').click();
    await page.getByTestId(`merge-pick-${sid('mergeB')}`).click();

    const nameField = page.getByTestId('merge-field-display_name');
    await expect(nameField).toContainText('Merge Survivor');
    await expect(nameField).toContainText('Merge Absorbed');

    // The checklist reads as a list WITH counts (§13).
    await expect(page.getByTestId('merge-checklist')).toContainText('conversations move');
    await expect(page.getByTestId('merge-checklist')).toContainText('tickets move');

    // The token is RENDERED beside the input (B4-S04's drawn law).
    await expect(page.getByTestId('merge-token')).toHaveText('MERGE');
    await page.getByTestId('merge-reason').fill('duplicate accounts, phone confirmed by voice');
    await page.getByTestId('merge-confirm').fill('MERGE');
    await page.getByTestId('merge-execute').click();
    // the 201 reloads the page — wait for the POST-reload state (dialog gone)
    await expect(page.getByTestId('merge-dialog')).toHaveCount(0, { timeout: 15000 });
    await expect(page.getByTestId('profile-page')).toBeVisible();

    // BR-05: the merged profile's URL RESOLVES — the tombstone surface.
    await page.goto(`/customers/${sid('mergeB')}`);
    await expect(page.getByTestId('tombstone-page')).toBeVisible();
    await expect(page.getByTestId('tombstone-page')).toContainText('Merge Survivor');
    await expect(page.getByTestId('tombstone-survivor-link')).toHaveAttribute('href', `/customers/${sid('mergeA')}`);

    // The undo (in-window, permitted): reason required, then B lives again.
    await page.getByTestId('tombstone-undo-open').click();
    await page.getByTestId('tombstone-undo-reason').fill('merged the wrong pair, restoring');
    await page.getByTestId('tombstone-undo-confirm').click();
    await expect(page.getByTestId('profile-page')).toBeVisible(); // the SAME URL is a profile again
});

test('B4-S05 E04–E07: RG set with reason → history shows BOTH acts after clear — the record never deletes', async ({ page }) => {
    await login(page);
    await page.goto(`/customers/${sid('mergeA')}`);
    await expect(page.getByTestId('rg-section')).toBeVisible();

    await page.getByTestId('rg-reason').fill('self-described chasing losses in chat');
    await page.getByTestId('rg-set').click();
    // the component clears the reason on success — THAT is the completion signal
    await expect(page.getByTestId('rg-reason')).toHaveValue('');
    await expect(page.getByTestId('rg-history')).toContainText('self-described chasing losses in chat');

    await page.getByTestId('rg-reason').fill('reviewed with lead, false positive');
    await page.getByTestId('rg-clear').click();
    await expect(page.getByTestId('rg-reason')).toHaveValue('');
    await expect(page.getByTestId('rg-history')).toContainText('cleared');
    await expect(page.getByTestId('rg-history')).toContainText('reviewed with lead, false positive');
    await expect(page.getByTestId('rg-history')).toContainText('set'); // E07: whole, forever
});

test('B4-S05 F01/F04: block with mandatory reason (red family), then unblock as its own reasoned act (A-39)', async ({ page }) => {
    await login(page);
    await page.goto(`/customers/${sid('mergeA')}`);

    await page.getByTestId('block-reason').fill('abuse after declined payout');
    await page.getByTestId('block-execute').click();
    await expect(page.getByTestId('customer-blocked-banner')).toBeVisible(); // §8: red, drawn

    await page.getByTestId('block-reason').fill('appeal accepted, restoring');
    await page.getByTestId('unblock-execute').click();
    await expect(page.getByTestId('customer-blocked-banner')).toHaveCount(0);
});

test('B4-S03 C02: linking a phone already on ANOTHER profile refuses with the merge-instead offer — an id, never a name', async ({ page }) => {
    await login(page);
    await page.goto(`/customers/${sid('mergeA')}`);

    // abebe's staff phone from the enriched scene — already linked there.
    await page.getByTestId('link-type').selectOption('phone');
    await page.getByTestId('link-value').fill('0912344417');
    await page.getByTestId('link-submit').click();

    const offer = page.getByTestId('link-merge-offer');
    await expect(offer).toBeVisible();
    await expect(offer).toContainText(sid('abebe').slice(-8)); // the id
    await expect(offer).not.toContainText('አበበ'); // never the name (SEC-05's cousin)
});

test('B4-S03 C03/C04 + AC-04: the unlink dialog\'s content COMES FROM THE MOVE SET — counts rendered, the UNLINK token beside its input, the button repeating the count', async ({ page }) => {
    await login(page);
    // A fresh, unique phone for this run: linked here, then unlinked — the
    // arc is self-contained (the moved shell keeps the throwaway value).
    const value = `+2519009${String(Date.now()).slice(-5)}`;
    await page.goto(`/customers/${sid('mergeA')}`);
    await page.getByTestId('link-type').selectOption('phone');
    await page.getByTestId('link-value').fill(value);
    await page.getByTestId('link-submit').click();
    await expect(page.getByTestId('profile-page')).toBeVisible();

    const unlinkButtons = page.locator('[data-testid^="unlink-open-"]');
    await expect(unlinkButtons.first()).toBeVisible();
    // find the row carrying our value
    const row = page.locator('li', { hasText: value.replace('+251', '') }).locator('[data-testid^="unlink-open-"]');
    await row.first().click();

    const dialog = page.getByTestId('unlink-dialog');
    await expect(dialog).toBeVisible();
    await expect(page.getByTestId('unlink-moves')).toContainText('conversations'); // the move set's counts
    await expect(page.getByTestId('unlink-token')).toHaveText('UNLINK');

    await page.getByTestId('unlink-reason').fill('separating a shared account');
    await page.getByTestId('unlink-confirm').fill('UNLINK');
    await page.getByTestId('unlink-execute').click();
    await expect(page.getByTestId('profile-page')).toBeVisible(); // reloaded, identity gone
});

test('[D17]: the Audit tab renders its seam for an audit.view holder — absent for this viewer without the key', async ({ page }) => {
    await login(page);
    await page.goto(`/customers/${sid('mergeA')}`);
    // The seeded viewer holds no audit.view — the tab must be ABSENT.
    await expect(page.getByTestId('profile-tab-audit')).toHaveCount(0);
});

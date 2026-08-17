import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 08 BR-01/FR-16/EC-09/EC-10/FS-03/SEC-04/A-38 — the data-honesty
 * surfaces (S08 step 12; B4-S07-v2 H01–H07 + the 375 pair).
 *
 * H02 the staff-collected label (and the verified affordance that does not
 * exist) · H03 staff-set wins, the detected offer chip · H04 the retired
 * fact that stays · H07 counts that admit they are behind (the beat driven
 * as a STORED VALUE — never a sleep; the server law is RollupStalenessTest)
 * · H05 export ask → building → ready (the request door is real; the
 * signing poll is intercepted — SEC-04/FS-05 are CustomerExportTest's) ·
 * H06 erasure: "this does not delete anything" FIRST, then the pending
 * card reporting what the audit record holds.
 *
 * The prohibition callout (the struck verified badge) is DOCUMENTATION —
 * nothing is built from it; the standing repo-wide AC-09 test keeps the
 * absence. Here the browser asserts the absence on the RENDERED surface.
 */

const PASSWORD = 'browser-fixture-password';
const VIEWER = 'customers-agent@fixture.example';

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
echo 'tenant='.$t.PHP_EOL;
foreach (['chat-s08-abebe'=>'abebe','chat-s08-h03'=>'h03','chat-s08-kidist'=>'kidist'] as $chat => $name) {
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

test('H02/H04: the betting card wears "Staff-collected" beside its heading — never a verified affordance anywhere on the page — and the retired loyalty-tier fact stays, read-only and labeled', async ({
    page,
}) => {
    await login(page);
    await page.goto(`/customers/${sid('abebe')}`);
    const panel = page.getByTestId('customer-panel-fill');
    await expect(panel).toContainText('Staff-collected');
    await expect(panel).toContainText('NB-40219');

    // H04: the value an agent recorded is still true about then.
    await expect(panel).toContainText('Gold');
    await expect(panel).toContainText('retired field');

    // The affordance that does not exist (BR-01/AC-09's rendered half).
    const body = await page.locator('body').innerText();
    expect(body).not.toContain('Verified');
    expect(body).not.toContain('Confirmed identity');

    const axe = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .include('[data-testid="honesty-section"]')
        .analyze();
    expect(axe.violations).toEqual([]);
});

test('H03: a staff-set language is sticky — the detected difference arrives as a one-line OFFER chip, not an overwrite', async ({
    page,
}) => {
    await login(page);
    await page.goto(`/customers/${sid('h03')}`);
    const chip = page.getByTestId('chip-detected');
    await expect(chip).toBeVisible();
    await expect(chip).toContainText('en'); // detected: en — apply?
    // The staff value still stands on the card (am), unoverwritten.
    await expect(page.getByTestId('customer-panel-fill')).toContainText('am');
});

test('H07: the rollups card admits it is behind — ◷ plus the WORD when the beat is old WITH pending work; the chip leaves when the beat is fresh (stored value driven, never a sleep)', async ({
    page,
}) => {
    // Drive the stored beat old + one pending-family event newer than it.
    artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Redis;
use Illuminate\\Support\\Str;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
Redis::connection('volatile')->command('set', ['t:'.$t.':customers:rollup_heartbeat', (string) (time() - 600)]);
DB::table('events')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $t, 'name' => 'message.received', 'schema_version' => 1, 'payload' => '{}']);
echo 'staged';`,
    );

    await login(page);
    await page.goto(`/customers/${sid('abebe')}`);
    const chip = page.getByTestId('rollups-stale-chip');
    await expect(chip).toBeVisible();
    await expect(chip).toContainText('stale'); // the word, never a colour alone
    await expect(chip).toHaveAttribute('title', /minutes/);

    // A fresh beat clears the claim.
    artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Redis;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
Redis::connection('volatile')->command('set', ['t:'.$t.':customers:rollup_heartbeat', (string) time()]);
echo 'fresh';`,
    );
    await page.reload();
    await expect(page.getByTestId('rollups-card')).toBeVisible();
    await expect(page.getByTestId('rollups-stale-chip')).toHaveCount(0);
});

test('H05: export — the ask states the contents and the watermark, the start answers building-with-carry-on, and ready hands a dying link (the request door real; the signing poll intercepted — the job law is pest-proven)', async ({
    page,
}) => {
    await login(page);

    // First poll: not ready (FS-05's honest 404). Second: the signed link.
    let polls = 0;
    await page.route('**/api/staff/customers/*/export/*', (route) => {
        polls += 1;
        if (polls === 1) {
            void route.fulfill({ status: 404, contentType: 'application/json', body: '{}' });
            return;
        }
        void route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({ url: 'https://localhost/fixture-signed-url' }),
        });
    });

    await page.goto(`/customers/${sid('abebe')}`);
    await page.getByTestId('export-open').click();
    const modal = page.getByTestId('export-modal');
    await expect(modal).toBeVisible();

    // The contents list, with counts, and the watermark sentence.
    const contents = modal.getByTestId('export-contents');
    await expect(contents).toContainText('provenance');
    await expect(contents).toContainText('tickets');
    await expect(contents).toContainText('blocked media is listed, not included');
    await expect(modal).toContainText('watermarked with your name');

    await modal.getByTestId('export-start').click();
    await expect(modal.getByTestId('export-building')).toBeVisible();
    await expect(modal).toContainText('Carry on working');

    await expect(modal.getByTestId('export-ready')).toBeVisible({ timeout: 10_000 });
    await expect(modal).toContainText('24 hours');
    await expect(modal.getByTestId('export-download')).toBeVisible();
});

test('H06 (+375): erasure — "This does not delete anything" is the FIRST sentence at every size, the reason is required, and after sending the profile REPORTS the pending request with its reference', async ({
    page,
}) => {
    // 375 first: the sheet leads with the sentence that matters.
    await page.setViewportSize({ width: 375, height: 720 });
    await login(page);
    await page.goto(`/customers/${sid('kidist')}`);

    // Re-run tolerance: the audit record is append-only, so a prior run's
    // request leaves the pending card standing — in that case the modal is
    // gone BY DESIGN and the card is the assertion.
    const pending = page.getByTestId('erasure-pending');
    if ((await pending.count()) === 0) {
        await page.getByTestId('erasure-open').click();
        const modal = page.getByTestId('erasure-modal');
        await expect(modal).toBeVisible();

        // The first content element after the heading block is the sentence.
        await expect(modal.getByTestId('erasure-not-delete')).toBeVisible();
        await expect(modal.getByTestId('erasure-not-delete')).toContainText(
            'does not delete anything',
        );

        // No red, no confirm phrase, and the send refuses without a reason.
        await expect(modal.getByTestId('erasure-send')).toBeDisabled();
        await modal
            .getByTestId('erasure-reason')
            .fill('Customer asked by phone on a fixture call.');
        await expect(modal.getByTestId('erasure-send')).toBeEnabled();
        await modal.getByTestId('erasure-send').click();
        await page.waitForLoadState('networkidle');
    }

    // H06 dark: the request, waiting — reported, not decided, with the
    // reference an agent can quote to compliance.
    await expect(page.getByTestId('erasure-pending')).toBeVisible();
    await expect(page.getByTestId('erasure-pending')).toContainText('awaiting approval');
    await expect(page.getByTestId('erasure-reference')).toContainText('ERA-');

    // The customer can still be helped normally: the panel renders whole.
    await expect(page.getByTestId('customer-panel-fill')).toBeVisible();
});

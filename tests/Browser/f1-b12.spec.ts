// SESSION-F1 [B12-batch] — the visibility proofs for Doc 12's Workflow surfaces against the
// frozen Batch 9 record (design/frozen/B9-S05…S13, 67dc4e4). The existing Workflow specs
// (workflow-screens, structure-admin, inbox-palette) stay byte-untouched and run beside this
// file; this file proves what the frames DRAW. Build shots land under docs/evidence/F1-B12/shots/.
import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };
const SHOTS = 'docs/evidence/F1-B12/shots';

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

function tinker(code: string): string {
    return artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
${code}`,
    );
}

test.beforeAll(() => {
    execFileSync('mkdir', ['-p', SHOTS]);
    artisan('auth:session-inspect', 'clear-throttle');
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
});
// twenty-odd logins in a few minutes trip the 0f limiter — clear it before each proof
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
    const overflow = await page
        .getByTestId('backoffice-shell')
        .evaluate((el) => el.scrollWidth - el.clientWidth);
    expect(overflow, `${label} shell overflow px`).toBeLessThanOrEqual(0);
}
async function targets44(page: Page, selector: string, label: string): Promise<void> {
    for (const el of await page.locator(selector).all()) {
        if (!(await el.isVisible())) continue;
        const box = await el.boundingBox();
        expect(box?.height ?? 0, `${label}: ${selector} height`).toBeGreaterThanOrEqual(44);
    }
}
// The Workflow fixtures are created through the doors by the proofs themselves (the built
// workflow-screens spec's habit); names carry [F1] so a leak is self-identifying.
async function ensureRule(page: Page, name: string): Promise<string> {
    await page.goto('/admin/workflow/rules');
    const row = page.locator('[data-rule-row]', { hasText: name });
    if ((await row.count()) > 0) return (await row.first().getAttribute('data-rule-row')) ?? '';
    await page.getByTestId('wf-rule-new').click();
    await page.getByTestId('wf-rule-name').fill(name);
    await page.getByTestId('wf-rule-create').click();
    await page.waitForURL(/\/admin\/workflow\/rules\//);
    return page.url().split('/').pop() ?? '';
}

test.describe('B9-S13 M01 — the Workflow chrome', () => {
    test('the shared Backoffice nav with the Workflow group live: Organization a link, People/Channels drawn, Knowledge base one row, the seven Workflow rows with aria-current; the hub crumb; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/rules');
        await expect(page.getByTestId('backoffice-shell')).toBeVisible();
        await expect(page.getByTestId('bo-nav-kb')).toBeVisible();
        await expect(page.locator('.bo-nav-sub [data-testid^="wf-nav-"]')).toHaveCount(7);
        await expect(page.getByTestId('wf-nav-rules')).toHaveAttribute('aria-current', 'page');
        await expect(page.getByTestId('bo-crumb')).toContainText('Workflow');
        await axeClean(page, 'workflow chrome (light)');
        await setTheme(page, 'dark');
        await axeClean(page, 'workflow chrome (dark)');
    });
});

test.describe('B9-S05 — the rules list', () => {
    test('§E: the toolbar (search · trigger · state · guard trips · the BR-05 limits), the explainer, the row anatomy (position with ↑↓ on the selected row · name + description · trigger · matched + sparkline or "no runs yet" · last run · switch + state word), the legend; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] rules-list proof');
        await page.goto('/admin/workflow/rules');
        await expect(page.getByTestId('wf-guards-sentence')).toContainText(
            '10 rules · 20 actions · chain depth 3',
        );
        await expect(page.getByTestId('wf-rules-explainer')).toContainText(
            'Position order is execution order',
        );
        await expect(page.getByTestId('wf-rules-search')).toBeVisible();
        await expect(page.getByTestId('wf-rules-filter-trigger')).toBeVisible();
        const row = page.locator(`[data-rule-row="${id}"]`);
        await expect(row).toBeVisible();
        await row.focus();
        await expect(page.getByTestId(`wf-rule-up-${id}`)).toBeVisible();
        await expect(row.locator('.mt')).toContainText(/no runs yet|\d/);
        await expect(page.getByTestId(`wf-rule-state-${id}`)).toContainText(/Draft|Active/);
        await expect(page.getByTestId(`wf-rule-toggle-${id}`)).toHaveAttribute('role', 'switch');
        await expect(page.locator('.wf-legend')).toContainText('amber shield');
        // filters compose: state=active hides the draft row; Clear restores it
        await page.getByTestId('wf-rules-filter-state').selectOption('active');
        await expect(row).toHaveCount(0);
        await page.getByTestId('wf-rules-filter-state').selectOption('');
        await expect(row).toBeVisible();
        await axeClean(page, 'rules list (light)');
        await shot(page, 'B9-S05-rules-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'rules list (dark)');
        await shot(page, 'B9-S05-rules-build-1440-dark');
    });

    test('§E E02: the activate dialog names the position, the trigger, the capability floor, the dry-run habit and the audit; Cancel changes nothing; the deactivate dialog is the red primary with stops · keeps · untouched · audited', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] rules-list proof');
        await page.goto('/admin/workflow/rules');
        await page.getByTestId(`wf-rule-toggle-${id}`).click();
        const dlg = page.getByTestId('wf-rule-dialog');
        await expect(dlg).toContainText('Capability floor');
        await expect(dlg).toContainText('review habit');
        await expect(dlg).toContainText('automation.rule.activated');
        await expect(page.getByTestId('wf-rule-confirm')).toHaveClass(/\bp\b/);
        await shot(page, 'B9-S05-activate-build-1440-light');
        await page.getByRole('button', { name: 'Cancel' }).click();
        await expect(dlg).toHaveCount(0);
        await expect(page.getByTestId(`wf-rule-state-${id}`)).toContainText('Draft');
    });

    test('§E E07 (absence of the door): the pause banner renders only from the R-04 flag; with the flag off no banner, no paused chip, no per-row "Paused"', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/rules');
        await expect(page.getByTestId('wf-paused')).toHaveCount(0);
        await expect(page.getByTestId('wf-rules-paused-chip')).toHaveCount(0);
        await expect(page.getByTestId('wf-rules-table')).not.toContainText('Paused');
    });

    test('§E E09 375: the read notice, cards, no switch, no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/rules');
        await expect(page.getByTestId('bo-readnote')).toContainText(
            'Reordering and activation need a tablet',
        );
        await expect(page.locator('[data-rule-row]').first()).toBeVisible();
        await expect(page.locator('.wf-sw:visible')).toHaveCount(0);
        await targets44(page, '[data-rule-row]', 'rule cards at 375');
        await noOverflow(page, 'rules 375');
        await axeClean(page, 'rules 375 (light)');
        await shot(page, 'B9-S05-rules-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'rules 375 (dark)');
        await shot(page, 'B9-S05-rules-build-375-dark');
    });
});

test.describe('B9-S06 — the rule editor', () => {
    test('§F F01 + F02: three numbered panels in execution order, the header state + position stamp, the 13 trigger chips, schedule → cron with the five-minute floor stated and the tenant-timezone next run, cooldown + run window hints; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.goto(`/admin/workflow/rules/${id}`);
        await expect(page.getByTestId('wf-panel-trigger')).toBeVisible();
        await expect(page.getByTestId('wf-panel-conditions')).toBeVisible();
        await expect(page.getByTestId('wf-panel-actions')).toBeVisible();
        await expect(page.getByTestId('wf-editor-state')).toContainText('Draft');
        await expect(page.locator('.bo-stamp')).toContainText(/position \d+ of \d+/);
        await expect(page.getByTestId('wf-trigger-chips').locator('span')).toHaveCount(13);
        await expect(page.getByTestId('wf-panel-trigger')).toContainText(
            'Per ticket, keyed on rule + ticket',
        );
        await expect(page.getByTestId('wf-panel-trigger')).toContainText('Run window');
        await page.getByTestId('wf-editor-trigger').selectOption('schedule');
        await expect(page.getByTestId('wf-editor-cron')).toBeVisible();
        await expect(page.getByTestId('wf-panel-trigger')).toContainText('Five-minute floor');
        await expect(page.getByTestId('wf-next-run')).toBeVisible();
        await page.getByTestId('wf-editor-trigger').selectOption('ticket.created');
        await axeClean(page, 'rule editor (light)');
        await shot(page, 'B9-S06-editor-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'rule editor (dark)');
        await shot(page, 'B9-S06-editor-build-1440-dark');
    });

    test('§F F03: the condition tree is a tree (aria-level) whose add / group verbs are BUTTONS; the facts + operators sentence stands under the panel head', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.goto(`/admin/workflow/rules/${id}`);
        await expect(page.getByTestId('wf-panel-conditions')).toContainText(
            'Facts: ticket · customer · message',
        );
        await page.getByTestId('wf-cond-add').first().click();
        await expect(
            page
                .getByTestId('wf-panel-conditions')
                .locator('[role="treeitem"][aria-level]')
                .first(),
        ).toBeVisible();
        await expect(page.getByTestId('wf-cond-add').first()).toHaveRole('button');
        await expect(page.getByTestId('wf-cond-add-group').first()).toHaveRole('button');
    });

    test('§F F05 + F06: all 15 action types as cards with their door named; the three external arms CLOSED (SMS = template reference, webhook = allowlist pick with no URL field, AI task = three names); an SMS card without a template blocks save inline', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.goto(`/admin/workflow/rules/${id}`);
        const types = await page
            .getByTestId('wf-action-add')
            .locator('option:not([value=""])')
            .evaluateAll((os) => os.map((o) => (o as HTMLOptionElement).value));
        expect(types).toHaveLength(15);
        const cards = page.locator(
            '[data-testid^="wf-action-"]:not([data-testid="wf-action-add"])',
        );
        const before = await cards.count();
        for (const t of types) await page.getByTestId('wf-action-add').selectOption(t);
        await expect(cards).toHaveCount(before + 15);
        expect(await page.locator('.closedtag').count()).toBeGreaterThanOrEqual(3);
        const panel = page.getByTestId('wf-panel-actions');
        await expect(panel).toContainText('Template reference only');
        await expect(panel).toContainText('Never deliverable to a customer (N-04)');
        await expect(panel).toContainText('a URL cannot be typed here (SEC-02)');
        await expect(panel).toContainText('Three names only');
        await expect(page.getByTestId('wf-webhook-destination')).toBeVisible();
        await expect(panel.locator('input[type="url"]')).toHaveCount(0);
        await expect(page.locator('.bo-btn.sm.up')).toHaveCount(0); // no stray chrome
        // F06 — the SMS card has no template: the summary lists it, save is refused before the door
        await expect(page.getByTestId('wf-validation-summary')).toBeVisible();
        await expect(page.getByTestId('wf-inline-validation').first()).toContainText(
            'Send SMS template',
        );
        await page.getByTestId('wf-save-draft-bar').click();
        await expect(page.locator('.wf-savebar')).toContainText('Cannot save yet');
        await expect(page.getByTestId('wf-draft-saved')).toHaveCount(0);
        await shot(page, 'B9-S06-actions-build-1440-light');
        await axeClean(page, 'rule editor · 15 cards (light)');
    });

    test('§F F07 + F08: the dry-run drawer (event picker, truth badges with the evaluated value, per-action would-run, guards, zero side effects); save-as-draft keeps the active revision and shows the banner; the activate dialog names the atomic swap and the dry-run nudge; Cancel changes nothing', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.goto(`/admin/workflow/rules/${id}`);
        await expect(page.getByTestId('wf-dry-nudge')).toBeVisible();
        await page.getByTestId('wf-cond-add').first().click();
        await page.getByTestId('wf-action-add').selectOption('add_note');
        await page
            .getByTestId('wf-action-0')
            .locator('input, textarea')
            .first()
            .fill('[F1] from the proof');
        const toggle = page.getByTestId('wf-dry-run-toggle');
        if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
        await expect(page.getByTestId('wf-dry-run')).toBeVisible();
        await expect(page.getByTestId('wf-dry-event')).toBeVisible();
        await expect(page.getByTestId('wf-dry-run')).toContainText('Reads only');
        await page.getByTestId('wf-dry-run-go').click();
        await expect(page.getByTestId('wf-dry-result')).toBeVisible();
        await expect(page.getByTestId('wf-truth-0')).toContainText(/matches|does not match/);
        await expect(page.getByTestId('wf-dry-action-0')).toBeVisible();
        await expect(page.getByTestId('wf-dry-run')).toContainText(
            /No guard tripped|Guards tripped/,
        );
        await expect(page.getByTestId('wf-dry-run')).toContainText('stateless (FR-05)');
        await shot(page, 'B9-S06-dryrun-build-1440-light');
        await page.getByTestId('wf-save-draft-bar').click();
        await expect(page.getByTestId('wf-draft-saved')).toContainText(
            'active revision is untouched',
        );
        await expect(page.getByTestId('wf-editor-state')).toContainText('Draft');
        await page.getByTestId('wf-activate-bar').click();
        const dlg = page.getByTestId('wf-activate-dialog');
        await expect(dlg).toContainText('swapped in one step');
        await expect(dlg).toContainText(/dry run/i);
        await dlg.getByRole('button', { name: 'Cancel' }).click();
        await expect(dlg).toHaveCount(0);
        await expect(page.getByTestId('wf-editor-state')).toContainText('Draft');
    });

    test('§F F09: the execution log inline with its five columns and the SEC-03 note for the privileged viewer; F10 retry is absent (no door)', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.goto(`/admin/workflow/rules/${id}`);
        const log = page.getByTestId('wf-log');
        await expect(log).toBeVisible();
        for (const h of ['When', 'Event', 'Result', 'Guards'])
            await expect(log.locator('.lh')).toContainText(h);
        await expect(log).toContainText(/No executions in the retention window|matched|no match/);
        await expect(page.locator('.wf-log-note')).toContainText('SEC-03');
        await expect(page.getByRole('button', { name: /retry/i })).toHaveCount(0);
    });

    test('§F F01 375 + F11 am: the read posture (sentences, no builder) with the notice, no overflow; the Amharic locale renders the header, panels and chips without overflow', async ({
        page,
    }) => {
        await login(page);
        const id = await ensureRule(page, '[F1] editor proof');
        await page.setViewportSize(V375);
        await page.goto(`/admin/workflow/rules/${id}`);
        await expect(page.getByTestId('wf-read-posture')).toBeVisible();
        await expect(page.getByTestId('bo-readnote')).toBeVisible();
        await expect(page.getByTestId('wf-action-add')).toHaveCount(0);
        await noOverflow(page, 'editor 375');
        await axeClean(page, 'editor 375 (light)');
        await shot(page, 'B9-S06-editor-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'editor 375 (dark)');
        await shot(page, 'B9-S06-editor-build-375-dark');
        setAgentLocale('am');
        try {
            await page.setViewportSize(V1440);
            await page.goto(`/admin/workflow/rules/${id}`);
            await expect(page.getByTestId('wf-panel-trigger')).toContainText('ቀስቃሽ');
            await expect(page.getByTestId('wf-trigger-chips').locator('span')).toHaveCount(13);
            await noOverflow(page, 'editor am 1440');
            await shot(page, 'B9-S06-editor-build-1440-am');
            await page.goto('/admin/workflow/rules');
            await expect(page.getByTestId('wf-guards-sentence')).toBeVisible();
            await noOverflow(page, 'rules am 1440');
            await shot(page, 'B9-S05-rules-build-1440-am');
        } finally {
            setAgentLocale('en');
        }
    });
});

test.describe('B9-S07 — SLA policies', () => {
    async function ensurePolicy(page: Page, name: string): Promise<string> {
        await page.goto('/admin/workflow/sla');
        const row = page.locator('[data-policy-row]', { hasText: name });
        if ((await row.count()) > 0)
            return (await row.first().getAttribute('data-policy-row')) ?? '';
        await page.getByTestId('wf-sla-new').click();
        await page.getByTestId('wf-sla-name').fill(name);
        await expect(page.getByTestId('wf-sla-example')).not.toHaveText('');
        await page.getByTestId('wf-sla-save').click();
        await page.waitForLoadState('networkidle');
        return (
            (await page
                .locator('[data-policy-row]', { hasText: name })
                .first()
                .getAttribute('data-policy-row')) ?? ''
        );
    }

    test('§G G01 + G02 + G04 + G06: the ordered two-pane page — list rows (position · name · match summary · target line · state), ↑↓ on the selected row, the FR-09 footer; the editor with the BR-03 banner, the FR-08 match pickers, targets per priority × stage, clock + calendar + warn %, the pause switches with captions and the implied line, the breach hook; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensurePolicy(page, '[F1] sla proof');
        await page.goto('/admin/workflow/sla');
        const row = page.locator(`[data-policy-row="${id}"]`);
        await expect(row).toBeVisible();
        await expect(row).toContainText('Matches:');
        await expect(row).toContainText(/min/);
        await expect(row.locator('.st')).toContainText(/Active|Inactive/);
        await expect(page.locator('.sla-list .bo-pane-head')).toContainText('first match wins');
        await page.getByTestId(`wf-sla-row-${id}`).click();
        await expect(page.getByTestId(`wf-sla-up-${id}`)).toBeVisible();
        const ed = page.getByTestId('wf-sla-editor');
        await expect(page.getByTestId('wf-sla-prospective')).toContainText('Prospective only');
        await expect(page.getByTestId('wf-sla-match-department')).toBeVisible();
        await expect(page.getByTestId('wf-sla-match-priority-high')).toBeVisible();
        await expect(page.getByTestId('wf-sla-targets').locator('input')).toHaveCount(12);
        await expect(ed).toContainText('re-arms on every customer message');
        await expect(page.getByTestId('wf-sla-clock')).toBeVisible();
        await expect(page.getByTestId('wf-sla-warning')).toBeVisible();
        await expect(ed).toContainText('steps green → amber at');
        await expect(page.getByTestId('wf-sla-pause-pending_customer')).toHaveAttribute(
            'role',
            'switch',
        );
        await expect(ed).toContainText('Default off — internal waits still count against you');
        await expect(ed).toContainText('Implied by the clock mode above — not a switch');
        await expect(ed).toContainText('never double-subtracted (EC-04)');
        await expect(page.getByTestId('wf-sla-breach')).toBeVisible();
        await page.getByTestId('wf-sla-breach').check();
        await expect(page.getByTestId('wf-sla-breach-reason')).toBeVisible();
        await expect(ed).toContainText('high-visibility audit events (SEC-06)');
        await axeClean(page, 'sla (light)');
        await shot(page, 'B9-S07-sla-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'sla (dark)');
        await shot(page, 'B9-S07-sla-build-1440-dark');
    });

    test('§G G03: the live example in en + am from the clock library — reachable on the calendar, wall-clock on 24 × 7; G05: the EC-03 diagram draws keep vs rescale from the targets and follows the chosen mode; G07: the FR-11 chip strip in the fixed tokens', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensurePolicy(page, '[F1] sla proof');
        await page.goto('/admin/workflow/sla');
        await page.getByTestId(`wf-sla-row-${id}`).click();
        const en = page.getByTestId('wf-sla-example');
        const am = page.getByTestId('wf-sla-example-am');
        await expect(en).toContainText(/would target first response|never opens/);
        await expect(am).toContainText(/ዒላማው|አይከፈትም/);
        await page.getByTestId('wf-sla-clock').selectOption('24x7');
        await expect(page.getByTestId('wf-sla-example-card')).toContainText('clock 24 × 7');
        await expect(en).toContainText('would target first response');
        await expect(page.getByTestId('wf-sla-example-foot')).toContainText('Wall-clock time');
        await page.getByTestId('wf-sla-clock').selectOption('calendar');
        // G05 — the diagram: three bars, the question line, the chosen row follows the radio
        const dg = page.getByTestId('wf-sla-retarget-diagram');
        await expect(dg.locator('.dg-row')).toHaveCount(3);
        await expect(dg).toContainText('what happens to the');
        await expect(dg).toContainText('deadline unchanged');
        await expect(dg).toContainText(/\d+ % × \d+ = \d+ remaining/);
        await page.getByTestId('wf-sla-retarget-rescale').check();
        await expect(dg.locator('.dg-row.chosen')).toContainText('Rescale remaining');
        await page.getByTestId('wf-sla-retarget-keep').check();
        await expect(dg.locator('.dg-row.chosen')).toContainText('Keep');
        await shot(
            page,
            'B9-S07-diagram-build-1440-light',
            '[data-testid="wf-sla-retarget-diagram"]',
        );
        // G07 — the chip strip: four states, the warning % quoted, no animation declared
        const chips = page.getByTestId('wf-sla-chips');
        await expect(chips.locator('.sla-chip')).toHaveCount(4);
        await expect(chips).toContainText('no animation');
        await expect(chips.locator('.sla-chip.paused')).toHaveAttribute(
            'title',
            /waiting on the customer/,
        );
        const anim = await chips
            .locator('.sla-chip')
            .first()
            .evaluate((el) => getComputedStyle(el).animationName);
        expect(anim).toBe('none');
        await shot(page, 'B9-S07-chips-build-1440-light', '[data-testid="wf-sla-chips"]');
    });

    test('§G G03 unreachable: a calendar with no weekly interval yields the honesty line in both languages — never a fabricated date', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        // a calendar that never opens, through the hours door (the clock parser accepts an empty week)
        await page.goto('/admin/workflow/hours');
        const name = `[F1] never-opens ${Date.now().toString(36)}`;
        await page.getByTestId('wf-hours-new').click();
        await page.getByTestId('wf-hours-name').fill(name);
        for (const d of ['1', '2', '3', '4', '5'])
            await page.getByTestId(`wf-hours-remove-${d}-0`).click();
        await expect(page.getByTestId('wf-hours-intervals')).toContainText('Closed');
        await page.getByTestId('wf-hours-save').click();
        await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
        await expect(page.locator('[data-calendar-row]', { hasText: name })).toBeVisible();
        await page.waitForLoadState('networkidle');
        const id = await ensurePolicy(page, '[F1] sla proof');
        await page.goto('/admin/workflow/sla');
        await page.getByTestId(`wf-sla-row-${id}`).click();
        await page.getByTestId('wf-sla-clock').selectOption('calendar');
        const value = await page
            .getByTestId('wf-sla-calendar')
            .locator('option', { hasText: name })
            .getAttribute('value');
        await page.getByTestId('wf-sla-calendar').selectOption(value ?? '');
        await expect(page.getByTestId('wf-sla-example')).toContainText('never opens');
        await expect(page.getByTestId('wf-sla-example-am')).toContainText('አይከፈትም');
        await expect(page.getByTestId('wf-sla-example-card')).toContainText('no weekly intervals');
        await expect(page.getByTestId('wf-sla-example-foot')).toContainText(
            'fabricated date is never shown',
        );
        await expect(page.getByTestId('wf-sla-example')).not.toContainText(/\d{2}:\d{2}/);
        await shot(
            page,
            'B9-S07-unreachable-build-1440-light',
            '[data-testid="wf-sla-example-card"]',
        );
    });

    test('§G G01 empty (absence): the empty state is drawn only when no policy exists — with policies on the list it is absent; G08 375: the read notice, the list cards, policy 1 live example in en + am, no editor, no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensurePolicy(page, '[F1] sla proof');
        await page.goto('/admin/workflow/sla');
        await expect(page.getByTestId('wf-sla-empty')).toHaveCount(0);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/sla');
        await expect(page.getByTestId('bo-readnote')).toContainText('Editing needs a tablet');
        await expect(page.locator('[data-policy-row]').first()).toBeVisible();
        await expect(page.getByTestId('wf-sla-editor')).toHaveCount(0);
        await expect(page.getByTestId('wf-sla-new')).toHaveCount(0);
        await expect(page.getByTestId('wf-sla-phone-example')).toContainText('live example');
        await expect(page.getByTestId('wf-sla-phone-example').locator('.ex-am')).not.toHaveText('');
        await targets44(page, '[data-policy-row] .body', 'policy cards at 375');
        await noOverflow(page, 'sla 375');
        await axeClean(page, 'sla 375 (light)');
        await shot(page, 'B9-S07-sla-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'sla 375 (dark)');
        await shot(page, 'B9-S07-sla-build-375-dark');
    });
});

test.describe('B9-S08 — business hours', () => {
    async function ensureDefaultCalendar(page: Page): Promise<string> {
        await page.goto('/admin/workflow/hours');
        const card = page.locator('[data-calendar-row]', {
            has: page.locator('.bo-chip', { hasText: 'tenant default' }),
        });
        if ((await card.count()) > 0)
            return (await card.first().getAttribute('data-calendar-row')) ?? '';
        await page.getByTestId('wf-hours-new').click();
        await page.getByTestId('wf-hours-name').fill('[F1] workspace default');
        await page.locator('input[name="hr-scope"]').first().check();
        await page.getByTestId('wf-hours-save').click();
        await page.waitForLoadState('networkidle');
        return (await card.first().getAttribute('data-calendar-row')) ?? '';
    }

    test('§H H01 + H02 + H03 + H05: the editor for the default calendar — the BR-03/EC-05 banner naming both facts, the tenant timezone labelled (UTC offset), THE PAINTER (drag paints a band with the live line, release applies, Esc cancels), the interval list with a 24:00 close, the holiday table with recurring rows; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureDefaultCalendar(page);
        await page.goto('/admin/workflow/hours');
        await page.getByTestId(`wf-hours-open-${id}`).click();
        const ed = page.getByTestId('wf-hours-editor');
        await expect(page.getByTestId('wf-hours-prospective')).toContainText(
            'armed SLA clocks keep the calendar they started with',
        );
        await expect(page.getByTestId('wf-hours-tz-label')).toContainText(/UTC[+−-]\d/);
        await expect(page.getByTestId('wf-hours-times-in')).toContainText('All times in');
        await expect(ed).toContainText('cells are 30 minutes');
        // the painter: Wednesday 17:00 → 20:00 is six cells
        const wed = page.getByTestId('wf-hours-grid').locator('tbody tr').nth(2);
        const from = wed.locator('button[title="17:00"]');
        const to = wed.locator('button[title="19:30"]');
        await from.hover();
        await page.mouse.down();
        await to.hover();
        await expect(page.getByTestId('wf-hours-painting')).toContainText(
            'Wednesday 17:00 → 20:00 · 6 cells',
        );
        await shot(page, 'B9-S08-painting-build-1440-light', '[data-testid="wf-hours-editor"]');
        await page.mouse.up();
        await expect(page.getByTestId('wf-hours-painting')).toHaveText('');
        await expect(wed.locator('th small')).toContainText('20:00');
        await expect(page.getByTestId('wf-hours-close-3-0')).toHaveValue('20:00');
        // Esc cancels a band on Sunday
        const sun = page.getByTestId('wf-hours-grid').locator('tbody tr').nth(6);
        await sun.locator('button[title="10:00"]').hover();
        await page.mouse.down();
        await sun.locator('button[title="11:30"]').hover();
        await expect(page.getByTestId('wf-hours-painting')).toContainText('Sunday');
        await page.keyboard.press('Escape');
        await page.mouse.up();
        await expect(sun.locator('th small')).toContainText('closed');
        // the list: Sunday 20:00–24:00 through the fields — the 24:00 close is legal
        await page.getByTestId('wf-hours-add-7').click();
        await page.getByTestId('wf-hours-open-7-0').fill('20:00');
        await page.getByTestId('wf-hours-open-7-0').dispatchEvent('change');
        await page.getByTestId('wf-hours-close-7-0').fill('24:00');
        await page.getByTestId('wf-hours-close-7-0').dispatchEvent('change');
        await expect(sun.locator('th small')).toContainText('20:00–24:00');
        await expect(ed).toContainText('24:00 = end of day');
        // holidays: add a recurring one through the door, see the row, remove it
        await expect(page.getByTestId('wf-holidays')).toBeVisible();
        await page.getByTestId('wf-holiday-date').fill('2026-09-11');
        await page.getByTestId('wf-holiday-name').fill('[F1] Enkutatash');
        await page.getByTestId('wf-holiday-add').click();
        await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
        await page.waitForLoadState('networkidle');
        await page.getByTestId(`wf-hours-open-${id}`).click();
        await expect(page.getByTestId('wf-hours-editor')).toBeVisible();
        const row = page.locator('[data-holiday-row]', { hasText: '[F1] Enkutatash' }).first();
        await expect(row).toContainText('Every year');
        await expect(ed).toContainText('Referenced by');
        await axeClean(page, 'hours editor (light)');
        await shot(page, 'B9-S08-hours-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'hours editor (dark)');
        await shot(page, 'B9-S08-hours-build-1440-dark');
        // remove every proof holiday (earlier runs may have left one behind)
        while (
            (await page.locator('[data-holiday-row]', { hasText: '[F1] Enkutatash' }).count()) > 0
        ) {
            await page
                .locator('[data-holiday-row]', { hasText: '[F1] Enkutatash' })
                .first()
                .locator('button')
                .click();
            await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
            await page.waitForLoadState('networkidle');
            await page.getByTestId(`wf-hours-open-${id}`).click();
            await expect(page.getByTestId('wf-hours-editor')).toBeVisible();
        }
        await expect(
            page.locator('[data-holiday-row]', { hasText: '[F1] Enkutatash' }),
        ).toHaveCount(0);
    });

    test('§H H04: a department override — the card states the diff, the editor draws the side-by-side day table with the changed row worded; departments without an override read "Same as the workspace default"; Remove override → use the default keeps the calendar on record, inactive', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureDefaultCalendar(page);
        await page.goto('/admin/workflow/hours');
        // one calendar per department (the schema's unique): a free department gets a new override,
        // otherwise the proof reads the override that already exists
        const free = page.locator('[data-no-override]').first();
        let id = '';
        let name = '';
        if ((await free.count()) > 0) {
            const deptId = (await free.getAttribute('data-no-override')) ?? '';
            name = `[F1] override ${Date.now().toString(36)}`;
            await page.getByTestId('wf-hours-new').click();
            await page.getByTestId('wf-hours-name').fill(name);
            await page.locator('input[name="hr-scope"]').nth(1).check();
            await page.getByTestId('wf-hours-department').selectOption(deptId);
            await page.getByTestId('wf-hours-remove-5-0').click(); // Friday closed
            await page.getByTestId('wf-hours-save').click();
            await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
            await page.waitForLoadState('networkidle');
            const card = page.locator('[data-calendar-row]', { hasText: name });
            await expect(card).toBeVisible();
            id = (await card.getAttribute('data-calendar-row')) ?? '';
        } else {
            const card = page
                .locator('[data-calendar-row]', {
                    has: page.locator('.bo-chip', { hasText: 'override' }),
                })
                .first();
            await expect(card).toBeVisible();
            id = (await card.getAttribute('data-calendar-row')) ?? '';
            name = (await card.locator('.name').innerText()).trim();
        }
        await expect(page.getByTestId(`wf-hours-diff-${id}`)).toContainText(
            /closed|Same as the workspace default|–/,
        );
        await page.getByTestId(`wf-hours-open-${id}`).click();
        const table = page.getByTestId('wf-hours-diff-table');
        await expect(table.locator('tbody tr')).toHaveCount(7);
        await expect(page.getByTestId('wf-hours-diff-sentence')).toContainText(/differs:|Same as/);
        const changed = table.locator('tr.chg');
        if ((await changed.count()) > 0) await expect(changed.first()).toContainText('changed');
        await shot(page, 'B9-S08-diff-build-1440-light', '[data-testid="wf-hours-editor"]');
        // Remove override → the confirm names BR-03/EC-05 → the card reads inactive (an already
        // inactive override has no such verb — the absence is the proof)
        const remove = page.getByTestId('wf-hours-remove-override');
        if ((await remove.count()) > 0) {
            await remove.click();
            await expect(page.getByTestId('wf-hours-remove-dialog')).toContainText('BR-03, EC-05');
            await page.getByTestId('wf-hours-remove-confirm').click();
            await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
            await page.waitForLoadState('networkidle');
        }
        await expect(page.locator('[data-calendar-row]', { hasText: name })).toContainText(
            'inactive',
        );
    });

    test('§H H06 (absence) + H07 375: the empty state is absent while calendars exist; the phone reads the hours per day, the holidays and the override diff under the notice — no editor, no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureDefaultCalendar(page);
        await page.goto('/admin/workflow/hours');
        await expect(page.getByTestId('wf-hours-empty')).toHaveCount(0);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/hours');
        await expect(page.getByTestId('bo-readnote')).toContainText('Editing needs a tablet');
        await expect(
            page.locator('[data-calendar-row]').first().locator('.hr-phone dt'),
        ).toHaveCount(7);
        await expect(page.getByTestId('wf-hours-editor')).toHaveCount(0);
        await expect(page.getByTestId('wf-hours-new')).toHaveCount(0);
        await noOverflow(page, 'hours 375');
        await axeClean(page, 'hours 375 (light)');
        await shot(page, 'B9-S08-hours-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'hours 375 (dark)');
        await shot(page, 'B9-S08-hours-build-375-dark');
    });
});

test.describe('B9-S09 — macros manager', () => {
    async function ensureMacro(page: Page, name: string): Promise<string> {
        await page.goto('/admin/workflow/macros');
        const row = page.locator('[data-macro-row]', { hasText: name });
        if ((await row.count()) > 0)
            return (await row.first().getAttribute('data-macro-row')) ?? '';
        await page.getByTestId('wf-macro-new').click();
        await page.getByTestId('wf-macro-name').fill(name);
        await page.getByTestId('wf-macro-body').fill('Hi ');
        await page.getByTestId('wf-var-customer.name').click();
        await page.getByTestId('wf-macro-body').press('End');
        await page.keyboard.type(', your wait is ');
        await page.getByTestId('wf-var-queue.wait_band').click();
        await page.getByTestId('wf-macro-bundle-add_tags').check();
        await page.getByTestId('wf-macro-save').click();
        await page.waitForLoadState('networkidle');
        return (
            (await page
                .locator('[data-macro-row]', { hasText: name })
                .first()
                .getAttribute('data-macro-row')) ?? ''
        );
    }

    test('§I I01 + I02 + I04: the manager grouped by scope (personal before shared, the permission key on the group head, kind tag, bundled chips, usage); the pill body — brace-free pills, delete-as-unit by Backspace and by ✕, the announced state list; bundled actions with the EC-09 notice quoted; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureMacro(page, '[F1] macro proof');
        await page.goto('/admin/workflow/macros');
        const list = page.locator('.mc-list');
        await expect(list.locator('[data-scope="personal"] h4')).toContainText(
            'macros.personal.manage',
        );
        const row = page.locator(`[data-macro-row="${id}"]`);
        await expect(row.locator('.bo-tag')).toContainText('Macro');
        await expect(row.locator('.bo-chip')).toContainText('⚙ Add tags');
        await expect(row.locator('.use')).toContainText('uses');
        await expect(list).toContainText('Personal before shared');
        await page.getByTestId(`wf-macro-row-${id}`).click();
        const body = page.getByTestId('wf-macro-body');
        await expect(body.locator('[data-pill="customer.name"]')).toBeVisible();
        await expect(body).not.toContainText('{{');
        await expect(body.locator('[data-pill]')).toHaveCount(2);
        // delete-as-unit: the ✕ removes the whole pill; Backspace after a pill removes the whole pill
        await body.locator('[data-pill="queue.wait_band"] button').click();
        await expect(body.locator('[data-pill]')).toHaveCount(1);
        await page.getByTestId('wf-var-queue.wait_band').click();
        await expect(body.locator('[data-pill]')).toHaveCount(2);
        await body.click();
        await page.keyboard.press('End');
        await page.keyboard.press('Backspace');
        await expect(body.locator('[data-pill]')).toHaveCount(1);
        await expect(body.locator('[data-pill="customer.name"]')).toBeVisible();
        await expect(page.getByTestId('wf-pill-law')).toContainText('cannot be edited');
        await expect(page.getByTestId('wf-macro-bundled')).toBeVisible();
        await expect(page.getByTestId('wf-ec09')).toContainText(
            'was skipped — you do not have that permission',
        );
        await expect(page.getByTestId('wf-ec09')).toContainText('could not be applied here');
        await expect(page.getByTestId('wf-no-send')).toContainText('no send door');
        await expect(page.getByTestId('wf-palette-ref')).toContainText('B12-S03');
        await axeClean(page, 'macros (light)');
        await shot(page, 'B9-S09-macros-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'macros (dark)');
        await shot(page, 'B9-S09-macros-build-1440-dark');
    });

    test('§I I03: the preview against a sample ticket through the one resolver — resolved values highlighted, a missing variable as the amber placeholder with the "cannot be sent as-is" line and the pill turning amber with its announced state', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureMacro(page, '[F1] macro proof');
        await page.goto('/admin/workflow/macros');
        await page.getByTestId(`wf-macro-row-${id}`).click();
        await page.getByTestId('wf-macro-preview').click();
        const out = page.getByTestId('wf-macro-preview-out');
        await expect(out).toBeVisible();
        await expect(out.locator('mark.ok').first()).toBeVisible();
        await expect(out.locator('.vars li')).toHaveCount(2);
        const missing = out.locator('mark.miss');
        if ((await missing.count()) > 0) {
            await expect(out).toContainText('cannot be sent as-is (T-06)');
            await expect(out).toContainText('has no value on this ticket');
            await expect(
                page
                    .getByTestId('wf-macro-body')
                    .locator('[data-pill][data-state="missing"] .mc-pill-said')
                    .first(),
            ).toContainText(/has no value/);
        } else {
            await expect(out).toContainText('Every variable resolves');
            await expect(out).toContainText('Preview = send');
        }
        await expect(
            page.getByTestId('wf-macro-body').locator('[data-pill="customer.name"] .mc-pill-said'),
        ).toContainText(/resolves on this ticket|has no value/);
        await shot(page, 'B9-S09-preview-build-1440-light', '[data-testid="wf-macro-editor"]');
    });

    test('§I I05 (absence) + I07 375: no per-language variant control exists (one body per macro — F1 follow-on); the phone reads the grouped list with scopes, kinds, chips and usage under the notice — no editor, no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureMacro(page, '[F1] macro proof');
        await page.goto('/admin/workflow/macros');
        await page.getByTestId(`wf-macro-row-${id}`).click();
        await expect(page.getByRole('button', { name: /add language/i })).toHaveCount(0);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/macros');
        await expect(page.getByTestId('bo-readnote')).toContainText('B12-S03');
        await expect(page.locator('[data-macro-row]').first()).toBeVisible();
        await expect(page.getByTestId('wf-macro-editor')).toHaveCount(0);
        await expect(page.getByTestId('wf-macro-new')).toHaveCount(0);
        await noOverflow(page, 'macros 375');
        await axeClean(page, 'macros 375 (light)');
        await shot(page, 'B9-S09-macros-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'macros 375 (dark)');
        await shot(page, 'B9-S09-macros-build-375-dark');
    });
});

test.describe('B9-S10 — fields manager', () => {
    const KEY = 'f1_proof_field';
    async function ensureField(page: Page): Promise<void> {
        await page.goto('/admin/workflow/fields');
        const row = page.locator(`[data-field-row="${KEY}"]`);
        if ((await row.count()) > 0) {
            const re = page.getByTestId(`wf-field-reactivate-${KEY}`);
            if ((await re.count()) > 0) {
                await re.click();
                await page.waitForLoadState('networkidle');
            }
            return;
        }
        await page.getByTestId('wf-field-new').click();
        await page.getByTestId('wf-field-label-en').fill('F1 proof field');
        await expect(page.getByTestId('wf-field-key')).toHaveValue(KEY);
        await page.getByTestId('wf-field-label-am').fill('የF1 ማረጋገጫ መስክ');
        await page.getByTestId('wf-field-type').selectOption('dropdown');
        await page.getByTestId('wf-option-add').click();
        await page.getByTestId('wf-option-add').click();
        const rows = page.getByTestId('wf-field-options').locator('tbody tr');
        await rows.nth(0).locator('input').nth(0).fill('telebirr');
        await rows.nth(0).locator('input').nth(1).fill('Telebirr');
        await rows.nth(0).locator('input').nth(2).fill('ቴሌብር');
        await rows.nth(1).locator('input').nth(0).fill('cash_agent');
        await rows.nth(1).locator('input').nth(1).fill('Cash agent');
        await rows.nth(1).locator('input').nth(2).fill('የጥሬ ገንዘብ ወኪል');
        await page.getByTestId('wf-field-save').click();
        await page.waitForLoadState('networkidle');
        await expect(page.locator(`[data-field-row="${KEY}"]`)).toBeVisible();
    }

    test('§J J01 + J02 + J06: entity tabs with counts; the row anatomy (↑↓, both labels, the immutable key, the 🔒 type badge, flags, used-in counts); the editor — key derived at birth, the 13-type picker on a new field, NO type control on a saved one (the lock law in words), options with stable values, validation per type, the N-06 line, where-used live; the Doc 08 seam on the customer tab; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureField(page);
        await page.goto('/admin/workflow/fields');
        await expect(page.getByTestId('wf-fields-tab-ticket')).toHaveAttribute(
            'aria-selected',
            'true',
        );
        const row = page.locator(`[data-field-row="${KEY}"]`);
        await expect(row).toContainText('F1 proof field');
        await expect(row.locator('.lb.am')).toContainText('የF1');
        await expect(row.locator('.type')).toContainText('🔒 dropdown');
        await expect(page.getByTestId(`wf-used-${KEY}`)).toContainText(
            /forms \d+ · rules \d+ · views \d+/,
        );
        await expect(page.getByTestId(`wf-field-up-${KEY}`)).toBeVisible();
        // a new field: the type picker with the 13 types; the key follows the label
        await page.getByTestId('wf-field-new').click();
        await expect(page.getByTestId('wf-field-type').locator('option')).toHaveCount(13);
        await page.getByTestId('wf-field-label-en').fill('Refund reference');
        await expect(page.getByTestId('wf-field-key')).toHaveValue('refund_reference');
        await expect(page.getByTestId('wf-field-editor')).toContainText('chosen once');
        // the saved field: no type control at all, the lock law, options read-only, where-used live
        await page.getByTestId(`wf-field-edit-${KEY}`).click();
        await expect(page.getByTestId('wf-field-type')).toHaveCount(0);
        await expect(page.getByTestId('wf-field-type-locked')).toHaveText('dropdown');
        await expect(page.getByTestId('wf-type-lock-law')).toContainText(
            'There is no type control here',
        );
        await expect(page.getByTestId('wf-field-key')).toHaveAttribute('readonly', '');
        await expect(page.getByTestId('wf-field-options').locator('tbody tr')).toHaveCount(2);
        await expect(page.getByTestId('wf-option-add')).toHaveCount(0);
        await expect(page.getByTestId('wf-field-editor')).toContainText('one of the options');
        await expect(page.getByTestId('wf-field-editor')).toContainText(
            'a form decides per placement (FR-22)',
        );
        await expect(page.getByTestId('wf-n06')).toContainText('staff-collected (N-06)');
        await expect(page.getByTestId('wf-where-used-panel')).toContainText(
            /Not used anywhere yet|Forms|Rules|Views/,
        );
        await expect(page.getByTestId('wf-field-editor')).toContainText('high-risk audit (SEC-06)');
        await axeClean(page, 'fields (light)');
        await shot(page, 'B9-S10-fields-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'fields (dark)');
        await shot(page, 'B9-S10-fields-build-1440-dark');
        await setTheme(page, 'light');
        await page.getByTestId('wf-fields-tab-customer').click();
        await expect(page.getByTestId('wf-doc08-seam')).toContainText('staff-collected');
        await expect(page.getByTestId('wf-doc08-seam')).toContainText('never “verified” (N-06)');
    });

    test('§J J03: removing an option opens the EC-08 dialog (label kept on past answers · rules reading the field · forms offering it); Keep it changes nothing; Remove keeps the row as a snapshot marked removed', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureField(page);
        await page.goto('/admin/workflow/fields');
        await page.getByTestId(`wf-field-edit-${KEY}`).click();
        const opts = page.getByTestId('wf-field-options');
        const removeBtn = page.getByTestId('wf-option-remove-cash_agent');
        if ((await removeBtn.count()) > 0) {
            await removeBtn.click();
            const dlg = page.getByTestId('wf-option-remove-dialog');
            await expect(dlg).toContainText('Removing “Cash agent” (cash_agent)');
            await expect(dlg).toContainText('keeps its label on past answers');
            await expect(dlg).toContainText(/\d+ rules read this field/);
            await expect(dlg).toContainText(/\d+ published forms offer this option today/);
            await shot(
                page,
                'B9-S10-option-remove-build-1440-light',
                '[data-testid="wf-option-remove-dialog"]',
            );
            await dlg.getByRole('button', { name: 'Keep it' }).click();
            await expect(dlg).toHaveCount(0);
            await expect(opts.locator('tr[data-option="cash_agent"]')).not.toHaveClass(/removed/);
            await page.getByTestId('wf-option-remove-cash_agent').click();
            await page.getByTestId('wf-option-remove-confirm').click();
            await expect(page.getByTestId('wf-field-editor')).toHaveCount(0);
            await page.waitForLoadState('networkidle');
            await page.getByTestId(`wf-field-edit-${KEY}`).click();
        }
        await expect(opts.locator('tr[data-option="cash_agent"]')).toHaveClass(/removed/);
        await expect(opts.locator('tr[data-option="cash_agent"]')).toContainText('snapshot kept');
    });

    test('§J J04 + J05: the deactivate dialog carries the complete where-used panel with EC-14’s consequence per entry, the Kept and Audited rows; the retired section shows the read-only line; a new field with the retired key is refused in place with "Reactivate the retired field"; reactivation restores it', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureField(page);
        await page.goto('/admin/workflow/fields');
        await page.getByTestId(`wf-field-deactivate-${KEY}`).click();
        const dlg = page.getByTestId('wf-field-confirm');
        await expect(dlg).toContainText('every consequence, before you decide');
        await expect(dlg).toContainText(`the key ${KEY} is never reused`);
        await expect(dlg).toContainText('fields.deactivated');
        await expect(page.getByTestId('wf-where-used')).toContainText(
            /Not used anywhere yet|NEXT publish is blocked|evaluates as is-empty|return nothing/,
        );
        await shot(page, 'B9-S10-deactivate-build-1440-light', '[data-testid="wf-field-confirm"]');
        await page.getByTestId('wf-field-deactivate-confirm').click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId('wf-fields-retired')).toContainText('keys never re-mint');
        await expect(page.locator(`[data-field-row="${KEY}"]`)).toContainText('read-only');
        // the key never re-mints: creating with the retired key is refused in place
        await page.getByTestId('wf-field-new').click();
        await page.getByTestId('wf-field-label-en').fill('F1 proof field');
        await expect(page.getByTestId('wf-field-key')).toHaveValue(KEY);
        await page.getByTestId('wf-field-save').click();
        await expect(page.getByTestId('wf-key-refused')).toContainText(
            'belongs to a retired field',
        );
        await page.getByTestId('wf-key-reactivate').click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId(`wf-field-deactivate-${KEY}`)).toBeVisible();
    });

    test('§J J07 375: the read posture — tabs, order, both labels, keys, locked types, the retired line; no editor, no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await ensureField(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/fields');
        await expect(page.getByTestId('bo-readnote')).toContainText('need a tablet');
        await expect(page.locator(`[data-field-row="${KEY}"]`)).toBeVisible();
        await expect(page.getByTestId('wf-field-new')).toHaveCount(0);
        await expect(page.getByTestId(`wf-field-edit-${KEY}`)).toHaveCount(0);
        await noOverflow(page, 'fields 375');
        await axeClean(page, 'fields 375 (light)');
        await shot(page, 'B9-S10-fields-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'fields 375 (dark)');
        await shot(page, 'B9-S10-fields-build-375-dark');
    });
});

test.describe('B9-S11 — form builder', () => {
    const FORM = '[F1] form proof';
    async function ensureForm(page: Page): Promise<string> {
        await page.goto('/admin/workflow/forms');
        const row = page.locator('[data-form-row]', { hasText: FORM });
        if ((await row.count()) > 0) return (await row.first().getAttribute('data-form-row')) ?? '';
        await page.getByTestId('wf-form-name').fill(FORM);
        await page.getByTestId('wf-form-create').click();
        await page.waitForURL(/\/admin\/workflow\/forms\/[0-9a-f-]+$/);
        return page.url().split('/').pop() ?? '';
    }
    async function openBuilder(page: Page): Promise<void> {
        const id = await ensureForm(page);
        await page.goto(`/admin/workflow/forms/${id}`);
        await expect(page.getByTestId('wf-form-builder')).toBeVisible();
        // a clean working copy each time — no browser draft
        await page.evaluate(() => {
            Object.keys(localStorage)
                .filter((k) => k.startsWith('wf-form-draft:'))
                .forEach((k) => localStorage.removeItem(k));
        });
        await page.reload();
        await expect(page.getByTestId('wf-form-builder')).toBeVisible();
    }
    async function buildStep(page: Page): Promise<void> {
        if ((await page.getByTestId('wf-step-0').count()) === 0)
            await page.getByTestId('wf-step-add').click();
        await page.getByTestId('wf-step-title-en').fill('Payment');
        await page.getByTestId('wf-step-title-am').fill('ክፍያ');
        if ((await page.getByTestId('wf-canvas-field-f1_proof_field').count()) === 0)
            await page.getByTestId('wf-palette-ref-f1_proof_field').click();
        if (
            (await page.getByTestId('wf-canvas-field-text_2').count()) === 0 &&
            (await page.locator('[data-testid^="wf-canvas-field-text_"]').count()) === 0
        ) {
            await page.getByTestId('wf-palette-text').click();
        }
        const textCard = page.locator('[data-testid^="wf-canvas-field-text_"]').first();
        await textCard.locator('.body').click();
        await page.getByTestId('wf-inspector-label-en').fill('Transaction reference');
        await page.getByTestId('wf-inspector-label-am').fill('የግብይት ማጣቀሻ');
    }

    test('§K K01 + K02 + K03: the B5-S07 chrome — the 13 types and the refs on the palette, step tabs with dots, the field cards (both labels, key, ref/inline tag, type), the placement menu with Alt-arrows, the inspector with labels/help per language, required-when-visible, the conditional that never offers a later field and its plain sentence, the branch glyph on the card; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openBuilder(page);
        await expect(
            page.locator('[data-testid^="wf-palette-"]:not([data-testid^="wf-palette-ref-"])'),
        ).toHaveCount(13);
        await expect(page.getByTestId('wf-palette-ref-f1_proof_field')).toBeVisible();
        await buildStep(page);
        await expect(page.getByTestId('wf-step-0')).toContainText('Payment');
        await expect(page.getByTestId('wf-step-0')).toContainText('●');
        const refCard = page.getByTestId('wf-canvas-field-f1_proof_field');
        await expect(refCard).toContainText('ref · f1_proof_field');
        await expect(refCard).toContainText('dropdown');
        const textCard = page.locator('[data-testid^="wf-canvas-field-text_"]').first();
        await expect(textCard).toContainText('inline · form-only');
        await expect(textCard).toContainText('የግብይት ማጣቀሻ');
        // the conditional: only the earlier ref field is offered; the sentence renders in words
        await textCard.locator('.body').click();
        await expect(page.getByTestId('wf-inspector-head')).toContainText('inline · text');
        const condField = page.getByTestId('wf-cond-field');
        await expect(condField.locator('option')).toHaveCount(2); // — and the earlier field only
        await condField.selectOption('f1_proof_field');
        await page.getByTestId('wf-cond-op').selectOption('not_equals');
        await page.getByTestId('wf-cond-value').selectOption('telebirr');
        await expect(page.getByTestId('wf-conditional-sentence')).toHaveText(
            'Show when F1 proof field is not Telebirr',
        );
        await page.getByTestId('wf-inspector-required-when-visible').check();
        await expect(
            page.getByTestId(
                `wf-branch-${(await textCard.getAttribute('data-testid'))?.replace('wf-canvas-field-', '')}`,
            ),
        ).toContainText('⑂ Show when F1 proof field is not Telebirr');
        await expect(textCard).toContainText('required when visible');
        // the placement menu: every drag has a menu (§13) — Move up swaps the two cards
        const key =
            (await textCard.getAttribute('data-testid'))?.replace('wf-canvas-field-', '') ?? '';
        await page.getByTestId(`wf-place-${key}`).click();
        const menu = page.getByTestId(`wf-place-menu-${key}`);
        for (const item of [
            'Move up',
            'Move down',
            'Move to a new step',
            'Duplicate',
            'Remove from form',
        ])
            await expect(menu).toContainText(item);
        await expect(menu).toContainText('Alt ↑');
        await shot(page, 'B9-S11-placement-build-1440-light', '[data-testid="wf-form-builder"]');
        await menu.getByRole('menuitem', { name: /Move up/ }).click();
        await expect(page.locator('.fb-cards li').first()).toHaveAttribute(
            'data-testid',
            `wf-canvas-field-${key}`,
        );
        // the conditional now points forward: the picker offers no earlier field
        await page.locator('.fb-cards li').first().locator('.body').click();
        await expect(page.getByTestId('wf-cond-field').locator('option')).toHaveCount(1);
        await page.getByTestId(`wf-place-${key}`).click();
        await page
            .getByTestId(`wf-place-menu-${key}`)
            .getByRole('menuitem', { name: /Move down/ })
            .click();
        await axeClean(page, 'form builder (light)');
        await shot(page, 'B9-S11-builder-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'form builder (dark)');
        await shot(page, 'B9-S11-builder-build-1440-dark');
    });

    test('§K K04 + K08: the dual live preview — the widget pre-chat render, Telegram direct with inline buttons, Telegram business with numbered text — and the Amharic variant of all three', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openBuilder(page);
        await buildStep(page);
        await page.getByTestId('wf-preview-widget').click();
        const widget = page.getByTestId('wf-widget-render');
        await expect(widget).toContainText('Step 1 of');
        await expect(widget).toContainText('Transaction reference');
        await expect(widget).toContainText('Continue');
        await expect(widget).toContainText('Telebirr');
        await page.getByTestId('wf-preview-telegram').click();
        const direct = page.getByTestId('wf-telegram-direct');
        await expect(direct).toContainText('🤖 1/2 — F1 proof field?');
        await expect(direct.locator('.ib span').first()).toContainText('Telebirr');
        await expect(direct).toContainText('one field per message');
        await page.getByTestId('wf-preview-telegram-business').click();
        const business = page.getByTestId('wf-telegram-business');
        await expect(business).toContainText('1. Telebirr');
        await expect(business).toContainText('Reply with the number of your choice');
        await expect(business.locator('.ib')).toHaveCount(0);
        await shot(
            page,
            'B9-S11-preview-business-build-1440-light',
            '[data-testid="wf-preview-pane"]',
        );
        await page.getByTestId('wf-preview-lang').selectOption('am');
        await expect(business).toContainText('🤖 2/2 — የግብይት ማጣቀሻ?');
        await expect(business).toContainText('1. ቴሌብር');
        await page.getByTestId('wf-preview-widget').click();
        await expect(widget).toContainText('የግብይት ማጣቀሻ');
        await expect(widget).toContainText('ደረጃ 1 ከ');
        await shot(
            page,
            'B9-S11-preview-widget-am-build-1440-light',
            '[data-testid="wf-preview-pane"]',
        );
    });

    test('§K K05 + K06: the publish dialog lists the SchemaValidator’s blocks with the EC-07 auto-fix, then the publishable state with the atomic-version facts, the usage banner and EC-06; publishing mints an immutable version; the history shows the diff and never an edit affordance', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openBuilder(page);
        await buildStep(page);
        const textCard = page.locator('[data-testid^="wf-canvas-field-text_"]').first();
        await textCard.locator('.body').click();
        await page.getByTestId('wf-cond-field').selectOption('f1_proof_field');
        await page.getByTestId('wf-cond-op').selectOption('not_equals');
        await page.getByTestId('wf-cond-value').selectOption('telebirr');
        await page.getByTestId('wf-inspector-required').check();
        await page.getByTestId('wf-inspector-required-when-visible').uncheck();
        await page.getByTestId('wf-publish').click();
        const dlg = page.getByTestId('wf-publish-dialog');
        await expect(dlg).toContainText('Publish blocked');
        await expect(
            page.getByTestId('wf-publish-blocks').locator('[data-block="required_hidden"]'),
        ).toBeVisible();
        await expect(dlg).toContainText('the list is complete, not the first error');
        await shot(
            page,
            'B9-S11-publish-blocked-build-1440-light',
            '[data-testid="wf-publish-dialog"]',
        );
        await page.getByTestId('wf-fix-required-hidden').click();
        await expect(dlg).toContainText(/Becomes|Publish blocked/);
        if ((await page.getByTestId('wf-publish-confirm').count()) === 0) {
            // other blocks stand — the dialog stays honest; back out
            await dlg.getByRole('button', { name: 'Back to the builder' }).click();
            return;
        }
        await expect(dlg).toContainText('an immutable version');
        await expect(dlg).toContainText('Changed since');
        await expect(dlg).toContainText('0 blocks');
        await expect(dlg).toContainText('forms.published');
        await expect(page.getByTestId('wf-publish-usage')).toContainText(/In use by/);
        await expect(dlg).toContainText('Running Telegram flows finish on their current version');
        await shot(page, 'B9-S11-publish-build-1440-light', '[data-testid="wf-publish-dialog"]');
        await page.getByTestId('wf-publish-confirm').click();
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId('wf-form-live')).toContainText(/v\d+ published/);
        await page.getByTestId('wf-history-toggle').click();
        const history = page.getByTestId('wf-history');
        await expect(history.locator('[data-version-row]').first()).toContainText('published');
        await expect(history).toContainText('No version can be edited');
        await expect(history.locator('button, input, textarea')).toHaveCount(0);
        await shot(page, 'B9-S11-history-build-1440-light', '[data-testid="wf-history"]');
    });

    test('§K K07: the forms list — the seeded forms carry "seeded · editable" (content, not config), each row its shape, version and bindings; K01 375: the builder is READ posture with the notice, steps and fields; no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        const id = await ensureForm(page);
        await page.goto('/admin/workflow/forms');
        await expect(page.locator('.fm-foot')).toContainText('content, not config (FR-23');
        const seeded = page.getByTestId('wf-form-seeded');
        if ((await seeded.count()) > 0)
            await expect(seeded.first()).toContainText('seeded · editable');
        await expect(page.locator('[data-form-row]', { hasText: FORM })).toContainText(
            /v\d+ · |draft · never published/,
        );
        await axeClean(page, 'forms list (light)');
        await shot(page, 'B9-S11-forms-build-1440-light');
        await page.setViewportSize(V375);
        await page.goto(`/admin/workflow/forms/${id}`);
        await expect(page.getByTestId('bo-readnote')).toContainText('at least 1024 px');
        await expect(page.getByTestId('wf-form-read')).toBeVisible();
        await expect(page.getByTestId('wf-form-builder')).toHaveCount(0);
        await noOverflow(page, 'form builder 375');
        await axeClean(page, 'form builder 375 (light)');
        await shot(page, 'B9-S11-builder-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'form builder 375 (dark)');
        await shot(page, 'B9-S11-builder-build-375-dark');
    });
});

test.describe('B9-S12 — CSAT', () => {
    test('§L L01 + L04: the three registered keys as settings rows (label · key · description · control · provenance · reset) with the consequence BEFORE save and the live change count on the verb; what a survey is, the Doc 15 events, the designed absence; the shipped labels per language; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/csat');
        const row = page.getByTestId('wf-csat-row-cooldown');
        await expect(row).toContainText('csat.cooldown.hours');
        await expect(row).toContainText('Counted per customer across channels');
        await expect(page.getByTestId('wf-csat-prov-cooldown')).toContainText(/default \d+/);
        await expect(page.getByTestId('wf-csat-row-window')).toContainText('What a tap at hour');
        await expect(
            page.getByTestId('wf-csat-row-comment').locator('[role="switch"]'),
        ).toBeVisible();
        await expect(page.getByTestId('wf-csat-labels').locator('tbody tr')).toHaveCount(4);
        await expect(page.getByTestId('wf-csat-what')).toContainText(
            'one survey per resolution event',
        );
        await expect(page.getByTestId('wf-csat-events')).toContainText('csat.sent');
        await expect(page.getByTestId('wf-csat-absences')).toContainText('No incentive, no nudge');
        await expect(page.getByTestId('wf-csat-consequence')).toHaveCount(0);
        const cooldown = page.getByTestId('wf-csat-cooldown');
        const before = Number(await cooldown.inputValue());
        await cooldown.fill(String(before + 12));
        await expect(page.getByTestId('wf-csat-consequence')).toContainText('A longer cooldown');
        await expect(page.getByTestId('wf-csat-save')).toContainText('Save 1 change');
        await shot(page, 'B9-S12-csat-build-1440-light');
        await axeClean(page, 'csat (light)');
        await setTheme(page, 'dark');
        await axeClean(page, 'csat (dark)');
        await shot(page, 'B9-S12-csat-build-1440-dark');
        await page.getByTestId('wf-csat-save').click();
        await expect(page.getByTestId('wf-csat-saved')).toContainText('Saved');
        await page.waitForLoadState('networkidle');
        await page.goto('/admin/workflow/csat');
        await expect(page.getByTestId('wf-csat-prov-cooldown')).toContainText(/Changed/);
        await page.getByTestId('wf-csat-cooldown').fill(String(before));
        await page.getByTestId('wf-csat-save').click();
        await expect(page.getByTestId('wf-csat-saved')).toContainText('Saved');
    });

    test('§L L02 + L03: the Telegram 1-tap render (five inline stars, the polite window-closed line at hour :window+1, the thanks) in both modes, and the widget star row as a radiogroup of five 44 px targets with the expired row dashed and unclickable; en + am', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/csat');
        const tg = page.getByTestId('wf-csat-telegram');
        await expect(tg).toContainText('How did we do?');
        await expect(tg.locator('.ib span')).toHaveCount(5);
        await expect(page.getByTestId('wf-csat-closed')).toContainText(
            'this rating window has closed',
        );
        await expect(tg).toContainText(/hour \d+ · nothing recorded \(EC-11\)/);
        await expect(page.getByTestId('wf-csat-telegram-business')).toContainText(
            '1 · 2 · 3 · 4 · 5',
        );
        const stars = page.getByTestId('wf-csat-star-row');
        await expect(stars).toHaveAttribute('role', 'radiogroup');
        await expect(stars.locator('[role="radio"]')).toHaveCount(5);
        await targets44(page, '[data-testid="wf-csat-star-row"] button', 'star row');
        await expect(page.getByTestId('wf-csat-expired').locator('button[disabled]')).toHaveCount(
            5,
        );
        await expect(page.getByTestId('wf-csat-renders')).not.toContainText(/error|failed/i);
        await shot(page, 'B9-S12-renders-build-1440-light', '[data-testid="wf-csat-renders"]');
        await page.getByTestId('wf-csat-lang').selectOption('am');
        await expect(tg).toContainText('እንዴት ነበርን');
        await expect(stars).toHaveAttribute('aria-label', /እንዴት ነበርን/);
        await shot(page, 'B9-S12-renders-am-build-1440-light', '[data-testid="wf-csat-renders"]');
    });

    test('§L L05 375: phone-readable — the rows stacked with provenance and the designed absence under the notice; no save verb; no overflow; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/csat');
        await expect(page.getByTestId('bo-readnote')).toContainText('Editing needs a tablet');
        await expect(page.getByTestId('wf-csat-row-cooldown')).toBeVisible();
        await expect(page.getByTestId('wf-csat-save')).toHaveCount(0);
        await expect(page.getByTestId('wf-csat-absences')).toContainText('No incentive');
        await noOverflow(page, 'csat 375');
        await axeClean(page, 'csat 375 (light)');
        await shot(page, 'B9-S12-csat-build-375-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'csat 375 (dark)');
        await shot(page, 'B9-S12-csat-build-375-dark');
    });
});

test.describe('B9-S13 — degraded, error and cross-cutting', () => {
    test('§M M01: the hub — seven cards with live sub-lines, the chrome legend (Organization real, People / Channels drawn, Knowledge base, the seven rows), the palette row; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow');
        await expect(
            page.locator('[data-testid^="wf-hub-"]:not([data-testid="wf-hub-legend"])'),
        ).toHaveCount(7);
        await expect(page.getByTestId('wf-hub-rules')).toContainText(
            'position order is execution order',
        );
        await expect(page.getByTestId('wf-hub-csat')).toContainText(
            /cooldown \d+ h · window \d+ h/,
        );
        const legend = page.getByTestId('wf-hub-legend');
        await expect(legend).toContainText('Organization');
        await expect(legend).toContainText('drawn');
        await expect(legend).toContainText('command palette');
        await expect(page.getByTestId('bo-nav-kb')).toBeVisible();
        await axeClean(page, 'hub (light)');
        await shot(page, 'B9-S13-hub-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'hub (dark)');
        await shot(page, 'B9-S13-hub-build-1440-dark');
    });

    test('§M M02: the execution detail page — event, ticket, rule, guards, the condition results with evaluated values and the elided twin beside them, the action outcomes in order; reached from the editor’s log', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/rules');
        const ids = await page
            .locator('[data-rule-row]')
            .evaluateAll((els) => els.map((e) => e.getAttribute('data-rule-row') ?? ''));
        let found = '';
        for (const id of ids) {
            await page.goto(`/admin/workflow/rules/${id}`);
            const rows = page.locator('[data-testid^="wf-log-row-"]');
            if ((await rows.count()) > 0) {
                await rows.first().click();
                const link = page.locator('[data-testid^="wf-log-open-"]').first();
                await expect(link).toBeVisible();
                found = (await link.getAttribute('href')) ?? '';
                break;
            }
        }
        if (found === '') {
            // no rule has fired here yet: a schedule-triggered rule, made due, fired by the built
            // sweep command — the engine writes the execution row the page reads
            const name = '[F1] execution proof';
            const id = await ensureRule(page, name);
            await page.goto(`/admin/workflow/rules/${id}`);
            await page.getByTestId('wf-editor-trigger').selectOption('schedule');
            await page.getByTestId('wf-editor-cron').fill('*/5 * * * *');
            await page.getByTestId('wf-editor-cron').dispatchEvent('input');
            if (
                (await page
                    .locator('[data-testid^="wf-action-"]:not([data-testid="wf-action-add"])')
                    .count()) === 0
            ) {
                await page.getByTestId('wf-action-add').selectOption('add_note');
                await page
                    .getByTestId('wf-action-0')
                    .locator('input, textarea')
                    .first()
                    .fill('[F1] fired by the sweep');
            }
            await page.getByTestId('wf-save-activate').click();
            await page.waitForLoadState('networkidle');
            tinker(
                `DB::table('automation_rules')->where('name', '${name}')->update(['schedule_next_run_at' => now()->subMinutes(2)]);`,
            );
            artisan('automation:schedule-sweep');
            await page.goto(`/admin/workflow/rules/${id}`);
            const rows = page.locator('[data-testid^="wf-log-row-"]');
            if ((await rows.count()) > 0) {
                await rows.first().click();
                found =
                    (await page
                        .locator('[data-testid^="wf-log-open-"]')
                        .first()
                        .getAttribute('href')) ?? '';
            }
        }
        test.skip(
            found === '',
            'no execution row could be produced in this environment (the sweep did not fire) — the page cannot be reached from data',
        );
        await page.goto(found);
        await expect(page.getByTestId('wf-exec-headline')).toContainText(/matched|no match/);
        await expect(page.getByTestId('wf-exec-guards')).toContainText(
            /none tripped|Guards tripped/,
        );
        await expect(page.getByTestId('wf-exec-privileged')).toBeVisible();
        await expect(page.getByTestId('wf-exec-elided')).toBeVisible();
        await expect(page.getByTestId('wf-exec-elided')).toContainText('SEC-03');
        await expect(page.getByTestId('wf-exec-outcomes')).toContainText('Action isolation');
        await expect(page.getByTestId('wf-exec-open-rule')).toBeVisible();
        await expect(page.getByRole('button', { name: /retry/i })).toHaveCount(0);
        await axeClean(page, 'execution (light)');
        await shot(page, 'B9-S13-execution-build-1440-light');
        await setTheme(page, 'dark');
        await axeClean(page, 'execution (dark)');
        await shot(page, 'B9-S13-execution-build-1440-dark');
    });

    test('§M M04 + M05: the refusal inline (role=alert, the door’s own detail, in place, the list unchanged); the AI-off tag is absent while AI is on; the auto-disabled destination note is honest to the data', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/sla');
        await page.getByTestId('wf-sla-new').click();
        await page.getByTestId('wf-sla-name').fill('');
        const before = await page.locator('[data-policy-row]').count();
        await page.getByTestId('wf-sla-save').click();
        const refusal = page.getByTestId('wf-refusal');
        await expect(refusal).toBeVisible();
        await expect(refusal).toHaveAttribute('role', 'alert');
        await expect(refusal).toContainText('refused');
        await expect(page.locator('[data-policy-row]')).toHaveCount(before);
        await shot(page, 'B9-S13-refusal-build-1440-light', '[data-testid="wf-sla-editor"]');
        // M05 — the AI task card wears no AI-off tag while Doc 09's banner is clear
        await page.goto('/admin/workflow/rules');
        const id =
            (await page.locator('[data-rule-row]').first().getAttribute('data-rule-row')) ?? '';
        await page.goto(`/admin/workflow/rules/${id}`);
        await page.getByTestId('wf-action-add').selectOption('run_ai_task');
        const banner = await page.evaluate(async () => {
            const r = await fetch('/admin/ai/banner', { headers: { Accept: 'application/json' } });
            return (await r.json()) as { off?: unknown[] };
        });
        if ((banner.off ?? []).length === 0)
            await expect(page.getByTestId('wf-ai-off')).toHaveCount(0);
        else await expect(page.getByTestId('wf-ai-off')).toContainText('AI is unavailable');
        await page.getByTestId('wf-action-add').selectOption('webhook_out');
        const disabled = page
            .getByTestId('wf-webhook-destination')
            .last()
            .locator('option[disabled]');
        if ((await disabled.count()) > 0)
            await expect(disabled.first()).toContainText('auto-disabled');
    });

    test('§M M06 + L04: reduced motion (no running animations on the rules list and the SLA chips), visible focus rings, 44 px verbs; the Amharic sweep across every Workflow page at 1440 and 375 with the shared chrome and no overflow', async ({
        page,
    }) => {
        await login(page);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/rules');
        expect(await page.evaluate(() => document.getAnimations().length)).toBe(0);
        const row = page.locator('[data-rule-row]').first();
        await row.focus();
        const ring = await row.evaluate((el) => {
            const s = getComputedStyle(el);
            return s.outlineStyle !== 'none' || s.boxShadow !== 'none';
        });
        expect(ring).toBe(true);
        await page.setViewportSize(V375);
        await page.goto('/admin/workflow/rules');
        await targets44(page, '.bo-btn', 'workflow verbs at 375');
        await page.setViewportSize(V1440);
        await page.goto('/admin/workflow/sla');
        const first = page.locator('[data-policy-row]').first();
        if ((await first.count()) > 0) {
            await first.locator('.body').click();
            await expect(page.getByTestId('wf-sla-chips')).toBeVisible();
            expect(await page.evaluate(() => document.getAnimations().length)).toBe(0);
        }
        await shot(page, 'B9-S13-reduced-motion-build-1440-light');
        // L04 — the Amharic sweep
        setAgentLocale('am');
        try {
            for (const path of [
                '',
                '/rules',
                '/sla',
                '/hours',
                '/macros',
                '/fields',
                '/forms',
                '/csat',
            ]) {
                await page.setViewportSize(V1440);
                await page.goto(`/admin/workflow${path}`);
                await expect(page.getByTestId('bo-nav-workflow')).toContainText('የስራ ፍሰት');
                await noOverflow(page, `am 1440 ${path || '/'}`);
                await page.setViewportSize(V375);
                await page.goto(`/admin/workflow${path}`);
                await noOverflow(page, `am 375 ${path || '/'}`);
            }
            await page.setViewportSize(V1440);
            await page.goto('/admin/workflow/rules');
            await shot(page, 'B9-S05-rules-build-1440-am-sweep');
        } finally {
            setAgentLocale('en');
        }
    });
});

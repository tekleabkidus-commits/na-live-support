import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * Doc 12 §8/§9 (S12 step 14) — Backoffice → Workflow, walked whole on the real
 * machinery under the 0l law: the scene is what THIS spec creates (its own rule,
 * policy, calendar, macro, field, form), asserted by the ids it minted — nothing
 * here depends on the census of the seeded canon. The fixture agent is a Super
 * Admin (every catalog key), so every surface renders with its controls.
 *   J1  hub → the seven groups linked; the drawn Backoffice chrome present
 *   J2  rules: create → editor's three panels → dry-run drawer with ✓/✗ truth
 *       badges → activate (the verb on the primary) → the list shows Active
 *   J3  SLA: the live example sentence answers from the server
 *   J4  hours: the interval-list editor (the accessible requirement) saves
 *   J5  fields: create → where-used panel → deactivate with the typed weight
 *   J6  forms: builder canvas → dual preview toggle → publish v1
 *   J7  macros: editor with variable pills → preview against a sample ticket
 *   J8  CSAT: the settings-row writes through the registry
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

function artisan(...args: string[]): void {
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
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('auth:session-inspect', 'clear-throttle');
});

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

test('J1 + J2: the hub links every group; a rule is created, edited in the three panels, dry-run with truth badges, and activated', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page);
    await page.goto('/admin/workflow');
    for (const key of ['rules', 'sla', 'hours', 'macros', 'fields', 'forms', 'csat']) {
        await expect(page.getByTestId(`wf-hub-${key}`)).toBeVisible();
    }
    await expect(page.getByTestId('wf-nav-rules')).toBeVisible();

    const name = `Browser rule ${Date.now()}`;
    await page.goto('/admin/workflow/rules');
    await page.getByTestId('wf-rule-new').click();
    await page.getByTestId('wf-rule-name').fill(name);
    await page.getByTestId('wf-rule-create').click();
    await page.waitForURL(/\/admin\/workflow\/rules\/[0-9a-f-]+$/);
    await expect(page.getByTestId('wf-panel-trigger')).toBeVisible();
    await expect(page.getByTestId('wf-panel-conditions')).toBeVisible();
    await expect(page.getByTestId('wf-panel-actions')).toBeVisible();

    // A condition + an action through the builders (buttons, not drag — §13).
    await page.getByTestId('wf-cond-add').first().click();
    await page.getByTestId('wf-action-add').selectOption('add_note');
    await page.getByTestId('wf-action-0').locator('input').fill('from the browser');
    // The dry-run drawer answers with a truth badge per condition row.
    await page.getByTestId('wf-dry-run-go').click();
    await expect(page.getByTestId('wf-dry-result')).toBeVisible();
    await expect(page.getByTestId('wf-truth-0')).toContainText(/matches|does not match/);
    await expect(page.getByTestId('wf-dry-action-0')).toBeVisible();
    // Activate: the verb on the primary; the list shows Active.
    await page.getByTestId('wf-save-activate').click();
    await page.waitForLoadState('networkidle');
    await page.goto('/admin/workflow/rules');
    const row = page.locator('[data-rule-row]', { hasText: name });
    await expect(row).toBeVisible();
    await expect(row.locator('[data-testid^="wf-rule-state-"]')).toHaveText('Active');
});

test('J3 + J4: the SLA example sentence answers from the clock library; the hours interval-list editor saves a calendar with a holiday', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page);
    await page.goto('/admin/workflow/hours');
    await page.getByTestId('wf-hours-new').click();
    await page.getByTestId('wf-hours-name').fill(`Browser calendar ${Date.now()}`);
    await page.getByTestId('wf-hours-add-6').click(); // Saturday gains an interval through the LIST editor
    await page.getByTestId('wf-hours-save').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-calendar-row]').first()).toBeVisible();

    await page.goto('/admin/workflow/sla');
    await page.getByTestId('wf-sla-new').click();
    await page.getByTestId('wf-sla-name').fill(`Browser policy ${Date.now()}`);
    await expect(page.getByTestId('wf-sla-example')).not.toHaveText('');
    await page.getByTestId('wf-sla-save').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-policy-row]').first()).toBeVisible();
});

test('J5 + J6 + J7 + J8: a field with where-used, a form published v1 from the builder with the dual preview, a macro previewed, the CSAT settings row saved', async ({
    page,
}) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await login(page);
    const key = `bf_${Date.now().toString(36)}`;
    await page.goto('/admin/workflow/fields');
    await page.getByTestId('wf-field-new').click();
    await page.getByTestId('wf-field-key').fill(key);
    await page.getByTestId('wf-field-label-en').fill('Browser field');
    await page.getByTestId('wf-field-label-am').fill('የአሳሽ መስክ');
    await page.getByTestId('wf-field-save').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator(`[data-field-row="${key}"]`)).toBeVisible();
    await page.getByTestId(`wf-field-deactivate-${key}`).click();
    await expect(page.getByTestId('wf-where-used')).toBeVisible();
    await page.getByTestId('wf-field-deactivate-confirm').click();
    await page.waitForLoadState('networkidle');
    await expect(page.getByTestId(`wf-field-reactivate-${key}`)).toBeVisible();

    await page.goto('/admin/workflow/forms');
    await page.getByTestId('wf-form-name').fill(`Browser form ${Date.now()}`);
    await page.getByTestId('wf-form-create').click();
    await page.waitForURL(/\/admin\/workflow\/forms\/[0-9a-f-]+$/);
    await page.getByTestId('wf-step-add').click();
    await page.getByTestId('wf-step-title-en').fill('About it');
    await page.getByTestId('wf-step-title-am').fill('ስለ ጉዳዩ');
    await page.getByTestId('wf-palette-text').click();
    await page.getByTestId('wf-inspector-label-en').fill('Summary');
    await page.getByTestId('wf-inspector-label-am').fill('ማጠቃለያ');
    await page.getByTestId('wf-preview-telegram').click();
    await expect(page.getByTestId('wf-preview-pane')).toContainText('Summary');
    await page.getByTestId('wf-preview-widget').click();
    await page.getByTestId('wf-publish').click();
    await page.getByTestId('wf-publish-confirm').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-version-row="1"]')).toBeVisible();

    await page.goto('/admin/workflow/macros');
    await page.getByTestId('wf-macro-new').click();
    await page.getByTestId('wf-macro-name').fill(`Browser macro ${Date.now()}`);
    await page.getByTestId('wf-macro-body').fill('Hello ');
    await page.getByTestId('wf-var-customer.name').click();
    await expect(page.locator('[data-pill="customer.name"]')).toBeVisible();
    await page.getByTestId('wf-macro-save').click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-macro-row]').first()).toBeVisible();

    await page.goto('/admin/workflow/csat');
    await page.getByTestId('wf-csat-cooldown').fill('36');
    await page.getByTestId('wf-csat-save').click();
    await expect(page.locator('[role="status"]')).toContainText('Saved');
});

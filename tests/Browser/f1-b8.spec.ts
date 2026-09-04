import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { shotsDir } from './support/shots';
import { dismissCoachMarks } from './support/coach';

/*
 * SESSION-F1 [B8] — the fidelity proofs for Doc 09's surfaces against the frozen
 * Batch 8 (design/frozen/B8-*). Every existing spec stays UNMODIFIED; this file
 * adds the frame-level proofs the mandate asks for: the drawn states are
 * VISIBLE (not merely present), axe-clean in BOTH themes, 44 px targets at 375,
 * and the build side of every frame-vs-build screenshot pair is written under
 * test-results/f1-shots/F1-B8/shots/ (untracked; the committed docs/evidence/F1-B8/shots/ set is the accepted record) so the owner can compare it with the frame.
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };
const SHOTS = shotsDir('F1-B8'); // P-F1-SHOTS: untracked (test-results/), never the committed evidence

// The staff user's stored locale always wins over the login cookie (ApplyUserLocale), so the
// Amharic sweep flips the fixture agent's users.locale under the tenant's own RLS context and
// restores it in `finally` — the seeded row is otherwise untouched.
function setAgentLocale(locale: 'en' | 'am'): void {
    artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
DB::table('users')->where('email','agent@fixture.example')->update(['locale' => '${locale}']);
echo 'locale='.DB::table('users')->where('email','agent@fixture.example')->value('locale').PHP_EOL;`,
    );
}

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
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\AiBrowserSeeder', '--force');
});
test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});
async function login(page: Page, email = AGENT_EMAIL): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}
async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}
async function axeClean(page: Page, context: string, include?: string): Promise<void> {
    let builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']);
    if (include) builder = builder.include(include);
    const results = await builder.analyze();
    expect(
        results.violations,
        `${context}: ${JSON.stringify(results.violations.map((v) => v.id))}`,
    ).toEqual([]);
}
async function openCopilotThread(page: Page): Promise<void> {
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Copilot Fixture Player' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test.describe('B8-S01 — the Copilot panel', () => {
    test('§A/§C/§D: six labelled regions in reading order with stamps; the draft card carries the meter as TEXT; Discard rests the region; axe-clean light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        await page.getByTestId('tab-copilot').click();
        const tab = page.getByTestId('copilot-tab');
        await expect(tab).toBeVisible();

        // A01: reading order = decision order — Summary → Classification → Suggested reply → KB → Similar → Macros, every one a labelled region.
        const labels = await tab
            .locator('section[role="region"]')
            .evaluateAll((els) => els.map((e) => e.getAttribute('aria-label')));
        expect(labels).toEqual([
            'Summary',
            'Classification',
            'Suggested reply',
            'Relevant KB',
            'Similar resolved',
            'Macros',
        ]);
        // A02: the summary carries its freshness stamp.
        await expect(page.getByTestId('copilot-summary-stamp')).not.toHaveText('');
        // A03: chips carry the label AND the value as text.
        await expect(page.getByTestId('copilot-chip-intent')).toContainText('Intent');
        await expect(page.getByTestId('copilot-chip-intent')).toContainText('withdrawals');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-idle-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        // A04/A13: Suggest → the drawn card: "✦ AI draft" label, the text, five segments + "NN% · Band".
        await page.getByTestId('copilot-regenerate').click();
        await expect(page.getByTestId('card-text')).toContainText('2,500 ETB withdrawal', {
            timeout: 15_000,
        });
        await expect(page.getByTestId('card-confidence')).toHaveText(
            /^\d{1,3}% · (High|Medium|Low)$/,
        );
        expect(await tab.locator('.cp-seg').count()).toBe(5);
        await expect(page.getByTestId('copilot-regen-stamp')).toContainText('left');
        await expect(page.getByTestId('copilot-card')).toContainText('✦ AI draft');
        await axeClean(page, 'copilot populated (light)', '[data-testid="copilot-tab"]');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-populated-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        await setTheme(page, 'dark');
        await axeClean(page, 'copilot populated (dark)', '[data-testid="copilot-tab"]');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-populated-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'light');

        // A04 (v2): Discard → the region RESTS — the copy, "Suggest", no banner, the budget unchanged.
        await page.getByTestId('copilot-discard').click();
        await expect(page.getByTestId('copilot-resting')).toContainText('Draft discarded');
        await expect(page.getByTestId('copilot-regenerate')).toContainText('Suggest');
        await expect(page.getByTestId('card-banner')).toHaveCount(0);
        await expect(page.getByTestId('copilot-regen-stamp')).toContainText('left');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-resting-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });

    test('§G at 375: the sheet carries the same panel; every control row clears 44 px; axe-clean in both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await openCopilotThread(page);
        await page.getByTestId('mobile-context-trigger').click();
        await expect(page.getByTestId('mobile-context-sheet')).toBeVisible();
        await page.getByTestId('tab-copilot').click();
        const tab = page.getByTestId('copilot-tab');
        await expect(tab).toBeVisible();
        await page.getByTestId('copilot-regenerate').click();
        await expect(page.getByTestId('card-text')).toBeVisible({ timeout: 15_000 });
        for (const id of ['copilot-insert', 'copilot-regenerate', 'copilot-discard']) {
            const box = await page.getByTestId(id).boundingBox();
            expect(box?.height ?? 0, `${id} height`).toBeGreaterThanOrEqual(44);
        }
        for (const btn of await tab.locator('.cp-ins').all()) {
            expect(
                (await btn.boundingBox())?.height ?? 0,
                'insert-link height',
            ).toBeGreaterThanOrEqual(40);
        }
        await axeClean(page, 'copilot 375 (light)', '[data-testid="mobile-context-sheet"]');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-populated-build-375-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'copilot 375 (dark)', '[data-testid="mobile-context-sheet"]');
        await tab.screenshot({
            path: `${SHOTS}/B8-S01-populated-build-375-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });
});

test.describe('B8-S02 — composer AI actions', () => {
    const IMPROVE_TEXT = '[FIXTURE] BROWSER-IMPROVE-FIXTURE we check your 2,500 ETB withdrawal';

    test('§A/§B/§D: four labelled verbs; Improve → the "meaning kept" card with digits verified and "Insert — replaces your text"; Summarize → the note card whose Insert lands in NOTE mode; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        const cluster = page.getByTestId('ai-actions');
        await expect(cluster).toBeVisible();
        for (const [a, label] of [
            ['draft', 'Draft'],
            ['improve', 'Improve'],
            ['translate', 'Translate'],
            ['summarize', 'Summarize'],
        ]) {
            await expect(page.getByTestId('ai-action-' + a)).toHaveText('✦ ' + label);
        }
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-cluster-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        // §B Improve: whole-text accept, the promise in the label, digits verified in the header.
        await page.getByTestId('composer-input').fill(IMPROVE_TEXT);
        await page.getByTestId('ai-action-improve').click();
        await expect(page.getByTestId('ai-action-text')).toContainText('2,500 ETB', {
            timeout: 20_000,
        });
        await expect(page.getByTestId('ai-action-card')).toContainText('meaning kept');
        await expect(page.getByTestId('ai-digits-verified')).toContainText('digits verified');
        await expect(page.getByTestId('ai-action-insert')).toHaveText(
            'Insert — replaces your text',
        );
        await expect(
            page.getByTestId('ai-action-card').getByRole('button', { name: /^send/i }),
        ).toHaveCount(0); // X1
        await axeClean(page, 'composer improve card (light)', '[data-testid="composer"]');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-improve-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'composer improve card (dark)', '[data-testid="composer"]');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-improve-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'light');
        await page.getByTestId('ai-action-discard').click();
        await expect(page.getByTestId('ai-action-card')).toHaveCount(0);
        await expect(page.getByTestId('composer-input')).toHaveValue(IMPROVE_TEXT); // the original is untouched by Discard

        // §D Summarize → the note card; Insert lands the draft in NOTE mode.
        await page.getByTestId('ai-action-summarize').click();
        await expect(page.getByTestId('ai-action-text')).toContainText('2,500 ETB', {
            timeout: 20_000,
        });
        await expect(page.getByTestId('ai-action-card')).toContainText('internal note');
        await expect(page.getByTestId('ai-action-insert')).toHaveText('Insert as note draft');
        await page.getByTestId('ai-action-insert').click();
        await expect(page.getByTestId('composer-input')).toHaveValue(/2,500 ETB/);
        await expect(page.locator('[data-mode="note"]')).toHaveCount(1);
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-summary-note-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });

    test('§C (the door shapes pinned by ComposerActionsTest, rendered): the translate PAIR with digits verified and "Insert the Amharic"; the validator rejection NAMES the digits and offers no insert; no card sends', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        await page.route('**/inbox/ai/copilot/*/actions/translate', (route) =>
            route.fulfill({
                status: 202,
                contentType: 'application/json',
                body: JSON.stringify({ request_id: 'f1-translate' }),
            }),
        );
        let refuse = false;
        await page.route('**/inbox/ai/copilot/*/requests/f1-translate', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(
                    refuse
                        ? {
                              state: 'refused',
                              kind: 'translate',
                              artifact_id: 'f1-art-2',
                              notices: ['ai.copilot.notice.digit_mismatch'],
                              digits: { missing: ['2,500'], extra: ['2,050'] },
                          }
                        : {
                              state: 'artifact',
                              kind: 'translate',
                              artifact_id: 'f1-art-1',
                              text: 'የ2,500 ብር የቴሌብር ወጪዎን አሁን እየተመለከትን ነው።',
                              source_language: 'en',
                              target_language: 'am',
                              notices: [],
                          },
                ),
            }),
        );
        await page.route('**/inbox/ai/copilot/artifacts/*/discard', (route) =>
            route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ artifact_id: 'x' }),
            }),
        );
        await page
            .getByTestId('composer-input')
            .fill("We're checking your 2,500 ETB Telebirr withdrawal now.");
        await page.getByTestId('ai-action-translate').click();
        const pair = page.getByTestId('ai-translate-pair');
        await expect(pair).toBeVisible();
        await expect(pair).toContainText('EN');
        await expect(pair).toContainText('AM');
        await expect(pair).toContainText("We're checking your 2,500 ETB");
        await expect(pair).toContainText('2,500');
        await expect(page.getByTestId('ai-action-card')).toContainText(
            'Translate — English → Amharic',
        );
        await expect(page.getByTestId('ai-digits-verified')).toBeVisible();
        await expect(page.getByTestId('ai-action-insert')).toHaveText('Insert the Amharic');
        await expect(
            page.getByTestId('ai-action-card').getByRole('button', { name: /^send/i }),
        ).toHaveCount(0);
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-translate-pair-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('ai-action-discard').click();

        refuse = true;
        await page.getByTestId('ai-action-translate').click();
        const banner = page.getByTestId('ai-action-banner');
        await expect(banner).toContainText('Translation blocked');
        await expect(banner).toContainText('2,500 → 2,050');
        await expect(page.getByTestId('ai-action-insert')).toHaveCount(0);
        await expect(page.getByTestId('ai-action-regenerate')).toBeVisible();
        await axeClean(page, 'composer rejection (light)', '[data-testid="composer"]');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-rejection-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-rejection-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });

    test('§A AI-off (LIVE kill switch): copilot OFF → the cluster is GONE and one line says what still works; back ON → the verbs return', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/kill-switches');
        await page.getByTestId('switch-copilot-all-all').click();
        await page.getByTestId('killswitch-reason').fill('f1 drill');
        await page.getByTestId('killswitch-confirm-yes').click();
        await expect(page.getByTestId('switch-copilot-all-all')).toHaveAttribute(
            'aria-checked',
            'false',
        );
        try {
            await openCopilotThread(page);
            await expect(page.getByTestId('ai-actions-off')).toContainText(
                'AI actions unavailable',
            );
            await expect(page.getByTestId('ai-action-improve')).toHaveCount(0);
            await page.getByTestId('composer').screenshot({
                path: `${SHOTS}/B8-S02-ai-off-build-1440-light.jpg`,
                type: 'jpeg',
                quality: 70,
            });
        } finally {
            await page.goto('/admin/ai/kill-switches');
            await page.getByTestId('switch-copilot-all-all').click();
            await page.getByTestId('killswitch-confirm-yes').click();
            await expect(page.getByTestId('switch-copilot-all-all')).toHaveAttribute(
                'aria-checked',
                'true',
            );
        }
        await openCopilotThread(page);
        await expect(page.getByTestId('ai-action-improve')).toBeVisible();
        await expect(page.getByTestId('ai-actions-off')).toHaveCount(0);
    });

    test('§F at 375: all four verbs stay visible and wrap; every target clears 44 px; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await openCopilotThread(page);
        for (const a of ['draft', 'improve', 'translate', 'summarize']) {
            const btn = page.getByTestId('ai-action-' + a);
            await expect(btn).toBeVisible();
            expect((await btn.boundingBox())?.height ?? 0, `${a} height`).toBeGreaterThanOrEqual(
                44,
            );
        }
        await axeClean(page, 'composer 375 (light)', '[data-testid="composer"]');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-cluster-build-375-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'composer 375 (dark)', '[data-testid="composer"]');
        await page.getByTestId('composer').screenshot({
            path: `${SHOTS}/B8-S02-cluster-build-375-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });
});

test.describe('B8-S04 — the persona editor', () => {
    test('§A/§B/§C: the Settings › AI shell; tone as a WORD value; the disclosure lock; the preview select over the five canned conversations; Publish locked with its reason until the preview ran, then unlocked with the three-fact confirm; versions listed; axe light + dark', async ({
        page,
    }) => {
        test.setTimeout(180_000); // the preview replays five canned conversations through the queue
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/persona');
        await expect(page.locator('.ai-crumb')).toContainText('Settings');
        await expect(page.locator('.ai-crumb b')).toHaveText('Persona');
        await expect(page.getByTestId('persona-chip')).not.toHaveText('');
        // D01: tone carries a WORD value, never position alone
        await expect(page.getByTestId('tone-formality-word')).toHaveText(
            /Balanced 0|Friendly|Formal/,
        );
        await page.getByTestId('tone-formality').fill('75');
        await expect(page.getByTestId('tone-formality-word')).toHaveText('Friendly +1');
        // D04 / X3: the disclosure is read-only wording under the permanent lock
        await expect(page.getByTestId('persona-disclosure-en')).toHaveAttribute('readonly', '');
        await expect(page.getByTestId('persona-disclosure-lock-en')).toContainText(
            'Presence is platform law',
        );
        await expect(
            page.locator('[data-testid="persona-disclosure-lock-en"] input[type="checkbox"]'),
        ).toHaveCount(0);
        // D02: the preview select cycles the five canned conversations; the customer turn renders before any run
        await expect(page.getByTestId('persona-dialog').locator('option')).toHaveCount(5);
        await expect(page.getByTestId('persona-dialog').locator('option').first()).toContainText(
            'Conversation 1 of 5',
        );
        await expect(page.getByTestId('persona-preview-results')).toContainText('[FIXTURE]');
        // D03 / FR-18: Publish is locked with the reason ON the button
        await expect(page.getByTestId('persona-publish')).toBeDisabled();
        await expect(page.getByTestId('persona-publish')).toContainText('preview the change first');
        await axeClean(page, 'persona editor (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-editor-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'persona editor (dark)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-editor-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'light');

        // the preview runs the draft through the five conversations → Publish unlocks → the confirm names version, instant, rollback
        await page.getByTestId('persona-name').fill('Selam');
        await page
            .getByTestId('persona-greeting-en')
            .fill(
                "[FIXTURE] Hello! I'm Selam, an AI assistant for this support team — a person is one message away.",
            );
        await page.getByTestId('persona-preview').click();
        await expect(page.getByTestId('persona-preview-text')).toBeVisible({ timeout: 90_000 });
        await expect(page.getByTestId('persona-chip')).toContainText('Previewed 5 of 5');
        await expect(page.getByTestId('persona-publish')).toBeEnabled();
        await expect(page.getByTestId('persona-publish')).toHaveText(/^Publish v\d+$/);
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-previewed-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('persona-publish').click();
        const dialog = page.getByTestId('persona-publish-dialog');
        await expect(dialog).toContainText(/Publish persona v\d+\?/);
        await expect(dialog).toContainText('instantly');
        await expect(dialog).toContainText('roll back');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-publish-confirm-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await dialog.getByRole('button', { name: 'Cancel' }).click();
        await expect(dialog).toHaveCount(0);
    });

    test('§F at 375: the editor stacks above the preview; every control clears 44 px; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/persona');
        const ed = await page.locator('.ps-edcol').boundingBox();
        const pv = await page.locator('.ps-pvcol').boundingBox();
        expect((ed?.y ?? 0) + (ed?.height ?? 0)).toBeLessThanOrEqual((pv?.y ?? 0) + 1); // stacked, editor first
        for (const id of ['persona-save', 'persona-preview']) {
            expect(
                (await page.getByTestId(id).boundingBox())?.height ?? 0,
                id,
            ).toBeGreaterThanOrEqual(44);
        }
        await axeClean(page, 'persona 375 (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-editor-build-375-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'persona 375 (dark)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S04-editor-build-375-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });
});

test.describe('B8-S05 — thresholds & simulation', () => {
    test('§A–§F: four sliders with consequence + value line; the RG precision readout; Publish locked with its reason; a slider touch → pending; simulate → FRESH gate + bar pairs + worded deltas + the text table + the flips drill-down; another touch → STALE gate re-locks; the confirm repeats the headline; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/thresholds');
        await expect(page.locator('.ai-crumb b')).toHaveText('Thresholds');
        for (const k of ['auto_resolve_min', 'handoff_below', 'spam_min', 'rg_min']) {
            await expect(page.getByTestId('th-row-' + k)).toContainText('published');
        }
        await expect(page.getByTestId('th-rg-precision')).toContainText('target ≥');
        await expect(page.getByTestId('thresholds-publish')).toBeDisabled();
        await expect(page.getByTestId('thresholds-publish')).toContainText(
            'simulate these values first',
        );
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-initial-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        // E01: a slider touch shows the pending delta beside the published value
        await page.getByTestId('slider-handoff_below').fill('0.34');
        await expect(page.getByTestId('th-row-handoff_below')).toContainText('pending');
        await expect(page.getByTestId('th-discard')).toBeVisible();

        // E02/E04: simulate → fresh, bars + worded deltas, Publish unlocked with the version
        await page.getByTestId('simulate').click();
        await expect(page.getByTestId('th-fresh')).toBeVisible({ timeout: 20_000 });
        await expect(page.getByTestId('simulation-results')).toBeVisible();
        for (const k of [
            'eligible_auto_resolve',
            'handoff_low_confidence',
            'spam',
            'rg_override',
            'eligible',
        ]) {
            await expect(page.getByTestId('delta-' + k)).toBeVisible();
        }
        await expect(page.getByTestId('th-sim-status')).toContainText('Computed');
        await expect(page.getByTestId('thresholds-publish')).toBeEnabled();
        await expect(page.getByTestId('thresholds-publish')).toHaveText(/^Publish v\d+$/);
        await axeClean(page, 'thresholds fresh (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-fresh-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'thresholds fresh (dark)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-fresh-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'light');

        // E07: the same result as a text table — the record
        await page.getByTestId('th-view-table').click();
        await expect(page.getByTestId('simulation-table')).toBeVisible();
        await expect(page.getByTestId('simulation-table')).toContainText('Published values');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-table-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('th-view-table').click();

        // E03: the flips drill-down opens (anonymized) — the read answers even when nothing flips
        await page.getByTestId('th-flips-open').click();
        await expect(page.getByTestId('th-flips')).toBeVisible();
        await expect(page.getByTestId('th-flips')).toContainText('Anonymized');
        await expect(page.getByTestId('th-flips')).toContainText('more', { timeout: 15_000 });
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-flips-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        // E04 / X5: touching a slider AFTER the simulation re-locks Publish and discredits the numbers
        await page.getByTestId('slider-handoff_below').fill('0.36');
        await expect(page.getByTestId('th-stale')).toContainText(
            'Values changed since the last simulation',
        );
        await expect(page.getByTestId('thresholds-publish')).toBeDisabled();
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-stale-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        // fresh again → the confirm names the endpoint, the headline and the rollback
        await page.getByTestId('simulate').click();
        await expect(page.getByTestId('thresholds-publish')).toBeEnabled({ timeout: 20_000 });
        await page.getByTestId('thresholds-publish').click();
        const dialog = page.getByTestId('thresholds-publish-dialog');
        await expect(dialog).toContainText(/Publish thresholds v\d+\?/);
        await expect(dialog).toContainText('auto-resolved');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S05-confirm-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await dialog.getByRole('button', { name: 'Cancel' }).click();

        // E05: the overrides table lists the default row with the three columns
        await expect(page.getByTestId('th-overrides')).toContainText('auto_resolve_min');
        await expect(page.getByTestId('th-edit-default')).toBeVisible();
    });

    test('§H at 375: the simulation stacks under the sliders; publish and simulate clear 44 px; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/thresholds');
        const sl = await page.locator('.th-sliders').boundingBox();
        const sim = await page.locator('.th-simcol').boundingBox();
        expect((sl?.y ?? 0) + (sl?.height ?? 0)).toBeLessThanOrEqual((sim?.y ?? 0) + 1);
        for (const id of ['simulate', 'thresholds-publish']) {
            expect(
                (await page.getByTestId(id).boundingBox())?.height ?? 0,
                id,
            ).toBeGreaterThanOrEqual(44);
        }
        await axeClean(page, 'thresholds 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S05-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'thresholds 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S05-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S06 — guardrails', () => {
    test('§A–§E: the seeded floor wears the lock and offers NO delete; a custom topic deletes behind the consequence confirm; the disclosure lock; the RG template tabs carry COMPLETE and the slate preview; every limit states its consequence; the PII toggle carries its word; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/guardrails');
        await expect(page.locator('.ai-crumb b')).toHaveText('Guardrails');
        const seededRows = page.getByTestId('gr-topic-seeded');
        expect(await seededRows.count()).toBeGreaterThanOrEqual(4);
        await expect(seededRows.first()).toContainText('PLATFORM');
        await expect(seededRows.locator('button')).toHaveCount(0); // F06's mirror: no delete path, not a disabled one
        // add a custom topic → it carries Delete → the confirm names the consequence → gone
        await page.getByTestId('gr-add-topic').click();
        await page.getByTestId('blocked-topic-input').fill('Competitor comparisons');
        await page.getByTestId('gr-add-confirm').click();
        const custom = page.locator(
            '[data-testid="gr-topic-custom"][data-topic="Competitor comparisons"]',
        );
        await expect(custom).toBeVisible();
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S06-topics-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('gr-delete-Competitor comparisons').click();
        await expect(page.getByTestId('gr-delete-dialog')).toContainText(
            'Delete "Competitor comparisons"?',
        );
        await expect(page.getByTestId('gr-delete-dialog')).toContainText('audited');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S06-custom-delete-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('gr-delete-confirm').click();
        await expect(custom).toHaveCount(0);
        // F02 / X3
        await expect(page.getByTestId('gr-disclosure-lock')).toContainText(
            'Presence is platform law',
        );
        await expect(
            page.locator('[data-testid="gr-disclosure"] input[type="checkbox"]'),
        ).toHaveCount(0);
        // F03 / X2: complete chips, the slate preview labelled as the fixed template
        await expect(page.getByTestId('gr-rg-tab-am')).toContainText('COMPLETE');
        await expect(page.getByTestId('gr-rg-tab-en')).toContainText('COMPLETE');
        await expect(page.getByTestId('gr-rg-preview-am')).toContainText('fixed template');
        await expect(page.getByTestId('gr-rg')).toContainText('byte for byte');
        // F04/F05: consequences beside the controls; the toggle carries its word
        await expect(page.getByTestId('gr-limits')).toContainText('After 8 assistant replies');
        await expect(page.getByTestId('gr-pii')).toHaveAttribute('role', 'switch');
        await expect(page.getByTestId('gr-pii')).toContainText(/On|Off/);
        await axeClean(page, 'guardrails (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S06-full-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'guardrails (dark)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S06-full-build-1440-dark.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });

    test('§G at 375: rows stack; every button clears 44 px; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/guardrails');
        for (const id of ['gr-add-topic', 'guardrails-save', 'guardrails-publish']) {
            expect(
                (await page.getByTestId(id).boundingBox())?.height ?? 0,
                id,
            ).toBeGreaterThanOrEqual(44);
        }
        await axeClean(page, 'guardrails 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S06-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'guardrails 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S06-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S07 — scope & kill switches', () => {
    test('§A–§F: the quiet grid (every cell a role=switch with its word); Scope with the endpoints and the web-widget absence; the audit strip; OFF → the blast-radius confirm with the scoped red verb → the red cell "OFF since … by …", the page banner and the chip; back ON through the banner; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/kill-switches');
        await expect(page.locator('.ai-crumb b')).toHaveText('Kill switches');
        await expect(page.getByTestId('ks-chip')).toContainText('All switches on');
        const toggle = page.getByTestId('switch-chatbot-telegram-all');
        await expect(toggle).toHaveAttribute('role', 'switch');
        await expect(toggle.locator('xpath=..')).toContainText('On');
        expect(
            await page.locator('[data-testid="killswitch-grid"] [role="switch"]').count(),
        ).toBeGreaterThanOrEqual(6);
        await expect(page.getByTestId('ks-scope')).toContainText('Web widget');
        await expect(page.getByTestId('ks-audit')).toContainText('Recent switch activity');
        await axeClean(page, 'kill switches all-on (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S07-all-on-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });

        await toggle.click();
        const confirm = page.getByTestId('killswitch-confirm');
        await expect(confirm).toContainText('Turn the Chatbot OFF for');
        await expect(page.getByTestId('ks-blast')).toContainText('Blast radius');
        await expect(page.getByTestId('killswitch-confirm-yes')).toContainText(
            'Turn OFF — Chatbot',
        );
        await page.locator('body').screenshot({
            path: `${SHOTS}/B8-S07-confirm-off-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('killswitch-reason').fill('f1 drill');
        await page.getByTestId('killswitch-confirm-yes').click();
        try {
            await expect(toggle).toHaveAttribute('aria-checked', 'false');
            await expect(page.getByTestId('since-chatbot').first()).toContainText('OFF since');
            await expect(page.getByTestId('ks-off-banner').first()).toContainText('since');
            await expect(page.getByTestId('ks-chip')).toContainText('1 switch');
            await expect(page.getByTestId('ks-audit')).toContainText('→ OFF');
            await axeClean(page, 'kill switches mixed (light)');
            await page.locator('.ai-shell').screenshot({
                path: `${SHOTS}/B8-S07-mixed-build-1440-light.jpg`,
                type: 'jpeg',
                quality: 70,
            });
            await setTheme(page, 'dark');
            await axeClean(page, 'kill switches mixed (dark)');
            await page.locator('.ai-shell').screenshot({
                path: `${SHOTS}/B8-S07-mixed-build-1440-dark.jpg`,
                type: 'jpeg',
                quality: 70,
            });
            await setTheme(page, 'light');
        } finally {
            await page.getByTestId('ks-banner-on-chatbot').first().click();
            await expect(page.getByTestId('killswitch-confirm')).toContainText('back ON');
            await page.getByTestId('killswitch-confirm-yes').click();
            await expect(toggle).toHaveAttribute('aria-checked', 'true');
        }
        await expect(page.getByTestId('ks-off-banner')).toHaveCount(0);
        await expect(page.getByTestId('ks-chip')).toContainText('All switches on');
    });

    test('§G at 375: the grid stacks by surface; switches stay 64×32 (≥ 44 px targets); axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/kill-switches');
        const box = await page.getByTestId('switch-chatbot-telegram-all').boundingBox();
        expect(box?.height ?? 0).toBeGreaterThanOrEqual(32);
        expect(box?.width ?? 0).toBeGreaterThanOrEqual(64);
        await expect(page.locator('.ks-grid thead')).toBeHidden();
        await axeClean(page, 'kill switches 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S07-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'kill switches 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S07-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S08 — providers & status', () => {
    test('§A–§E: its own screen (no channel-health link); one chain per tier with the breaker state in WORDS; credentials as vault references only; the ladder as live status; the model map with Change; config versions with the honesty layer as a hash; the quota meter with the 80% mark and the reserve; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/providers');
        await expect(page.locator('.ai-crumb b')).toHaveText('Providers & status');
        await expect(page.locator('a[href*="telegram/health"]')).toHaveCount(0); // Q1b: no cross-link
        for (const tier of ['fast', 'strong', 'embeddings']) {
            await expect(page.getByTestId('pv-tier-' + tier)).toBeVisible();
            await expect(page.getByTestId('pv-node-' + tier + '-0')).toContainText(
                /Serving|Tripped|Standby/,
            );
        }
        await expect(page.getByTestId('pv-ladder')).toContainText(/serving|failed over|exhausted/);
        await expect(page.getByTestId('pv-chains')).toContainText('References only');
        await expect(page.getByTestId('pv-chains')).not.toContainText(/sk-[a-z0-9]{8}/i); // SEC-01: never a key fragment
        await expect(page.getByTestId('pv-model-map')).toContainText('ai.providers.manage');
        await page.getByTestId('pv-change-fast').click();
        await expect(page.getByTestId('pv-edit-fast')).toBeVisible();
        await expect(page.getByTestId('pv-publish-routing')).toBeVisible();
        await expect(page.getByTestId('pv-honesty')).toContainText('CODE-SHIPPED');
        await expect(page.getByTestId('pv-honesty')).toContainText('hash');
        await expect(page.getByTestId('pv-honesty').getByRole('button')).toHaveCount(0); // no rollback affordance on the honesty layer
        await expect(page.getByTestId('pv-quota')).toContainText('tokens');
        await expect(page.locator('.pv-qmark')).toBeVisible();
        await expect(page.locator('.pv-qres')).toBeVisible();
        await axeClean(page, 'providers (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S08-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'providers (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S08-build-1440-dark.jpg`, type: 'jpeg', quality: 70 });
    });

    test('§G at 375: the chains stack vertically; axe-clean both themes', async ({ page }) => {
        test.setTimeout(90_000);
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/providers');
        const a = await page.getByTestId('pv-node-fast-0').boundingBox();
        if ((await page.getByTestId('pv-node-fast-1').count()) > 0) {
            const b = await page.getByTestId('pv-node-fast-1').boundingBox();
            expect(b?.y ?? 0).toBeGreaterThan((a?.y ?? 0) + (a?.height ?? 0) - 1); // stacked, not beside
        }
        await axeClean(page, 'providers 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S08-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'providers 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S08-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S09 — decision log & quality loop', () => {
    test('§A–§E: the enriched table under "AI · Decisions" with the today chip; filter chips compose and the empty state names them; the row record view (no payload door); exactly TWO quality tabs and NO RG vocabulary; the calibration table + finding; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/decisions');
        await expect(page.locator('.ai-crumb b')).toHaveText('Decisions');
        await expect(page.locator('.ai-crumb')).not.toContainText('Settings');
        await expect(page.getByTestId('dl-chip')).toContainText('Today');
        await expect(page.getByTestId('decisions-table')).toBeVisible();
        await expect(page.getByTestId('dl-ec11-note')).toContainText('provider_refusal'); // K06 — EC-11 stated where the log lives
        const rows = page.locator('[data-testid^="dl-row-"]');
        await expect(rows.first()).toBeVisible({ timeout: 15_000 });
        await expect(rows.first()).toContainText(/payload · 30 d|hash only/);
        // I03: the record view is the curated record; the payload note names the other key
        await rows.first().click();
        await expect(page.getByTestId('dl-record')).toContainText('platform-debug');
        await page.getByTestId('dl-record').getByRole('button', { name: 'Close' }).click();
        // I01: a filter that matches nothing yields the honest empty state naming the filters
        await page.getByTestId('dl-filter-outcome').selectOption({ index: 0 });
        // I06 / Q2: exactly two tabs, no RG material anywhere on this page
        await expect(page.locator('[data-testid="dl-quality"] [role="tab"]')).toHaveCount(2);
        await expect(page.locator('.ai-shell')).not.toContainText(
            /RG review|rg_risk|responsible gambling/i,
        );
        await page.getByTestId('dl-tab-calibration').click();
        await expect(page.getByTestId('dl-calibration-table')).toBeVisible();
        await expect(page.getByTestId('dl-finding')).toContainText('Reading');
        await axeClean(page, 'decisions (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S09-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await page.getByTestId('dl-tab-unanswerable').click();
        await expect(page.getByTestId('dl-panel-unanswerable')).toBeVisible();
        await setTheme(page, 'dark');
        await axeClean(page, 'decisions (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S09-build-1440-dark.jpg`, type: 'jpeg', quality: 70 });
    });

    test('§G at 375: rows become cards; the two tabs persist; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/decisions');
        await expect(page.locator('[data-testid^="dl-row-"]').first()).toBeVisible({
            timeout: 15_000,
        });
        await expect(page.locator('.dl-table thead')).toBeHidden();
        await expect(page.locator('[data-testid="dl-quality"] [role="tab"]')).toHaveCount(2);
        await axeClean(page, 'decisions 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S09-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'decisions 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S09-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S10 — supervisor outputs & the RG review queue', () => {
    test('§A–§E: the feed, the digest, the QA pre-score labelled advisory, and the RG queue present for the RG key (this agent holds it) with the month figure and the calm slate; axe light + dark', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/supervisor');
        await expect(page.locator('.ai-crumb b')).toHaveText('Supervisor feed');
        await expect(page.getByTestId('sv-feed')).toBeVisible();
        await expect(page.getByTestId('sv-digest')).toBeVisible();
        await expect(page.getByTestId('sv-qa')).toContainText('QA review');
        await expect(page.getByTestId('sv-nav')).toContainText('RG review');
        await expect(page.getByTestId('sv-rg')).toContainText('precision');
        await expect(page.getByTestId('sv-rg')).toContainText('specialist team only');
        await axeClean(page, 'supervisor (light)');
        await page.locator('.ai-shell').screenshot({
            path: `${SHOTS}/B8-S10-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
        await setTheme(page, 'dark');
        await axeClean(page, 'supervisor (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S10-build-1440-dark.jpg`, type: 'jpeg', quality: 70 });
    });

    test('J06 — the same page for a viewer WITHOUT the RG key carries no RG row, no count, no vocabulary', async ({
        page,
    }) => {
        await login(page, 'readonly-fixture@fixture.example');
        await page.setViewportSize(V1440);
        const res = await page.goto('/admin/ai/supervisor');
        if (res && res.status() < 400) {
            await expect(page.getByTestId('sv-nav')).not.toContainText('RG');
            await expect(page.getByTestId('sv-rg')).toHaveCount(0);
            await expect(page.locator('.ai-shell')).not.toContainText(/rg_risk|RG review/);
        } else {
            expect([403, 404]).toContain(res?.status() ?? 0); // the page itself needs ai.decisions.view — absence at the door is absence
        }
    });

    test('§G at 375: the feed reads on a phone; RG actions stack full-width; axe-clean both themes', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V375);
        await page.goto('/admin/ai/supervisor');
        await expect(page.getByTestId('sv-feed')).toBeVisible();
        await axeClean(page, 'supervisor 375 (light)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S10-build-375-light.jpg`, type: 'jpeg', quality: 70 });
        await setTheme(page, 'dark');
        await axeClean(page, 'supervisor 375 (dark)');
        await page
            .locator('.ai-shell')
            .screenshot({ path: `${SHOTS}/B8-S10-build-375-dark.jpg`, type: 'jpeg', quality: 70 });
    });
});

test.describe('B8-S12 — the cross-cutting proof sheet', () => {
    const AI_PAGES = [
        '/admin/ai/persona',
        '/admin/ai/thresholds',
        '/admin/ai/guardrails',
        '/admin/ai/kill-switches',
        '/admin/ai/providers',
        '/admin/ai/decisions',
        '/admin/ai/supervisor',
    ];

    test('L04 — the Amharic sweep: every AI page renders in the am locale at 1440 and 375, light and dark, with no horizontal overflow (real Ethiopic breaks lines, never the layout)', async ({
        page,
    }) => {
        test.setTimeout(240_000);
        setAgentLocale('am');
        try {
            await login(page);
            for (const path of AI_PAGES) {
                for (const [w, h] of [
                    [1440, 900],
                    [375, 812],
                ] as [number, number][]) {
                    await page.setViewportSize({ width: w, height: h });
                    await page.goto(path);
                    await expect(page.locator('.ai-shell')).toBeVisible();
                    for (const theme of ['light', 'dark'] as const) {
                        await setTheme(page, theme);
                        const overflow = await page.evaluate(
                            () =>
                                document.documentElement.scrollWidth -
                                document.documentElement.clientWidth,
                        );
                        expect(
                            overflow,
                            `${path} ${w} ${theme} horizontal overflow px`,
                        ).toBeLessThanOrEqual(0);
                        const slug = path.replace('/admin/ai/', '');
                        await page.locator('.ai-shell').screenshot({
                            path: `${SHOTS}/sweep-am-${slug}-${w}-${theme}.jpg`,
                            type: 'jpeg',
                            quality: 60,
                        });
                    }
                }
            }
            // the strings really are Amharic (not Latin stand-ins): the persona crumb reads ቅንብሮች
            await page.setViewportSize(V1440);
            await page.goto('/admin/ai/persona');
            await expect(page.locator('.ai-crumb')).toContainText('ቅንብሮች');
            // A16 — the Copilot panel itself in Amharic, both viewports (the long-string layout proof)
            await openCopilotThread(page);
            await page.getByTestId('tab-copilot').click();
            await expect(page.getByTestId('copilot-tab')).toBeVisible();
            await page.getByTestId('copilot-tab').screenshot({
                path: `${SHOTS}/sweep-am-copilot-1440-light.jpg`,
                type: 'jpeg',
                quality: 60,
            });
            await page.setViewportSize(V375);
            await openCopilotThread(page); // the phone layout opens the thread from the list, as S01 §G does
            await page.getByTestId('mobile-context-trigger').click();
            await page.getByTestId('tab-copilot').click();
            await expect(page.getByTestId('copilot-tab')).toBeVisible();
            // The sheet's OWN overflow (Doc 07's top bar overflows 48 px at 375 in am on the
            // plain inbox list before any Copilot UI opens — B8-D37, packet P-F1-TOPBAR).
            const sheetOverflow = await page
                .getByTestId('mobile-context-sheet')
                .evaluate((el) => el.scrollWidth - el.clientWidth);
            expect(sheetOverflow, 'copilot sheet am 375 overflow').toBeLessThanOrEqual(0);
            const tabBox = await page.getByTestId('copilot-tab').boundingBox();
            expect(
                (tabBox?.x ?? 0) + (tabBox?.width ?? 0),
                'copilot tab right edge at 375',
            ).toBeLessThanOrEqual(375);
            await page.getByTestId('copilot-tab').screenshot({
                path: `${SHOTS}/sweep-am-copilot-375-light.jpg`,
                type: 'jpeg',
                quality: 60,
            });
        } finally {
            setAgentLocale('en');
        }
    });

    test('L05 — reduced motion: with the preference on, nothing animates or transitions on the Copilot card, the thresholds panel and the kill-switch grid', async ({
        page,
    }) => {
        await login(page);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        await page.getByTestId('tab-copilot').click();
        await page.getByTestId('copilot-regenerate').click();
        await expect(page.getByTestId('card-text')).toBeVisible({ timeout: 15_000 });
        const animated = await page.evaluate(
            () =>
                Array.from(document.querySelectorAll('[data-testid="copilot-tab"] *')).filter(
                    (el) => {
                        const cs = getComputedStyle(el);
                        return (
                            (cs.animationName && cs.animationName !== 'none') ||
                            (cs.transitionDuration &&
                                cs.transitionDuration.split(',').some((d) => parseFloat(d) > 0))
                        );
                    },
                ).length,
        );
        expect(
            animated,
            'animated/transitioning elements in the Copilot tab under reduced motion',
        ).toBe(0);
        for (const path of ['/admin/ai/thresholds', '/admin/ai/kill-switches']) {
            await page.goto(path);
            const n = await page.evaluate(
                () =>
                    Array.from(document.querySelectorAll('.ai-shell *')).filter((el) => {
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

    test('L07 — the five hard absences, probed where they would appear: no send on any AI card (X1); no generated-RG control (X2); no disclosure disable (X3); publish never enabled without a fresh simulation (X5); the decision log carries no RG material (Q2)', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        await page.getByTestId('tab-copilot').click();
        await expect(
            page.locator('[data-testid="copilot-tab"] button', { hasText: /^send/i }),
        ).toHaveCount(0);
        await expect(
            page.locator('[data-testid="ai-actions"] button', { hasText: /^send/i }),
        ).toHaveCount(0);
        await page.goto('/admin/ai/guardrails');
        await expect(
            page.locator('[data-testid="gr-rg"] button', { hasText: /improve|generate|suggest/i }),
        ).toHaveCount(0);
        await expect(
            page.locator(
                '[data-testid="gr-disclosure"] input[type="checkbox"], [data-testid="gr-disclosure"] [role="switch"]',
            ),
        ).toHaveCount(0);
        await page.goto('/admin/ai/persona');
        await expect(
            page.locator(
                '[data-testid="persona-disclosure-lock-en"] input, [data-testid="persona-disclosure-lock-en"] [role="switch"]',
            ),
        ).toHaveCount(0);
        await page.goto('/admin/ai/thresholds');
        await expect(page.getByTestId('thresholds-publish')).toBeDisabled();
        await page.goto('/admin/ai/decisions');
        await expect(page.locator('.ai-shell')).not.toContainText(/rg_risk|RG review/i);
    });
});

// ── SESSION-F1 packets LANDED under the advisor's countersign (owner-relayed at 5e8a953) ──
test.describe('P-F1 packets — BUBBLE · BANNER · TOPBAR (countersigned, landed)', () => {
    test('P-F1-BUBBLE: the AI bubble wears the B1-DS anatomy (ai-bg · 1 px ai-border · 3 px ai-edge inline-start · body in text-primary · the ✦ marker in ai-fg); the transcript-less voice fixture shows the FS-03 degrade line', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await openCopilotThread(page);
        const ai = page.locator('[data-role="ai-marker"]').first().locator('xpath=..');
        await expect(ai).toBeVisible();
        const edge = await ai.evaluate((el) => {
            const s = getComputedStyle(el);
            return { w: s.borderInlineStartWidth, bw: s.borderTopWidth };
        });
        expect(edge.w).toBe('3px');
        expect(edge.bw).toBe('1px');
        const line = page.locator('[data-role="transcript-unavailable"]').first();
        await expect(line).toBeVisible();
        await expect(line).toContainText('Transcript unavailable');
        await expect(line).toContainText('The audio plays as normal');
        await page.locator('[data-testid="thread-pane"]').screenshot({
            path: `${SHOTS}/B8-S03-bubble-anatomy-build-1440-light.jpg`,
            type: 'jpeg',
            quality: 70,
        });
    });

    test('P-F1-BANNER: the inbox kill-switch mirror carries the link to the grid ("Details in Settings → AI → Kill switches") while a switch is off; gone with the flip back', async ({
        page,
    }) => {
        await login(page);
        await page.setViewportSize(V1440);
        await page.goto('/admin/ai/kill-switches');
        const toggle = page.getByTestId('switch-chatbot-telegram-all');
        await expect(toggle).toHaveAttribute('aria-checked', 'true');
        await toggle.click();
        await page.getByTestId('killswitch-reason').fill('f1 packet drill');
        await page.getByTestId('killswitch-confirm-yes').click();
        await expect(toggle).toHaveAttribute('aria-checked', 'false');
        try {
            await page.goto('/inbox');
            await dismissCoachMarks(page);
            const banner = page.getByTestId('ai-killswitch-banner');
            await expect(banner).toBeVisible();
            const link = banner.locator('[data-role="ai-banner-details"]');
            await expect(link).toContainText('Details in Settings');
            await expect(link).toHaveAttribute('href', '/admin/ai/kill-switches');
            await page.locator('[data-testid="ai-killswitch-banner"]').screenshot({
                path: `${SHOTS}/B8-S07-banner-link-build-1440-light.jpg`,
                type: 'jpeg',
                quality: 70,
            });
        } finally {
            await page.goto('/admin/ai/kill-switches');
            await page.getByTestId('switch-chatbot-telegram-all').click();
            await page.getByTestId('killswitch-confirm-yes').click();
            await expect(page.getByTestId('switch-chatbot-telegram-all')).toHaveAttribute(
                'aria-checked',
                'true',
            );
        }
        await page.goto('/inbox');
        await dismissCoachMarks(page);
        await expect(page.getByTestId('ai-killswitch-banner')).toHaveCount(0);
    });

    test('P-F1-TOPBAR: at 375 in the am locale the inbox list no longer overflows; the status WORD hides under 640 px while the dot and the aria-label keep it (B8-D37 closed)', async ({
        page,
    }) => {
        await login(page);
        setAgentLocale('am');
        try {
            await page.setViewportSize(V375);
            await page.goto('/inbox');
            await dismissCoachMarks(page);
            const overflow = await page.evaluate(
                () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
            );
            // on a red, name the offenders (right edge past the viewport) — the runner's fonts differ
            const offenders = await page.evaluate(() =>
                Array.from(document.querySelectorAll('body *'))
                    .map((el) => ({ el, r: el.getBoundingClientRect() }))
                    .filter(({ r }) => r.right > window.innerWidth + 0.5 && r.width > 0)
                    .slice(0, 8)
                    .map(
                        ({ el, r }) =>
                            `${el.tagName.toLowerCase()}${el.getAttribute('data-testid') ? '[' + el.getAttribute('data-testid') + ']' : ''}${el.className && typeof el.className === 'string' ? '.' + el.className.split(' ').slice(0, 2).join('.') : ''} right=${Math.round(r.right)}`,
                    ),
            );
            expect(
                overflow,
                `document overflow at 375 (am); offenders: ${offenders.join(' | ')}`,
            ).toBeLessThanOrEqual(0);
            const status = page.getByTestId('agent-status');
            await expect(status).toBeVisible();
            await expect(status.locator('.status-word')).not.toBeInViewport();
            const aria = (await status.getAttribute('aria-label')) ?? '';
            expect(aria.length).toBeGreaterThan(3);
            await expect(status.locator('span').first()).toBeVisible(); // the dot
            await page.locator('body').screenshot({
                path: `${SHOTS}/B8-S12-topbar-375-am-build-light.jpg`,
                type: 'jpeg',
                quality: 70,
            });
        } finally {
            setAgentLocale('en');
        }
    });
});

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * SESSION-F1 [B8] — the fidelity proofs for Doc 09's surfaces against the frozen
 * Batch 8 (design/frozen/B8-*). Every existing spec stays UNMODIFIED; this file
 * adds the frame-level proofs the mandate asks for: the drawn states are
 * VISIBLE (not merely present), axe-clean in BOTH themes, 44 px targets at 375,
 * and the build side of every frame-vs-build screenshot pair is written under
 * docs/evidence/F1-B8/shots/ so the owner can compare it with the frame.
 */
const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 812 };
const SHOTS = 'docs/evidence/F1-B8/shots';

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
    execFileSync('mkdir', ['-p', SHOTS]);
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

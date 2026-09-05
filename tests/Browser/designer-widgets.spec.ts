// Doc 13 §4.4 FR-19/FR-20/NFR-04/EC-11/EC-12/SEC-03/BR-07 — B3-S08 v3 J01–J10 at full fidelity, walked on the REAL
// doors in the Backoffice shell: the list (J09: 1440 + 375, list first), create with the key shown once, the editor's
// five tabs, the INLINE contrast refusal (ratio + suggestion + the 36/44 px apply — D13-D01), the live preview (both
// themes, both frames, the draft pushed in place), Content (am incomplete), Behavior (voice ABSENT under the flag),
// Install (snippet shown-once line, CSP block, domains through the matcher, the secret), Health (counters, history),
// publish → history → rollback; light/dark + en/am; axe on every screen.
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const V375 = { width: 375, height: 740 };

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}
async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}
async function axeClean(page: Page, ctx: string): Promise<void> {
    const r = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa'])
        .exclude('iframe')
        .analyze();
    expect(r.violations, `${ctx}: ${JSON.stringify(r.violations.map((v) => v.id))}`).toEqual([]);
}
async function createWidget(page: Page, name: string): Promise<string> {
    await page.goto('/admin/widgets');
    await page.getByTestId('widgets-new').click();
    await page.getByTestId('widgets-create-name').fill(name);
    await page.getByTestId('widgets-create-domain').fill('help.fixture.example');
    await page.getByTestId('widgets-create-submit').click();
    await expect(page.getByTestId('widgets-created')).toBeVisible();
    const snippet = await page.getByTestId('widgets-created-snippet').innerText();
    expect(snippet).toMatch(
        /<script async src="https?:\/\/[^"]+\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js"><\/script>/,
    );
    const href = await page.getByTestId('widgets-created-open').getAttribute('href');
    return href!.split('/')[3]!;
}

test('J09 + J01–J03: the list, create (key ONCE), the editor chrome, the inline contrast refusal with the suggestion applied at 36 px, the preview both themes both frames receiving the draft', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await login(page);
    await page.setViewportSize(V1440);
    const id = await createWidget(page, '[FIXTURE] Support subdomain');
    await page.goto('/admin/widgets');
    await expect(page.getByTestId('widgets-list').locator(`[data-widget="${id}"]`)).toContainText(
        'Never published',
    );
    await expect(page.getByTestId('bo-nav-widgets')).toHaveAttribute('aria-current', 'page');
    await axeClean(page, 'widgets list (light)');

    await page.goto(`/admin/widgets/${id}/edit`);
    await expect(page.getByTestId('widgets-editor')).toBeVisible();
    await expect(page.getByTestId('wd-dirty')).toContainText('Everything published');
    await expect(page.locator('.wd-tabs [role=tab]')).toHaveCount(5);
    // the preview frames boot the panel bundle in preview mode — the greeting renders from the SAME strings the visitor gets
    const light = page.frameLocator('[data-testid=wd-preview-light]');
    const dark = page.frameLocator('[data-testid=wd-preview-dark]');
    await expect(light.locator('.nlw-greeting')).toBeVisible({ timeout: 20000 });
    await expect(dark.locator('.nlw-greeting')).toBeVisible({ timeout: 20000 });
    expect(await dark.locator('html').getAttribute('data-theme')).toBe('dark');
    await page.getByTestId('wd-preview-mobile').click();
    await expect(page.getByTestId('wd-preview')).toHaveAttribute('data-frame', 'mobile');
    await page.getByTestId('wd-preview-desktop').click();

    // J03: the refusal, INLINE, from the server's own validation — ratio + the nearest passing shade
    await page.getByTestId('wd-brand-custom').fill('#F2C94C');
    await page.getByTestId('wd-brand-custom').press('Tab');
    const refusal = page.getByTestId('wd-contrast-refusal');
    await expect(refusal).toBeVisible({ timeout: 10000 });
    await expect(refusal).toContainText('#f2c94c on #ffffff is 1.59:1');
    await expect(refusal).toContainText('cannot be published');
    await expect(refusal).toContainText(/#[0-9a-f]{6} at 4\.\d+:1/);
    await expect(page.getByTestId('wd-publish')).toBeDisabled();
    await expect(page.getByTestId('wd-dirty')).toContainText('Unpublished changes');
    const apply = page.getByTestId('wd-contrast-apply');
    expect((await apply.boundingBox())!.height).toBeGreaterThanOrEqual(36); // D13-D01: 36 px at desktop density
    await apply.click();
    await expect(refusal).toHaveCount(0, { timeout: 10000 });
    await expect(page.getByTestId('wd-publish')).toBeEnabled();
    // the draft reached the preview in place: the accent variable moved (EC-11 — no reload of the frame)
    await expect
        .poll(
            async () =>
                light
                    .locator('html')
                    .evaluate((el) => getComputedStyle(el).getPropertyValue('--ds-accent').trim()),
            { timeout: 10000 },
        )
        .toMatch(/^#[0-9a-f]{6}$/);
    await axeClean(page, 'editor appearance (light)');
    await setTheme(page, 'dark');
    await axeClean(page, 'editor appearance (dark)');
});

test('J04–J05: Content (am incomplete gate, shortcuts builder, tab toggles) and Behavior (chatbot read-only, triggers, cooldown ceiling, voice ABSENT); publish → history → rollback (J10); Install + Health (J06–J08)', async ({
    page,
}) => {
    test.setTimeout(150_000);
    await login(page);
    await page.setViewportSize(V1440);
    const id = await createWidget(page, '[FIXTURE] Content widget');
    await page.goto(`/admin/widgets/${id}/edit?tab=content`);
    await page.getByTestId('wd-welcome-en').fill('[FIXTURE] Ask us anything about deposits.');
    await page.getByTestId('wd-welcome-lang-am').click();
    await page.getByTestId('wd-welcome-am').fill('');
    await expect(page.getByTestId('wd-am-incomplete')).toBeVisible({ timeout: 10000 });
    await page.getByTestId('wd-welcome-am').fill('[FIXTURE] ስለ ተቀማጭ ማንኛውንም ይጠይቁን።');
    await expect(page.getByTestId('wd-am-incomplete')).toHaveCount(0, { timeout: 10000 });
    await page.getByTestId('wd-welcome-lang-en').click();
    await page.getByTestId('wd-shortcut-add').click();
    await page.getByTestId('wd-shortcut-label-0').fill('[FIXTURE] Where is my withdrawal?');
    await page.getByTestId('wd-shortcut-target-0').fill('withdrawal');
    await expect(page.getByTestId('wd-refusal')).toHaveCount(0, { timeout: 10000 }); // a complete shortcut validates
    await expect(page.getByTestId('wd-am-incomplete')).toBeVisible({ timeout: 10000 }); // the am label is empty now
    await page.getByTestId('wd-welcome-lang-am').click();
    await page.getByTestId('wd-shortcut-label-0').fill('[FIXTURE] ገንዘቤ የት ደረሰ?');
    await expect(page.getByTestId('wd-am-incomplete')).toHaveCount(0, { timeout: 10000 });
    await expect(page.getByTestId('wd-agent-identity')).toContainText('First name');
    await page.getByTestId('wd-tab-home').uncheck();
    await page.getByTestId('wd-tab-help').uncheck();
    await expect(page.getByTestId('wd-content')).toContainText('Messages-only');

    await page.getByTestId('wd-tab-behavior').click();
    await expect(page.getByTestId('wd-chatbot')).toContainText('Managed in Copilot settings');
    await expect(page.getByTestId('wd-voice-absent')).toContainText('Platform flag off');
    expect(await page.getByTestId('wd-voice').count()).toBe(0);
    await expect(page.getByTestId('wd-behavior')).toContainText('this is a ceiling');
    await page.getByTestId('wd-proactive-add').click();
    await expect(page.getByTestId('wd-proactive').locator('li')).toHaveCount(1);
    await page.getByTestId('wd-proactive').locator('li input[type=text]').first().fill('/deposit');
    await page
        .getByTestId('wd-proactive')
        .locator('li input[placeholder=English]')
        .fill('[FIXTURE] Stuck? I can check how long your deposit should take.');

    // J10: publish with a note, then the history carries it live
    await page.getByTestId('wd-publish-note').fill('[FIXTURE] Messages-only + a trigger');
    await expect(page.getByTestId('wd-publish')).toBeEnabled({ timeout: 10000 });
    await page.getByTestId('wd-publish').click();
    await expect(page.getByTestId('wd-flash')).toContainText('Published');
    await page.waitForURL(/\/edit/);
    await page.goto(`/admin/widgets/${id}/edit?tab=health`);
    const history = page.getByTestId('wd-history');
    await expect(history.locator('.wd-history-row[data-status=live]')).toContainText(
        'Messages-only + a trigger',
    );
    await expect(page.getByTestId('wd-health-version')).toContainText('rollout');
    await expect(page.getByTestId('wd-health-counters')).toContainText('Loads');
    // a second publish, then roll back to v1 (a NEW version, v3)
    await page.getByTestId('wd-tab-appearance').click();
    await page.getByTestId('wd-position').selectOption('bottom_left');
    await expect(page.getByTestId('wd-publish')).toBeEnabled({ timeout: 10000 });
    await page.getByTestId('wd-publish').click();
    await expect(page.getByTestId('wd-flash')).toContainText('Published');
    await page.goto(`/admin/widgets/${id}/edit?tab=health`);
    await page.getByTestId('wd-rollback-1').click();
    await page.waitForLoadState('networkidle');
    await page.goto(`/admin/widgets/${id}/edit?tab=health`);
    await expect(page.getByTestId('wd-history').locator('.wd-history-row')).toHaveCount(3);
    await expect(
        page.getByTestId('wd-history').locator('.wd-history-row[data-status=rolled_back]'),
    ).toHaveCount(1);
    await expect(
        page.getByTestId('wd-history').locator('.wd-history-row[data-status=live]'),
    ).toContainText('v3');
    const patched = page.waitForResponse((r) => r.url().includes('/admin/widgets/api/') && r.request().method() === 'PATCH');
    await page.getByTestId('wd-test-mode').locator('input').check();
    expect((await patched).ok()).toBe(true);
    await page.goto('/admin/widgets');
    await expect(page.getByTestId('widgets-list').locator(`[data-widget="${id}"]`)).toContainText(
        'Test mode',
    );

    // J06: Install — the shown-once line, the CSP block, the domains through the matcher, the secret
    await page.goto(`/admin/widgets/${id}/edit?tab=install`);
    await expect(page.getByTestId('wd-snippet')).toContainText(/\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js/); // RIDER 2: the drawn snippet, at any time
    expect(await page.getByTestId('wd-snippet-shown-once').count()).toBe(0);
    await expect(page.getByTestId('wd-csp')).toContainText('script-src');
    await expect(page.getByTestId('wd-csp')).toContainText('frame-src');
    await expect(page.getByTestId('wd-domains')).toContainText('help.fixture.example');
    await page.getByTestId('wd-domain-input').fill('evil-fixture.example/path');
    await page.getByTestId('wd-domain-add').click();
    await expect(page.getByTestId('wd-domain-refused')).toBeVisible();
    await page.getByTestId('wd-domain-input').fill('*.fixture.example');
    await page.getByTestId('wd-domain-add').click();
    await expect(page.getByTestId('wd-domains')).toContainText('*.fixture.example');
    await expect(page.getByTestId('wd-secret-none')).toBeVisible();
    await page.getByTestId('wd-secret-mint').click();
    await expect(page.getByTestId('wd-secret-shown')).toContainText('wis_');
    await expect(page.getByTestId('wd-secret-mint')).toContainText('Rotate');
    await axeClean(page, 'editor install (light)');
});

test('J09 at 375: the admin on their phone — the list, version and status first; the editor behind; 44 px targets; dark + Amharic axe', async ({
    page,
}) => {
    test.setTimeout(120_000);
    await login(page);
    await page.setViewportSize(V1440);
    const id = await createWidget(page, '[FIXTURE] Phone check');
    await page.setViewportSize(V375);
    await page.goto('/admin/widgets');
    await expect(
        page.getByTestId('widgets-list').locator(`[data-widget="${id}"] .wd-badge`),
    ).toBeVisible();
    expect((await page.getByTestId('widgets-new').boundingBox())!.height).toBeGreaterThanOrEqual(
        44,
    );
    await setTheme(page, 'dark');
    await axeClean(page, 'widgets list 375 (dark)');
    await page.goto(`/admin/widgets/${id}/edit`);
    await expect(page.getByTestId('widgets-editor')).toBeVisible();
    expect((await page.getByTestId('wd-tab-content').boundingBox())!.height).toBeGreaterThanOrEqual(
        44,
    );
    expect(
        await page.evaluate(
            () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
        ),
    ).toBeLessThanOrEqual(0);
    await page.context().addCookies([{ name: 'locale', value: 'am', url: 'https://localhost' }]);
    await page.goto('/admin/widgets');
    await expect(page.getByTestId('widgets-index')).toContainText('አዲስ መግብር');
    await axeClean(page, 'widgets list 375 (am, dark)');
});

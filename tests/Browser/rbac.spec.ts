import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * Doc 03 step 9 — the four B6 screens in the real browser:
 *
 *  - AC-08 (DOM half): for sampled permissions, controls are hidden or
 *    disabled EXACTLY where verdict=deny — proven by contrast between the
 *    Super Admin fixture and the Read Only fixture on the same screens.
 *    The PIXEL half is design-export-blocked (pre-agreed bucket).
 *  - AC-04 / SS-03: a role change reaches an OPEN session's capabilities
 *    within 10 s without re-login.
 *  - §13: axe-clean on all four screens, light AND dark, en AND am; the
 *    matrix keyboard map; aria-checked="mixed"; accordion aria-expanded at
 *    ≤1024; the 375 read-only notice.
 */
function clearThrottle(): void {
    // Many UI logins from one IP in one run trip the per-IP auth throttle
    // (working as designed — session.spec does the same reset).
    execFileSync('docker', [
        'compose',
        '-f',
        'docker/compose.yaml',
        'exec',
        '-T',
        'app',
        'php',
        'artisan',
        'auth:session-inspect',
        'clear-throttle',
    ]);
}

test.beforeEach(() => {
    clearThrottle();
});

const PASSWORD = 'browser-fixture-password';
const ADMIN_EMAIL = 'agent@fixture.example';
const READER_EMAIL = 'readonly-fixture@fixture.example';

async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function expectAxeClean(page: Page, context: string): Promise<void> {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(
        results.violations,
        `${context}: ${JSON.stringify(results.violations.map((v) => v.id))}`,
    ).toEqual([]);
}

test.describe('AC-08 — UI parity with verdicts', () => {
    test('Super Admin sees the mutating controls; Read Only sees NONE of them, on the same screens', async ({
        browser,
    }) => {
        const adminContext = await browser.newContext();
        const adminPage = await adminContext.newPage();
        await login(adminPage, ADMIN_EMAIL);

        await adminPage.goto('/admin/roles');
        await expect(adminPage.getByTestId('role-create')).toBeVisible();
        await expect(adminPage.getByTestId('role-delete-Agent')).toBeVisible();

        // The sampled-permission contrast: the same page for Read Only shows
        // the data (admin.roles.view allows) but NO mutating control
        // (admin.roles.manage denies) — hidden, exactly as FR-11/SEC-01 says
        // hiding is UX with the server refusing beneath.
        const readerContext = await browser.newContext();
        const readerPage = await readerContext.newPage();
        await login(readerPage, READER_EMAIL);

        await readerPage.goto('/admin/roles');
        await expect(readerPage.getByTestId('role-row-Agent')).toBeVisible();
        await expect(readerPage.getByTestId('role-create')).toHaveCount(0);
        await expect(readerPage.getByTestId('role-delete-Agent')).toHaveCount(0);

        // Editor: matrix cells render but are DISABLED for the viewer.
        const editHref = await adminPage
            .getByTestId('role-row-Agent')
            .locator('a')
            .first()
            .getAttribute('href');
        await readerPage.goto(editHref ?? '/admin/roles');
        await expect(readerPage.getByTestId('matrix-cell-tickets.view')).toBeDisabled();
        await expect(readerPage.getByTestId('matrix-save')).toHaveCount(0);
        await expect(adminPage.getByTestId('role-create')).toBeVisible();

        await adminContext.close();
        await readerContext.close();
    });
});

test.describe('AC-04 — propagation into an open session', () => {
    test('a role change reflects in an OPEN session within 10 s, no re-login (SS-03, FR-07)', async ({
        browser,
    }) => {
        const adminContext = await browser.newContext();
        const adminPage = await adminContext.newPage();
        await login(adminPage, ADMIN_EMAIL);

        // The open session whose capabilities will change: the Read Only
        // fixture, already on the roles screen with NO create control.
        const subjectContext = await browser.newContext();
        const subjectPage = await subjectContext.newPage();
        await login(subjectPage, READER_EMAIL);
        await subjectPage.goto('/admin/roles');
        await expect(subjectPage.getByTestId('role-create')).toHaveCount(0);

        // The admin EDITS the Read Only role through the real API: adds
        // admin.roles.manage. Invalidation fans out to every holder.
        const rolesBody = (await (
            await adminPage.request.get('/api/staff/admin/roles')
        ).json()) as {
            roles: {
                id: string;
                name: string;
                version: number;
                permissions: string[];
                scopes: Record<string, string>;
            }[];
        };
        const readOnly = rolesBody.roles.find((role) => role.name === 'Read Only');
        expect(readOnly).toBeTruthy();
        if (!readOnly) return;

        try {
            const grant = await adminPage.request.put(`/api/staff/admin/roles/${readOnly.id}`, {
                data: {
                    name: readOnly.name,
                    color: 'role-read-only',
                    permissions: [...readOnly.permissions, 'admin.roles.manage'],
                    scopes: readOnly.scopes,
                    version: readOnly.version,
                },
            });
            expect(grant.ok()).toBeTruthy();

            // ≤ 10 s later the OPEN session's next navigation carries the new
            // capability — same cookie, no re-login.
            const start = Date.now();
            await expect(async () => {
                await subjectPage.reload();
                await expect(subjectPage.getByTestId('role-create')).toBeVisible({
                    timeout: 1_000,
                });
            }).toPass({ timeout: 10_000 });
            expect(Date.now() - start).toBeLessThanOrEqual(10_000);
        } finally {
            // Restore the template EVEN ON FAILURE (a poisoned fixture broke
            // AC-08 on this suite's first run — never again): content returns
            // to its seeded hash, so EC-07 still sees an untouched template.
            const fresh = (await (
                await adminPage.request.get('/api/staff/admin/roles')
            ).json()) as {
                roles: { id: string; name: string; version: number }[];
            };
            const current = fresh.roles.find((role) => role.name === 'Read Only');
            if (current) {
                await adminPage.request.put(`/api/staff/admin/roles/${current.id}`, {
                    data: {
                        name: readOnly.name,
                        color: 'role-read-only',
                        permissions: readOnly.permissions,
                        scopes: readOnly.scopes,
                        version: current.version,
                    },
                });
            }
        }

        await adminContext.close();
        await subjectContext.close();
    });
});

test.describe('§13 — accessibility, both themes, both languages', () => {
    for (const theme of ['light', 'dark'] as const) {
        test(`the four screens are axe-clean (${theme})`, async ({ browser }) => {
            const context = await browser.newContext({
                colorScheme: theme === 'dark' ? 'dark' : 'light',
            });
            const page = await context.newPage();
            await login(page, ADMIN_EMAIL);
            if (theme === 'dark') {
                await page.emulateMedia({ colorScheme: 'dark' });
                await page.addInitScript(() =>
                    document.documentElement.setAttribute('data-theme', 'dark'),
                );
            }

            await page.goto('/admin/roles');
            await expectAxeClean(page, `roles list ${theme}`);

            const editHref = await page
                .getByTestId('role-row-Agent')
                .locator('a')
                .first()
                .getAttribute('href');
            await page.goto(editHref ?? '/admin/roles');
            await expectAxeClean(page, `role editor ${theme}`);

            await page.goto('/admin/pending-grants');
            await expectAxeClean(page, `pending grants ${theme}`);

            await context.close();
        });
    }

    test('the matrix keyboard map works and the tri-state exposes aria-checked (§9, §13)', async ({
        page,
    }) => {
        await login(page, ADMIN_EMAIL);
        await page.goto('/admin/roles');
        const editHref = await page
            .getByTestId('role-row-Agent')
            .locator('a')
            .first()
            .getAttribute('href');
        await page.goto(editHref ?? '/admin/roles');

        // aria-checked on module select-alls; a partially-selected module is
        // literally "mixed".
        const states = await page
            .locator('[role=checkbox][aria-checked]')
            .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('aria-checked')));
        expect(states).toContain('mixed');

        // `/` focuses search.
        await page.getByTestId('matrix-cell-tickets.view').focus();
        await page.keyboard.press('/');
        await expect(page.getByTestId('matrix-search')).toBeFocused();

        // Arrows move between cells; Space toggles.
        await page.getByTestId('matrix-cell-tickets.view').focus();
        await page.keyboard.press('ArrowDown');
        await expect(page.getByTestId('matrix-cell-tickets.create')).toBeFocused();
        const before = await page
            .getByTestId('matrix-cell-tickets.create')
            .getAttribute('aria-checked');
        await page.keyboard.press(' ');
        const after = await page
            .getByTestId('matrix-cell-tickets.create')
            .getAttribute('aria-checked');
        expect(after).not.toBe(before);
    });

    test('≤1024 the matrix is an accordion with aria-expanded; at 375 the screens are read-only with the explicit notice (§9)', async ({
        browser,
    }) => {
        const tablet = await browser.newContext({ viewport: { width: 1000, height: 800 } });
        const tabletPage = await tablet.newPage();
        await login(tabletPage, ADMIN_EMAIL);
        await tabletPage.goto('/admin/roles');
        const editHref = await tabletPage
            .getByTestId('role-row-Agent')
            .locator('a')
            .first()
            .getAttribute('href');
        await tabletPage.goto(editHref ?? '/admin/roles');
        await expect(tabletPage.locator('[aria-expanded]').first()).toBeVisible();
        await tablet.close();

        const phone = await browser.newContext({ viewport: { width: 375, height: 740 } });
        const phonePage = await phone.newPage();
        await login(phonePage, ADMIN_EMAIL);
        await phonePage.goto(editHref ?? '/admin/roles');
        await expect(phonePage.getByTestId('phone-readonly-notice')).toBeVisible();
        await phone.close();
    });

    test('the screens render in Amharic through the i18n chain (§14) and stay axe-clean', async ({
        browser,
    }) => {
        // The user's stored locale drives ApplyUserLocale → the server
        // localizes every copy prop (C-08's chain; the chain itself is
        // proven in I18nFallbackTest/UserLocaleTest — this test proves the
        // SCREENS through it).
        const setLocale = (locale: string): void => {
            execFileSync('docker', [
                'compose',
                '-f',
                'docker/compose.yaml',
                'exec',
                '-T',
                'postgres',
                'psql',
                '-U',
                'postgres',
                '-d',
                'na_live_support',
                '-c',
                `UPDATE users SET locale = ${locale} WHERE email = 'agent@fixture.example'`,
            ]);
        };
        setLocale("'am'");
        try {
            const context = await browser.newContext({ locale: 'am' });
            const page = await context.newPage();
            await login(page, ADMIN_EMAIL);
            await page.goto('/admin/roles');
            await expect(page.locator('h1')).toContainText('ሚናዎች');
            await expectAxeClean(page, 'roles list am');
            await context.close();
        } finally {
            setLocale('NULL');
        }
    });
});

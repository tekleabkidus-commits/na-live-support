// Doc 14 step 13 — shared plumbing for the six [B14-batch] screen specs:
// seeding (capture-first), login, theme/locale, the axe gate.
import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

export const PASSWORD = 'browser-fixture-password';
export const ADMIN_EMAIL = 'agent@fixture.example';
export const V1440 = { width: 1440, height: 900 };
export const V375 = { width: 375, height: 812 };

export function artisan(...args: string[]): void {
    try {
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
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(
            `${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`,
        );
    }
}

export function seedSmsScreens(): void {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\SmsBrowserSeeder', '--force');
}

export async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(ADMIN_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

export async function setTheme(page: Page, theme: 'light' | 'dark'): Promise<void> {
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme);
}

export async function setLocale(page: Page, locale: 'en' | 'am'): Promise<void> {
    await page.context().addCookies([{ name: 'locale', value: locale, url: 'https://localhost' }]);
}

export async function axeClean(page: Page, ctx: string): Promise<void> {
    const r = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(r.violations, `${ctx}: ${JSON.stringify(r.violations.map((v) => v.id))}`).toEqual([]);
}

export function clearThrottle(): void {
    // the house guard: serial logins trip the auth throttle (the telegram-screens precedent)
    artisan('auth:session-inspect', 'clear-throttle');
}

export function setAgentLocale(locale: 'en' | 'am'): void {
    // the f1-b9 idiom: users.locale ALWAYS wins over the guest cookie for staff,
    // so the am sweep flips the fixture agent's own locale and restores it after
    artisan(
        'tinker',
        '--execute',
        `use Illuminate\\Support\\Facades\\DB;
$t = DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');
DB::statement("SELECT set_config('app.tenant_id', ?, false)", [(string) $t]);
DB::table('users')->where('email','agent@fixture.example')->update(['locale' => '${locale}']);`,
    );
}

import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 12 — FR-28's status UI: the Online/Away/Offline menu through the
 * real door (views.manage — the R-26 sixth sibling), BR-06's 60 s CANCELABLE
 * countdown toast (scripted via the same hook the idle timer calls), and the
 * forced-status render WITH attribution ("set by …") — routing truth, not
 * surveillance theater.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

function artisan(...args: string[]): string {
    try {
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
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(
            `${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`,
        );
    }
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function openInbox(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.getByTestId('views-rail')).toBeVisible();
}

declare global {
    interface Window {
        __inboxStatus: {
            startCountdown: () => void;
            current: () => { status: string; setByName: string | null; countdown: number | null };
            refresh: () => Promise<void>;
        };
    }
}

test('FR-28: the menu sets Away then Online through the real door — the label round-trips server truth', async ({
    page,
}) => {
    await openInbox(page);

    await page.getByTestId('agent-status').click();
    await page.locator('[data-status-option="away"]').click();
    await expect(page.getByTestId('agent-status')).toContainText('Away');

    await page.getByTestId('agent-status').click();
    await page.locator('[data-status-option="online"]').click();
    await expect(page.getByTestId('agent-status')).toContainText('Online');
});

test('BR-06: the away countdown toast is CANCELABLE — it counts down, cancel keeps the agent online and the toast leaves', async ({
    page,
}) => {
    await openInbox(page);

    // Be online first (the countdown only arms while online).
    await page.getByTestId('agent-status').click();
    await page.locator('[data-status-option="online"]').click();
    await expect(page.getByTestId('agent-status')).toContainText('Online');

    // The scripted hook — the SAME function the idle timer calls after
    // inbox.away.idle_minutes of silence.
    await page.evaluate(() => window.__inboxStatus.startCountdown());
    const toast = page.getByTestId('away-countdown-toast');
    await expect(toast).toBeVisible();

    const first = await page.evaluate(() => window.__inboxStatus.current().countdown);
    await page.waitForTimeout(2100);
    const later = await page.evaluate(() => window.__inboxStatus.current().countdown);
    expect(later, 'the countdown must actually count down').toBeLessThan(first ?? 61);

    await page.getByTestId('away-countdown-cancel').click();
    await expect(toast).toHaveCount(0);
    await expect(page.getByTestId('agent-status')).toContainText('Online');
});

test('FR-28: a supervisor FORCE renders with attribution — "set by …" appears on the control', async ({
    page,
}) => {
    await openInbox(page);

    // The force door itself is Pest-proven (StatusNotificationsTest); here a
    // supervisor-attributed row is written through the REAL AgentStatus
    // service and the control must RENDER the attribution.
    artisan(
        'tinker',
        '--execute',
        `
        $tenant = \\Illuminate\\Support\\Facades\\DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->first();
        app(\\App\\Support\\Tenancy\\TenantContext::class)->bind($tenant->id, \\App\\Support\\Tenancy\\ResolutionSource::Console);
        app(\\App\\Support\\Tenancy\\RlsSession::class)->set($tenant->id);
        $agent = \\App\\Models\\User::query()->where('email','agent@fixture.example')->firstOrFail();
        $supervisor = \\App\\Models\\User::query()->firstOrCreate(
            ['email' => 'supervisor-fixture@fixture.example'],
            ['name' => 'Fixture Supervisor', 'password' => \\Illuminate\\Support\\Facades\\Hash::make('browser-fixture-password')]
        );
        app(\\App\\Domain\\Inbox\\Status\\AgentStatus::class)->force($supervisor, $agent->identifier(), 'away');
        `,
    );

    await page.evaluate(async () => window.__inboxStatus.refresh());
    await expect(page.getByTestId('agent-status')).toContainText('Away');
    await expect(page.getByTestId('status-forced-attribution')).toContainText(
        'set by Fixture Supervisor',
    );

    // Leave the fixture online for later specs (routing truth restored).
    await page.getByTestId('agent-status').click();
    await page.locator('[data-status-option="online"]').click();
    await expect(page.getByTestId('agent-status')).toContainText('Online');
});

import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * S07 step 12 — FR-26's bell UI: the badge (server count), the dropdown rows
 * (identifiers-only copy from i18n templates — Q7/SEC-04's posture), the
 * deep link IN PLACE, mark-all-read (the R-26 fifth sibling's door), and the
 * quick mute wired INTO the notifier's live prefs (a muted bell silences
 * real emissions, not just its own dot).
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

function artisan(...args: string[]): void {
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
    await expect(page.getByTestId('views-rail')).toBeVisible();
}

test('FR-26: the badge carries the server unread count; the dropdown renders the seeded rows with identifier-built copy; a row deep-links to its thread IN PLACE', async ({
    page,
}) => {
    await openInbox(page);

    // T-03's posture: the badge equals the SERVER's unread count, never a
    // client derivation (real fanout rows from earlier suite runs may sit
    // beside the three seeded fixtures — the badge must tell that truth).
    const serverUnread = await page.evaluate(async () => {
        const response = await fetch('/inbox/notifications', {
            headers: { Accept: 'application/json' },
        });
        return ((await response.json()) as { unread: number }).unread;
    });
    expect(serverUnread).toBeGreaterThanOrEqual(3);
    const badge = page.getByTestId('bell-unread-badge');
    await expect(badge).toHaveText(serverUnread > 999 ? '999+' : String(serverUnread));

    await page.getByTestId('notification-bell').click();
    const dropdown = page.getByTestId('bell-dropdown');
    await expect(dropdown).toBeVisible();

    await expect(dropdown.locator('[data-bell-row="notify.inbox.assignment"]')).toContainText(
        '#4801 was assigned to you',
    );
    await expect(dropdown.locator('[data-bell-row="notify.inbox.sla_warning"]')).toContainText(
        'SLA warning on #4801',
    );
    await expect(dropdown.locator('[data-bell-row="notify.inbox.bulk_completed"]')).toContainText(
        '18 done, 2 skipped',
    );

    // The deep link opens the thread in place — no navigation.
    await dropdown.locator('[data-bell-row="notify.inbox.assignment"]').click();
    await expect(page.getByTestId('thread-pane')).toBeVisible();
    await expect(page.getByTestId('bell-dropdown')).toHaveCount(0);
});

test('FR-26: mark-all-read stamps read_at server-side — the badge clears and STAYS cleared across a reload', async ({
    page,
}) => {
    await openInbox(page);

    await page.getByTestId('notification-bell').click();
    await page.getByTestId('bell-mark-all-read').click();
    await expect(page.getByTestId('bell-unread-badge')).toHaveCount(0);

    await page.reload();
    await expect(page.getByTestId('views-rail')).toBeVisible();
    await expect(page.getByTestId('bell-unread-badge')).toHaveCount(0);
});

test('FR-26 quick mute silences REAL emissions: mute 1 h → a scripted my-open event emits NOTHING; unmute → it emits again', async ({
    context,
    page,
}) => {
    await context.addInitScript(() => {
        Object.defineProperty(document, 'hasFocus', { value: () => false });
        class FakeNotification {
            static permission = 'granted';
            constructor() {}
        }
        Object.defineProperty(window, 'Notification', {
            value: FakeNotification,
            configurable: true,
        });
    });
    await openInbox(page);

    await page.getByTestId('notification-bell').click();
    await page.getByTestId('bell-mute-toggle').click();
    await page.getByTestId('bell-mute-1h').click();
    await expect(page.getByTestId('bell-muted-dot')).toBeVisible();

    await page.evaluate(() =>
        window.__inboxNotify.fire({
            kind: 'my_open_message',
            customerName: 'Muted Fixture',
            ticketNumber: 4801,
        }),
    );
    let emissions = await page.evaluate(() => window.__inboxNotify.emissions());
    expect(emissions.sounds + emissions.notifications.length).toBe(0);

    await page.getByTestId('notification-bell').click(); // close + reopen for a fresh menu
    await page.getByTestId('notification-bell').click();
    await page.getByTestId('bell-mute-toggle').click();
    await page.getByTestId('bell-unmute').click();
    await expect(page.getByTestId('bell-muted-dot')).toHaveCount(0);

    await page.evaluate(() =>
        window.__inboxNotify.fire({
            kind: 'my_open_message',
            customerName: 'Unmuted Fixture',
            ticketNumber: 4801,
        }),
    );
    emissions = await page.evaluate(() => window.__inboxNotify.emissions());
    expect(emissions.sounds + emissions.notifications.length).toBeGreaterThan(0);
});

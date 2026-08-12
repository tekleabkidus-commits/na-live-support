import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * S07 step 12 — THE ORDERED-FIRST PROOFS, before any sound ships:
 *  · FR-04/FR-27 (B12-A01 EC-03's dedup): TWO TABS, one scripted event to
 *    BOTH — exactly ONE emission total (the leader plays once). Sabotage #5
 *    disables the election and this goes red on a duplicate.
 *  · SEC-04: the desktop-notification CONTENT is customer name + ticket
 *    number ONLY — the event carries a snippet, the notification must NOT
 *    (sabotage #19's target); the reduced setting drops even the name.
 *  · FR-26's quick mute silences emissions entirely.
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

async function login(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

type NotifyHook = {
    fire: (event: {
        kind: string;
        customerName: string;
        ticketNumber: number;
        snippet?: string;
    }) => void;
    fireWith: (
        event: { kind: string; customerName: string; ticketNumber: number; snippet?: string },
        prefs: {
            soundMyOpen: boolean;
            soundUnassigned: boolean;
            reduced: boolean;
            mutedUntil: string | null;
            reducedLabel: string;
        },
    ) => void;
    emissions: () => { sounds: number; notifications: string[] };
    leader: () => { tabId: string; isLeader: boolean };
};

declare global {
    interface Window {
        __inboxNotify: NotifyHook;
    }
}

test('FR-04 THE DEDUP PROOF (sabotage #5 red here): two tabs, the same event scripted to BOTH — exactly ONE tab emits; the leader survives and the follower stays silent', async ({
    context,
    page,
}) => {
    // Deterministic notification substrate: a granted permission + a no-op
    // constructor (Playwright's grantPermissions does not set the legacy
    // Notification.permission getter on a self-signed origin). The proof is
    // about the CONTENT the notifier builds, not that Chrome paints it — the
    // notifier records every content string it would show.
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
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await expect(page.getByTestId('views-rail')).toBeVisible();

    const page2 = await context.newPage();
    await page2.goto('https://localhost/inbox');
    await expect(page2.getByTestId('views-rail')).toBeVisible();
    await page2.waitForTimeout(1500); // one heartbeat round — the election settles

    const event = {
        kind: 'my_open_message',
        customerName: 'አበበ ታደሰ',
        ticketNumber: 4801,
        snippet: 'SECRET BODY',
    };
    await page.evaluate((e) => window.__inboxNotify.fire(e), event);
    await page2.evaluate((e) => window.__inboxNotify.fire(e), event);

    const emissionsA = await page.evaluate(() => window.__inboxNotify.emissions());
    const emissionsB = await page2.evaluate(() => window.__inboxNotify.emissions());
    const leaderA = await page.evaluate(() => window.__inboxNotify.leader());
    const leaderB = await page2.evaluate(() => window.__inboxNotify.leader());

    expect(leaderA.isLeader !== leaderB.isLeader, 'exactly one leader must exist').toBe(true);
    expect(
        emissionsA.sounds + emissionsB.sounds,
        'the event must emit EXACTLY ONCE across tabs',
    ).toBe(1);
    expect(emissionsA.notifications.length + emissionsB.notifications.length).toBe(1);
    await page2.close();
});

test("SEC-04 (sabotage #19 red here): the notification content is NAME + NUMBER only — the event's snippet NEVER appears; reduced mode drops even the name", async ({
    context,
    page,
}) => {
    // Deterministic notification substrate: a granted permission + a no-op
    // constructor (Playwright's grantPermissions does not set the legacy
    // Notification.permission getter on a self-signed origin). The proof is
    // about the CONTENT the notifier builds, not that Chrome paints it — the
    // notifier records every content string it would show.
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
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await expect(page.getByTestId('views-rail')).toBeVisible();

    await page.evaluate(() =>
        window.__inboxNotify.fire({
            kind: 'my_open_message',
            customerName: 'Kidist Haile',
            ticketNumber: 4802,
            snippet: 'the secret message body',
        }),
    );
    let emissions = await page.evaluate(() => window.__inboxNotify.emissions());
    expect(emissions.notifications[0]).toBe('Kidist Haile · #4802');
    expect(emissions.notifications[0]).not.toContain('secret');

    // Reduced: "New activity" — not even the name (SEC-04's own words).
    await page.evaluate(() =>
        window.__inboxNotify.fireWith(
            {
                kind: 'my_open_message',
                customerName: 'Kidist Haile',
                ticketNumber: 4802,
                snippet: 'x',
            },
            {
                soundMyOpen: true,
                soundUnassigned: false,
                reduced: true,
                mutedUntil: null,
                reducedLabel: 'New activity',
            },
        ),
    );
    emissions = await page.evaluate(() => window.__inboxNotify.emissions());
    expect(emissions.notifications[1]).toBe('New activity');

    // FR-26 quick mute: nothing emits.
    const before = emissions.notifications.length;
    await page.evaluate(() =>
        window.__inboxNotify.fireWith(
            { kind: 'my_open_message', customerName: 'X', ticketNumber: 1 },
            {
                soundMyOpen: true,
                soundUnassigned: false,
                reduced: false,
                mutedUntil: new Date(Date.now() + 3_600_000).toISOString(),
                reducedLabel: '',
            },
        ),
    );
    emissions = await page.evaluate(() => window.__inboxNotify.emissions());
    expect(emissions.notifications.length).toBe(before);
});

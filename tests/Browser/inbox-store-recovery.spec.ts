import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 13 — FS-05: client-store corruption recovers to a working session,
 * COMPOSER TEXT ABOVE ALL (drafts are the last thing ever dropped), queued
 * sends survive or are reported lost — never silently dropped, and the agent
 * is told plainly that the session rebuilt (B12-A01 AC-07's executed form).
 *
 * Sabotage #17's target lives here: recovery's preserve/restore pair for the
 * composer text. Recovery REMOUNTS the thread (the refetch FS-05 names), so
 * without the guard the typed text dies with the component — disabling the
 * guard turns the composer-text assertion red.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const RUN = Date.now().toString(36);

type OfflineHook = {
    drop: () => void;
    up: () => void;
    state: () => {
        state: string;
        queued: number;
        flushed: number;
        failures: string[];
        lost: number;
        skew: boolean;
        rebuilt: boolean;
        clockOffsetMs: number;
    };
    flush: () => void;
    refreshMirror: () => void;
    setJitterMax: (ms: number) => void;
    recover: () => void;
};

declare global {
    interface Window {
        __inboxOffline: OfflineHook;
    }
}

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

async function openThread(page: Page, rowText: string): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: rowText })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('FS-05: an unparseable session store on load AUTO-RECOVERS — the schema guard resets, the workspace comes up working, and the agent is told it rebuilt', async ({
    page,
}) => {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await expect(page.locator('[data-ticket]').first()).toBeVisible();

    // Corrupt the session store the way a half-written deploy-skew write
    // would (FS-05's own scenario names FR-30), then reload into the guard.
    await page.evaluate(() => {
        sessionStorage.setItem('inbox-prefs-session-local', '{"density":"comp'); // truncated JSON
    });
    await page.reload();

    await expect(page.getByTestId('store-rebuilt-banner')).toBeVisible();
    // A WORKING session, not a broken one: the list serves rows.
    await expect(page.locator('[data-ticket]').first()).toBeVisible();
    // The corrupt blob is gone; the schema stamp is fresh.
    const rebuiltState = await page.evaluate(() => ({
        blob: sessionStorage.getItem('inbox-prefs-session-local'),
        stamp: sessionStorage.getItem('inbox-client-schema'),
    }));
    expect(rebuiltState.blob).toBeNull();
    expect(rebuiltState.stamp).toBe('1');
});

test("FS-05 COMPOSER TEXT ABOVE ALL (sabotage #17's target): recovery remounts the thread, and the typed text + the queued sends come through it alive", async ({
    page,
    context,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    // A queued send AND a composition in progress — both must survive.
    await context.setOffline(true);
    await expect(page.getByTestId('offline-banner')).toBeVisible({ timeout: 2_000 });
    await page.getByTestId('composer-input').fill(`queued recovery ${RUN}`);
    await page.getByTestId('composer-send').click();
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(1);
    await page.getByTestId('composer-input').fill('the precious composer text FS-05 protects');

    // Corruption strikes mid-session → recovery (reset + refetch + remount).
    await page.evaluate(() => window.__inboxOffline.recover());
    await expect(page.getByTestId('store-rebuilt-banner')).toBeVisible();

    // THE assertion: the composer text lived through the remount.
    await expect(page.getByTestId('composer-input')).toHaveValue(
        'the precious composer text FS-05 protects',
    );
    // The queued send survived too (readable rows are kept, never dropped).
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(1);
    expect((await page.evaluate(() => window.__inboxOffline.state())).queued).toBe(1);

    // And it still LANDS when the network returns — recovery lost nothing.
    await context.setOffline(false);
    await expect
        .poll(async () => (await page.evaluate(() => window.__inboxOffline.state())).flushed, {
            timeout: 15_000,
        })
        .toBe(1);
    await expect(page.locator(`text=queued recovery ${RUN}`)).toHaveCount(1, {
        timeout: 10_000,
    });
});

test('B12-A01 AC-07 (reload case): a queued send survives a full RELOAD and flushes on the next online boot — the queue is storage, not memory', async ({
    page,
    context,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    await context.setOffline(true);
    await expect(page.getByTestId('offline-banner')).toBeVisible({ timeout: 2_000 });
    await page.getByTestId('composer-input').fill(`queued reload ${RUN}`);
    await page.getByTestId('composer-send').click();
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(1);

    // Hold the reply door shut so the pre-reload online event cannot flush —
    // the reload must be what carries the send. (Page routes SURVIVE a
    // reload, so the door is explicitly reopened after it.)
    await page.route('**/inbox/thread/*/reply', (route) => route.abort());
    await context.setOffline(false);
    await page.waitForTimeout(500);
    await page.reload();
    await page.unroute('**/inbox/thread/*/reply');
    await expect(page.locator('[data-ticket]').first()).toBeVisible();

    await expect
        .poll(async () => (await page.evaluate(() => window.__inboxOffline.state())).queued, {
            timeout: 15_000,
        })
        .toBe(0);
    // Exactly ONE landing (the idempotency key was minted at queue time and
    // survived the reload with the row).
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.locator(`text=queued reload ${RUN}`)).toHaveCount(1, {
        timeout: 10_000,
    });
});

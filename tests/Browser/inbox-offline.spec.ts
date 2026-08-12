import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 13 — FR-29/FR-30/EC-07/EC-08 + AC-07's OFFLINE GOLDEN.
 *
 * The golden compresses AC-07's "2-min network cut" — the mechanics under
 * proof (state flip ≤ 2 s, read-only posture, visible queueing, in-order
 * idempotent flush, drafts intact, store convergence) are duration-
 * independent; the cut's LENGTH changes nothing the client does. SS-03's
 * 90-s shape rides the same golden (its 40-agent scale is the Doc 19
 * bucket, stated in the plan).
 *
 * The machine is driven through window.__inboxOffline — the SAME signal
 * seam Reverb's connection callbacks will call in production, plus
 * Playwright's real network-level offline emulation for the browser-offline
 * posture.
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

test('AC-07 THE OFFLINE GOLDEN: a network cut during composition — banner ≤2 s, sends queue as visible chips, reconnect flushes IN ORDER with idempotency keys (zero dupes), the draft in progress survives, stores converge', async ({
    page,
    context,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    // Composition in progress when the network dies.
    await page.getByTestId('composer-input').fill('draft in progress before the cut');

    const cutAt = Date.now();
    await context.setOffline(true);
    // The browser's own offline event drives the machine — FR-29's ≤2 s.
    await expect(page.getByTestId('offline-banner')).toBeVisible({ timeout: 2_000 });
    expect(Date.now() - cutAt).toBeLessThan(2_000);

    // Two sends while offline: both QUEUE, visibly, in order. (The queue
    // clears the box asynchronously — wait for each chip before typing on.)
    await page.getByTestId('composer-input').fill(`offline golden first ${RUN}`);
    await page.getByTestId('composer-send').click();
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(1);
    await page.getByTestId('composer-input').fill(`offline golden second ${RUN}`);
    await page.getByTestId('composer-send').click();

    await expect(page.getByTestId('queued-send-chip')).toHaveCount(2);
    await expect(page.getByTestId('offline-send-strip')).toContainText('2');

    // The third composition stays in the box — the draft AC-07 protects.
    await page.getByTestId('composer-input').fill('draft that must survive the flush');

    // Reconnect: the browser online event → jittered (0) sync → in-order flush.
    await context.setOffline(false);
    await expect(page.getByTestId('offline-banner')).toBeHidden({ timeout: 10_000 });
    await expect
        .poll(async () => (await page.evaluate(() => window.__inboxOffline.state())).queued, {
            timeout: 15_000,
        })
        .toBe(0);

    const state = await page.evaluate(() => window.__inboxOffline.state());
    expect(state.flushed).toBe(2);
    expect(state.failures).toEqual([]);
    expect(state.lost).toBe(0);

    // IN ORDER, exactly once each (the idempotency key is the guarantee; the
    // DOM is the proof): first above second, one bubble per body.
    await expect(page.locator(`text=offline golden first ${RUN}`)).toHaveCount(1, {
        timeout: 10_000,
    });
    await expect(page.locator(`text=offline golden second ${RUN}`)).toHaveCount(1);
    const bodies = await page
        .locator('[data-message]')
        .allTextContents()
        .then((all) => all.filter((t) => t.includes('offline golden')));
    expect(bodies.length).toBe(2);
    expect(bodies[0]).toContain('first');
    expect(bodies[1]).toContain('second');

    // Drafts intact — the composition survived the whole journey.
    await expect(page.getByTestId('composer-input')).toHaveValue(
        'draft that must survive the flush',
    );
});

test('FR-29: a SOCKET drop with live network shows the amber reconnecting pill within 2 s and recovers by itself through the sync path', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    await page.evaluate(() => window.__inboxOffline.drop());
    await expect(page.getByTestId('reconnecting-pill')).toBeVisible({ timeout: 2_000 });

    // Network is fine — the machine's own reconnect (jitter 0) heals it.
    await expect(page.getByTestId('reconnecting-pill')).toBeHidden({ timeout: 10_000 });
    expect((await page.evaluate(() => window.__inboxOffline.state())).state).toBe('online');
});

test('EC-08: 429-on-connect is HONORED — the client backs off instead of hammering, and lands when the server stops shedding', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    const syncTimes: number[] = [];
    let shed = 2; // two 429s, then pass through
    await page.route('**/inbox/sync**', async (route) => {
        syncTimes.push(Date.now());
        if (shed > 0) {
            shed -= 1;
            await route.fulfill({
                status: 429,
                headers: { 'Retry-After': '1' },
                contentType: 'application/json',
                body: JSON.stringify({ code: 'rate_limited' }),
            });
            return;
        }
        await route.continue();
    });

    await page.evaluate(() => window.__inboxOffline.drop());
    await expect
        .poll(async () => (await page.evaluate(() => window.__inboxOffline.state())).state, {
            timeout: 20_000,
        })
        .toBe('online');

    // Three attempts total; every retry waited at least the shed's ask
    // (Retry-After 1 s, jitter ≤1 s on top) — never a hammer.
    expect(syncTimes.length).toBe(3);
    expect((syncTimes[1] ?? 0) - (syncTimes[0] ?? 0)).toBeGreaterThanOrEqual(900);
    expect((syncTimes[2] ?? 0) - (syncTimes[1] ?? 0)).toBeGreaterThanOrEqual(900);
});

test('FR-30: a build-id mismatch on sync shows the PASSIVE refresh banner — composition is never interrupted, nothing reloads on its own', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    await page.getByTestId('composer-input').fill('mid-composition text the toast must not touch');

    await page.route('**/inbox/sync**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                v: 1,
                events: [],
                next_cursor: null,
                overflow: false,
                server_now: new Date().toISOString(),
                build_id: 'a-newer-build-than-loaded',
            }),
        });
    });

    await page.evaluate(() => window.__inboxOffline.drop());
    await expect(page.getByTestId('skew-banner')).toBeVisible({ timeout: 10_000 });

    // PASSIVE: same page, same typed text, a button — not a forced reload.
    await expect(page.getByTestId('skew-refresh')).toBeVisible();
    await expect(page.getByTestId('composer-input')).toHaveValue(
        'mid-composition text the toast must not touch',
    );
    expect(page.url()).toContain('/inbox');
});

test('EC-07: relative times anchor to the SERVER clock — a sync answering 2 minutes ahead moves the client offset, not the client clock', async ({
    page,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    await page.route('**/inbox/sync**', async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                v: 1,
                events: [],
                next_cursor: null,
                overflow: false,
                server_now: new Date(Date.now() + 120_000).toISOString(),
                build_id: 'dev',
            }),
        });
    });

    await page.evaluate(() => window.__inboxOffline.drop());
    await expect
        .poll(
            async () => (await page.evaluate(() => window.__inboxOffline.state())).clockOffsetMs,
            { timeout: 10_000 },
        )
        .toBeGreaterThan(110_000);
});

test('BR-01: a queued send the server REFUSES on flush is reported visibly and never blocks the sends behind it — rolled back in the open, not silently dropped', async ({
    page,
    context,
}) => {
    await openThread(page, 'Yonas Tesfaye');
    await page.evaluate(() => window.__inboxOffline.setJitterMax(0));

    await context.setOffline(true);
    await expect(page.getByTestId('offline-banner')).toBeVisible({ timeout: 2_000 });
    await page.getByTestId('composer-input').fill(`refused send ${RUN}`);
    await page.getByTestId('composer-send').click();
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(1);
    await page.getByTestId('composer-input').fill(`later send ${RUN}`);
    await page.getByTestId('composer-send').click();
    await expect(page.getByTestId('queued-send-chip')).toHaveCount(2);

    // First reply POST is refused; everything after passes through.
    let refused = false;
    await page.route('**/inbox/thread/*/reply', async (route) => {
        if (!refused) {
            refused = true;
            await route.fulfill({
                status: 403,
                contentType: 'application/json',
                body: JSON.stringify({ code: 'forbidden', title: 'Forbidden', status: 403 }),
            });
            return;
        }
        await route.continue();
    });

    await context.setOffline(false);
    await expect
        .poll(async () => (await page.evaluate(() => window.__inboxOffline.state())).queued, {
            timeout: 15_000,
        })
        .toBe(0);

    // The refusal is VISIBLE with its stable code; the later send landed.
    await expect(page.getByTestId('flush-failure-banner')).toBeVisible();
    await expect(page.getByTestId('flush-failure-banner')).toContainText('forbidden');
    await expect(page.locator(`text=later send ${RUN}`)).toHaveCount(1, {
        timeout: 10_000,
    });
    await expect(page.locator(`text=refused send ${RUN}`)).toHaveCount(0);
});

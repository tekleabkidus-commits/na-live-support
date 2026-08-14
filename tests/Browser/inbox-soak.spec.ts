import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * NFR-05 / T-08 — the leak-budget SOAK STRUCTURE (S07 step 15).
 *
 * The SPEC number — 300 MB tab at 8 h with 5k processed events — is a
 * Doc 19 L2 measurement and is NEVER claimed here. What this file is: the
 * harness STRUCTURE, runnable at dev duration, asserting the property the
 * 8 h run will assert — the JS heap must not grow unboundedly across
 * repeated work cycles (view switches + thread opens + scripted deltas).
 * The default battery SKIPS it (duration is real even at dev scale); it
 * runs under SOAK=1, and the same file scaled up (iterations + wall time)
 * is the Doc 19 harness. Growth bound at dev scale: the LAST quarter's
 * mean heap must stay within 1.5x of the FIRST quarter's mean — a leak
 * that survives GC shows up as a monotonic staircase; honest noise doesn't.
 * Dual-tab runs (R-04's soak clause) ride the same harness via SOAK_TABS=2.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

// Retained-memory measurement needs a forceable GC.
test.use({ launchOptions: { args: ['--js-flags=--expose-gc'] } });

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

test.skip(
    process.env.SOAK !== '1',
    'NFR-05 soak: structure only in the default battery — run with SOAK=1 (Doc 19 owns the 8 h p-values)',
);

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function heapBytes(page: Page): Promise<number> {
    // Force GC first so the series measures RETAINED memory, not allocation
    // noise (chromium launches with --js-flags=--expose-gc under this spec).
    return await page.evaluate(() => {
        (globalThis as { gc?: () => void }).gc?.();
        const memory = (performance as unknown as { memory?: { usedJSHeapSize: number } }).memory;
        return memory?.usedJSHeapSize ?? 0;
    });
}

test('NFR-05 structure: the JS heap holds steady across repeated inbox work cycles (dev duration; the 8 h / 300 MB / 5k-event profile is Doc 19 L2)', async ({
    page,
}) => {
    test.setTimeout(600_000);
    const iterations = Number(process.env.SOAK_ITERATIONS ?? 40);

    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.goto('/inbox');
    await dismissCoachMarks(page);

    const samples: number[] = [];
    for (let i = 0; i < iterations; i++) {
        // One work cycle: switch views, open the top thread, feed a
        // scripted delta (the same entry the realtime feed drives).
        await page.locator('[data-view="unassigned"]').first().click();
        await page.locator('[data-ticket]').first().locator('[data-role="row-open"]').click();
        await page.locator('[data-view="my_open"]').first().click();
        await page.evaluate(() => {
            const hook = (
                window as unknown as {
                    __inboxList?: {
                        state: () => { order: string[] };
                        applyDelta: (d: object) => void;
                        flush: () => void;
                    };
                }
            ).__inboxList;
            const id = hook?.state().order[0];
            if (hook && id) {
                hook.applyDelta({ op: 'patch', id, sort_key: Date.now() * 1000, patch: {} });
                hook.flush();
            }
        });
        samples.push(await heapBytes(page));
    }

    const quarter = Math.max(1, Math.floor(samples.length / 4));
    const mean = (xs: number[]): number => xs.reduce((a, b) => a + b, 0) / xs.length;
    const first = mean(samples.slice(0, quarter));
    const last = mean(samples.slice(-quarter));
    console.log(
        `[soak-indicative] heap first-quarter mean ${(first / 1048576).toFixed(1)} MB, last-quarter mean ${(last / 1048576).toFixed(1)} MB over ${samples.length} cycles`,
    );
    expect(first).toBeGreaterThan(0);
    expect(last).toBeLessThan(first * 1.5);
});

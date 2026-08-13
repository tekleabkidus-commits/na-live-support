import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 14 — FS-01 AGAINST THE REAL RELAY (advisor ruling condition 3 on
 * the no-process discovery): the original step-4 drill killed a consumer in
 * a world where the relay was already dead. THIS drill kills the actual
 * `relay` compose service and proves, against the running stack:
 *
 *   FREEZE  — truth changes behind a dead relay; the list keeps showing the
 *             pre-outage world (correct-as-of, never fabricated).
 *   PILL    — the drawn "Live updates delayed — showing everything as of
 *             :time" banner appears once staleness crosses 30 s WITH
 *             pending work (built at step 14: the step-8 "rendered" claim
 *             was false and is corrected on the record).
 *   RECOVERY— the restarted relay drains the backlog; the consumer
 *             recomputes FROM TRUTH; the client converges; the banner goes.
 *
 * Docker calls use compose.yaml alone so CI's stack runs the same drill.
 * The truth-change idiom (direct status write + seeded event, NO inline
 * recompute) is InboxResilienceTest's own, applied at stack level.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };

function compose(...args: string[]): string {
    return execFileSync('docker', ['compose', '-f', 'docker/compose.yaml', ...args], {
        encoding: 'utf8',
    }).trim();
}

function artisan(...args: string[]): string {
    return compose('exec', '-T', 'app', 'php', 'artisan', ...args);
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

test.afterAll(() => {
    // Whatever happened above, the relay must be running for later specs
    // and the scene heals on the next seed.
    compose('start', 'relay');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function openInboxOnline(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    // The heartbeat (the banner's live feed) only beats while ONLINE.
    await page.getByTestId('agent-status').click();
    await page.locator('[data-status-option="online"]').click();
}

test('FS-01 v REAL RELAY: kill the service → truth moves, the list freezes correct-as-of, the staleness banner rises past 30 s; restart → backlog drains, the client converges, the banner goes', async ({
    page,
}) => {
    test.setTimeout(300_000); // a real 30 s threshold is waited out, twice

    await openInboxOnline(page);

    // A HEALTHY PAST (the original drill's own first move): one real event
    // through the live relay stamps the projector heartbeat.
    const abebeRow = page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' });
    await expect(abebeRow).toBeVisible();
    await abebeRow.locator('[data-role="row-open"]').click();
    await expect(page.getByTestId('ticket-panel-number')).toHaveText('#4801');
    await page.getByTestId('panel-status-pending').click();
    await page.getByTestId('pending-set').click(); // pending_customer via the door
    await expect(page.getByTestId('panel-pending-reason')).toBeVisible();
    await page.getByTestId('panel-status-open').click(); // back to canon
    await expect(page.getByTestId('panel-pending-reason')).toHaveCount(0);

    // ── THE OUTAGE ────────────────────────────────────────────────────────
    compose('stop', 'relay');

    // Truth changes BEHIND the dead relay: #4820 resolves directly (the
    // resilience test's idiom — no inline recompute, the event queued).
    artisan(
        'tinker',
        '--execute',
        'DB::connection("pgsql_platform")->table("tickets")->where("number", 4820)->update(["status" => "resolved"]); ' +
            '$t = DB::connection("pgsql_platform")->table("tickets")->where("number", 4820)->first(["id","tenant_id","conversation_id"]); ' +
            'DB::connection("pgsql_platform")->table("outbox")->insert(["id" => (string) Str::uuid7(), "tenant_id" => $t->tenant_id, "event_id" => (string) Str::uuid7(), "name" => "ticket.status_changed", "schema_version" => 1, "payload" => json_encode(["tenant_id" => $t->tenant_id, "ticket_id" => $t->id, "conversation_id" => $t->conversation_id, "old_status" => "open", "new_status" => "resolved", "actor_type" => "system", "actor_id" => null]), "correlation_id" => (string) Str::uuid7(), "occurred_at" => DB::raw("clock_timestamp()")]); ' +
            'echo "truth moved";',
    );

    // THE FREEZE: the Yonas row stays in My Open — a full refetch (view
    // switch there and back) still serves the FROZEN projection, because
    // the projector never saw the event. Correct-as-of, not fabricated.
    //
    // Each chord SETTLES on the store's viewKey (SERVER-truth — it only
    // flips when the fetch lands) before the next fires: back-to-back
    // g,u/g,m raced the unassigned refetch and the second chord was
    // swallowed — the step-14 battery caught it (the shortcuts spec's own
    // banked lesson, applied here). Assertions are unchanged.
    await page.keyboard.press('g');
    await page.keyboard.press('u');
    await expect
        .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey))
        .toBe('unassigned');
    await page.keyboard.press('g');
    await page.keyboard.press('m');
    await expect
        .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey))
        .toBe('my_open');
    const yonasRow = page.locator('[data-ticket]', { hasText: 'Yonas Tesfaye' });
    await expect(yonasRow).toBeVisible();
    await page.waitForTimeout(8_000); // the world keeps not-changing
    await expect(yonasRow).toBeVisible();

    // THE BANNER: staleness crosses 30 s with pending work; the next
    // heartbeat (30 s cadence) carries it up. Budget: threshold + cadence.
    await expect(page.getByTestId('projector-stale-banner')).toBeVisible({ timeout: 90_000 });

    // ── RECOVERY ──────────────────────────────────────────────────────────
    compose('start', 'relay');

    // The backlog drains; the consumer recomputes FROM TRUTH; a refetch
    // converges the client (FS-01: "clients converge via deltas + sync").
    await expect
        .poll(
            async () => {
                await page.keyboard.press('g');
                await page.keyboard.press('u');
                await page.keyboard.press('g');
                await page.keyboard.press('m');
                return await yonasRow.count();
            },
            { timeout: 30_000, intervals: [2_000] },
        )
        .toBe(0);

    // The banner clears on the next heartbeat after the beat resumes.
    await expect(page.getByTestId('projector-stale-banner')).toBeHidden({ timeout: 60_000 });
});

import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 14, Unit D — C15/C16 drawn whole and run against the REAL bulk
 * machinery on the automation queue: the bar's drawn action row → the
 * house A/P/T palette bulk-armed → the id-based door → C15's drawer
 * polling the progress read (title verb, N of M done, ✓/✕/waiting, per-row
 * failures NAMED by number and reason, Retry-the-failed re-batching), and
 * C16's typed phrase above the drawn 50-ticket line via "select all in
 * view" (the mint's server count) with the open-window consequence line.
 *
 * The scene is 55 BULKRUN tickets on their own fixture customer
 * (@fixturebulkrun) — canon rows are never mutated: bulk CLOSE over open
 * tickets refuses per item (open→closed is illegal, the AC-07 edge), which
 * makes the failure drawer honest AND the wide execute non-destructive by
 * physics. Cleanup closes the BULKRUN tickets (never deletes — N-05).
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
            { encoding: 'utf8' },
        );
    } catch (e) {
        const err = e as { message: string; stdout?: unknown; stderr?: unknown };
        throw new Error(
            `${err.message}\n--- stdout ---\n${String(err.stdout ?? '')}\n--- stderr ---\n${String(err.stderr ?? '')}`,
        );
    }
}

const BIND = `
$tid = DB::connection("pgsql_platform")->table("tickets")->where("number", 4801)->value("tenant_id");
app(App\\Support\\Tenancy\\TenantContext::class)->bind($tid, App\\Support\\Tenancy\\ResolutionSource::Console);
app(App\\Support\\Tenancy\\RlsSession::class)->set($tid);
`;

/**
 * The queue's first three rows — unassigned reads ASCENDING (FR-10's
 * queue-fairness default, SystemViews::descending), and page 1 renders its
 * fetched rows un-virtualized, so ranks 1–3 are always in the DOM. The 55
 * seeded rows guarantee the >50 mint for C16; they rank at the queue's
 * tail (created_at is the column's clock default — R-19b's grants refuse a
 * backdate, proven 42501), so the HEAD rows are the interaction targets.
 * Every OPEN_FAMILY status refuses `close` (closed comes only from
 * resolved — the FR-04 matrix), which keeps every close batch in this spec
 * non-destructive by physics while producing honest failure rows.
 */
let top: { id: string; number: number }[] = [];

function headRow(index: number): { id: string; number: number } {
    const row = top[index];
    if (!row) throw new Error('bulk scene not seeded — headRow(' + index + ') missing');
    return row;
}

function seedBulkScene(): void {
    const out = artisan(
        'tinker',
        '--execute',
        `${BIND}
$proj = app(App\\Domain\\Inbox\\ReadModels\\ListProjector::class);
$customer = App\\Models\\Customer::query()->firstOrCreate(
    ["username" => "@fixturebulkrun"],
    ["display_name" => "Bulk Run", "is_shell" => true],
);
// Prior-run leftovers: closed, never deleted (N-05), and re-projected.
$old = DB::table("tickets")->where("customer_id", $customer->identifier())->where("status", "!=", "closed")->pluck("id")->all();
foreach ($old as $oldId) {
    DB::table("tickets")->where("id", $oldId)->update(["status" => "closed"]);
    $proj->recomputeTicket($oldId);
}
$base = (int) DB::table("tickets")->max("number") + 1;
$made = [];
for ($i = 0; $i < 55; $i++) {
    $conversationId = (string) Illuminate\\Support\\Str::uuid7();
    DB::table("conversations")->insert([
        "id" => $conversationId, "tenant_id" => $tid, "channel" => "telegram",
        "channel_ref_id" => "fixture_brand_bot", "external_chat_id" => "bulkrun-".Illuminate\\Support\\Str::random(10),
        "customer_id" => $customer->identifier(), "state" => "active",
    ]);
    $ticketId = (string) Illuminate\\Support\\Str::uuid7();
    DB::table("tickets")->insert([
        "id" => $ticketId, "tenant_id" => $tid, "conversation_id" => $conversationId,
        "customer_id" => $customer->identifier(), "number" => $base + $i,
        "status" => "open", "priority" => "normal", "source" => "agent",
        // created_at stays the column's clock default — R-19b's grant
        // physics REFUSE an app-written stamp here (proven: 42501 on the
        // backdate attempt), and none is needed: the queue is ascending, so
        // the first-inserted of these rank right after the ~dozen canon
        // rows, on page 1, where the un-virtualized DOM renders them.
    ]);
    $proj->recomputeTicket($ticketId);
    $made[] = ["id" => $ticketId, "number" => $base + $i];
}
// The queue's OWN head — the ascending unassigned definition's first FIVE
// (SystemViews' exact membership), page-1 ranks 1–5. Test 1 acts on rows
// 0–2; test 2 acts on rows 3–4 ONLY: with the relay LIVE, test 1's
// priority events reach the projector ASYNCHRONOUSLY and their recomputes
// wipe fixture window stamps on the same rows — the condition-4 battery
// caught the race (filed as a finding). Disjoint rows remove it.
$head = DB::table("tickets as t")
    ->whereNull("t.assignee_id")
    ->whereIn("t.status", ["new", "open", "pending_customer", "pending_internal", "escalated"])
    ->orderBy("t.created_at")
    ->limit(5)
    ->get(["t.id", "t.number"])
    ->all();
// Reset the heads to normal priority so the bulk change CHANGES on every
// run (a repeat run would otherwise re-apply the same value).
$headIds = array_map(fn ($h) => $h->id, $head);
DB::table("tickets")->whereIn("id", $headIds)->update(["priority" => "normal"]);
foreach ($headIds as $headId) { $proj->recomputeTicket($headId); }
echo json_encode(["top" => $head]);`,
    );
    const parsed = JSON.parse(out.slice(out.indexOf('{'))) as {
        top: { id: string; number: number }[];
    };
    top = parsed.top;
    artisan('inbox:views-rebuild');
}

/**
 * The consequence line's stamps — set right before the page loads, because
 * any projector recompute (test 1's priority batch triggers them) rebuilds
 * the rows from truth and clears fixture-written window stamps.
 */
function stampOpenWindows(ids: string[]): void {
    const list = ids.map((id) => '"' + id + '"').join(', ');
    artisan(
        'tinker',
        '--execute',
        `${BIND}
DB::table("ticket_list_items")->whereIn("ticket_id", [${list}])
    ->update(["window_expires_at" => DB::raw("clock_timestamp() + interval '3 hours'")]);
echo "stamped";`,
    );
}

function cleanupBulkScene(): void {
    artisan(
        'tinker',
        '--execute',
        `${BIND}
$proj = app(App\\Domain\\Inbox\\ReadModels\\ListProjector::class);
$customer = App\\Models\\Customer::query()->where("username", "@fixturebulkrun")->first();
if ($customer !== null) {
    $ids = DB::table("tickets")->where("customer_id", $customer->identifier())->where("status", "!=", "closed")->pluck("id")->all();
    foreach ($ids as $id) {
        DB::table("tickets")->where("id", $id)->update(["status" => "closed"]);
        $proj->recomputeTicket($id);
    }
}
echo "cleaned";`,
    );
    artisan('inbox:views-rebuild');
}

function ticketColumn(ticketId: string, column: string): string {
    const out = artisan(
        'tinker',
        '--execute',
        `echo json_encode(DB::connection("pgsql_platform")->table("tickets")->where("id", "${ticketId}")->value("${column}"));`,
    );
    return out.trim().replace(/^"|"$/g, '');
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
    seedBulkScene();
});

test.afterAll(() => {
    cleanupBulkScene();
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function openUnassigned(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    // A seeded row in the DOM proves the refetch landed on the grown view.
    await expect(page.locator(`[data-ticket="${headRow(2).id}"]`)).toBeVisible();
}

async function selectRow(page: Page, ticketId: string): Promise<void> {
    await page.locator(`[data-ticket="${ticketId}"] [data-role="row-select"]`).click();
}

test('C15 all-ok: the bar\'s Priority arms the HOUSE palette for the whole selection — the id door runs the real batch on the automation queue, the drawer polls to "3 of 3 done · ✓ 3 changed" and announces, and the database agrees', async ({
    page,
}) => {
    await openUnassigned(page);

    for (const row of top.slice(0, 3)) {
        await selectRow(page, row.id);
    }
    await expect(page.getByTestId('bulk-count')).toHaveText('3 selected');

    await page.getByTestId('bulk-priority').click();
    await expect(page.getByTestId('action-palette')).toHaveAttribute('data-kind', 'priority');
    await page.locator('[data-testid="action-palette-item"]', { hasText: 'High' }).click();

    // ≤50 and non-destructive → no confirm; the drawer opens on the job ids.
    const drawer = page.getByTestId('bulk-progress-drawer');
    await expect(drawer).toBeVisible();
    await expect(page.getByTestId('bulk-progress-title')).toHaveText(
        'Changing priority on 3 tickets',
    );
    await expect(page.getByTestId('bulk-progress-done')).toHaveText('3 of 3 done', {
        timeout: 30_000,
    });
    await expect(page.getByTestId('bulk-progress-tally')).toContainText('✓ 3 changed');
    // §13/AC-09: the live region spoke — not a silent bar.
    await expect(page.getByTestId('bulk-progress-announcer')).toContainText('3 of 3 done');

    for (const row of top.slice(0, 3)) {
        expect(ticketColumn(row.id, 'priority')).toBe('high');
    }
    await page.getByTestId('bulk-progress-close').click();
    await expect(drawer).toHaveCount(0);
});

test('C15 failures + retry, then C16 over the 50 line: close refuses per item with the reason NAMED on the row by ticket number; Retry re-batches exactly the failed; "select all" mints the server count and the wide close is TYPED, not clicked; the drawer is the 375 sheet', async ({
    page,
}) => {
    // The wide execute runs the whole queue through the automation family.
    test.setTimeout(300_000);
    stampOpenWindows([headRow(3).id, headRow(4).id]);
    await openUnassigned(page);

    // The window pair (rows test 1 never touched — see the seed note).
    await selectRow(page, headRow(3).id);
    await selectRow(page, headRow(4).id);
    await expect(page.getByTestId('bulk-count')).toHaveText('2 selected');

    await page.getByTestId('bulk-close').click();
    const confirm = page.getByTestId('bulk-confirm-dialog');
    await expect(confirm).toBeVisible();
    await expect(confirm).toContainText('Close 2 tickets');
    // The drawn consequence, in nouns, BEFORE the action.
    await expect(page.getByTestId('bulk-confirm-windows')).toHaveText(
        'Customers on 2 of these still have an open reply window.',
    );
    // Under the 50 line: clicked, not typed.
    await expect(page.getByTestId('bulk-confirm-phrase')).toHaveCount(0);
    await page.getByTestId('bulk-confirm-go').click();

    // Both refuse (open→closed is illegal) — the drawer names each row.
    const drawer = page.getByTestId('bulk-progress-drawer');
    await expect(drawer).toBeVisible();
    await expect(page.getByTestId('bulk-progress-done')).toHaveText('2 of 2 done', {
        timeout: 30_000,
    });
    const failures = page.getByTestId('bulk-progress-failures');
    await expect(failures).toContainText(`#${headRow(3).number}`);
    await expect(failures).toContainText(`#${headRow(4).number}`);
    // The reason is a NAMED sentence, never the raw stable code (N-03/§14).
    await expect(failures).not.toContainText('illegal_transition');

    // "Retry the 2 that failed" re-batches EXACTLY those two.
    await page.getByTestId('bulk-progress-retry').click();
    await expect(page.getByTestId('bulk-progress-done')).toHaveText('2 of 2 done', {
        timeout: 30_000,
    });
    await expect(failures).toContainText(`#${headRow(3).number}`);

    // 375: the same drawer renders as the bottom sheet (drawn variant).
    await page.setViewportSize({ width: 375, height: 812 });
    await expect(drawer).toBeVisible();
    await expect(drawer).toHaveClass(/inset-x-0/);
    await page.setViewportSize(V1440);
    await page.getByTestId('bulk-progress-close').click();

    // ── C16: over the 50 line via the SERVER-minted whole-view selection ──
    const total = Number(
        await page
            .getByTestId('list-total')
            .textContent()
            .then((text) => text?.match(/\d+/)?.[0] ?? '0'),
    );
    expect(total).toBeGreaterThan(50);
    await page.locator('[data-role="row-select"]').first().click();
    await page.getByTestId('select-all-in-view').click();
    // The retrying expect waits for the mint's SERVER count to land.
    await expect(page.getByTestId('bulk-count')).toHaveText(`${total} selected`);
    const minted = total;

    await page.getByTestId('bulk-close').click();
    await expect(page.getByTestId('bulk-confirm-dialog')).toContainText(
        `${minted} is above the 50-ticket line, so this one is typed, not clicked.`,
    );
    const go = page.getByTestId('bulk-confirm-go');
    await expect(go).toBeDisabled();
    await page.getByTestId('bulk-confirm-phrase').fill('close 1');
    await expect(go).toBeDisabled();
    await page.getByTestId('bulk-confirm-phrase').fill(`close ${minted}`);
    await expect(go).toBeEnabled();
    await go.click();

    // The token door executes the WHOLE view — every open ticket refuses,
    // nothing mutates (non-destructive by physics), the drawer reports all.
    await expect(page.getByTestId('bulk-progress-title')).toHaveText(`Closing ${minted} tickets`);
    await expect(page.getByTestId('bulk-progress-done')).toHaveText(`${minted} of ${minted} done`, {
        timeout: 180_000,
    });
    await expect(page.getByTestId('bulk-progress-tally')).toContainText(`✕ ${minted} failed`);
    await page.getByTestId('bulk-progress-close').click();
});

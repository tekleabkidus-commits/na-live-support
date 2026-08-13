import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * B2-S04's state banners (S07 step 14), walked on the REAL doors: C8
 * resolved (+Reopen now through the transition door — the no-reason edge),
 * C11 escalated (reason expander; composer KEPT), C10 spam (Not spam with
 * the written reason through the unspam door), C9 closed (banner replaces
 * the composer), and D14's thread error state with retry.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';

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

function ticketField(number: number, column: string): string {
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
            'tinker',
            '--execute',
            `echo (string) DB::connection("pgsql_platform")->table("tickets")->where("number", ${number})->value("${column}");`,
        ],
        { encoding: 'utf8' },
    ).trim();
}

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});
test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});
test.afterAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

async function openThread(page: Page, view = 'unassigned'): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator(`[data-view="${view}"]`).first().click();
    await page.locator('[data-ticket]').first().locator('[data-role="row-open"]').click();
    await expect(page.getByTestId('thread-scroll')).toBeVisible();
}

test('C8→C11→C10: resolve raises the banner with Reopen now (the no-reason edge); escalate shows the ▲ record and KEEPS the composer; spam replaces it until "Not spam" with a written reason restores', async ({
    page,
}) => {
    await openThread(page);
    const post = (path: string, payload: Record<string, unknown>) =>
        page.evaluate(
            async ({ path, payload }) => {
                const xsrf = decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                );
                const conv = window.location.href;
                void conv;
                const response = await fetch(path, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': xsrf,
                    },
                    body: JSON.stringify(payload),
                });
                return response.status;
            },
            { path, payload },
        );

    // Deterministic ids from the machinery itself (canon row #4830, open).
    const conversationId = ticketField(4830, 'conversation_id');
    const ticketId4830 = ticketField(4830, 'id');
    await page.goto(`/inbox/t/${ticketId4830}`);
    await expect(page.getByTestId('thread-scroll')).toBeVisible();
    // C8 — resolve through the composer's own transition door.
    expect(await post(`/inbox/thread/${conversationId}/transition`, { to: 'resolve' })).toBe(200);
    await page.reload();
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ticket-state-banner')).toBeVisible();
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Resolved');
    // The composer survives on resolved (only closed/spam replace it).
    await expect(page.getByTestId('composer')).toBeVisible();

    // Reopen now — the resolved→open edge needs no reason (the matrix's own rule).
    await page.getByTestId('reopen-now').click();
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Reopened', {
        timeout: 5_000,
    });

    // C11 — escalate through the door; banner names the record, composer stays.
    const ticketId = ticketId4830;
    expect(
        await post(`/inbox/t/${ticketId}/escalate`, {
            path: 'supervisor_review',
            reason: 'payout over limit with a mismatched reference',
        }),
    ).toBe(200);
    await page.reload();
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Escalated');
    await page.getByTestId('read-escalation-reason').click();
    await expect(page.getByTestId('escalation-reason')).toContainText('mismatched reference');
    await expect(page.getByTestId('composer')).toBeVisible();

    // De-escalate back to open, then C10 — spam REPLACES the composer.
    expect(
        await post(`/inbox/t/${ticketId}/deescalate`, {
            note: 'reviewed and cleared by supervisor',
        }),
    ).toBe(200);
    expect(await post(`/inbox/t/${ticketId}/spam`, { reason: 'promo blast, not a customer' })).toBe(
        200,
    );
    await page.reload();
    await dismissCoachMarks(page);
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Marked as spam');
    await expect(page.getByTestId('composer')).toHaveCount(0);

    // "Not spam" with the WRITTEN reason → restored, composer back.
    await page.getByTestId('not-spam').click();
    await page.getByTestId('banner-reason').fill('real customer, mis-flagged in triage');
    await page.getByTestId('banner-reason-submit').click();
    await expect(page.getByTestId('composer')).toBeVisible({ timeout: 5_000 });
});

test("C9: the CLOSED ticket's banner replaces the composer with the reopen posture; D14: a blocked thread route shows the error state and Try again recovers it", async ({
    page,
}) => {
    // Close #4805 through the MACHINE (the sole writer, properly bound —
    // the canon closed row #4819 shares its conversation with a newer
    // ticket, and the latest ticket rightly governs the banner).
    const closedTicket = ticketField(4805, 'id');
    artisan(
        'tinker',
        '--execute',
        `$tid = DB::connection("pgsql_platform")->table("tickets")->where("number", 4805)->value("tenant_id");
         app(App\\Support\\Tenancy\\TenantContext::class)->bind($tid, App\\Support\\Tenancy\\ResolutionSource::Console);
         app(App\\Support\\Tenancy\\RlsSession::class)->set($tid);
         $svc = app(App\\Domain\\Tickets\\Transitions\\TicketTransitionService::class);
         $m = App\\Models\\Ticket::query()->findOrFail("${closedTicket}");
         if ($m->getAttribute("status") !== "resolved") { $svc->transition($m, App\\Domain\\Tickets\\Transitions\\TicketStatus::Resolved, App\\Domain\\Tickets\\Transitions\\TransitionContext::system("spec_resolve")); $m->refresh(); }
         $svc->transition($m, App\\Domain\\Tickets\\Transitions\\TicketStatus::Closed, App\\Domain\\Tickets\\Transitions\\TransitionContext::system("spec_close"));
         echo "closed";`,
    );
    await openThread(page);
    await page.goto(`/inbox/t/${closedTicket}`);
    await expect(page.getByTestId('ticket-state-banner')).toBeVisible();
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Closed');
    await expect(page.getByTestId('composer')).toHaveCount(0);

    // D14 — block the thread read, force a refetch, see the error + retry.
    await page.route('**/inbox/thread/**', (route) => {
        if (route.request().method() === 'GET' && !route.request().url().includes('/draft')) {
            void route.fulfill({ status: 500, body: '{}' });
        } else {
            void route.continue();
        }
    });
    await page.reload();
    await expect(page.getByTestId('thread-error')).toBeVisible();
    await page.unroute('**/inbox/thread/**');
    await page.getByTestId('thread-retry').click();
    await expect(page.getByTestId('thread-error')).toHaveCount(0, { timeout: 5_000 });
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Closed');
});

test('D13: a failed list fetch is SAID — the error strip with the cached-truth note, and Try again recovers; My Open emptied through the REAL doors shows the claim-next shape whose affordance lands on the waiting queue (the D7 handler, first row lit)', async ({
    page,
}) => {
    await openThread(page);

    // Empty My Open honestly: resolve every canon ticket of mine through
    // the composer's own transition door (the heal restores them after).
    await page.evaluate(async () => {
        const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        const state = window.__inboxList.state() as unknown as { viewKey: string };
        void state;
        const list = await fetch('/inbox/list?view=my_open', {
            headers: { Accept: 'application/json' },
        }).then((r) => r.json() as Promise<{ rows: { conversation_id: string }[] }>);
        for (const row of list.rows) {
            await fetch(`/inbox/thread/${row.conversation_id}/transition`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrf,
                },
                body: JSON.stringify({ to: 'resolve' }),
            });
        }
    });
    await page.keyboard.press('g');
    await page.keyboard.press('m');
    await expect
        .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey))
        .toBe('my_open');
    await expect(page.getByTestId('list-empty-my-open')).toBeVisible({ timeout: 10_000 });

    // The affordance is the D7 handler: land on Unassigned, first row lit
    // (it navigates to the claim, it does not claim silently).
    await page.getByTestId('empty-claim-next').click();
    await expect(page.locator('[data-ticket]').first()).toBeVisible({ timeout: 10_000 });

    // The ERROR shape: block the list read, force a refetch, see it said.
    await page.route(/\/inbox\/list/, (route) => void route.fulfill({ status: 500, body: '{}' }));
    // claim-next already switched the view to Unassigned (D7's own
    // behavior), so the switch must go SOMEWHERE ELSE to force a fetch —
    // the rail click is the deterministic route.
    await page.locator('[data-view="my_open"]').first().click();
    // The store's viewKey is SERVER-truth (it flips when a fetch lands) —
    // exactly what the block prevents; the strip is the assertable signal.
    await expect(page.getByTestId('list-error')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('list-error')).toContainText('Nothing was lost');
    await page.unroute(/\/inbox\/list/);
    await page.getByTestId('list-retry').click();
    await expect(page.getByTestId('list-error')).toHaveCount(0, { timeout: 10_000 });
    // The recovered view is the EMPTIED My Open — the honest post-retry
    // state is the D13 empty shape, not rows.
    await expect(page.getByTestId('list-empty-my-open')).toBeVisible({ timeout: 10_000 });
});

test('B1-S04 DIALOGS: Escalate (named path demands a target, ≥10-char reason gates the verb) and Mark-as-spam (block is a separate unticked decision) drive the REAL doors from the panel — the banners confirm', async ({
    page,
}) => {
    const openTicket = ticketField(4806, 'id');
    await openThread(page);
    await page.goto(`/inbox/t/${openTicket}`);
    await expect(page.getByTestId('thread-scroll')).toBeVisible();

    // ESCALATE — the dialog's own gates first, then the door.
    await page.getByTestId('op-escalate').click();
    await expect(page.getByTestId('operation-dialog')).toHaveAttribute('data-kind', 'escalate');
    await page.getByTestId('path-named').check();
    await page.getByTestId('operation-reason').fill('payout over limit, mismatched ref');
    await expect(page.getByTestId('operation-confirm')).toBeDisabled(); // named needs a target
    await page.getByTestId('path-supervisor').check();
    await page.getByTestId('operation-reason').fill('short');
    await expect(page.getByTestId('operation-confirm')).toBeDisabled(); // reason < 10
    await page.getByTestId('operation-reason').fill('payout over limit, mismatched ref');
    await expect(page.getByTestId('operation-confirm')).toBeEnabled();
    await page.getByTestId('operation-confirm').click();
    await expect(page.getByTestId('operation-dialog')).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Escalated');

    // De-escalate through the door so spam is reachable, then SPAM.
    await page.evaluate(async (id) => {
        const xsrf = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
        await fetch(`/inbox/t/${id}/deescalate`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf,
            },
            body: JSON.stringify({ note: 'reviewed and cleared by supervisor' }),
        });
    }, openTicket);
    await page.reload();
    await page.getByTestId('op-spam').click();
    await expect(page.getByTestId('operation-dialog')).toHaveAttribute('data-kind', 'spam');
    // Blocking is a SEPARATE decision, unticked by default.
    await expect(page.getByTestId('spam-block')).not.toBeChecked();
    await page.getByTestId('operation-reason').fill('promo blast, not a customer');
    await page.getByTestId('operation-confirm').click();
    await expect(page.getByTestId('operation-dialog')).toHaveCount(0);
    await page.reload();
    await expect(page.getByTestId('ticket-state-banner')).toContainText('Marked as spam');
    await expect(page.getByTestId('op-spam')).toHaveCount(0); // ops hidden on spam
});

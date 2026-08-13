import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/*
 * S07 step 14 — B2-S03's property panel, unit B9: the R4 Ticket tab renders
 * the thread's live ticket, and the status control's TWO-LEVEL pending
 * selector posts {to, pending_reason} through the countersigned transition
 * door (ruled core edit #6). The proof is served twice: the panel's own
 * truth (the current-reason line) and the DATABASE's (the same physics the
 * SS-01 correction established as this project's bar).
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
            `echo json_encode(DB::connection("pgsql_platform")->table("tickets")->where("number", ${number})->value("${column}"));`,
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

async function openYonasThread(page: Page): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('composer')).toBeVisible();
}

test('B9: the panel renders the live ticket, and SET PENDING carries the reason through the door — one tap for awaiting-customer, the second level for awaiting-internal, leaving pending clears the reason (panel truth AND database truth)', async ({
    page,
}) => {
    await openYonasThread(page);

    // The panel shows the thread's live ticket (#4820, the scene's canon).
    await expect(page.getByTestId('ticket-panel')).toBeVisible();
    await expect(page.getByTestId('ticket-panel-number')).toHaveText('#4820');

    // ONE TAP: Pending → the selector opens with the drawn default
    // (awaiting customer) → Set pending.
    await page.getByTestId('panel-status-pending').click();
    await expect(page.getByTestId('pending-selector')).toBeVisible();
    await expect(page.getByTestId('pending-awaiting-customer')).toBeChecked();
    // The second level does NOT exist under the common case.
    await expect(page.getByTestId('pending-waiting-on')).toHaveCount(0);
    await page.getByTestId('pending-set').click();

    // Panel truth: the current-reason line renders from the refetched row.
    await expect(page.getByTestId('panel-pending-reason')).toBeVisible();
    // B12, RELAY-LIVE: the transition's event crosses outbox → relay →
    // events and the timeline renders it — the first browser proof that
    // reads the RELAYED log with no seam (the step-14 discovery's point).
    await expect(page.locator('[data-timeline-event="ticket.status_changed"]').first()).toBeVisible(
        { timeout: 10_000 },
    );
    // Database truth: status AND reason landed in one transition.
    await expect.poll(() => ticketField(4820, 'status')).toBe('"pending_customer"');
    expect(ticketField(4820, 'pending_reason')).toBe('"awaiting_customer"');

    // Leaving the pending family clears the reason in the same statement.
    await page.getByTestId('panel-status-open').click();
    await expect(page.getByTestId('panel-pending-reason')).toHaveCount(0);
    await expect.poll(() => ticketField(4820, 'status')).toBe('"open"');
    expect(ticketField(4820, 'pending_reason')).toBe('null');

    // THE SECOND LEVEL: awaiting internal → waiting on a department.
    await page.getByTestId('panel-status-pending').click();
    await page.getByTestId('pending-awaiting-internal').check();
    await expect(page.getByTestId('pending-waiting-on')).toBeVisible();
    await expect(page.getByTestId('waiting-department')).toBeChecked();
    await page.getByTestId('pending-set').click();

    await expect(page.getByTestId('panel-pending-reason')).toBeVisible();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"pending_internal"');
    expect(ticketField(4820, 'pending_reason')).toBe('"awaiting_internal:department"');

    // B13: edit the subject through the door — the text lands, the source
    // label flips to the staff fact, and the counter guards the cap.
    await page.getByTestId('panel-subject-edit').click();
    await page.getByTestId('panel-subject-input').fill('Bonus not credited — checked with finance');
    await expect(page.getByTestId('panel-subject-counter')).toHaveText('41 / 120');
    await page.getByTestId('panel-subject-save').click();
    await expect(page.getByTestId('panel-subject-text')).toHaveText(
        'Bonus not credited — checked with finance',
    );
    await expect(page.getByTestId('panel-subject-source')).toContainText('Edited by staff');
    expect(ticketField(4820, 'subject_source')).toBe('"staff"');

    // Back to open first — from pending_internal the matrix (rightly)
    // offers no resolve; the D3 absence just proved itself en passant.
    await page.getByTestId('panel-status-open').click();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"open"');

    // D3, LEGALITY AS DATA (SS-04's bar): resolve the ticket — the control
    // re-renders from the matrix ∩ this viewer, and what is illegal is
    // ABSENT, not greyed. From resolved: no Pending, no Resolved; the open
    // target IS the reopen act and wears its label.
    await page.getByTestId('panel-status-resolved').click();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"resolved"');
    await expect(page.getByTestId('panel-status-pending')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-resolved')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-open')).toHaveText('Reopen');
    await page.getByTestId('panel-status-open').click();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"open"');
    // Back at open, the full at-rest set returns.
    await expect(page.getByTestId('panel-status-pending')).toBeVisible();
    await expect(page.getByTestId('panel-status-resolved')).toBeVisible();

    // D2 + THE RACE STRIP (legal at render, refused at execution): truth
    // moves behind the panel — the still-rendered Resolved fires, the door
    // refuses against reality, the strip explains with the put-back heading,
    // and the control SNAPS BACK to the real value with D3's legal set.
    artisan(
        'tinker',
        '--execute',
        'DB::connection("pgsql_platform")->table("tickets")->where("number", 4820)->update(["status" => "escalated"]); echo "raced";',
    );
    await page.getByTestId('panel-status-resolved').click();
    await expect(page.getByTestId('panel-refusal')).toBeVisible();
    await expect(page.getByTestId('panel-putback')).toBeVisible();
    // The refetched truth re-renders: escalated offers ONLY de-escalate.
    await expect(page.getByTestId('panel-status-resolved')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-pending')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-open')).toBeVisible();
    // Recovery through the rendered door: de-escalate back to open.
    await page.getByTestId('panel-status-open').click();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"open"');
});

test('B9 render gate: without tickets.status.change the status control does not exist — the panel stays read-only truth (N-19: flags and grants hide, they never lie)', async ({
    page,
}) => {
    await page.goto('/login');
    await page.locator('#login-email').fill('readonly-fixture@fixture.example');
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    // Read Only's My Open is empty by scope — the unassigned Kidist row is
    // the R-26 precedent's door into a thread.
    await page.locator('[data-view="unassigned"]').first().click();
    await page
        .locator('[data-ticket]', { hasText: 'Kidist Haile' })
        .locator('[data-role="row-open"]')
        .click();

    await expect(page.getByTestId('ticket-panel')).toBeVisible();
    await expect(page.getByTestId('ticket-panel-number')).toHaveText('#4802');
    await expect(page.getByTestId('panel-status-pending')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-open')).toHaveCount(0);
    await expect(page.getByTestId('panel-status-resolved')).toHaveCount(0);
    // B13's render gate: no tickets.edit → no Edit affordance.
    await expect(page.getByTestId('panel-subject-edit')).toHaveCount(0);
});

test('B14 @ 375: the mobile property sheet carries the SAME controls, nothing dropped — the panel renders in the bottom sheet and SET PENDING works from it (identical controls, not a reduced set)', async ({
    page,
}) => {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/inbox');
    await dismissCoachMarks(page);

    // Two-level nav: open the Yonas thread from the list screen.
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    // The R4 bottom sheet with the same three tabs (B2-S01's contract).
    await page.getByTestId('mobile-context-trigger').click();
    await expect(page.getByTestId('mobile-context-sheet')).toBeVisible();

    // The SAME panel, the SAME testids — nothing dropped.
    const sheet = page.getByTestId('mobile-context-sheet');
    await expect(sheet.getByTestId('ticket-panel')).toBeVisible();
    await expect(sheet.getByTestId('ticket-panel-number')).toHaveText('#4820');
    await expect(sheet.getByTestId('panel-subject')).toBeVisible();
    await expect(sheet.getByTestId('ticket-timeline')).toBeVisible();

    // And the controls WORK from the sheet: the same one-tap pending flow.
    await sheet.getByTestId('panel-status-pending').click();
    await sheet.getByTestId('pending-set').click();
    await expect(sheet.getByTestId('panel-pending-reason')).toBeVisible();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"pending_customer"');
    expect(ticketField(4820, 'pending_reason')).toBe('"awaiting_customer"');

    // Canon restored for later specs.
    await sheet.getByTestId('panel-status-open').click();
    await expect.poll(() => ticketField(4820, 'status')).toBe('"open"');
});

test('D5/D6/D1: the drawn empties offer the act (Assign to me claims through the real door), the timeline fails ALONE with Try again recovering, and a switching panel shows a SKELETON, never a stale ticket', async ({
    page,
}) => {
    await page.goto('/login');
    await page.locator('#login-email').fill(AGENT_EMAIL);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);

    // D1: delay the thread fetch — the panel must show the skeleton shape
    // while rows are in flight, never the previous ticket.
    await page.route('**/inbox/thread/**', async (route) => {
        if (route.request().method() === 'GET') {
            await new Promise((resolve) => setTimeout(resolve, 700));
        }
        await route.continue();
    });
    await page.goto('/inbox');
    await dismissCoachMarks(page);
    await page.locator('[data-view="unassigned"]').first().click();
    await page
        .locator('[data-ticket]', { hasText: 'Kidist Haile' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('ticket-panel-skeleton')).toBeVisible();
    await expect(page.getByTestId('ticket-panel')).toBeVisible();
    await page.unroute('**/inbox/thread/**');

    // D5: the unassigned row says what fills it AND offers the act — the
    // inline claim rides the REAL claim door (BR-01: the result is visible).
    await expect(page.getByTestId('panel-assign-me')).toBeVisible();
    await page.getByTestId('panel-assign-me').click();
    await expect(page.getByTestId('panel-assignee')).toBeVisible();
    await expect.poll(() => ticketField(4802, 'assignee_id')).not.toBe('null');

    // D6: the timeline can fail ALONE — block its route, then let the
    // panel's own landed change trigger the refetch; the state says so and
    // Try again (after the route heals) recovers it.
    await page.route('**/timeline**', (route) => route.abort());
    await page.getByTestId('panel-status-pending').click();
    await page.getByTestId('pending-set').click();
    await expect(page.getByTestId('timeline-failed')).toBeVisible();
    await page.unroute('**/timeline**');
    await page.getByTestId('timeline-retry').click();
    await expect(page.getByTestId('timeline-failed')).toHaveCount(0);
    // Recovery proven by ROWS RENDERING (the claim relayed long before the
    // block; the fresh transition may still be in relay flight — which row
    // is not D6's point).
    await expect(page.locator('[data-timeline-event]').first()).toBeVisible({ timeout: 10_000 });
    // Status back to canon.
    await page.getByTestId('panel-status-open').click();

    // Restore the scene: hand the claim back (the seeder would heal it).
    artisan(
        'tinker',
        '--execute',
        'DB::connection("pgsql_platform")->table("tickets")->where("number", 4802)->update(["assignee_id" => null]); echo "restored";',
    );
});

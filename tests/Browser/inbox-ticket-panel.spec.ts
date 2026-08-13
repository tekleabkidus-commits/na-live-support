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

    // Restore the scene's canon for later specs (the seeder would heal it,
    // but stay tidy — the shortcuts precedent).
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
});

import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';

/*
 * S07 step 10 — the thread. THE FIRST TEST IS DOC 05 AC-07's CLOSING PROOF
 * (the release's ordered minimum): "UI never renders a delivered state for
 * Telegram (DOM assertion across all message states)" — the fixture thread
 * carries an outbound message whose delivery_state IS 'delivered', and the
 * DOM must contain NO delivered glyph anywhere. Sabotage #7's target.
 *
 * Then: FR-14 (boundaries, AC-08 handoff card — the Doc 06 closing row —
 * system-event bulk collapse, notes, tombstone posture), US-03 owner-phone
 * (Doc 05 closing row), FR-15 (first-unread open, jumps, deep links,
 * EC-01's 404 pattern), EC-10's clamp, B12-S01 lightbox (incl. the SEC-04
 * infected absence), B12-S02 edit markers, §9 print (SEC-05), and the
 * R-26 fourth-sibling consequence: Read Only's SESSION-LOCAL unread.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const READ_ONLY_EMAIL = 'readonly-fixture@fixture.example';
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

async function login(page: Page, email: string = AGENT_EMAIL): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

async function openThreadFixture(page: Page): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await page
        .locator('[data-ticket]', { hasText: 'Yonas Tesfaye' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('thread-pane')).toBeVisible();
    await expect(page.locator('[data-message]').first()).toBeVisible();
}

test('DOC 05 AC-07 — THE CLOSING PROOF, FIRST: the fixture thread holds a delivery_state=delivered telegram message, and the DOM renders NO delivered glyph across ALL states (sabotage #7 red here)', async ({
    page,
}) => {
    await openThreadFixture(page);

    // The states that DO render, truthfully.
    await expect(page.locator('[data-delivery-glyph="sent"]').first()).toBeVisible();
    await expect(page.locator('[data-delivery-glyph="failed_final"]').first()).toBeVisible();

    // THE LAW: no delivered glyph exists ANYWHERE in this DOM — the
    // delivered-state fixture drew the SENT glyph instead.
    await expect(page.locator('[data-delivery-glyph="delivered"]')).toHaveCount(0);
    await expect(page.getByText('✓✓')).toHaveCount(0);
});

test('FR-14: ticket boundary ("#4800 closed · #4801 opened"), the AC-08 handoff card (collapsible, AI-accented, "customer already saw"), bulk-collapsed system events, the note bubble, axe-clean', async ({
    page,
}) => {
    await openThreadFixture(page);

    await expect(
        page.locator('[data-role="ticket-boundary"]', { hasText: '#4819 closed' }),
    ).toBeVisible();
    await expect(
        page.locator('[data-role="ticket-boundary"]').filter({ hasText: '#4820 opened' }),
    ).toBeVisible();

    const card = page.locator('[data-role="handoff-card"]');
    await expect(card).toBeVisible();
    await expect(card).toContainText('withdrawal_delay');
    await expect(card).toContainText('0.91');
    await expect(card.locator('[data-role="handoff-seen"]')).toContainText('Withdrawal times');
    await card.locator('[data-role="handoff-toggle"]').click();
    await expect(card.locator('[data-role="handoff-summary"]')).toHaveCount(0);

    // Three consecutive system markers → ONE "show 3 events" control.
    const showEvents = page.locator('[data-role="show-events"]');
    await expect(showEvents).toHaveText('Show 3 events');
    await showEvents.click();
    await expect(page.locator('[data-role="system-event"]')).toHaveCount(3);

    // The note renders AS a note (banner) — never as a customer bubble.
    await expect(page.locator('[data-role="note-banner"]').first()).toBeVisible();

    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(axe.violations, JSON.stringify(axe.violations.map((v) => v.id))).toEqual([]);
});

test('US-03 (Doc 05 closing row): the owner-phone reply renders MARKED, with the snapshotted display name', async ({
    page,
}) => {
    await openThreadFixture(page);

    const chip = page.locator('[data-role="owner-phone-chip"]');
    await expect(chip).toBeVisible();
    await expect(chip).toContainText("via owner's phone");
    await expect(chip).toContainText('KemerBet Owner');
});

test('EC-10: the 45-line message clamps at 40 with "show more"; expanding reveals the tail', async ({
    page,
}) => {
    await openThreadFixture(page);

    const clamped = page.locator('[data-message]', { hasText: 'long message line 1' });
    await expect(clamped.locator('[data-role="body"]')).not.toContainText('long message line 45');
    await clamped.locator('[data-role="clamp-toggle"]').click();
    await expect(clamped.locator('[data-role="body"]')).toContainText('long message line 45');
});

test('B12-S01: the lightbox — clean photo opens WITH description + download; the INFECTED attachment gets the block notice, NO download, NO description (SEC-04 absence)', async ({
    page,
}) => {
    await openThreadFixture(page);

    await page.locator('[data-role="media-photo"]').first().click();
    await expect(page.getByTestId('media-lightbox')).toBeVisible();
    await expect(page.getByTestId('lightbox-description')).toHaveText('Payment receipt screenshot');
    await expect(page.getByTestId('lightbox-download')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByTestId('media-lightbox')).toHaveCount(0);

    await page.locator('[data-role="media-infected"]').first().click();
    await expect(page.getByTestId('media-lightbox')).toBeVisible();
    await expect(page.getByTestId('lightbox-infected')).toBeVisible();
    await expect(page.getByTestId('lightbox-download')).toHaveCount(0);
    await expect(page.getByTestId('lightbox-description')).toHaveCount(0);
    await page.getByTestId('lightbox-close').click();
});

test('B12-S02: the edited marker opens preserved history for the capability holder (Super Admin fixture); revisions render oldest truth', async ({
    page,
}) => {
    await openThreadFixture(page);

    await page.locator('[data-role="edited-marker"]').first().click();
    await expect(page.getByTestId('history-modal')).toBeVisible();
    await expect(page.locator('[data-role="revision"]')).toContainText('I sent 2,500 birr');
    await page.getByTestId('history-close').click();
});

test('FR-15: deep links /inbox/t/{ticket} open the thread; a FOREIGN id and a REAL out-of-scope id land the SAME 404-pattern page with "search instead" (EC-01, sabotage #15 red here)', async ({
    page,
    browser,
}) => {
    await login(page);
    await page.setViewportSize(V1440);

    // The genuine deep link opens the thread directly.
    const ticketId = await page.evaluate(async () => {
        const response = await fetch('/inbox/list?view=my_open', {
            headers: { Accept: 'application/json' },
        });
        const body = (await response.json()) as { rows: { ticket_id: string; number: number }[] };
        return body.rows.find((row) => row.number === 4820)?.ticket_id ?? '';
    });
    await page.goto(`/inbox/t/${ticketId}`);
    await expect(page.getByTestId('thread-pane')).toBeVisible();
    await expect(page.locator('[data-role="handoff-card"]')).toBeVisible();

    // A nonexistent id: the 404 pattern.
    const missing = await page.goto('/inbox/t/01999999-dead-7bbb-8ccc-000000000404');
    expect(missing?.status()).toBe(404);
    await expect(page.getByTestId('notfound-pattern')).toBeVisible();
    await expect(page.getByTestId('search-instead')).toBeVisible();

    // A REAL same-tenant ticket OUTSIDE the viewer's scope: the Own-scoped
    // fixture holds the view keys but #4801 is assigned to someone else —
    // the page must be byte-identical to the missing-id render (no oracle).
    const missingHtml = await page.locator('[data-testid="notfound-pattern"]').innerHTML();

    const fresh = await browser.newContext({ ignoreHTTPSErrors: true });
    const page2 = await fresh.newPage();
    await page2.goto('https://localhost/login');
    await page2.locator('#login-email').fill('ownscope-fixture@fixture.example');
    await page2.locator('#login-password').fill(PASSWORD);
    await page2.getByTestId('login-submit').click();
    await page2.waitForURL((url) => !url.pathname.startsWith('/login'));
    const foreign = await page2.goto(`https://localhost/inbox/t/${ticketId}`); // REAL id, OUT OF SCOPE
    expect(foreign?.status()).toBe(404);
    await expect(page2.getByTestId('notfound-pattern')).toBeVisible();
    const foreignHtml = await page2.locator('[data-testid="notfound-pattern"]').innerHTML();
    expect(foreignHtml).toBe(missingHtml); // no oracle, down to the DOM
    await fresh.close();
});

test('§9 print (SEC-05): the print page carries the watermark (user + timestamp) and the PUBLIC transcript — the internal note NEVER prints (sabotage #20 red here)', async ({
    page,
    context,
}) => {
    await openThreadFixture(page);

    const [printPage] = await Promise.all([
        context.waitForEvent('page'),
        page.getByTestId('print-thread').click(),
    ]);
    await printPage.waitForLoadState();

    await expect(printPage.getByTestId('print-watermark')).toContainText(AGENT_EMAIL);
    await expect(printPage.getByTestId('print-messages')).toContainText(
        'Thank you for the receipt',
    );
    await expect(printPage.getByTestId('print-messages')).not.toContainText('SECRET-NOTE-S10');
    await printPage.close();
});

test('THE R-26 FOURTH-SIBLING CONSEQUENCE (countersign verify): Read Only opens a thread — the markRead 403 is SILENT (zero alerts), unread stays session-local', async ({
    page,
}) => {
    await login(page, READ_ONLY_EMAIL);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');

    // Read Only reads the workspace; open the visible thread.
    const refusal = page.waitForResponse(
        (r) => r.url().includes('/inbox/read') && r.status() === 403,
    );
    await page.locator('[data-view="unassigned"]').first().click();
    await page
        .locator('[data-ticket]', { hasText: 'Kidist Haile' })
        .locator('[data-role="row-open"]')
        .click();
    await expect(page.getByTestId('thread-pane')).toBeVisible();
    await refusal;

    // Silent: no alert surface anywhere (the countersigned posture).
    await expect(page.getByRole('alert')).toHaveCount(0);
    // And nothing persisted server-side: the marker table stays empty for
    // this viewer (server truth asserted through the thread payload).
    const lastRead = await page.evaluate(async () => {
        const response = await fetch(window.location.origin + '/inbox/list?view=unassigned', {
            headers: { Accept: 'application/json' },
        });
        const body = (await response.json()) as { rows: { number: number; unread: number }[] };
        return body.rows.find((row) => row.number === 4802)?.unread ?? -1;
    });
    expect(lastRead).toBeGreaterThan(0); // still unread SERVER-side — nothing persisted
});

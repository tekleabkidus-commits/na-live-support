import { execFileSync } from 'node:child_process';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page, test } from '@playwright/test';
import { dismissCoachMarks } from './support/coach';

/**
 * Doc 11 FR-17 / SEC-02 — the routing-record expandable (S11 step 11b; STOP-G countersigned):
 * B5-S02 §D C09–C11 at FULL fidelity. C09: a `ticket.routed` timeline row opens the card —
 * title, numbered plain-language steps, monospace facts lines, Copy as text. C11: the SAME
 * record for a viewer without reports.agents.individual — the "Names hidden" chip, the
 * elision NOTE, and VISIBLE ABSENCE (no agent name anywhere in the card), never a
 * redacted-looking blank. Light AND dark, axe-clean (0l law: the fixed-id queue scene).
 */

const AGENT_EMAIL = 'agent@fixture.example';
const READ_ONLY_EMAIL = 'readonly-fixture@fixture.example';
const PASSWORD = 'browser-fixture-password';
const V1440 = { width: 1440, height: 900 };

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

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    artisan('db:seed', '--class=Database\\Seeders\\InboxBrowserSeeder', '--force');
});

test.beforeEach(() => {
    artisan('auth:session-inspect', 'clear-throttle');
});

async function openTicketOne(page: Page, email: string = AGENT_EMAIL): Promise<void> {
    await page.goto('/login');
    await page.locator('#login-email').fill(email);
    await page.locator('#login-password').fill(PASSWORD);
    await page.getByTestId('login-submit').click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await page.setViewportSize(V1440);
    await page.goto('/inbox/t/00000000-0000-7000-8000-0000000d1101');
    await dismissCoachMarks(page);
}

test('C09: the routed timeline row expands into the record card — title, numbered steps with plain sentences and monospace facts, Copy as text', async ({
    page,
}) => {
    await openTicketOne(page);

    const toggle = page.locator('[data-record-toggle]').first();
    await expect(toggle).toBeVisible();
    await toggle.click();

    const card = page.getByTestId('routing-record-card');
    await expect(card).toBeVisible();
    await expect(page.getByTestId('record-title')).toContainText(/Why this ticket/);
    await expect(card.locator('[data-record-step]').first()).toBeVisible();
    await expect(
        card.locator('[data-record-step="wait"], [data-record-step="assign"]').first(),
    ).toBeVisible();
    await expect(page.getByTestId('record-copy')).toBeVisible();
    // Collapse again — the card is an expandable, not a page (C09's collapsed system event).
    await toggle.click();
    await expect(card).toHaveCount(0);
});

test('C11 + §13: the elided card for a viewer without individual-metrics rights (the READ ONLY fixture — every non-high .view key, so tickets.view without reports.agents.individual) shows the lock chip and the note, with NO raw uuid in the card body; light and dark are both axe-clean', async ({
    page,
    browser,
}) => {
    await openTicketOne(page, READ_ONLY_EMAIL);
    await page.locator('[data-record-toggle]').first().click();
    const card = page.getByTestId('routing-record-card');
    await expect(card).toBeVisible();
    // The fixture agent holds tickets.view only — the C11 state is the one drawn for them.
    await expect(page.getByTestId('record-names-hidden')).toBeVisible();
    await expect(page.getByTestId('record-elision-note')).toBeVisible();
    // VISIBLE ABSENCE of AGENT facts: no agent-bearing key renders at all (queue/department ids
    // in the mono lines are routing facts SEC-02 keeps auditable — the C11 drawing's own point).
    const body = (await card.innerText()).toLowerCase();
    for (const agentKey of [
        'candidate=',
        'pointer=',
        'next=',
        'assigned_to=',
        'by=',
        'candidates=',
    ]) {
        expect(body.includes(agentKey)).toBeFalsy();
    }

    const lightScan = await new AxeBuilder({ page })
        .include('[data-testid="routing-record-card"]')
        .analyze();
    expect(lightScan.violations).toEqual([]);

    const dark = await browser.newContext({ colorScheme: 'dark' });
    const darkPage = await dark.newPage();
    await openTicketOne(darkPage, READ_ONLY_EMAIL);
    await darkPage.locator('[data-record-toggle]').first().click();
    await expect(darkPage.getByTestId('routing-record-card')).toBeVisible();
    const darkScan = await new AxeBuilder({ page: darkPage })
        .include('[data-testid="routing-record-card"]')
        .analyze();
    expect(darkScan.violations).toEqual([]);
    await dark.close();
});

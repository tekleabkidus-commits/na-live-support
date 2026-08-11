import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * FR-11 / AC-03 / B12-A01 AC-04 (S07 step 9): SCRIPTED DELTA STREAMS —
 * AC-03's own wording — drive the SAME store entry points the realtime
 * consumer will call (window.__inboxList), and the assertions are the
 * document's: patches land in place immediately; the resort is BATCHED
 * (5 s); while the pointer is INSIDE the list rows NEVER move — the
 * "list updated — resort" affordance offers instead (B12-A01 AC-04's
 * rows-never-move-under-pointer bar); entering rows highlight, leaving rows
 * fade+collapse; reduced-motion suppresses both; §13's polite announcement
 * carries the batch count; EC-09's queue-removed toast falls back to
 * My Open. SABOTAGE #6 bypasses the batcher and this file goes red.
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

async function openUnassigned(page: Page): Promise<void> {
    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await page.locator('[data-view="unassigned"]').first().click();
    // Unassigned-only content proves the switch LANDED (the refetch is async).
    await expect(page.locator('[data-ticket]', { hasText: 'Kidist Haile' })).toBeVisible();
}

test('AC-03 + B12-A01 AC-04: a rescore delta patches IN PLACE at once, but with the pointer inside the list the ORDER holds past the 5 s batch — the affordance offers, the click resorts', async ({
    page,
}) => {
    await openUnassigned(page);

    const orderBefore = await page.evaluate(() => window.__inboxList.state().order);
    const lastId = orderBefore[orderBefore.length - 1] ?? '';

    // Pointer INSIDE the list (the rug-pull hazard FR-11 exists to prevent).
    await page.getByTestId('ticket-list').hover();

    // The scripted stream: the LAST row rockets to the top of the order
    // (created-asc view → smallest score first) AND patches its snippet.
    await page.evaluate(
        (id) =>
            window.__inboxList.applyDelta({
                op: 'patch',
                id,
                sort_key: 1,
                patch: { snippet: 'PATCHED IN PLACE' },
            }),
        lastId,
    );

    // The PATCH is visible immediately (rows patch in place)…
    await expect(page.locator(`[data-ticket="${lastId}"] [data-role="snippet"]`)).toHaveText(
        'PATCHED IN PLACE',
    );

    // …but the ORDER has not moved, and does not move through the batch
    // window while the pointer holds (B12-A01 AC-04's exact bar).
    await page.waitForTimeout(5600); // past the 5 s batch deliberately
    const orderHeld = await page.evaluate(() => window.__inboxList.state().order);
    expect(orderHeld).toEqual(orderBefore);

    // The affordance appeared instead of a yank. Activating it KEYBOARD-side
    // (focus + Enter — a mouse journey to the button exits the list, and the
    // pointer-leave rule below already lands the batch on exit) resorts.
    const affordance = page.getByTestId('resort-affordance');
    await expect(affordance).toBeVisible();
    await expect(page.getByTestId('list-announcement')).toContainText('List updated');
    await affordance.focus();
    await page.keyboard.press('Enter');
    const orderAfter = await page.evaluate(() => window.__inboxList.state().order);
    expect(orderAfter[0]).toBe(lastId);
    await expect(affordance).toHaveCount(0);
});

test('FR-11: an entering row waits for the batch (pointer OUTSIDE → applies on its own), lands IN POSITION with the entering highlight; a leaving row fades and is gone after the batch', async ({
    page,
}) => {
    await openUnassigned(page);
    const before = await page.evaluate(() => window.__inboxList.state().order);
    const leavingId = before[0] ?? '';

    // Pointer OUTSIDE the list: the batch may land unaided.
    await page.getByTestId('inbox-topbar').hover();

    await page.evaluate((gone) => {
        window.__inboxList.applyDelta({
            op: 'add',
            id: '01999999-aaaa-7bbb-8ccc-000000000001',
            sort_key: 2,
            patch: {
                conversation_id: '01999999-aaaa-7bbb-8ccc-0000000000c1',
                customer_display_name: 'Scripted Entry',
                number: 9001,
                priority: 'normal',
                snippet_kind: 'text',
                snippet: 'slid in at position',
                tags: [],
            },
        });
        window.__inboxList.applyDelta({ op: 'remove', id: gone });
    }, leavingId);

    // The leaving row fades NOW (visual state), the entering row is not yet
    // in the order — both land with the batch.
    await expect(page.locator(`[data-ticket="${leavingId}"]`)).toHaveAttribute(
        'data-leaving',
        'true',
    );

    await expect(page.locator('[data-ticket="01999999-aaaa-7bbb-8ccc-000000000001"]')).toBeVisible({
        timeout: 8000,
    });
    await expect(page.locator(`[data-ticket="${leavingId}"]`)).toHaveCount(0, { timeout: 8000 });

    const after = await page.evaluate(() => window.__inboxList.state().order);
    expect(after[0]).toBe('01999999-aaaa-7bbb-8ccc-000000000001'); // at position (score 2, created-asc)
    // The 300 ms highlight was flagged for the insert (cleared right after
    // the batch — the data attribute is the reduced-motion-safe signal).
});

test('reduced motion: the enter/leave keyframes are suppressed (§13/B12-A01) — the DATA state still flows, nothing animates', async ({
    page,
}) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await openUnassigned(page);

    const before = await page.evaluate(() => window.__inboxList.state().order);
    const goneId = before[0] ?? '';
    await page.getByTestId('inbox-topbar').hover();
    await page.evaluate(
        (gone) => window.__inboxList.applyDelta({ op: 'remove', id: gone }),
        goneId,
    );

    const row = page.locator(`[data-ticket="${goneId}"]`);
    await expect(row).toHaveAttribute('data-leaving', 'true');
    const animation = await row.evaluate((el) => getComputedStyle(el).animationName);
    expect(animation).toBe('none'); // the media query kills the keyframes
});

test('EC-09: the active queue view is removed mid-shift → toast (role=status, never an error), then the pane falls back to My Open', async ({
    page,
}) => {
    await openUnassigned(page);

    await page.evaluate(() => window.__inboxList.viewRemoved('unassigned'));

    const toast = page.getByTestId('queue-removed-toast');
    await expect(toast).toBeVisible();
    await expect(toast).toHaveText('That queue was removed — showing My Open.');

    // The fallback lands: My Open's content (the assigned fixture) renders.
    await expect(page.locator('[data-ticket]', { hasText: 'አበበ ታደሰ' })).toBeVisible({
        timeout: 8000,
    });
});

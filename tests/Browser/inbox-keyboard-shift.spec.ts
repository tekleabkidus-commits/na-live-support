import { execFileSync } from 'node:child_process';
import { expect, Page, test } from '@playwright/test';

/*
 * S07 step 13 — US-01/SS-01: the keyboard-only shift. Sixty seeded
 * unassigned tickets worked claim→answer→resolve END TO END with ZERO
 * pointer interactions inside the core loop — after the login form (outside
 * the core loop US-01 names), not one mouse API is invoked; every action is
 * keyboard.press/type. "Time-motion review shows zero forced mouse
 * interactions in the core loop" is proven by construction: this file
 * contains no click/hover/tap calls past login.
 *
 * The loop per ticket: J (activate the top row) → O (open — Enter's twin
 * on the map; after the first ticket, focus rests on a composer BUTTON
 * where Enter rightly belongs to the button, §13 — O is the open binding
 * for exactly this roaming posture) → I (claim, the real door) → R (focus
 * reply) → type → ⌘Enter (send) → Shift+Tab (leave the input — E in a
 * typing surface is rightly guarded) → E (resolve). The resolved row
 * leaves the open-family views; the loop eats the whole pool.
 */

const PASSWORD = 'browser-fixture-password';
const AGENT_EMAIL = 'agent@fixture.example';
const V1440 = { width: 1440, height: 900 };
const SHIFT_SIZE = 60;

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
    artisan('db:seed', '--class=Database\\Seeders\\InboxShiftSeeder', '--force');
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

test('SS-01 THE SIXTY-TICKET SHIFT, KEYBOARD ONLY: claim → answer → resolve on every seeded shift ticket, zero pointer interactions in the core loop', async ({
    page,
}) => {
    test.setTimeout(900_000); // sixty real round-trips take minutes — deliberately

    await login(page);
    await page.setViewportSize(V1440);
    await page.goto('/inbox');
    await expect(page.locator('[data-ticket]').first()).toBeVisible();
    if (
        await page
            .getByTestId('coach-card')
            .isVisible()
            .catch(() => false)
    ) {
        // The one non-loop interaction: first-run dismissal, before the shift.
        await page.getByTestId('coach-skip').click();
    }

    // ══ THE CORE LOOP: keyboard from here on — no pointer APIs below. ══
    await page.keyboard.press('g');
    await page.keyboard.press('u');
    await expect
        .poll(async () => await page.evaluate(() => window.__inboxList.state().viewKey))
        .toBe('unassigned');

    // Filter the pool to the seeded shift tickets: the spec works whatever
    // ELSE unassigned holds by simply processing rows until the shift set
    // (tracked by subject in the row data) is exhausted.
    const isShiftRow = (id: string | null): Promise<boolean> =>
        page.evaluate(
            (rowId) =>
                String((window.__inboxList.row(rowId as string) ?? {}).subject ?? '').startsWith(
                    'Shift question',
                ),
            id,
        );

    let worked = 0;
    let guard = 0;
    let consecutiveSkips = 0;
    const workedIds: string[] = [];
    // Each worked ticket restarts J at the top, re-walking Unassigned's
    // non-shift residents — the guard budgets those walks, and a full page
    // of consecutive skips means no shift row remains.
    while (worked < SHIFT_SIZE && guard < 3_000 && consecutiveSkips <= 60) {
        guard += 1;

        // J — activate the next row down (top after each worked ticket).
        await page.keyboard.press('j');
        const active = await page.evaluate(() => window.__inboxList.state().activeId);
        if (active === null) {
            break; // the view is empty — the pool is done
        }
        if (!(await isShiftRow(active))) {
            consecutiveSkips += 1;
            continue;
        }
        consecutiveSkips = 0;

        // O — open (see the header note: Enter would belong to the focused
        // composer button from ticket 2 onward and the thread would never
        // switch — the first fresh battery proved exactly that, sixty
        // replies into one conversation). The composer must then show THIS
        // ticket before the claim targets it.
        await page.keyboard.press('o');
        await expect(page.getByTestId('composer')).toBeVisible();
        // The thread must actually SHOW this ticket before the claim/reply
        // target it — the print link carries the conversation id.
        const conversationId = await page.evaluate(
            (id) => String((window.__inboxList.row(id as string) ?? {}).conversation_id ?? ''),
            active,
        );
        await expect(page.getByTestId('print-thread')).toHaveAttribute(
            'href',
            new RegExp(conversationId),
        );
        await page.keyboard.press('i');

        // R — focus the reply box; type; ⌘Enter — send.
        await page.keyboard.press('r');
        await expect(page.getByTestId('composer-input')).toBeFocused();
        await page.keyboard.type(`On it — answer ${worked + 1}`);
        await page.keyboard.press('ControlOrMeta+Enter');
        await expect
            .poll(
                async () =>
                    await page.evaluate(
                        () =>
                            (
                                document.querySelector(
                                    '[data-testid="composer-input"]',
                                ) as HTMLTextAreaElement
                            )?.value ?? '',
                    ),
                {
                    timeout: 15_000,
                },
            )
            .toBe(''); // the send cleared the box — landed

        // Shift+Tab — leave the input; E — resolve through the door.
        await page.keyboard.press('Shift+Tab');
        await page.keyboard.press('e');
        await expect
            .poll(
                async () =>
                    await page.evaluate(
                        (id) => window.__inboxList.state().order.includes(id as string),
                        active,
                    ),
                { timeout: 15_000 },
            )
            .toBe(false); // resolved → left the open-family view

        worked += 1;
        workedIds.push(active);
    }

    expect(worked).toBe(SHIFT_SIZE);

    // The shift is done: no shift ticket remains in Unassigned.
    const leftovers = await page.evaluate(
        () =>
            window.__inboxList
                .state()
                .order.filter((id) =>
                    String((window.__inboxList.row(id) ?? {}).subject ?? '').startsWith(
                        'Shift question',
                    ),
                ).length,
    );
    expect(leftovers).toBe(0);

    // THE RESOLVE LEG, NON-VACUOUS (advisor ruling 2026-08-13): the claim
    // already removed each row from Unassigned, so the in-loop "row left the
    // view" poll proved nothing about resolution — the first fresh battery
    // passed it with all sixty tickets claimed and NOT resolved. The proof is
    // now the server's own truth: every worked ticket's status is resolved AT
    // THE DATABASE, the same physics that exposed the defect.
    const resolvedRaw = execFileSync(
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
            `echo DB::connection("pgsql_platform")->table("tickets")->whereIn("id", json_decode('${JSON.stringify(
                workedIds,
            )}'))->where("status", "resolved")->count();`,
        ],
        { encoding: 'utf8' },
    );
    const resolvedCount = Number(resolvedRaw.trim().match(/\d+$/)?.[0] ?? '-1');
    expect(
        resolvedCount,
        `worked ${worked} tickets but the database holds ${resolvedCount} resolved — the resolve leg did not land`,
    ).toBe(SHIFT_SIZE);
});

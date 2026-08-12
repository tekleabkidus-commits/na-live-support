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
 * The loop per ticket: J (activate the top row) → Enter (open) → I (claim,
 * the real door) → R (focus reply) → type → ⌘Enter (send) → Shift+Tab
 * (leave the input — E in a typing surface is rightly guarded) → E
 * (resolve). The resolved row leaves the open-family views; the loop eats
 * the whole pool.
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
    // Each worked ticket restarts J at the top, re-walking Unassigned's
    // non-shift residents — the guard budgets those walks, and a full page
    // of consecutive skips means no shift row remains.
    while (worked < SHIFT_SIZE && guard < 1_500 && consecutiveSkips <= 60) {
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

        // Enter — open. I — claim through the real door.
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('composer')).toBeVisible();
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
});

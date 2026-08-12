import { type Page } from '@playwright/test';

/**
 * §8 first-run guard (S07 step-13 completion, advisor ruling 2026-08-12):
 * on a fresh profile the four-step coach marks open over the workspace as a
 * fixed inset-0 backdrop that intercepts every pointer action. The
 * consolidated fresh-DB battery proved the specs without this guard had
 * only ever passed on carried state — a long-lived dev DB whose fixture
 * users had already dismissed the marks — because InboxBrowserSeeder wipes
 * inbox_user_prefs on every run, so each spec file's beforeAll brings the
 * marks back. This is the same dismissal idiom inbox-keyboard-shift,
 * inbox-mobile-interactions and inbox-shortcuts already carried, shared so
 * every workspace entry can run it. Setup-only: it acts before a test's
 * assertions and changes none of them.
 */
export async function dismissCoachMarks(page: Page): Promise<void> {
    // The workspace root mounts before the coach decision is observable;
    // coachOpen is set synchronously in onMounted from Inertia-hydrated
    // prefs, so once the root is attached the card is either present or
    // never coming.
    await page.getByTestId('inbox-workspace').waitFor();
    if (
        await page
            .getByTestId('coach-card')
            .isVisible()
            .catch(() => false)
    ) {
        // Armed BEFORE the click: dismissal persists through the store's
        // serialized write chain, and a test that arms its own prefs-PATCH
        // waiter later must never catch THIS write instead of its own.
        // Any status matches deliberately — Read Only's designed 403 is a
        // settled write too (waitForResponse observes, never consumes, so a
        // test watching for that same 403 still sees it).
        const persistSettled = page
            .waitForResponse(
                (r) => r.url().includes('/inbox/prefs') && r.request().method() === 'PATCH',
                { timeout: 10_000 },
            )
            .catch(() => null);
        await page.getByTestId('coach-skip').click();
        await page.getByTestId('coach-card').waitFor({ state: 'hidden' });
        await persistSettled;
    }
}

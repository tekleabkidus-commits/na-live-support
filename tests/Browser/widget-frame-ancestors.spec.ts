// Doc 13 AC-03 (the CSP layer) — THE OWED FAIL-FIRST CONTROL of P-FRAME-ANCESTORS
// (countersign 2026-09-04): after the packet, the panel document MOUNTS inside
// the HARNESS customer origin's iframe, and a page on the PLATFORM origin — not
// in the widget's allowlist — is REFUSED by the browser (a chrome-error frame,
// no #nlw-root). The FIRST browser leg of the next charging window.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey, PLATFORM } from './support/harness';

test('the panel frames inside the allowed customer origin and is refused from the platform origin', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('#nlw-root')).toHaveCount(1);

    // The negative half: a document on the platform origin (the app's own root) framing the panel.
    await page.goto(`${PLATFORM}/`);
    await page.evaluate(
        (src) => {
            const f = document.createElement('iframe');
            f.id = 'refused';
            f.src = src;
            document.body.appendChild(f);
        },
        `${PLATFORM}/w/panel/${encodeURIComponent(key)}`,
    );
    await page.waitForTimeout(1500);
    const refused = page.frames().find((f) => f.url().includes('/w/panel/'));
    // A CSP-refused navigation leaves no accessible document with the mount point.
    const mounted = refused
        ? await refused
              .locator('#nlw-root')
              .count()
              .catch(() => 0)
        : 0;
    expect(mounted).toBe(0);
});

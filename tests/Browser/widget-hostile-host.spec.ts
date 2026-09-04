// Doc 13 SS-03 / T-09 / AC-02 — THE HOSTILE HOST: `* { all: unset !important }`,
// an aggressive CSP, prototype tampering. The widget renders at its own size and
// the only mutations the host observes are the two iframe insertions. Runs at
// EVERY step-commit from step 7 on (wall power).
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

test('SS-03: on the hostile page the two iframes mount at their fixed size; the host observes exactly two added nodes; the tampered page keeps its own input value', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    await page.goto(`${CUSTOMER}/hostile.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-launcher')).toHaveCount(1);
    const box = await page.locator('iframe#nlw-launcher').boundingBox();
    expect(box?.width).toBe(64);
    expect(box?.height).toBe(64);
    const mutations = await page.evaluate(() =>
        (window as unknown as { __harnessMutations: string[] }).__harnessMutations.filter((m) =>
            m.startsWith('IFRAME'),
        ),
    );
    expect(mutations.sort()).toEqual(['IFRAME#nlw-launcher', 'IFRAME#nlw-panel']);
    expect(await page.locator('#host-input').inputValue()).toBe('FIXTURE hostile host value');
    expect(errors.filter((e) => e.includes('nlw') || e.includes('PlatformChat'))).toEqual([]);
});

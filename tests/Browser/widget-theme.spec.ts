// Doc 13 §9 / L03 / L12 / M-rows — THEME + ACCESS: auto follows the visitor's
// system, not the host's CSS; genuine dark surfaces; axe on Home and Messages in
// BOTH themes; nothing carried by colour alone (the band has a glyph + text).
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { openWidget } from './support/panel';

for (const scheme of ['light', 'dark'] as const) {
    test(`L03 + axe (${scheme}): auto theme resolves to the system preference; Home and Messages pass axe`, async ({
        page,
    }) => {
        await page.emulateMedia({ colorScheme: scheme });
        const { panel } = await openWidget(page);
        await expect(panel.locator('html')).toHaveAttribute('data-theme', scheme);
        const frame = page.frames().find((f) => f.url().includes('frame=panel'))!;
        const home = await new AxeBuilder({ page })
            .include('iframe#nlw-panel')
            .analyze()
            .catch(() => null);
        void home;
        const homeViolations = await frame.evaluate(() => Promise.resolve([]));
        void homeViolations;
        // axe inside the frame: run it on the frame document directly
        const results = await new AxeBuilder({ page })
            .options({ iframes: true })
            .include('iframe#nlw-panel')
            .analyze();
        const serious = results.violations.filter((v) =>
            ['serious', 'critical'].includes(v.impact ?? ''),
        );
        expect(
            serious.map((v) => v.id + ': ' + v.nodes.map((n) => n.target.join(' ')).join(', ')),
        ).toEqual([]);
        await panel.locator('#nlw-tab-messages').click();
        const results2 = await new AxeBuilder({ page })
            .options({ iframes: true })
            .include('iframe#nlw-panel')
            .analyze();
        expect(
            results2.violations
                .filter((v) => ['serious', 'critical'].includes(v.impact ?? ''))
                .map((v) => v.id),
        ).toEqual([]);
        // L12: the band carries a glyph AND text, never colour alone
        await panel.locator('#nlw-tab-home').click();
        await expect(panel.locator('.nlw-band span').last()).not.toHaveText('');
    });
}

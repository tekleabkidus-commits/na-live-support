// Doc 13 FR-07 / BR-05 / F06 / F13 — SIGNED IDENTITY: the host page calls
// identify({payload, sig}); a valid signature shows "Signed in as …" (continuity
// only — the word "verified" appears nowhere); an invalid one changes nothing
// and never errors the visitor.
import { expect, test } from '@playwright/test';
import { harness, openWidget } from './support/panel';

test('F06: a valid signature → the badge; an invalid one → nothing, no error; "verified" appears nowhere', async ({
    page,
}) => {
    const signed = JSON.parse(
        /SIGNED=(.*)/.exec(harness('identity-sign', '--key=ab_4417', '--text=አበበ ታደሰ'))![1]!,
    ) as { payload: Record<string, unknown>; sig: string };
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const { panel } = await openWidget(page);
    await page.evaluate(
        (s) =>
            (
                window as unknown as { PlatformChat: { identify(x: unknown): void } }
            ).PlatformChat.identify({ payload: s.payload, sig: 'deadbeef' }),
        signed,
    );
    await page.waitForTimeout(1500);
    expect(await panel.locator('[data-role=identity]').count()).toBe(0); // an invalid signature is ignored (a Health counter, never an error)
    await page.evaluate(
        (s) =>
            (
                window as unknown as { PlatformChat: { identify(x: unknown): void } }
            ).PlatformChat.identify(s),
        signed,
    );
    await expect(panel.locator('[data-role=identity]')).toContainText('Signed in as አበበ ታደሰ', {
        timeout: 10000,
    });
    await expect(panel.locator('[data-role=identity]')).toContainText(
        'from your account on the site',
    );
    const text = ((await panel.locator('[role=dialog]').textContent()) ?? '').toLowerCase();
    expect(text).not.toContain('verified');
    expect(errors).toEqual([]);
});

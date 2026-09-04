import { execFileSync } from 'node:child_process';
import type { FrameLocator, Page } from '@playwright/test';
import { expect } from '@playwright/test';
import { composeFiles, CUSTOMER, harnessWidgetKey } from './harness';

/** Open the widget on the clean host page and return the panel frame + the key. */
export async function openWidget(page: Page): Promise<{ panel: FrameLocator; key: string }> {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    await page.evaluate(() =>
        (window as unknown as { PlatformChat: { open(): void } }).PlatformChat.open(),
    );
    const panel = page.frameLocator('iframe#nlw-panel');
    await expect(panel.locator('[role=dialog]')).toHaveCount(1);
    await expect(panel.locator('.nlw-greeting')).toBeVisible();
    return { panel, key };
}

/** The staff side, through the real doors (widget:harness). */
export function harness(action: string, ...args: string[]): string {
    try {
        return execFileSync(
            'docker',
            [
                'compose',
                ...composeFiles(),
                'exec',
                '-T',
                'app',
                'php',
                'artisan',
                'widget:harness',
                action,
                '--latest',
                ...args,
            ],
            { encoding: 'utf8', stdio: 'pipe' },
        );
    } catch (e) {
        const err = e as { stdout?: string; stderr?: string; message: string };
        throw new Error(
            `widget:harness ${action} failed: ${String(err.stderr ?? '')} ${String(err.stdout ?? '')} ${err.message}`
                .replace(/\s+/g, ' ')
                .slice(0, 1500),
        );
    }
}

export async function sendFromPanel(panel: FrameLocator, text: string): Promise<void> {
    await panel.locator('#nlw-tab-messages').click();
    await panel.locator('textarea').fill(text.startsWith('[FIXTURE]') ? text : '[FIXTURE] ' + text); // FixturesOnlyEgress: synthetic text carries the tag
    await panel.locator('textarea').press('Enter');
    await expect(panel.locator('[data-message][data-author=customer]').last()).toHaveAttribute(
        'data-delivery',
        'delivered',
        { timeout: 15000 },
    );
}

export function panelHooks(page: Page) {
    const frame = () => page.frames().find((f) => f.url().includes('frame=panel'));
    return {
        socketUp: () =>
            frame()!.evaluate(() =>
                (
                    window as unknown as { __nlwPanel: { socketUp(): boolean } }
                ).__nlwPanel.socketUp(),
            ),
        isLeader: () =>
            frame()!.evaluate(() =>
                (
                    window as unknown as { __nlwPanel: { isLeader(): boolean } }
                ).__nlwPanel.isLeader(),
            ),
        dropSocket: () =>
            frame()!.evaluate(() =>
                (
                    window as unknown as { __nlwPanel: { dropSocket(): void } }
                ).__nlwPanel.dropSocket(),
            ),
        polling: () =>
            frame()!.evaluate(() =>
                (window as unknown as { __nlwPanel: { polling(): boolean } }).__nlwPanel.polling(),
            ),
    };
}

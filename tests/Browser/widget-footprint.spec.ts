// Doc 13 AC-02 / SEC-01 / FR-24 — THE FOOTPRINT AUDIT: the loader's entire
// footprint on the host page is enumerated and asserted EXACTLY — globals,
// listeners, DOM nodes — against the inventory the loader declares itself.
// Sabotage (c) (one document.querySelector outside the iframes) goes red here.
import { expect, test } from '@playwright/test';
import { CUSTOMER, harnessWidgetKey } from './support/harness';

test('AC-02: globals = {PlatformChat, __nlwLoaded}; listeners = {focus, message, popstate, resize}; nodes = the two iframes — nothing else; the page input is never read', async ({
    page,
}) => {
    const key = harnessWidgetKey();
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    await expect(page.locator('iframe#nlw-panel')).toHaveCount(1);
    const audit = await page.evaluate(() => {
        const w = window as unknown as {
            __harnessBaselineGlobals: string[];
            __harnessListeners: string[];
            PlatformChat: {
                __nlw: { inventory(): { globals: string[]; nodes: string[]; listeners: string[] } };
            };
        };
        const globalsNow = Object.getOwnPropertyNames(window);
        // Numeric names are the DOM's own frame indexes (window[0], window[1]) that exist for ANY
        // iframe on ANY page — the browser's, never the loader's; the audit is the loader's names.
        const added = globalsNow
            .filter((g) => !w.__harnessBaselineGlobals.includes(g) && !/^\d+$/.test(g))
            .sort();
        const nodes = Array.from(document.querySelectorAll('body > *'))
            .filter((n) => !['H1', 'P', 'FORM', 'SCRIPT'].includes(n.tagName))
            .map((n) => n.tagName.toLowerCase() + '#' + n.id)
            .sort();
        return {
            added,
            listeners: [...w.__harnessListeners].sort(),
            nodes,
            declared: w.PlatformChat.__nlw.inventory(),
            // FR-24 structural: every host DOM/cookie/storage READ the page counted; the audit's own
            // querySelectorAll above is the one allowed call.
            reads: (
                window as unknown as { __harnessHostReads: string[] }
            ).__harnessHostReads.filter((r) => r !== 'querySelectorAll'),
        };
    });
    expect(audit.added).toEqual(['PlatformChat', '__nlwLoaded']);
    expect(audit.listeners).toEqual(['focus', 'message', 'popstate', 'resize']); // resize: the host viewport for the sheet decision (B10), declared in the inventory
    expect(audit.nodes).toEqual(['iframe#nlw-launcher', 'iframe#nlw-panel']);
    expect(audit.declared.globals.sort()).toEqual(audit.added);
    expect(audit.declared.nodes.sort()).toEqual(['nlw-launcher', 'nlw-panel']);
    expect(audit.reads).toEqual([]); // zero host reads — sabotage (c) plants one and lands here
});

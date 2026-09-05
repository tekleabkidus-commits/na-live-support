// Ruling measurement (advisor, 2026-09-05): the launcher's paint WITH and WITHOUT the core bundle — same network/CPU
// profile as ci/widget-loader-timing.mjs (3G-fast + 4× CPU). Times are relative to the shim's responseEnd.
// Run from the repo root: node docs/evidence/S13-step13/launcher-paint-measure.mjs
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';
const CUSTOMER = 'https://customer.harness.localhost:8443';
const compose = ['compose', '--profile', 'embeddings', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml'];
const out = execFileSync('docker', [...compose, 'exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--class=Database\\Seeders\\WidgetHarnessSeeder', '--force'], { encoding: 'utf8' });
const key = /HARNESS_WIDGET_KEY=(\S+)/.exec(out)?.[1] ?? '';
const isShim = (p) => /\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js$/.test(p);
async function measure(label, blockCore) {
    const browser = await chromium.launch();
    const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
    // installed BEFORE any page script: the exact moment the launcher iframe enters the host DOM
    await context.addInitScript(() => {
        if (window.top !== window) return;
        window.__nlwInsertedAt = NaN;
        new MutationObserver((muts) => {
            for (const m of muts) for (const n of m.addedNodes) if (n.id === 'nlw-launcher' && Number.isNaN(window.__nlwInsertedAt)) window.__nlwInsertedAt = performance.now();
        }).observe(document, { childList: true, subtree: true }); // documentElement does not exist yet at init-script time
    });
    const page = await context.newPage();
    const cdp = await context.newCDPSession(page);
    await cdp.send('Network.enable');
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    const reqs = [];
    const t0 = Date.now();
    page.on('request', (r) => reqs.push({ t: Date.now() - t0, url: r.url().replace(/^https:\/\/[^/]+/, '').replace(/\?.*/, ''), type: r.resourceType(), top: !r.frame().parentFrame() }));
    if (blockCore) await page.route((u) => u.pathname.startsWith('/w/') && u.pathname.endsWith('.js') && !isShim(u.pathname), (route) => route.abort());
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`, { waitUntil: 'commit' });
    const shim = await page.waitForFunction(() => { const e = performance.getEntriesByType('resource').find((r) => /\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js$/.test(r.name)); return e ? { responseEnd: e.responseEnd, origin: performance.timeOrigin } : null; }, null, { timeout: 20000 }).then((h) => h.jsonValue());
    const inserted = await page.waitForFunction(() => !Number.isNaN(window.__nlwInsertedAt) ? window.__nlwInsertedAt : null, null, { timeout: 20000 }).then((h) => h.jsonValue());
    const deadline = Date.now() + 12000; let frame = null;
    while (!frame && Date.now() < deadline) { frame = page.frames().find((f) => f.url().includes('frame=launcher')); if (!frame) await page.waitForTimeout(25); }
    let docEnd = NaN, painted = NaN;
    if (frame) {
        try {
            docEnd = await frame.evaluate(() => performance.timeOrigin + performance.getEntriesByType('navigation')[0].responseEnd);
            painted = await frame.evaluate(() => new Promise((resolve) => { const s = performance.now(); const c = () => { if (document.querySelector('button')) requestAnimationFrame(() => resolve(performance.timeOrigin + performance.now())); else if (performance.now() - s < 12000) setTimeout(c, 25); else resolve(NaN); }; c(); }));
        } catch { /* frame navigated or blocked */ }
    }
    const shimAbs = shim.origin + shim.responseEnd;
    console.log(JSON.stringify({
        label, core_blocked: blockCore,
        iframe_inserted_after_shim_ms: Math.round(inserted - shim.responseEnd),
        launcher_document_received_after_shim_ms: Number.isNaN(docEnd) ? 'n/a' : Math.round(docEnd - shimAbs),
        launcher_button_painted_after_shim_ms: Number.isNaN(painted) ? 'NEVER within 12 s' : Math.round(painted - shimAbs),
        requests: reqs.map((r) => `${r.top ? 'top' : 'frame'} ${r.type} ${r.url} @+${r.t}ms`).slice(0, 12),
    }, null, 2));
    await browser.close();
}
await measure('A baseline (as shipped)', false);
await measure('B core bundle request BLOCKED', true);

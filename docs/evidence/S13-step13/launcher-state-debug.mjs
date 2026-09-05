// Debug instrument (S13 step 13): the launcher frame's state after an API open — label, data-open, static button present, messages seen.
import { chromium, devices } from '@playwright/test';
import { execFileSync } from 'node:child_process';
const CUSTOMER = 'https://customer.harness.localhost:8443';
const out = execFileSync('docker', ['compose', '--profile', 'embeddings', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml', 'exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--class=Database\\Seeders\\WidgetHarnessSeeder', '--force'], { encoding: 'utf8' });
const key = /HARNESS_WIDGET_KEY=(\S+)/.exec(out)?.[1] ?? '';
const browser = await chromium.launch();
const mode0 = process.argv[2] ?? 'idle';
const context = await browser.newContext(mode0 === 'spec375' ? { ...devices['iPhone 13'], ignoreHTTPSErrors: true } : { ignoreHTTPSErrors: true });
// record every message each FRAME receives (installed before scripts run in every frame)
await context.addInitScript(() => {
    window.__seen = [];
    addEventListener('message', (e) => { try { const d = e.data; window.__seen.push(d && d.type === 'api' ? 'api:' + (d.data && d.data.method) : (d && d.type) || typeof d); } catch {} }, true);
});
const page = await context.newPage();
page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') console.log('[console]', m.location().url.slice(-40), m.text().slice(0, 160)); });
page.on('requestfailed', (r) => console.log('[reqfail]', r.url().slice(-60), r.failure()?.errorText));
const launcher = () => page.frames().find((f) => f.url().includes('frame=launcher'));
const panel = () => page.frames().find((f) => f.url().includes('frame=panel'));
const snap = async (label) => {
    const l = launcher(); const p = panel();
    const ls = l ? await l.evaluate(() => ({ label: document.querySelector('button.nlw-launcher')?.getAttribute('aria-label'), open: document.querySelector('button.nlw-launcher')?.getAttribute('data-open'), static: !!document.getElementById('nlw-static'), seen: window.__seen })).catch((e) => String(e)) : 'no launcher frame';
    const ps = p ? await p.evaluate(() => ({ dialog: !!document.querySelector('[role=dialog]'), seen: window.__seen })).catch((e) => String(e)) : 'no panel frame (src unset)';
    console.log(label, JSON.stringify({ launcher: ls, panel: ps }));
};
const mode = process.argv[2] ?? 'idle';
if (mode === 'click-delay') {
    const isShim = (p) => /\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js$/.test(p);
    await page.route((u) => u.pathname.startsWith('/w/') && u.pathname.endsWith('.js') && !isShim(u.pathname), (route) => { setTimeout(() => void route.continue(), 3000); });
    await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
    const l = page.frameLocator('iframe#nlw-launcher').locator('button#nlw-static');
    await l.waitFor({ state: 'visible', timeout: 5000 });
    await snap('static visible:');
    await l.click();
    for (let i = 1; i <= 8; i++) { await page.waitForTimeout(1000); await snap(`click +${i}s:`); }
    await browser.close(); process.exit(0);
}
await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(key)}`);
if (mode === 'click-delay') {
    const isShim = (p) => /\/w\/[a-z0-9]{12}\.[a-f0-9]{64}\.js$/.test(p);
    await page.unroute('**/*').catch(() => {});
}
if (mode === 'proactive') {
    await page.goto(`${CUSTOMER}/deposit.html?key=${encodeURIComponent(key)}`);
    for (let i = 1; i <= 8; i++) {
        await page.waitForTimeout(1000);
        const l = launcher(); const p = panel();
        const ls = l ? await l.evaluate(() => ({ card: !!document.querySelector('[data-role=proactive]'), static: !!document.getElementById('nlw-static'), seen: window.__seen })).catch((e) => String(e)) : 'no launcher';
        const ps = p ? await p.evaluate(() => ({ seen: window.__seen, hooks: Object.keys(window.__nlwPanel || {}) })).catch((e) => String(e)) : 'no panel (src unset)';
        console.log(`deposit +${i}s:`, JSON.stringify({ frames: page.frames().map((f) => f.url().slice(0, 90)), launcher: ls, panel: ps }));
    }
    await browser.close(); process.exit(0);
}
if (mode === 'spec' || mode === 'spec375') {
    // exactly what tests/Browser/support/panel.ts openWidget() does: open() the instant the panel iframe EXISTS
    await page.waitForSelector('iframe#nlw-panel', { state: 'attached' });
    await page.evaluate(() => window.PlatformChat.open());
    for (let i = 1; i <= 6; i++) { await page.waitForTimeout(1000); await snap(`spec-open +${i}s:`); }
    await browser.close(); process.exit(0);
}
await page.waitForTimeout(2500);
const _unused = 0;
await snap('after 2.5 s idle:');
await page.evaluate(() => window.PlatformChat.open());
await page.waitForTimeout(3000);
await snap('3 s after open():');
await browser.close();

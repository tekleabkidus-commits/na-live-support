// Doc 13 §12 / NFR-01 / NFR-03 — the loader-timing harness (S13 step 12), DEV-HARDWARE-INDICATIVE like every
// budget in this repo: the measured figures print on every run; the assertion holds the spec numbers only under
// --assert (wall power — R-D13-6: a discharging laptop prints "timing not taken" and exits 0 without asserting).
//
//   launcher paint  ≤ 300 ms after the loader executes, on a 3G-fast + 4× CPU profile (NFR-01)
//   idle CPU        ≤ 1 % of wall time over a 10 s idle window with the panel open (NFR-03)
//   idle memory     ≤ 30 MB JS heap in the panel frame (NFR-03)
//   polling load    while the socket is down: ~1 GET messages per polling interval (NFR-03: none while healthy)
//   search p95      ≤ 200 ms over 20 Help searches (Doc 10 NFR-03)
//
// Usage: node ci/widget-loader-timing.mjs [--assert]   (needs the harness stack; WIDGET_BASE_URL optional)
import { chromium } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const ASSERT = process.argv.includes('--assert');
const CUSTOMER = process.env.WIDGET_BASE_URL ?? 'https://customer.harness.localhost:8443';
const compose = ['compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml'];
function key() {
    const out = execFileSync('docker', [...compose, 'exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--class=Database\\Seeders\\WidgetHarnessSeeder', '--force'], { encoding: 'utf8' });
    return /HARNESS_WIDGET_KEY=(\S+)/.exec(out)?.[1] ?? '';
}
function p95(xs) {
    const s = [...xs].sort((a, b) => a - b);
    return s[Math.min(s.length - 1, Math.floor(s.length * 0.95))];
}
const results = {};
const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } });
const page = await context.newPage();
const cdp = await context.newCDPSession(page);
await cdp.send('Network.enable');
await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
const k = key();
await page.goto(`${CUSTOMER}/host.html?key=${encodeURIComponent(k)}`);
// launcher paint: from the loader script's responseEnd to the launcher iframe's first paint (a MutationObserver mark + rAF)
const paint = await page.evaluate(
    () =>
        new Promise((resolve) => {
            const started = performance.now();
            const check = () => {
                const f = document.querySelector('iframe#nlw-launcher');
                if (f) requestAnimationFrame(() => resolve(performance.now()));
                else if (performance.now() - started < 20000) setTimeout(check, 10);
                else resolve(NaN);
            };
            check();
        }),
);
const loader = await page.evaluate(() => {
    const e = performance.getEntriesByType('resource').find((r) => r.name.includes('/w/') && r.name.endsWith('.js'));
    return e ? { responseEnd: e.responseEnd, duration: e.duration, size: e.transferSize } : null;
});
results.launcher_paint_ms = loader ? Math.round(paint - loader.responseEnd) : NaN;
results.loader_transfer_bytes = loader?.size ?? NaN;
// open the panel, then an idle window
await page.evaluate(() => window.PlatformChat.open());
const panel = page.frames().find((f) => f.url().includes('frame=panel'));
await page.waitForTimeout(3000);
const m0 = await cdp.send('Performance.getMetrics').catch(() => null);
await cdp.send('Performance.enable').catch(() => {});
const t0 = Date.now();
const a = await cdp.send('Performance.getMetrics');
await page.waitForTimeout(10000);
const b = await cdp.send('Performance.getMetrics');
const metric = (m, name) => m.metrics.find((x) => x.name === name)?.value ?? 0;
results.idle_cpu_pct = Math.round(((metric(b, 'TaskDuration') - metric(a, 'TaskDuration')) / ((Date.now() - t0) / 1000)) * 1000) / 10;
results.idle_js_heap_mb = Math.round((metric(b, 'JSHeapUsedSize') / 1024 / 1024) * 10) / 10;
void m0;
// polling load: drop the socket, count GET messages over 30 s
let gets = 0;
page.on('request', (r) => {
    if (r.url().includes('/api/widget/v1/messages') && r.method() === 'GET') gets++;
});
if (panel) {
    await panel.evaluate(() => window.__nlwPanel?.dropSocket?.());
    await page.waitForTimeout(31000);
    results.polling_gets_per_30s_socket_down = gets;
    // search p95 from inside the panel's origin with its own token
    const times = await panel.evaluate(async () => {
        const token = localStorage.getItem('nlw.token') ?? '';
        const out = [];
        for (const q of ['deposit', 'withdrawal', 'telebirr', 'bonus', 'account', 'ማውጣት', 'ተቀማጭ', 'card', 'limit', 'help']) {
            for (let i = 0; i < 2; i++) {
                const t = performance.now();
                await fetch('/api/widget/v1/kb/search?q=' + encodeURIComponent(q), { headers: { 'X-Visitor-Token': token, Accept: 'application/json' }, credentials: 'include' });
                out.push(performance.now() - t);
            }
        }
        return out;
    });
    results.search_p95_ms = Math.round(p95(times));
}
await browser.close();
const power = (() => {
    try {
        const o = execFileSync('powershell.exe', ['-NoProfile', '-c', '(Get-CimInstance Win32_Battery).BatteryStatus'], { encoding: 'utf8' }).trim();
        return o;
    } catch {
        return 'unknown';
    }
})();
results.battery_status = power; // 2 = charging / on AC; 1 = discharging (Win32_Battery)
console.log(JSON.stringify(results, null, 2));
const budgets = { launcher_paint_ms: 300, idle_cpu_pct: 1, idle_js_heap_mb: 30, search_p95_ms: 200 };
if (!ASSERT) {
    console.log('widget-loader-timing: figures printed (dev-indicative); pass --assert on wall power to hold the spec numbers');
    process.exit(0);
}
if (power !== '2') {
    console.log('widget-loader-timing: timing not taken — not on wall power (R-D13-6); figures above are NOT quotable');
    process.exit(0);
}
let red = 0;
for (const [k2, v] of Object.entries(budgets)) {
    const got = results[k2];
    const ok = Number.isFinite(got) && got <= v;
    console.log(`${ok ? 'PASS' : 'FAIL'} ${k2} = ${got} (budget ${v})`);
    if (!ok) red++;
}
process.exit(red ? 1 : 0);

// HARNESS step-0 browser control: from the CUSTOMER origin, a cross-origin
// fetch to the PLATFORM origin must be refused by the browser before any
// allowlist entry exists. Chromium; ignoreHTTPSErrors because the dev CA is
// not in the browser store (the curl legs validate the chain instead).
import { chromium } from 'playwright';
const browser = await chromium.launch();
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
const consoleLines = [];
page.on('console', m => consoleLines.push(`[console.${m.type()}] ${m.text()}`));
await page.goto('https://customer.harness.localhost:8443/', { waitUntil: 'load' });
await page.waitForFunction(() => document.getElementById('control-result').dataset.outcome !== undefined, null, { timeout: 10000 });
const outcome = await page.getAttribute('#control-result', 'data-outcome');
const text = await page.textContent('#control-result');
console.log('page origin :', await page.evaluate(() => location.origin));
console.log('outcome     :', outcome);
console.log('text        :', text);
for (const l of consoleLines) console.log(l);
// Second leg: the same fetch with credentials, against the mounted session door (POST), still no allowlist entry.
const r2 = await page.evaluate(async () => {
  try { const r = await fetch('https://platform.harness.localhost:8443/api/widget/v1/session', { method: 'POST', mode: 'cors', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: '{}' }); return 'UNEXPECTED allowed status ' + r.status; }
  catch (e) { return 'REFUSED: ' + e.name + ': ' + e.message; }
});
console.log('session POST:', r2);
await browser.close();
process.exit(outcome === 'refused' && r2.startsWith('REFUSED') ? 0 : 1);

// T-08 (S07 step 15): mint an authenticated session for the Lighthouse run.
// NFR-01's budget is the AUTHED cold load — Lighthouse itself cannot log in,
// so this helper does the real login through the real form with the fixture
// agent and prints the resulting Cookie header for lhci's extraHeaders.
import { chromium } from '@playwright/test';

const base = process.env.BASE_URL ?? 'https://localhost';
const browser = await chromium.launch();
const page = await browser.newPage({ ignoreHTTPSErrors: true });
await page.goto(base + '/login');
await page.locator('#login-email').fill('agent@fixture.example');
await page.locator('#login-password').fill('browser-fixture-password');
await page.getByTestId('login-submit').click();
await page.waitForURL((url) => !url.pathname.startsWith('/login'));
const cookies = await page.context().cookies();
console.log(cookies.map((c) => `${c.name}=${c.value}`).join('; '));
await browser.close();

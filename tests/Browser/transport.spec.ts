// Doc 02 SEC-06 — the transport posture as a real browser experiences it.
//
// The PHP suite proves the middleware refuses cleartext and the smoke suite
// proves the certificate validates. Neither can prove what a browser does with
// the whole chain: that typing the http:// address lands you on https, that the
// page really is served over TLS, and that HSTS arrives so the browser upgrades
// by itself next time.
import { expect, test } from '@playwright/test';

test('a cleartext address redirects to https and the page is served over TLS', async ({
    page,
    baseURL,
}) => {
    // From the fixture rather than process.env: the browser test tree is typed
    // without Node globals, and the fixture is the same value the config
    // resolved, so it cannot drift from what the other tests target.
    const insecure = (baseURL ?? 'https://localhost').replace('https://', 'http://');

    const response = await page.goto(insecure);

    // Landed on https, having followed the redirect rather than being served.
    expect(page.url()).toMatch(/^https:\/\//);
    expect(response?.url()).toMatch(/^https:\/\//);
    await expect(page.getByTestId('boot-status')).toHaveText('app booted');
});

test('responses carry HSTS and the nonce-based CSP (T-07)', async ({ page }) => {
    const response = await page.goto('/');
    const headers = response?.headers() ?? {};

    // Two years, preload — T-07's exact requirement.
    expect(headers['strict-transport-security']).toBe(
        'max-age=63072000; includeSubDomains; preload',
    );
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');

    const csp = headers['content-security-policy'] ?? '';
    expect(csp).toContain("'nonce-");
    expect(csp).not.toContain('unsafe-inline');
    expect(csp).toContain("frame-ancestors 'none'");
});

test('the CSP is enforced by the browser, not merely present', async ({ page }) => {
    // A header the browser ignores is decoration. This injects an inline script
    // WITHOUT the request's nonce and asserts the browser refused to run it —
    // which is the actual protection CSP is bought for.
    const violations: string[] = [];
    page.on('console', (message) => {
        if (message.type() === 'error' && /Content Security Policy/i.test(message.text())) {
            violations.push(message.text());
        }
    });

    await page.goto('/');
    await page.evaluate(() => {
        const script = document.createElement('script');
        script.textContent = 'window.__cspEscaped = true;';
        document.head.appendChild(script);
    });

    const escaped = await page.evaluate(() => '__cspEscaped' in window);

    expect(
        escaped,
        'an inline script without the nonce EXECUTED — the CSP is not being enforced',
    ).toBe(false);
    expect(violations.length, 'expected the browser to report a CSP violation').toBeGreaterThan(0);
});

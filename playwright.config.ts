import { defineConfig, devices } from '@playwright/test';

// Browser tests target the Docker stack (`make up`) — the same app entry a
// developer and production use. BASE_URL can be overridden for CI's
// ephemeral stack.
export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: true,

    // ONE worker, deliberately (S9's lesson extended): session.spec.ts and
    // password.spec.ts both mutate shared backing state — one Redis keyspace,
    // one per-IP login budget, clear-tenant/clear-throttle resets, and now
    // fixture CREDENTIALS. Two stateful files in parallel workers can wipe
    // each other's sessions mid-assertion; serial-within-file does not help
    // across files. Tests that share a real backing store cannot be made
    // independent by wishing.
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        // https, not http (Doc 02 SEC-06): the stack refuses cleartext, so a
        // browser test pointed at http:// would exercise the 308 redirect
        // instead of the application.
        baseURL: process.env.BASE_URL ?? 'https://localhost',

        // The stack presents a certificate from the image-baked DEV CA
        // (docker/app/generate-dev-certs.sh), which the bundled Chromium's
        // trust store does not and should not contain — installing a CA into
        // the browser fixture would be a bigger security footgun than this
        // flag.
        //
        // What compensates, so this is not a hole: TLS validity itself is
        // asserted in tests/Integration/StackSmokeTest.php, which runs INSIDE
        // the container where the dev CA IS trusted, with verify_peer on, and
        // additionally checks the SAN list and expiry. Chain validation is
        // therefore proven — just not by the browser, whose job here is the
        // application's behaviour.
        ignoreHTTPSErrors: true,

        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});

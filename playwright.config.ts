import { defineConfig, devices } from '@playwright/test';

// Browser tests target the Docker stack (`make up`) — the same app entry a
// developer and production use. BASE_URL can be overridden for CI's
// ephemeral stack.
export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: process.env.BASE_URL ?? 'http://localhost',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});

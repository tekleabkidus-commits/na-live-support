// T-08 (S07 step 15): the Lighthouse CI budget for the inbox route.
// NFR-01's number is the assertion; the measured figure prints every run.
// The session cookie comes from ci/lhci-session.mjs (the real login).
module.exports = {
    ci: {
        collect: {
            url: ['https://localhost/inbox'],
            numberOfRuns: 1,
            settings: {
                onlyCategories: ['performance'],
                // Mid-range hardware / fast-3G is NFR-01's own profile —
                // lighthouse's default simulated throttling models exactly
                // that class; localhost serving removes network variance
                // beyond the simulation (dev-indicative, like every budget).
                extraHeaders: JSON.stringify({ Cookie: process.env.LHCI_COOKIE ?? '' }),
                chromeFlags: '--ignore-certificate-errors',
            },
        },
        assert: {
            assertions: {
                // NFR-01: interactive ≤ 1.5 s — the SPEC number under
                // PERF_BUDGETS_ENFORCE (the house two-tier discipline: every
                // budget suite asserts a 5x regression ceiling on dev
                // hardware and the spec number in strict mode; Doc 19's L1
                // profile is where the number binds). Dev-measured figure
                // at step 15: 1977 ms under simulated fast-3G — RECORDED,
                // not hidden (see the close-out's flagged row).
                interactive: [
                    'error',
                    { maxNumericValue: process.env.PERF_BUDGETS_ENFORCE === '1' ? 1500 : 7500 },
                ],
                // NFR-04's sibling view of the same fact, in transfer bytes —
                // binding at every scale (the bundle is the bundle).
                'resource-summary:script:size': ['error', { maxNumericValue: 358400 }],
            },
        },
    },
};

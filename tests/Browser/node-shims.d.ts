// Minimal type declarations for the Node built-ins the browser suite uses.
//
// WHY A SHIM RATHER THAN @types/node. The session suite shells out to
// `php artisan auth:session-inspect` for two facts a browser structurally cannot
// compute (see session.spec.ts). That needs `child_process`, and vue-tsc has no
// Node types — CI failed with TS2591 while the local run passed from an
// incremental cache, which is its own small lesson about trusting a cached
// type-check.
//
// Adding `@types/node` would be a new dependency, and CLAUDE.md §0.5 requires an
// ADR for every one of those. A dependency-and-ADR to type ONE function called
// in ONE test file is disproportionate; declaring exactly what is used is
// smaller, reviewable in ten seconds, and cannot drift into the application
// bundle. If a later document genuinely needs Node's API surface in TypeScript,
// that is the moment for the dependency and the ADR.

declare module 'node:child_process' {
    export function execFileSync(
        file: string,
        args?: readonly string[],
        options?: { encoding?: string; cwd?: string },
    ): string;
}

// `process.env` for suite-level overrides (MAILPIT_URL). Same reasoning as
// child_process above: one global, declared narrowly, instead of @types/node.
declare const process: { env: Record<string, string | undefined> };

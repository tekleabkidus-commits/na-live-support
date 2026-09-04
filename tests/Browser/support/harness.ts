import { execFileSync } from 'node:child_process';

export const PLATFORM = 'https://platform.harness.localhost:8443';
export const CUSTOMER = 'https://customer.harness.localhost:8443';

/** The compose files the stack was brought up with: the local dev pair by default, CI's base + harness via NLW_COMPOSE_FILES. */
export function composeFiles(): string[] {
    const env = process.env.NLW_COMPOSE_FILES?.trim();
    return env
        ? env.split(/\s+/)
        : ['--profile', 'embeddings', '-f', 'docker/compose.yaml', '-f', 'docker/compose.dev.yaml'];
}

/** The HARNESS widget's key, minted by the seeder (never committed, never in a static page). */
export function harnessWidgetKey(): string {
    const out = execFileSync(
        'docker',
        [
            'compose',
            ...composeFiles(), // the local dev pair, or CI's base + harness (NLW_COMPOSE_FILES)
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            'db:seed',
            '--class=Database\\Seeders\\WidgetHarnessSeeder',
            '--force',
        ],
        { encoding: 'utf8' },
    );
    const key = /HARNESS_WIDGET_KEY=(\S+)/.exec(out)?.[1];
    if (!key) throw new Error('the harness seeder printed no HARNESS_WIDGET_KEY');
    return key;
}

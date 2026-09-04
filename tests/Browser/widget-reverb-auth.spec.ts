// Doc 13 SESSION-13 step 1 — THE REVERB VISITOR-PRINCIPAL PROOF FROM THE
// CUSTOMER ORIGIN (plan STOP-E, ruled; R-D13-1.1: real cross-origin physics
// the browser enforces locally are proven on THE HARNESS, never called the
// origin). Sequence, all from a page served by the CUSTOMER origin:
//   1. POST /api/widget/v1/session with the widget key → visitor token +
//      the FR-22 cookie (SameSite=None; Secure; Path=/api/widget);
//   2. a raw WebSocket to Reverb THROUGH the platform origin
//      (wss://platform.harness.localhost:8443/app/{key}) → connection_established;
//   3. POST /api/widget/v1/broadcasting/auth with X-Visitor-Token for
//      private-tenant.{t}.visitor.{v}.thread → auth signature;
//   4. pusher:subscribe → pusher_internal:subscription_succeeded.
//   5. THE NEGATIVE HALF: the same door for ANOTHER visitor's channel answers
//      403 — a refusal, never the S14 500-on-WidgetVisitor shape (ruled: a 500
//      on any widget channel = STOP).
// The tenant id is read back from the session response's channel hint? No —
// the session door returns visitor_id only; the tenant id comes from the
// seeder's artisan output (the spec's own fixture, 0l: dedicated rows).
import { expect, test } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const PLATFORM = 'https://platform.harness.localhost:8443';
const CUSTOMER = 'https://customer.harness.localhost:8443';

function artisan(...args: string[]): string {
    return execFileSync(
        'docker',
        [
            'compose',
            '--profile',
            'embeddings',
            '-f',
            'docker/compose.yaml',
            '-f',
            'docker/compose.dev.yaml',
            'exec',
            '-T',
            'app',
            'php',
            'artisan',
            ...args,
        ],
        { stdio: 'pipe' },
    ).toString();
}

let widgetKey = '';
let tenantId = '';

test.beforeAll(() => {
    artisan('db:seed', '--class=Database\\Seeders\\BrowserFixtureSeeder', '--force');
    const out = artisan('db:seed', '--class=Database\\Seeders\\WidgetHarnessSeeder', '--force');
    const m = out.match(/HARNESS_WIDGET_KEY=(\S+)/);
    if (!m) throw new Error('the harness seeder printed no widget key:\n' + out);
    widgetKey = m[1] ?? '';
    tenantId = artisan(
        'tinker',
        '--execute',
        "echo DB::connection('pgsql_platform')->table('tenants')->where('name','Fixture Operator A')->value('id');",
    )
        .trim()
        .split('\n')
        .pop()!
        .trim();
    if (!/^[0-9a-f-]{36}$/.test(tenantId)) throw new Error('no fixture tenant id: ' + tenantId);
});

test('a visitor principal from the CUSTOMER origin authenticates onto exactly its own Reverb channel; a foreign visitor channel is REFUSED with 403, never 500 (Doc 13 T-04 / Doc 02 FR-21; STOP-E)', async ({
    page,
}) => {
    const consoleLines: string[] = [];
    page.on('console', (m) => consoleLines.push(`[${m.type()}] ${m.text()}`));
    await page.goto(CUSTOMER + '/', { waitUntil: 'load' });
    expect(await page.evaluate(() => location.origin)).toBe(CUSTOMER);

    const result = await page.evaluate(
        async ({ platform, key, tenant }) => {
            const session = await fetch(platform + '/api/widget/v1/session', {
                method: 'POST',
                mode: 'cors',
                credentials: 'include',
                headers: { 'Content-Type': 'application/json', 'X-Tenant-Key': key },
                body: JSON.stringify({ ancestors: [location.origin] }),
            });
            const sessionBody = await session.json();
            const token: string = sessionBody.token;
            const visitorId: string = sessionBody.visitor_id;

            // 2. raw Pusher-protocol socket through the platform origin's /app/* door
            const ws = new WebSocket(
                `wss://${new URL(platform).host}/app/local-key?protocol=7&client=harness&version=0.0.1&flash=false`,
            );
            const socketId: string = await new Promise((resolve, reject) => {
                const t = setTimeout(
                    () => reject(new Error('no connection_established within 10 s')),
                    10000,
                );
                ws.onmessage = (ev) => {
                    const frame = JSON.parse(ev.data);
                    if (frame.event === 'pusher:connection_established') {
                        clearTimeout(t);
                        resolve(JSON.parse(frame.data).socket_id);
                    }
                };
                ws.onerror = () => {
                    clearTimeout(t);
                    reject(new Error('socket error'));
                };
            });

            const channel = `private-tenant.${tenant}.visitor.${visitorId}.thread`;
            const auth = await fetch(platform + '/api/widget/v1/broadcasting/auth', {
                method: 'POST',
                mode: 'cors',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Tenant-Key': key,
                    'X-Visitor-Token': token,
                },
                body: JSON.stringify({ socket_id: socketId, channel_name: channel }),
            });
            const authBody = auth.ok ? await auth.json() : null;

            const subscribed: string = await new Promise((resolve, reject) => {
                const t = setTimeout(
                    () => reject(new Error('no subscription_succeeded within 10 s')),
                    10000,
                );
                ws.onmessage = (ev) => {
                    const frame = JSON.parse(ev.data);
                    if (
                        frame.event === 'pusher_internal:subscription_succeeded' &&
                        frame.channel === channel
                    ) {
                        clearTimeout(t);
                        resolve(frame.channel);
                    }
                    if (frame.event === 'pusher:error') {
                        clearTimeout(t);
                        reject(new Error('pusher:error ' + frame.data));
                    }
                };
                ws.send(
                    JSON.stringify({
                        event: 'pusher:subscribe',
                        data: { channel, auth: authBody?.auth },
                    }),
                );
            });

            // 5. the negative half: another visitor's channel from the same principal
            const foreign = await fetch(platform + '/api/widget/v1/broadcasting/auth', {
                method: 'POST',
                mode: 'cors',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Tenant-Key': key,
                    'X-Visitor-Token': token,
                },
                body: JSON.stringify({
                    socket_id: socketId,
                    channel_name: `private-tenant.${tenant}.visitor.0199ffff-0000-7000-8000-00000000dead.thread`,
                }),
            });
            ws.close();
            return {
                sessionStatus: session.status,
                visitorId,
                authStatus: auth.status,
                subscribed,
                foreignStatus: foreign.status,
            };
        },
        { platform: PLATFORM, key: widgetKey, tenant: tenantId },
    );

    console.log('REVERB VISITOR-PRINCIPAL PROOF:', JSON.stringify(result));
    for (const l of consoleLines) console.log(l);
    expect([200, 201]).toContain(result.sessionStatus);
    expect(result.authStatus).toBe(200);
    expect(result.subscribed).toBe(`private-tenant.${tenantId}.visitor.${result.visitorId}.thread`);
    expect(
        result.foreignStatus,
        'a foreign visitor channel is a REFUSAL (403), never the S14 500 shape',
    ).toBe(403);
});

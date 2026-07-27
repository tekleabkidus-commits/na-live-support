// mock-telegram — deterministic Telegram Bot API stub (Session 0).
// Purpose: CI and local dev NEVER call api.telegram.org. This stub
//   1. accepts any POST /bot<token>/<method> call, records it, and returns a
//      deterministic success payload;
//   2. replays fixture updates into the app's webhook on demand
//      (POST /_replay {"url": "...", "update": {...}});
//   3. exposes what it recorded (GET /_recorded) and can be reset
//      (DELETE /_recorded) so tests are order-independent.
// Determinism: no randomness, no wall-clock in responses; message_id is a
// monotonic counter starting at 1000 (reset with DELETE /_recorded).
import { createServer } from 'node:http';

const PORT = Number(process.env.PORT ?? 8081);
let recorded = [];
let nextMessageId = 1000;

const json = (res, status, body) => {
    res.writeHead(status, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body));
};

const readBody = (req) =>
    new Promise((resolve) => {
        let data = '';
        req.on('data', (c) => (data += c));
        req.on('end', () => resolve(data));
    });

createServer(async (req, res) => {
    const url = new URL(req.url, `http://localhost:${PORT}`);

    if (req.method === 'GET' && url.pathname === '/healthz') {
        return json(res, 200, { ok: true, service: 'mock-telegram' });
    }
    if (req.method === 'GET' && url.pathname === '/_recorded') {
        return json(res, 200, { calls: recorded });
    }
    if (req.method === 'DELETE' && url.pathname === '/_recorded') {
        recorded = [];
        nextMessageId = 1000;
        return json(res, 200, { ok: true });
    }
    if (req.method === 'POST' && url.pathname === '/_replay') {
        const { url: target, update } = JSON.parse(await readBody(req));
        try {
            const r = await fetch(target, {
                method: 'POST',
                headers: { 'content-type': 'application/json' },
                body: JSON.stringify(update),
            });
            return json(res, 200, { ok: true, delivered_status: r.status });
        } catch (e) {
            return json(res, 502, { ok: false, error: String(e) });
        }
    }

    // Bot API surface: POST /bot<token>/<method>
    const m = url.pathname.match(/^\/bot([^/]+)\/(\w+)$/);
    if (req.method === 'POST' && m) {
        const [, token, method] = m;
        const raw = await readBody(req);
        let params = {};
        try {
            params = JSON.parse(raw);
        } catch {
            params = { _raw: raw };
        }
        recorded.push({ token, method, params });
        const result =
            method === 'sendMessage'
                ? {
                      message_id: nextMessageId++,
                      chat: { id: params.chat_id ?? 0 },
                      text: params.text ?? '',
                  }
                : { ok: true };
        return json(res, 200, { ok: true, result });
    }

    return json(res, 404, { ok: false, description: 'mock-telegram: unknown route' });
}).listen(PORT, () => console.log(`mock-telegram listening on :${PORT}`));

// mock-sms — deterministic SMS gateway stub (Session 0).
// Purpose: CI and local dev NEVER call a real gateway. This stub
//   1. accepts sends (POST /send {"to","text","callback_url"?}) and returns a
//      deterministic message id (sms-1, sms-2, ...);
//   2. if callback_url is present, immediately POSTs a DLR callback
//      {"message_id","status":"delivered"} — deterministic, no delays.
//      Numbers ending in 0000 get status "failed" so failure paths are
//      testable without flakiness (fixture numbers are +251-90000xxxx);
//   3. GET /_recorded lists sends + emitted DLRs; DELETE /_recorded resets.
import { createServer } from 'node:http';

const PORT = Number(process.env.PORT ?? 8082);
let sends = [];
let dlrs = [];
let seq = 0;

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
        return json(res, 200, { ok: true, service: 'mock-sms' });
    }
    if (req.method === 'GET' && url.pathname === '/_recorded') {
        return json(res, 200, { sends, dlrs });
    }
    if (req.method === 'DELETE' && url.pathname === '/_recorded') {
        sends = [];
        dlrs = [];
        seq = 0;
        return json(res, 200, { ok: true });
    }
    if (req.method === 'POST' && url.pathname === '/send') {
        const body = JSON.parse(await readBody(req));
        const id = `sms-${++seq}`;
        sends.push({ id, ...body });
        const status = String(body.to ?? '').endsWith('0000') ? 'failed' : 'delivered';
        if (body.callback_url) {
            const dlr = { message_id: id, status };
            try {
                await fetch(body.callback_url, {
                    method: 'POST',
                    headers: { 'content-type': 'application/json' },
                    body: JSON.stringify(dlr),
                });
                dlrs.push({ ...dlr, delivered_to_callback: true });
            } catch {
                dlrs.push({ ...dlr, delivered_to_callback: false });
            }
        }
        return json(res, 200, { message_id: id, accepted: true });
    }

    return json(res, 404, { ok: false, error: 'mock-sms: unknown route' });
}).listen(PORT, () => console.log(`mock-sms listening on :${PORT}`));

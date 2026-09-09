// mock-smpp-sidecar — the SMPP sidecar's CONTRACT half as a FIXTURE (Doc 14
// T-03 / FR-10 / FS-02 / AC-08). "The platform's only contract is T-02 over
// private HTTP + mTLS": this mock exposes that contract — POST /send,
// GET /health, DLR webhooks back — over HTTPS with a REQUIRED client
// certificate minted by entrypoint.sh (CA, server, client into the shared
// /certs-smpp volume the app reads). The SMPP bind, enquire_link keepalives and
// window management it stands in for are [D18] (the real sidecar's packaging
// and the real bind); nothing here is proof of them.
//
// Restart transparency (T-03 "sidecar restarts are invisible to the pipeline"):
// POST /_restart closes the listener for RESTART_DOWN_MS and reopens it — the
// driver classifies the gap as retryable and the next attempt lands.
// Per-number rules match the mock-sms REFERENCE profile (0000 undelivered,
// 0001 silence, else accepted → sent → delivered); DLRs are SIGNED in Doc 02
// FR-24's body_hmac shape with the shared fixture secret.
import { createServer } from 'node:https';
import { readFileSync } from 'node:fs';
import { createHmac, randomBytes } from 'node:crypto';

const PORT = Number(process.env.PORT ?? 8443);
const CERT_DIR = process.env.CERT_DIR ?? '/certs-smpp';
const DLR_SECRET = process.env.MOCK_SMPP_DLR_SECRET ?? 'fixture-sidecar-dlr-secret';
const RESTART_DOWN_MS = Number(process.env.MOCK_SMPP_RESTART_DOWN_MS ?? 1500);

let sends = [];
let dlrs = [];
let seq = 0;
let bind = 'up';

const json = (res, status, body) => {
    res.writeHead(status, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body));
};
const readBody = (req) => new Promise((resolve) => { let d = ''; req.on('data', (c) => (d += c)); req.on('end', () => resolve(d)); });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const signed = (body) => {
    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(16).toString('hex');
    const sig = createHmac('sha256', DLR_SECRET).update(`${ts}.${nonce}.${body}`).digest('hex');
    return { 'content-type': 'application/json', 'x-webhook-timestamp': ts, 'x-webhook-nonce': nonce, 'x-webhook-signature': sig };
};
const postDlr = async (url, payload) => {
    const body = JSON.stringify(payload);
    try {
        const r = await fetch(url, { method: 'POST', headers: signed(body), body });
        dlrs.push({ ...payload, delivered_to_callback: true, callback_status: r.status });
    } catch {
        dlrs.push({ ...payload, delivered_to_callback: false });
    }
};
const sequence = (to) => {
    const tail = String(to).slice(-4);
    if (tail === '0000') return [['accepted'], ['sent'], ['undelivered', 'ABSENT_SUBSCRIBER']];
    if (tail === '0001') return [['accepted']];
    return [['accepted'], ['sent'], ['delivered']];
};

const options = {
    key: readFileSync(`${CERT_DIR}/server.key`),
    cert: readFileSync(`${CERT_DIR}/server.crt`),
    ca: readFileSync(`${CERT_DIR}/ca.crt`),
    requestCert: true,
    rejectUnauthorized: true, // mTLS: no client certificate from our CA → the TLS handshake fails, no HTTP at all
};

const handler = async (req, res) => {
    const url = new URL(req.url, `https://localhost:${PORT}`);
    if (req.method === 'GET' && url.pathname === '/health') return json(res, 200, { ok: true, service: 'mock-smpp-sidecar', fixture: true, bind });
    if (req.method === 'GET' && url.pathname === '/_recorded') return json(res, 200, { sends, dlrs });
    if (req.method === 'DELETE' && url.pathname === '/_recorded') { sends = []; dlrs = []; seq = 0; return json(res, 200, { ok: true }); }
    if (req.method === 'POST' && url.pathname === '/_restart') {
        json(res, 202, { restarting: true, down_ms: RESTART_DOWN_MS });
        setTimeout(() => { bind = 'down'; server.close(() => setTimeout(() => { bind = 'up'; server.listen(PORT); }, RESTART_DOWN_MS)); }, 50);
        return;
    }
    if (req.method === 'POST' && url.pathname === '/send') {
        let body = {};
        try { body = JSON.parse(await readBody(req)); } catch { return json(res, 400, { error: 'bad_json' }); }
        if (!body.to || !body.text) return json(res, 400, { error: 'missing_fields' });
        const id = `smpp-${++seq}`;
        sends.push({ id, message_id: body.message_id ?? null, to: body.to, text: body.text, sender: body.sender ?? null, callback: body.callback ?? null });
        if (body.callback) {
            (async () => { for (const [status, code] of sequence(body.to)) { await sleep(0); await postDlr(body.callback, { message_id: id, status, ...(code ? { code } : {}), at: new Date().toISOString() }); } })();
        }
        return json(res, 200, { message_id: id, status: 'accepted' });
    }
    return json(res, 404, { error: 'unknown route' });
};

const server = createServer(options, handler);
server.listen(PORT, () => console.log(`mock-smpp-sidecar FIXTURE listening on :${PORT} (mTLS required)`));

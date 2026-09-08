// mock-sms — the SMS gateway FIXTURE (Session 0 stub reshaped at SESSION-14
// step 0 under R-D14-1.3). It is a FIXTURE: never the gateway, never proof of
// an [OA] row. CI and local dev NEVER call a real gateway.
//
// ONE process, TWO profiles, addressed by path prefix so the compose service
// (`mock-sms`, :8082) and the egress allowlist stay as they are:
//
//   /reference/…      — the REFERENCE profile: a gateway that speaks the full
//                       status vocabulary Doc 14 FR-12's machine needs
//                       (accepted → sent → delivered | undelivered | expired)
//                       and SIGNS its DLR callbacks with Doc 02 FR-24's
//                       body_hmac headers (X-Webhook-Signature/Timestamp/Nonce
//                       over "ts.nonce.body", HMAC-SHA256 with the shared
//                       DLR secret). Per-number rules make every branch of
//                       T-08's matrix reachable without flakiness.
//   /geezsms/api/v1/… — the GeezSMS profile: the vendor's DOCUMENTED contract
//                       byte-for-byte (docs/vendors/GEEZSMS-API-NOTES.md §3.4,
//                       §3.6, §9): POST form with `token`, `phone`, `msg`,
//                       optional `shortcode_id`, `callback`; the five-field
//                       callback with `success` as its ONLY status word;
//                       GET balance under `X-GeezSMS-Key`. Everything the
//                       vendor leaves UNKNOWN is a FIXTURE ASSUMPTION, marked
//                       inline; a real answer replaces it, never a guess in
//                       platform code.
//
// Fail-closed controls (both profiles): a request without its credential is
// REFUSED. The GeezSMS profile also refuses the GET send variant (a token in
// a query string lands in access logs — R-D14-1.6) and a token in the query
// of a POST.
//
// Fixture numbers are the +251-90000xxxx family (CLAUDE.md §8). Per-number
// rules (REFERENCE profile; the last four digits):
//   0000 → undelivered (carrier code ABSENT_SUBSCRIBER)
//   0001 → accepted only, then SILENCE (no further DLR → the 48 h `expired`)
//   0002 → OUT-OF-ORDER: delivered arrives BEFORE sent (FR-13 precedence)
//   0003 → delivered, then a later undelivered (EC-04 contradiction)
//   0004 → delivered DUPLICATED (idempotency)
//   0005 → send answers 503 (retryable)
//   0006 → send answers 400 invalid_number (non-retryable)
//   0007 → send answers 401 auth_expired (non-retryable, FS-01)
//   else → accepted → sent → delivered
// GeezSMS profile per-number rule: 0000 → NO callback at all (the vendor
// documents no failure status; the honest fixture is silence).
//
// GET /_recorded lists sends + emitted DLRs (both profiles); DELETE resets.
import { createServer } from 'node:http';
import { createHmac, randomBytes, randomUUID } from 'node:crypto';

const PORT = Number(process.env.PORT ?? 8082);
// FIXTURE credentials (dev only; the platform seeds the SAME values from its
// own env — never a real vendor token; incident #16).
const REFERENCE_API_TOKEN = process.env.MOCK_SMS_REFERENCE_TOKEN ?? 'fixture-reference-token';
const REFERENCE_DLR_SECRET = process.env.MOCK_SMS_REFERENCE_DLR_SECRET ?? 'fixture-reference-dlr-secret';
const GEEZSMS_FIXTURE_TOKEN = process.env.MOCK_SMS_GEEZSMS_TOKEN ?? 'fixture-geezsms-token';
// DLR pacing in ms (0 in CI/tests for determinism; the sequence order is what matters).
const DLR_DELAY_MS = Number(process.env.MOCK_SMS_DLR_DELAY_MS ?? 0);

let sends = [];
let dlrs = [];
let seq = 0;
let geezSeq = 6569829; // the docs' example api_log_id, continued (FIXTURE ASSUMPTION §4)

const json = (res, status, body) => {
    res.writeHead(status, { 'content-type': 'application/json' });
    res.end(JSON.stringify(body));
};

const readBody = (req) =>
    new Promise((resolve) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => resolve(Buffer.concat(chunks)));
    });

// Form parsing: x-www-form-urlencoded (Laravel asForm) and multipart/form-data
// (Postman "formdata", Laravel asMultipart) — field NAMES are the contract.
const parseForm = (req, raw) => {
    const ct = String(req.headers['content-type'] ?? '');
    if (ct.startsWith('application/x-www-form-urlencoded')) {
        return Object.fromEntries(new URLSearchParams(raw.toString('utf8')));
    }
    if (ct.startsWith('multipart/form-data')) {
        const m = /boundary=("?)([^";]+)\1/i.exec(ct);
        if (!m) return {};
        const boundary = `--${m[2]}`;
        const out = {};
        for (const part of raw.toString('utf8').split(boundary)) {
            const nm = /name="([^"]+)"/i.exec(part);
            if (!nm) continue;
            const idx = part.indexOf('\r\n\r\n');
            if (idx === -1) continue;
            out[nm[1]] = part.slice(idx + 4).replace(/\r\n$/, '').replace(/--\r?\n?$/, '');
        }
        return out;
    }
    if (ct.startsWith('application/json')) {
        try { return JSON.parse(raw.toString('utf8')); } catch { return {}; }
    }
    return {};
};

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// ---------------------------------------------------------------- REFERENCE
const signedHeaders = (body) => {
    const ts = String(Math.floor(Date.now() / 1000));
    const nonce = randomBytes(16).toString('hex');
    const sig = createHmac('sha256', REFERENCE_DLR_SECRET).update(`${ts}.${nonce}.${body}`).digest('hex');
    return {
        'content-type': 'application/json',
        'x-webhook-timestamp': ts,
        'x-webhook-nonce': nonce,
        'x-webhook-signature': sig,
    };
};

const postDlr = async (profile, url, payload, headers) => {
    const body = JSON.stringify(payload);
    let delivered = false;
    let status = null;
    try {
        const r = await fetch(url, { method: 'POST', headers: headers(body), body });
        delivered = true;
        status = r.status;
    } catch {
        delivered = false;
    }
    dlrs.push({ profile, ...payload, delivered_to_callback: delivered, callback_status: status });
};

const referenceSequence = (to) => {
    const tail = String(to).slice(-4);
    switch (tail) {
        case '0000': return [['accepted'], ['sent'], ['undelivered', 'ABSENT_SUBSCRIBER']];
        case '0001': return [['accepted']];
        case '0002': return [['accepted'], ['delivered'], ['sent']];
        case '0003': return [['accepted'], ['sent'], ['delivered'], ['undelivered', 'EXPIRED_AT_SMSC']];
        case '0004': return [['accepted'], ['sent'], ['delivered'], ['delivered']];
        default: return [['accepted'], ['sent'], ['delivered']];
    }
};

const referenceSend = async (req, res, raw) => {
    const auth = String(req.headers.authorization ?? '');
    if (auth !== `Bearer ${REFERENCE_API_TOKEN}`) {
        return json(res, 401, { error: 'unauthorized', detail: 'reference profile: Bearer token missing or wrong (fail-closed)' });
    }
    const body = parseForm(req, raw);
    const to = String(body.to ?? '');
    const tail = to.slice(-4);
    if (tail === '0005') return json(res, 503, { error: 'temporarily_unavailable' });
    if (tail === '0006') return json(res, 400, { error: 'invalid_number', code: 'INVALID_DESTINATION' });
    if (tail === '0007') return json(res, 401, { error: 'auth_expired', code: 'AUTH_EXPIRED' });
    const id = `ref-${++seq}`;
    sends.push({ profile: 'reference', id, to, text: body.text, sender_id: body.sender_id ?? null, callback_url: body.callback_url ?? null });
    if (body.callback_url) {
        // fire-and-forget AFTER answering; order is the fixture's contract
        (async () => {
            for (const [status, code] of referenceSequence(to)) {
                await sleep(DLR_DELAY_MS);
                await postDlr('reference', body.callback_url, {
                    message_id: id,
                    status,
                    ...(code ? { code } : {}),
                    at: new Date().toISOString(),
                }, signedHeaders);
            }
        })();
    }
    return json(res, 200, { message_id: id, status: 'accepted' });
};

// ------------------------------------------------------------------ GEEZSMS
const geezSend = async (req, res, raw, url) => {
    // R-D14-1.6 control: the token NEVER rides a URL.
    if (url.searchParams.has('token')) {
        return json(res, 400, { error: true, msg: 'FIXTURE CONTROL: token must ride the POST form, never the query string' });
    }
    const form = parseForm(req, raw);
    const token = String(form.token ?? '');
    if (token === '') {
        // FIXTURE ASSUMPTION (§13: no error codes documented): a missing token is refused with 401.
        return json(res, 401, { error: true, msg: 'token is required' });
    }
    if (token !== GEEZSMS_FIXTURE_TOKEN) {
        return json(res, 401, { error: true, msg: 'invalid token' });
    }
    const phone = String(form.phone ?? '');
    const msg = String(form.msg ?? '');
    if (!/^2519\d{8}$/.test(phone) && !/^2517\d{8}$/.test(phone)) {
        // §3.4 "must start with 2519" — 2517… accepted by the FIXTURE ONLY (open question §18 Q9).
        return json(res, 400, { error: true, msg: 'phone must start with 2519' });
    }
    if ([...msg].length >= 335) {
        // §3.4 "must be less than 335 characters" — counted in CHARACTERS by the fixture (open question §18 Q8).
        return json(res, 400, { error: true, msg: 'msg must be less than 335 characters' });
    }
    const apiLogId = ++geezSeq;
    sends.push({ profile: 'geezsms', api_log_id: apiLogId, phone, msg, shortcode_id: form.shortcode_id ?? null, callback: form.callback ?? null });
    if (form.callback && !phone.endsWith('0000')) {
        (async () => {
            await sleep(DLR_DELAY_MS);
            // §9: the ONLY documented callback — five fields, `success` the only status word, UNSIGNED, no headers.
            await postDlr('geezsms', form.callback, {
                message_status: 'success',
                log: `async ${randomUUID()}`,
                phone,
                message: msg,
                api_log_id: apiLogId,
            }, () => ({ 'content-type': 'application/json' }));
        })();
    }
    // FIXTURE ASSUMPTION (§4: the vendor documents NO response body): echo api_log_id under `data`.
    return json(res, 200, { error: false, msg: 'Message sent', data: { api_log_id: apiLogId } });
};

const geezBalance = (req, res) => {
    if (!req.headers['x-geezsms-key']) {
        return json(res, 401, { error: true, msg: 'X-GeezSMS-Key header is required' });
    }
    if (req.headers['x-geezsms-key'] !== GEEZSMS_FIXTURE_TOKEN) {
        return json(res, 401, { error: true, msg: 'invalid token' });
    }
    // FIXTURE ASSUMPTION (§15: response shape and currency UNKNOWN).
    return json(res, 200, { error: false, balance: 1234.5, currency: 'ETB' });
};

// ------------------------------------------------------------------- server
createServer(async (req, res) => {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    const p = url.pathname;

    if (req.method === 'GET' && p === '/healthz') {
        return json(res, 200, { ok: true, service: 'mock-sms', fixture: true, profiles: ['reference', 'geezsms'] });
    }
    if (req.method === 'GET' && p === '/_recorded') return json(res, 200, { sends, dlrs });
    if (req.method === 'DELETE' && p === '/_recorded') {
        sends = []; dlrs = []; seq = 0;
        return json(res, 200, { ok: true });
    }

    // REFERENCE profile
    if (p === '/reference/health' && req.method === 'GET') return json(res, 200, { ok: true, profile: 'reference' });
    if (p === '/reference/send' && req.method === 'POST') return referenceSend(req, res, await readBody(req));

    // GeezSMS profile (docs/vendors/GEEZSMS-API-NOTES.md §3)
    if (p === '/geezsms/api/v1/sms/send') {
        if (req.method === 'GET') {
            return json(res, 405, { error: true, msg: 'FIXTURE CONTROL: the GET send variant is refused (token in URL, R-D14-1.6)' });
        }
        if (req.method === 'POST') return geezSend(req, res, await readBody(req), url);
    }
    if (p === '/geezsms/api/v1/balance' && req.method === 'GET') return geezBalance(req, res);
    if (p === '/geezsms/api/v1/sms/otp' || p === '/geezsms/api/v1/sms/send/bulk') {
        // §6 / §5: NOT used by the platform (verification_code is the platform's own template; broadcasts fan out as single sends).
        return json(res, 404, { error: true, msg: 'FIXTURE: endpoint not modelled — the platform does not use it (GEEZSMS-API-NOTES §5, §6)' });
    }

    return json(res, 404, { ok: false, error: 'mock-sms: unknown route' });
}).listen(PORT, () => console.log(`mock-sms FIXTURE listening on :${PORT} (profiles: reference, geezsms)`));

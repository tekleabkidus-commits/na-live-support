// mock-ai — deterministic AI-provider stub (Session 0).
// Purpose: CI and local dev NEVER call a real model provider. This stub
// returns canned completions keyed on a stable FNV-1a hash of the prompt, so
// the same prompt ALWAYS yields the same completion (golden-test friendly)
// and different prompts yield distinguishable ones. No randomness, no clock.
//   POST /complete {"prompt": "..."} → {"completion","prompt_hash"}
//   GET /_recorded, DELETE /_recorded as in the other mocks.
import { createServer } from 'node:http';

const PORT = Number(process.env.PORT ?? 8083);
let recorded = [];

const fnv1a = (str) => {
    let h = 0x811c9dc5;
    for (let i = 0; i < str.length; i++) {
        h ^= str.charCodeAt(i);
        h = Math.imul(h, 0x01000193) >>> 0;
    }
    return h.toString(16).padStart(8, '0');
};

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
        return json(res, 200, { ok: true, service: 'mock-ai' });
    }
    if (req.method === 'GET' && url.pathname === '/_recorded') {
        return json(res, 200, { calls: recorded });
    }
    if (req.method === 'DELETE' && url.pathname === '/_recorded') {
        recorded = [];
        return json(res, 200, { ok: true });
    }
    if (req.method === 'POST' && url.pathname === '/complete') {
        const body = JSON.parse(await readBody(req));
        const prompt = String(body.prompt ?? '');
        const hash = fnv1a(prompt);
        const completion = `[mock-ai completion ${hash}]`;
        recorded.push({ prompt, hash });
        return json(res, 200, { completion, prompt_hash: hash });
    }

    return json(res, 404, { ok: false, error: 'mock-ai: unknown route' });
}).listen(PORT, () => console.log(`mock-ai listening on :${PORT}`));

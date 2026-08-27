// ADR 0018 — local embeddings sidecar. OpenAI-compatible POST /v1/embeddings
// (so ADR 0016's thin client reaches it as just another provider), GET /healthz,
// GET /v1/models. Runtime is OFFLINE by mechanism: env.allowRemoteModels=false
// and the model was baked into the image at build (prefetch.mjs). No auth —
// the service is private to the compose network; the app's fixtures-only
// egress guard still applies to every payload sent here (ADR 0018).
import http from 'node:http';
import { env, pipeline } from '@huggingface/transformers';
import { CACHE_DIR, DIMS, MAX_CHARS, MAX_INPUTS, MODEL_DTYPE, MODEL_ID, MODEL_REVISION } from './model.mjs';

env.cacheDir = CACHE_DIR;
env.allowRemoteModels = false;
env.allowLocalModels = true;

const PORT = Number(process.env.PORT ?? 8081);
let extractor = null;
let ready = false;

function json(res, status, body) {
    const payload = JSON.stringify(body);
    res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(payload) });
    res.end(payload);
}

function readBody(req) {
    return new Promise((resolve, reject) => {
        const chunks = [];
        let size = 0;
        req.on('data', (c) => {
            size += c.length;
            if (size > 4 * 1024 * 1024) {
                reject(new Error('payload too large'));
                req.destroy();
                return;
            }
            chunks.push(c);
        });
        req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
        req.on('error', reject);
    });
}

async function embed(inputs) {
    const out = await extractor(inputs, { pooling: 'cls', normalize: true });
    const rows = out.tolist();
    let tokens = 0;
    for (const text of inputs) {
        tokens += extractor.tokenizer(text).input_ids.size;
    }
    return { rows, tokens };
}

const server = http.createServer(async (req, res) => {
    try {
        if (req.method === 'GET' && req.url === '/healthz') {
            return json(res, ready ? 200 : 503, { ok: ready, model: MODEL_ID, revision: MODEL_REVISION, dtype: MODEL_DTYPE, dims: DIMS });
        }
        if (req.method === 'GET' && req.url === '/v1/models') {
            return json(res, 200, { object: 'list', data: [{ id: 'bge-m3', object: 'model', owned_by: 'local', dims: DIMS }] });
        }
        if (req.method === 'POST' && req.url === '/v1/embeddings') {
            if (!ready) {
                return json(res, 503, { error: { message: 'model warming up', type: 'unavailable' } });
            }
            let body;
            try {
                body = JSON.parse(await readBody(req));
            } catch {
                return json(res, 400, { error: { message: 'invalid JSON', type: 'invalid_request_error' } });
            }
            const raw = body?.input;
            const inputs = Array.isArray(raw) ? raw : typeof raw === 'string' ? [raw] : null;
            if (inputs === null || inputs.length === 0 || inputs.length > MAX_INPUTS || inputs.some((s) => typeof s !== 'string' || s.length === 0 || s.length > MAX_CHARS)) {
                return json(res, 400, { error: { message: `input must be 1..${MAX_INPUTS} non-empty strings of ≤ ${MAX_CHARS} chars`, type: 'invalid_request_error' } });
            }
            const { rows, tokens } = await embed(inputs);
            return json(res, 200, {
                object: 'list',
                model: 'bge-m3',
                data: rows.map((embedding, index) => ({ object: 'embedding', index, embedding })),
                usage: { prompt_tokens: tokens, total_tokens: tokens },
            });
        }
        return json(res, 404, { error: { message: 'unknown route', type: 'not_found' } });
    } catch (e) {
        return json(res, 500, { error: { message: String(e?.message ?? e), type: 'server_error' } });
    }
});

server.listen(PORT, '0.0.0.0', async () => {
    console.log(`embeddings sidecar listening on :${PORT} — loading ${MODEL_ID}@${MODEL_REVISION} (${MODEL_DTYPE})`);
    extractor = await pipeline('feature-extraction', MODEL_ID, { dtype: MODEL_DTYPE, revision: MODEL_REVISION });
    const warm = await extractor(['[FIXTURE] warm-up'], { pooling: 'cls', normalize: true });
    if (warm.dims[1] !== DIMS) {
        console.error(`dims mismatch: ${warm.dims[1]} !== ${DIMS}`);
        process.exit(1);
    }
    ready = true;
    console.log('embeddings sidecar READY (offline mode)');
});

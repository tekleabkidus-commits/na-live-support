// Build-time only: download the pinned model into the image so the RUNTIME
// container never reaches the internet (ADR 0018: "no internet access at
// runtime (the model ships in the image)").
import { env, pipeline } from '@huggingface/transformers';
import { CACHE_DIR, DIMS, MODEL_DTYPE, MODEL_ID, MODEL_REVISION } from './model.mjs';

env.cacheDir = CACHE_DIR;
env.allowRemoteModels = true;
env.allowLocalModels = true;

const extractor = await pipeline('feature-extraction', MODEL_ID, { dtype: MODEL_DTYPE, revision: MODEL_REVISION });
const out = await extractor(['[FIXTURE] prefetch sanity'], { pooling: 'cls', normalize: true });
if (out.dims[1] !== DIMS) {
    throw new Error(`prefetch: expected ${DIMS} dims, got ${out.dims[1]}`);
}
console.log(`prefetched ${MODEL_ID}@${MODEL_REVISION} (${MODEL_DTYPE}) → ${DIMS} dims`);

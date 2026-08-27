// ADR 0018 — the ONE place the model is named. Pinned to a Hugging Face commit
// so the served bytes cannot drift; dtype q8 = onnx/model_quantized.onnx.
export const MODEL_ID = 'Xenova/bge-m3';
export const MODEL_REVISION = '4de13258303883538bd53b696b452bf8099f0858';
export const MODEL_DTYPE = 'q8';
export const DIMS = 1024;
export const CACHE_DIR = '/models';
export const MAX_INPUTS = 64;
export const MAX_CHARS = 8000;

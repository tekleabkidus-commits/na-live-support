// Doc 13 T-01 / ADR 0020 — the widget's OWN Vite build: two entries (the vanilla
// loader, the Preact panel), content-hashed immutable files under public/w/
// (long-cache immutables; the per-key shim is a 60 s-cache Laravel route that
// reads this build's manifest), Help and forms as LAZY chunks (NFR-01: core
// ≤ 50 KB gz — the widget-bundle-budget gate reads the manifest, never a
// hand-kept number). No Laravel assets, no Inertia, no Tailwind.
import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';
import { resolve } from 'node:path';

const here = import.meta.dirname;

export default defineConfig({
    plugins: [preact()],
    build: {
        outDir: resolve(here, '../public/w'),
        emptyOutDir: false, // immutables from earlier builds stay on disk — a pointer revert always has its bytes (FR-04)
        manifest: true,
        minify: 'esbuild',
        target: 'es2019',
        cssCodeSplit: true,
        modulePreload: { polyfill: false },
        rollupOptions: {
            input: {
                loader: resolve(here, 'src/loader.ts'),
                panel: resolve(here, 'src/panel/main.tsx'),
            },
            output: {
                entryFileNames: '[name].[hash].js',
                chunkFileNames: '[name].[hash].js',
                assetFileNames: '[name].[hash][extname]',
            },
        },
    },
});

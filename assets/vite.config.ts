import { resolve } from 'node:path';

import { defineConfig, type UserConfig } from 'vite';

/**
 * One config, one entry per build (selected by --mode). Every dist file is
 * self-contained — no shared chunk — so that Webpack Encore, Vite and AssetMapper can
 * all consume it as is, and the prebuilt worker is a classic script usable through
 * importScripts(). Output is unminified and hash-free: dist/ is committed and CI
 * checks it is reproducible (`git diff --exit-code assets/dist`).
 */
const root = import.meta.dirname;

interface Entry {
    entry: string;
    fileName: string;
    format: 'es' | 'iife';
    external?: string[];
    emptyOutDir?: boolean;
}

const entries: Record<string, Entry> = {
    index: { entry: 'src/index.ts', fileName: 'index.js', format: 'es', emptyOutDir: true },
    'service-worker': { entry: 'src/service-worker/index.ts', fileName: 'service-worker.js', format: 'es' },
    controller: { entry: 'src/controllers/web_push_controller.ts', fileName: 'controllers/web_push_controller.js', format: 'es', external: ['@hotwired/stimulus'] },
    standalone: { entry: 'src/service-worker/standalone.ts', fileName: 'web-push-sw.js', format: 'iife' },
};

export default defineConfig(({ mode }): UserConfig => {
    const selected = entries[mode];

    if (!selected) {
        throw new Error(`Unknown build mode "${mode}". Expected one of: ${Object.keys(entries).join(', ')}.`);
    }

    return {
        publicDir: false,
        build: {
            outDir: resolve(root, 'dist'),
            emptyOutDir: selected.emptyOutDir ?? false,
            // Web Push requires Safari 16.4+ / Chrome 50+ anyway: ES2022 needs no helper.
            target: 'es2022',
            minify: false,
            sourcemap: false,
            reportCompressedSize: false,
            copyPublicDir: false,
            lib: {
                entry: resolve(root, selected.entry),
                name: 'WebPushServiceWorker',
                formats: [selected.format],
                fileName: () => selected.fileName,
            },
            rolldownOptions: {
                external: selected.external ?? [],
            },
        },
    };
});

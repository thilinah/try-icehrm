import { defineConfig } from 'vite';

export default defineConfig({
  root: 'web',
  publicDir: 'public',
  // @php-wasm/web imports a few non-JS assets (see its README, "Usage with bundlers").
  assetsInclude: [/\.dat$/, /\.so$/, /\.la$/],
  optimizeDeps: {
    // Required: pre-bundling breaks the loader's new URL('./x/php.wasm', import.meta.url).
    exclude: ['@php-wasm/web', '@php-wasm/universal'],
    // ...but their CommonJS dependencies still need converting to ES modules.
    include: ['@php-wasm/universal > ini', '@php-wasm/web > ini'],
  },
  worker: {
    format: 'es',
  },
  build: {
    outDir: '../dist',
    emptyOutDir: true,
    target: 'es2022',
  },
});

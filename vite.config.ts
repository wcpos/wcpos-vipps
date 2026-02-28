import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { resolve } from 'path';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['assets/src/__tests__/setup.ts'],
  },
  build: {
    outDir: resolve(__dirname, 'assets/dist'),
    emptyOutDir: true,
    lib: {
      entry: resolve(__dirname, 'assets/src/main.tsx'),
      name: 'WcposVipps',
      formats: ['iife'],
      fileName: () => 'payment.js',
    },
    rollupOptions: {
      output: {
        assetFileNames: 'payment.[ext]',
      },
    },
  },
});

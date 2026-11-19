import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  build: {
    target: 'esnext',
    cssCodeSplit: true,
    chunkSizeWarningLimit: 1000,
    rollupOptions: {
      output: {
        manualChunks: {
          'vendor-fluent': ['@fluentui/react-components', '@fluentui/react-icons'],
          'vendor-recharts': ['recharts'],
          'vendor-query': ['@tanstack/react-query'],
          'vendor-router': ['react-router-dom'],
        },
      },
    },
  },
  server: {
    host: '0.0.0.0',
    allowedHosts: true,
    port: 5180,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8021',
        changeOrigin: true,
      },
    },
  },
});

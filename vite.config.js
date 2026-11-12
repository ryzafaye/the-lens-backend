import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [vue(), tailwindcss()],
    build: {
        manifest: true,
        outDir: 'dist',
        emptyOutDir: true,
        rollupOptions: {
            input: {
                inertia: 'src/inertia.js',
            },
        },
    },
    server: {
        cors: true,
    },
});

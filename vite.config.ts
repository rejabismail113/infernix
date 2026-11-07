import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import path from 'node:path';

export default defineConfig(({ mode }) => {
    // The browser loads dev assets from the same host it opened the app on (APP_URL), so the app also works when
    // opened by LAN IP (e.g. http://192.168.1.20:8010) and not only via localhost.
    const env = loadEnv(mode, process.cwd(), '');
    const appUrl = env.APP_URL || 'http://localhost:8010';
    let devHost = 'localhost';
    try {
        devHost = new URL(appUrl).hostname;
    } catch {
        // keep localhost
    }
    const devOrigin = `http://${devHost}:5173`;

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.ts'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: { base: null, includeAbsolute: false },
                },
            }),
        ],
        resolve: {
            alias: { '@': path.resolve(__dirname, 'resources/js') },
        },
        server: {
            host: '0.0.0.0',
            port: 5173,
            strictPort: true,
            origin: devOrigin,
            hmr: { host: devHost },
            cors: { origin: [appUrl.replace(/\/$/, ''), /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/] },
        },
    };
});

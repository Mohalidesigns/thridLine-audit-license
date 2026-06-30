import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        // Bind to IPv4 so the public/hot URL is http://127.0.0.1:5173 (not the
        // IPv6 http://[::1]:5173). Browsing the app via 127.0.0.1/localhost then
        // resolves the dev bundle correctly — otherwise @vite emits an [::1] host
        // the browser can't reach and the SPA renders a blank page.
        host: '127.0.0.1',
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

});

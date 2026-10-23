import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/site.css', 'resources/js/site.js'],
            refresh: true,
        }),
    ],
    build: {
        target: 'es2020',
        cssMinify: true,
        // fonturile mici (<4KB) nu se inline-uiesc; fișierele woff2 rămân cache-uite separat
        assetsInlineLimit: 0,
        modulePreload: { polyfill: false },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

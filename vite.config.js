import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

// Control panel pages (Inertia + Vue). Build with `npm run build`; the output in
// resources/dist/build is committed so installs don't need Node.
export default defineConfig({
    plugins: [
        statamic(),
        laravel({
            input: ['resources/js/addon.js'],
            publicDirectory: 'resources/dist',
            refresh: true,
        }),
    ],
});

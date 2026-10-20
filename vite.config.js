import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath } from 'node:url';
import { execSync } from 'node:child_process';
import { existsSync } from 'node:fs';

// Aurora's prebuilt admin scripts (resources/dist): run `npm run build:aurora` from the app
// root after changing them. Sites that install Aurora use these as they are. Its admin
// theme is Borealis, shipaurora/borealis-theme (packages/borealis), built with its own config.
const resources = fileURLToPath(new URL('./resources', import.meta.url));

// After every build (also each rebuild while watching), copy the files to public/ the way
// `php artisan filament:assets` does, so the admin uses them straight away.
const publish = {
    name: 'aurora-publish',
    closeBundle() {
        if (existsSync('artisan')) {
            execSync('php artisan filament:assets', { stdio: 'inherit' });
        }
    },
};

export default defineConfig({
    plugins: [tailwindcss(), publish],
    // Not the app's public folder: only Aurora's own files go in resources/dist.
    publicDir: false,
    build: {
        outDir: `${resources}/dist`,
        emptyOutDir: true,
        rollupOptions: {
            input: {
                aurora: `${resources}/js/aurora.js`,
            },
            output: {
                entryFileNames: '[name].js',
                assetFileNames: '[name][extname]',
            },
        },
    },
});

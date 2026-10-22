import { defineConfig } from 'vite';
import { fileURLToPath } from 'node:url';

// Aurora's prebuilt admin scripts (resources/dist): run `npm run build` after changing them,
// and commit the result. Sites that install Aurora use these as they are.
const resources = fileURLToPath(new URL('./resources', import.meta.url));

export default defineConfig({
    // Not a public folder: only Aurora's own files go in resources/dist.
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

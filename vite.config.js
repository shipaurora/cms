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
                // The canvas's rich text editor, loaded when it's first needed (admin/canvas-rich-text.js).
                'rich-text': `${resources}/js/rich-text.js`,
            },
            // rich-text.js is imported by the admin's script: its exports stay.
            preserveEntrySignatures: 'exports-only',
            output: {
                entryFileNames: '[name].js',
                assetFileNames: '[name][extname]',
            },
        },
    },
});

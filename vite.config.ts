import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import fastGlob from 'fast-glob';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig, lazyPlugins } from 'vite-plus';

const moduleEntries = fastGlob.sync('Modules/*/resources/js/entries/*.ts', {
    cwd: fileURLToPath(new URL('.', import.meta.url)),
    absolute: true,
});

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    plugins: lazyPlugins(() => [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                'resources/css/filament/admin/theme.css',
                'resources/css/filament/finance/theme.css',
                ...moduleEntries,
            ],
            refresh: true,
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
    ]),
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        ignorePatterns: [
            'composer.json',
            'package.json',
            'tsconfig.json',
            'version.json',
            'modules_statuses.json',
            '.starter-kit.json',
            'opencode.json',
            'boost.json',
            '.ai/**',
            '.agent/**',
            '.agents/**',
            '.augment/**',
            '.claude/**',
            '.gemini/**',
            '.opencode/**',
            '.github/**',
            'public/**',
            '**/*.md',
            'resources/js/actions/**',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
    },
});

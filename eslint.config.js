import pluginVue from 'eslint-plugin-vue';
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettierConfig from '@vue/eslint-config-prettier';

export default defineConfigWithVueTs(
    {
        ignores: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'storage/**',
            'bootstrap/**',
            'playwright-report/**',
            'test-results/**',
        ],
    },
    pluginVue.configs['flat/recommended'],
    vueTsConfigs.recommended,
    prettierConfig,
    {
        // The widget is Preact (ADR 0020): JSX in .tsx, no Vue rules apply.
        files: ['widget/src/**/*.{ts,tsx}'],
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
    {
        // Inertia page components are addressed by file path (Pages/Welcome),
        // so single-word filenames are the framework convention, not a smell.
        files: ['resources/js/Pages/**/*.vue'],
        rules: {
            'vue/multi-word-component-names': 'off',
        },
    },
);

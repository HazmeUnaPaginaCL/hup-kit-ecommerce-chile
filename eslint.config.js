/**
 * Configuración de ESLint para HUP - Kit Ecommerce Chile
 *
 * Objetivo principal: detectar referencias a funciones o variables que no existen
 * (regla no-undef). Ese fue exactamente el error que rompió el selector de comunas
 * al extraer la información de entrega: quedó una llamada a una función ya borrada,
 * y como `node --check` solo valida sintaxis, pasó desapercibida hasta las pruebas.
 *
 * Uso:
 *   npm run lint      → revisa
 *   npm run lint:fix  → corrige lo que se pueda automáticamente
 */

module.exports = [
    {
        files: [ 'assets/js/**/*.js' ],

        languageOptions: {
            ecmaVersion: 2021,
            sourceType: 'script', // scripts encolados, no módulos ES

            globals: {
                // Navegador
                window: 'readonly',
                document: 'readonly',
                console: 'readonly',
                setTimeout: 'readonly',
                clearTimeout: 'readonly',
                setInterval: 'readonly',
                clearInterval: 'readonly',
                confirm: 'readonly',
                alert: 'readonly',
                sessionStorage: 'readonly',
                localStorage: 'readonly',
                FormData: 'readonly',
                URLSearchParams: 'readonly',

                // WordPress / WooCommerce
                jQuery: 'readonly',
                wp: 'readonly',
                ajaxurl: 'readonly',

                // Objetos que el plugin inyecta con wp_localize_script.
                // Si añades un wp_localize_script nuevo, decláralo también aquí.
                hupkecAdmin: 'readonly',
                hupkec_data: 'readonly',
                hupkec_billing_data: 'readonly',
            },
        },

        rules: {
            // La razón de ser de esta configuración
            'no-undef': 'error',

            // Errores reales que es fácil introducir editando a mano.
            // caughtErrors 'none': los catch(e) que no usan el error son idiomáticos aquí.
            'no-unused-vars': [ 'warn', { args: 'none', caughtErrors: 'none' } ],
            'no-dupe-keys': 'error',
            'no-dupe-args': 'error',
            'no-duplicate-case': 'error',
            'no-unreachable': 'error',
            'no-cond-assign': 'error',
            'no-constant-condition': 'error',
            'no-empty': [ 'error', { allowEmptyCatch: true } ],
            'no-fallthrough': 'error',
            'no-func-assign': 'error',
            'no-irregular-whitespace': 'error',
            'no-sparse-arrays': 'error',
            'use-isnan': 'error',
            'valid-typeof': 'error',

            // No debe quedar depuración en producción
            'no-console': 'warn',
            'no-debugger': 'error',
            // confirm() antes de una acción destructiva es un patrón normal del
            // admin de WordPress, no un descuido
            'no-alert': 'off',

            // Buenas prácticas mínimas, sin imponer estilo
            eqeqeq: [ 'warn', 'smart' ],
            'no-eval': 'error',
            'no-implied-eval': 'error',
        },
    },
];

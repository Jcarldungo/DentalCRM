import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },

            colors: {
                /*
                 * The staff app's primary. A desaturated navy-blue that
                 * reads as clinical software rather than marketing, and is
                 * deliberately unrelated to the public site's teal — the
                 * two surfaces stay visually separate (see CLAUDE.md).
                 *
                 * brand-600 on white is ~7:1, so it carries both body text
                 * and white-on-button.
                 */
                brand: {
                    50: '#eff4fb',
                    100: '#dbe7f6',
                    200: '#bdd2ec',
                    300: '#92b4df',
                    400: '#608ecd',
                    500: '#3e6fb8',
                    600: '#2a54a0',
                    700: '#244683',
                    800: '#223c6d',
                    900: '#20355b',
                    950: '#15223b',
                },
            },

            maxWidth: {
                /* The one staff-app content width. */
                shell: '88rem',
            },

            keyframes: {
                'toast-in': {
                    from: { opacity: '0', transform: 'translateY(-6px)' },
                    to: { opacity: '1', transform: 'none' },
                },
            },

            animation: {
                'toast-in': 'toast-in 160ms ease-out',
            },
        },
    },

    plugins: [forms],
};

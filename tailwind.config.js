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

                /*
                 * The sidebar is the one dark surface in the staff app, so
                 * its five values live here as named roles rather than as a
                 * numeric scale — there is no "sidebar-300" to reach for,
                 * only a background, a raised state, a divider, and two
                 * text weights. Naming them stops the shell from spelling
                 * out near-black hexes inline, and stops a second dark
                 * surface inventing its own.
                 *
                 * Cooled toward the brand navy rather than pure grey, so
                 * the rail and the brand mark read as the same family.
                 *
                 * `text` is 8.3:1 on `DEFAULT` and `muted` is 4.7:1 — the
                 * latter carries the 11px uppercase group labels, which is
                 * text and so has to clear 4.5:1. The obvious darker grey
                 * lands at 4.0:1 and would have quietly failed.
                 */
                sidebar: {
                    DEFAULT: '#131a2a',
                    raised: '#1e273b',
                    border: '#2a3347',
                    text: '#aab4c8',
                    muted: '#7b8699',
                },
            },

            maxWidth: {
                /* The one staff-app content width. */
                shell: '88rem',
            },

            boxShadow: {
                /*
                 * The card elevation. Two very shallow layers rather than
                 * one deep one: cards sit directly on `slate-50` and are
                 * often stacked a few pixels apart, where a single soft
                 * shadow turns into a grey haze between them.
                 */
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.05)',
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

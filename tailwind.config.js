import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Apple light theme via palette remap — 2026-09-02.
 *
 * The app was born dark (zinc-950 canvas, amber safelight accent). The
 * remap inverts the neutral ramp and re-tints the accents so every
 * existing utility class (bg-zinc-900/60, text-zinc-500, focus:ring-
 * amber-400/60, …) keeps compiling but renders the Apple look:
 *   - zinc ramp inverted: zinc-950→#F5F5F7 canvas, zinc-900→white cards,
 *     zinc-700→#D2D2D7 hairlines, zinc-500→#6E6E73 secondary text,
 *     zinc-100→#1D1D1F primary ink. Dark-side utilities (bg-zinc-950/40
 *     film wells, bg-black/50 overlays on photos) become subtle
 *     translucent grays on white.
 *   - amber→blue: the safelight accent becomes Apple system blue.
 *     amber-400 ≈ #0071E3 (primary CTA), amber-300 ≈ #0077ED (hover).
 *     Class names (and the test asserting text-amber-400) unchanged.
 *   - emerald→green, rose→red, sky→tinted blue per Apple system colors.
 *     Severity/status semantics preserved 1:1.
 *   - ring-offset + modal backdrop follow the new canvas.
 *
 * Why remap instead of find/replace over 500+ class instances: one
 * source of truth, zero drift between pages, trivially reversible
 * (delete the colors block), and the test-locked class vocabulary
 * stays intact.
 */
const apple = {
    zinc: {
        // inverted ramp: high step = light surface, low step = dark ink
        50: '#1D1D1F',
        100: '#1D1D1F',
        200: '#3A3A3C',
        300: '#48484A',
        400: '#6E6E73',
        500: '#6E6E73',
        600: '#8E8E93',
        700: '#D2D2D7',
        800: '#E5E5EA',
        900: '#FFFFFF',
        950: '#F5F5F7',
    },
    amber: {
        200: '#3395FF',
        300: '#0077ED',
        400: '#0071E3',
        500: '#0066CC',
        900: '#003B7A',
    },
    emerald: {
        300: '#34C759',
        400: '#30B450',
        500: '#34C759',
        600: '#1B7A3D',
    },
    rose: {
        300: '#E0457A',
        400: '#D93F5C',
        500: '#FF3B30',
        600: '#D70015',
        700: '#B00020',
        950: '#FFF0F0',
    },
    sky: {
        300: '#409CFF',
        400: '#007AFF',
        500: '#007AFF',
        600: '#0A84FF',
    },
    red: {
        300: '#E0457A',
        400: '#D93F5C',
        500: '#FF3B30',
    },
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.tsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['-apple-system', 'BlinkMacSystemFont', 'SF Pro Text', 'SF Pro Display', 'Helvetica Neue', ...defaultTheme.fontFamily.sans],
            },
            colors: apple,
        },
    },

    plugins: [forms],
};
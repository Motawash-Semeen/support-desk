import defaultTheme from 'tailwindcss/defaultTheme';
import colors from 'tailwindcss/colors';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/Enums/**/*.php',
        // L5Modular modules keep their own views and enums (colour names).
        './app/Modules/**/*.blade.php',
        './app/Modules/*/Enums/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Brand: a calm teal that reads as "resolved", with slate as the ink.
                brand: colors.teal,
                ink: colors.slate,
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)',
                lift: '0 10px 30px -12px rgb(15 23 42 / 0.25)',
            },
        },
    },

    plugins: [forms],
};

import type { Config } from 'tailwindcss';
import defaultTheme from 'tailwindcss/defaultTheme';

/**
 * Infernix Evolv brand tokens. Primary gradient #FF6A00 -> #FFA000 (docs/reference/infernix-logo.jpg).
 * Neutral grey surfaces follow the reference screenshots (docs/reference/).
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.ts',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#FFF4E8',
                    100: '#FFE4C7',
                    200: '#FFC994',
                    300: '#FFAE5C',
                    400: '#FFA000',
                    500: '#FF8A00',
                    // 600-900 darkened for WCAG AA (NFR-09): white on 600 ≥ 4.8:1, 700 text on white ≥ 6.3:1.
                    600: '#B84A00',
                    700: '#A34100',
                    800: '#7F3300',
                    900: '#5C2500',
                },
                surface: {
                    0: '#FFFFFF',
                    50: '#F8F9FB',
                    100: '#F1F3F6',
                    200: '#E4E7EC',
                    300: '#CFD4DC',
                    400: '#6B7385', // used for small helper text: ≥ 4.5:1 on white / surface-50 (NFR-09)
                    500: '#5B6372',
                    700: '#374151',
                    900: '#111827',
                },
            },
            // No external font CDN (offline-capable, no third-party requests); Inter is used when installed.
            fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Roboto', ...defaultTheme.fontFamily.sans] },
        },
    },
    plugins: [],
} satisfies Config;

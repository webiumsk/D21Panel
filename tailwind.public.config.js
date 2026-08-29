import defaultTheme from 'tailwindcss/defaultTheme';

/** Tailwind config scoped to public/marketing pages (smaller CSS bundle). */
/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/public.blade.php',
        './resources/views/partials/landing-hero-shell.blade.php',
        './resources/js/pages/Landing.vue',
        './resources/js/pages/Pricing.vue',
        './resources/js/pages/BillingSuccess.vue',
        './resources/js/pages/Support.vue',
        './resources/js/pages/auth/**/*.vue',
        // Modals/panels the auth pages render (passkey enroll offer, guest
        // backup/restore). Without this their unique classes (e.g. z-[100])
        // are missing from the public bundle and the modals stack wrong.
        './resources/js/components/auth/**/*.vue',
        './resources/js/pages/legal/**/*.vue',
        './resources/js/pages/documentation/**/*.vue',
        './resources/js/pages/faq/**/*.vue',
        './resources/js/components/layout/PublicHeader.vue',
        './resources/js/components/layout/AppFooter.vue',
        './resources/js/components/legal/**/*.vue',
        './resources/js/components/landing/**/*.vue',
        './resources/js/components/ui/FlashMessage.vue',
        './resources/js/AppPublic.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Outfit', ...defaultTheme.fontFamily.sans],
            },
            keyframes: {
                blob: {
                    '0%, 100%': { transform: 'translate(0, 0) scale(1)' },
                    '33%': { transform: 'translate(28px, -36px) scale(1.06)' },
                    '66%': { transform: 'translate(-22px, 18px) scale(0.94)' },
                },
                'sf-float': {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%': { transform: 'translateY(-10px)' },
                },
                'sf-orbit': {
                    '0%': { transform: 'rotate(0deg)' },
                    '100%': { transform: 'rotate(360deg)' },
                },
                'sf-shimmer': {
                    '0%': { backgroundPosition: '200% 50%' },
                    '100%': { backgroundPosition: '-200% 50%' },
                },
                'sf-rise': {
                    '0%': { opacity: '0', transform: 'translateY(18px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
            },
            animation: {
                blob: 'blob 8s ease-in-out infinite',
                'sf-float': 'sf-float 5s ease-in-out infinite',
                'sf-float-slow': 'sf-float 7s ease-in-out 0.5s infinite',
                'sf-orbit': 'sf-orbit 28s linear infinite',
                'sf-shimmer': 'sf-shimmer 6s linear infinite',
                'sf-rise': 'sf-rise 0.75s cubic-bezier(0.22, 1, 0.36, 1) both',
            },
        },
    },
    plugins: [],
};

import '../css/app.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PrimeVue from 'primevue/config';
import Aura from '@primevue/themes/aura';
import { definePreset } from '@primevue/themes';
import { ZiggyVue } from 'ziggy-js';

const appName = import.meta.env.VITE_APP_NAME || 'Infernix Workspace';

/** Infernix Evolv orange as PrimeVue primary (docs/reference/infernix-logo.jpg). */
const InfernixPreset = definePreset(Aura, {
    semantic: {
        primary: {
            50: '#FFF4E8', 100: '#FFE4C7', 200: '#FFC994', 300: '#FFAE5C', 400: '#FFA000',
            500: '#FF8A00', 600: '#FF6A00', 700: '#D95700', 800: '#A84300', 900: '#7A3000', 950: '#4A1D00',
        },
    },
});

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(PrimeVue, { theme: { preset: InfernixPreset, options: { darkModeSelector: '.app-dark' } } })
            .mount(el);
    },
    progress: { color: '#FF6A00' },
});

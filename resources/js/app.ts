import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import ServerProblemDialog from './components/ServerProblemDialog.vue';
import { initializeTheme } from './composables/useAppearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        // The server problem dialog sits beside the pages so it also covers the lock screen.
        createApp({ render: () => [h(App, props), h(ServerProblemDialog)] })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        // Follows the accent colour (see app.css).
        color: 'hsl(var(--primary))',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

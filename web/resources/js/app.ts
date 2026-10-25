import '../css/app.css';
import { createPinia } from 'pinia';
import './workspace.css';
import { createApp, h, type DefineComponent } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';

createInertiaApp({
    title: title => title ? `${title} · RoseWatch` : 'RoseWatch',
    resolve: name => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).use(createPinia()).mount(el);
    },
    progress: { color: '#e31b17' },
});

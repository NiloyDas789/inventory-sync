import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { AppProvider } from '@shopify/polaris';
import '@shopify/polaris/build/esm/styles.css';
import enTranslations from '@shopify/polaris/locales/en.json';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { Navigation } from './components/Navigation';

const appName = import.meta.env.VITE_APP_NAME || 'Inventory Sync';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
                <AppProvider i18n={enTranslations}>
                    <Navigation />
                    <App {...props} />
                </AppProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

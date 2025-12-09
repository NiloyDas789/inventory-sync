import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import '@shopify/polaris/build/esm/styles.css';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { ErrorBoundary } from './components/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Inventory Sync';

/**
 * Inertia.js Application Setup
 *
 * This is the root setup for the Inertia.js application.
 * - ErrorBoundary wraps everything to catch React errors
 * - Inertia's App component is the direct parent of all pages
 * - AppProvider and Navigation are moved to AppLayout component
 *   (which is used by individual pages) to ensure proper Inertia context
 */
createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

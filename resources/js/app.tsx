import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { AppProvider } from '@shopify/polaris';
import '@shopify/polaris/build/esm/styles.css';
import { ErrorBoundary } from './components/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Inventory Sync';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <AppProvider
                    i18n={{
                        Polaris: {
                            Avatar: {
                                label: 'Avatar',
                                labelWithInitials: 'Avatar with initials {initials}',
                            },
                            ContextualSaveBar: {
                                save: 'Save',
                                discard: 'Discard',
                            },
                            TextField: {
                                characterCount: '{count} characters',
                            },
                            TopBar: {
                                toggleMenuLabel: 'Toggle menu',
                            },
                        },
                    }}
                >
                    <App {...props} />
                </AppProvider>
            </ErrorBoundary>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

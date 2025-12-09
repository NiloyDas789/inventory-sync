import { AppProvider, Frame } from '@shopify/polaris';
import enTranslations from '@shopify/polaris/locales/en.json';
import { ReactNode } from 'react';
import { Navigation } from '../components/Navigation';

interface AppLayoutProps {
    children: ReactNode;
}

/**
 * AppLayout Component
 *
 * This component provides the main application layout structure:
 * - Wraps the entire app with Shopify Polaris AppProvider for i18n and theming
 * - Includes the Navigation component in a Frame sidebar
 * - Provides a flex layout with navigation sidebar and main content area
 * - Passes children (page content) to the main content area
 *
 * This layout ensures that:
 * - All Polaris components have access to i18n context
 * - Navigation is consistently available across all pages
 * - Inertia's usePage() hook works correctly inside Navigation
 */
export function AppLayout({ children }: AppLayoutProps) {
    return (
        <AppProvider i18n={enTranslations}>
            <Frame navigation={<Navigation />}>
                {children}
            </Frame>
        </AppProvider>
    );
}


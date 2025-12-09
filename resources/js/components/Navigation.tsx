import { router } from '@inertiajs/react';
import { Navigation as PolarisNavigation } from '@shopify/polaris';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import {
    HomeIcon,
    DataTableIcon,
    LayoutColumns2Icon,
    RefreshIcon,
} from '@shopify/polaris-icons';

interface NavigationItem {
    url: string;
    label: string;
    icon: React.ComponentType;
}

/**
 * Navigation Component
 * 
 * Provides the main navigation sidebar for the application.
 * - Uses Inertia's usePage() hook to determine active route
 * - Uses Inertia's router.visit() for client-side navigation
 * - Consolidates all navigation items into a single Navigation.Section
 * - Uses proper Shopify Polaris icons from @shopify/polaris-icons
 * 
 * This component must be used inside AppLayout (which provides AppProvider context)
 * to ensure usePage() hook works correctly.
 */
export function Navigation() {
    const { url } = usePage();

    const navigationItems: NavigationItem[] = useMemo(
        () => [
            {
                url: '/dashboard',
                label: 'Dashboard',
                icon: HomeIcon,
            },
            {
                url: '/google-sheets',
                label: 'Google Sheets',
                icon: DataTableIcon,
            },
            {
                url: '/field-mapping',
                label: 'Field Mapping',
                icon: LayoutColumns2Icon,
            },
            {
                url: '/sync-management',
                label: 'Sync Management',
                icon: RefreshIcon,
            },
        ],
        []
    );

    const handleNavigation = (url: string) => {
        router.visit(url);
    };

    return (
        <PolarisNavigation location="/">
            <PolarisNavigation.Section
                items={navigationItems.map((item) => ({
                    url: item.url,
                    label: item.label,
                    icon: item.icon as any,
                    selected: url === item.url || url.startsWith(item.url + '/'),
                    onClick: () => handleNavigation(item.url),
                }))}
            />
        </PolarisNavigation>
    );
}


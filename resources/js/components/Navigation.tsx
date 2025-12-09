import { router } from '@inertiajs/react';
import { Navigation as PolarisNavigation } from '@shopify/polaris';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

interface NavigationItem {
    url: string;
    label: string;
    icon?: string;
}

export function Navigation() {
    const { url } = usePage();

    const navigationItems: NavigationItem[] = useMemo(
        () => [
            {
                url: '/dashboard',
                label: 'Dashboard',
                icon: 'home',
            },
            {
                url: '/google-sheets',
                label: 'Google Sheets',
                icon: 'table',
            },
            {
                url: '/field-mapping',
                label: 'Field Mapping',
                icon: 'columns',
            },
            {
                url: '/sync-management',
                label: 'Sync Management',
                icon: 'sync',
            },
        ],
        []
    );

    const handleNavigation = (url: string) => {
        router.visit(url);
    };

    return (
        <PolarisNavigation location="/">
            {navigationItems.map((item) => (
                <PolarisNavigation.Section
                    key={item.url}
                    items={[
                        {
                            url: item.url,
                            label: item.label,
                            icon: item.icon as any,
                            selected: url === item.url || url.startsWith(item.url + '/'),
                            onClick: () => handleNavigation(item.url),
                        },
                    ]}
                />
            ))}
        </PolarisNavigation>
    );
}


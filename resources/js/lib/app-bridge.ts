import { createApp } from '@shopify/app-bridge';
import { getSessionToken as getAppBridgeSessionToken } from '@shopify/app-bridge-utils';

// Get shop origin from URL or window
function getShopOrigin(): string {
    const urlParams = new URLSearchParams(window.location.search);
    const shop = urlParams.get('shop');
    
    if (shop) {
        return shop.includes('.myshopify.com') ? shop : `${shop}.myshopify.com`;
    }
    
    // Try to get from hostname
    const hostname = window.location.hostname;
    if (hostname.includes('myshopify.com')) {
        return hostname;
    }
    
    // Fallback - should be set by backend
    return window.location.hostname;
}

// Get API key from meta tag or environment
function getApiKey(): string {
    const metaTag = document.querySelector('meta[name="shopify-api-key"]');
    if (metaTag) {
        return metaTag.getAttribute('content') || '';
    }
    
    // Fallback to environment variable
    return import.meta.env.VITE_SHOPIFY_API_KEY || '';
}

// Create App Bridge instance
export const app = createApp({
    apiKey: getApiKey(),
    host: new URLSearchParams(window.location.search).get('host') || '',
    forceRedirect: true,
});

// Get session token for API requests
export async function getSessionToken(): Promise<string> {
    try {
        const token = await getAppBridgeSessionToken(app);
        return token;
    } catch (error) {
        console.error('Failed to get session token:', error);
        throw error;
    }
}

export default app;


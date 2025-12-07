<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddShopifySecurityHeaders
{
    /**
     * Handle an incoming request.
     *
     * Add Content Security Policy headers for Shopify iframe protection.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Set CSP headers for embedded app security
        // frame-ancestors allows the page to be embedded in Shopify admin
        $response->headers->set('Content-Security-Policy',
            "frame-ancestors 'self' https://*.myshopify.com https://admin.shopify.com;"
        );

        return $response;
    }
}

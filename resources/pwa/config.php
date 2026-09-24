<?php

/**
 * PWA Configuration for SFPHP
 *
 * This file configures all Progressive Web App settings:
 * - Manifest (name, icons, colors)
 * - Service Worker (caching strategies)
 * - Offline support
 * - Push notifications
 * - Background sync
 */

return [
    // Enable/disable PWA completely
    'enabled' => env('PWA_ENABLED', true),

    // App identification
    'name' => env('APP_NAME', 'My App'),
    'short_name' => env('APP_SHORT_NAME', 'App'),
    'description' => env('APP_DESCRIPTION', ''),

    // Visual appearance
    'theme_color' => env('PWA_THEME_COLOR', '#007AFF'),
    'background_color' => env('PWA_BG_COLOR', '#ffffff'),

    // Installation behavior
    'display' => env('PWA_DISPLAY', 'standalone'), // standalone, fullscreen, minimal-ui, browser
    'start_url' => env('PWA_START_URL', '/'),
    'scope' => env('PWA_SCOPE', '/'),
    'orientation' => env('PWA_ORIENTATION', 'portrait-primary'), // portrait-primary, landscape-primary, etc

    // Caching strategy
    'cache' => [
        // Cache name with version (change version to bust cache)
        'name' => env('PWA_CACHE_NAME', 'sfphp-app-v1'),

        // Static assets: use cache-first strategy
        'static_assets' => [
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
            '/index.html',
        ],

        // API routes: use network-first strategy
        'api_routes' => [
            '/api/*',
        ],

        // Fallback when offline
        'offline_fallback' => '/offline.html',
    ],

    // Advanced features
    'push_notifications' => env('PWA_PUSH_NOTIFICATIONS', false),
    'background_sync' => env('PWA_BACKGROUND_SYNC', false),
];

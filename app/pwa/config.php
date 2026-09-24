<?php

/**
 * PWA (Progressive Web App) Configuration
 *
 * Customize your PWA behavior here. These settings are used by the Service Worker
 * and Web App Manifest to define how your app appears and behaves offline.
 */

return [
    /**
     * App Name
     * Displayed in app stores and OS menus
     */
    'name' => env('APP_NAME', 'SFPHP Application'),

    /**
     * Short Name
     * Used when space is limited (up to 12 characters)
     */
    'short_name' => env('APP_SHORT_NAME', mb_substr(env('APP_NAME', 'SFPHP App'), 0, 12)),

    /**
     * Description
     * Describes your app purpose
     */
    'description' => env('APP_DESCRIPTION', ''),

    /**
     * Start URL
     * Where the app opens when installed
     */
    'start_url' => '/',

    /**
     * Scope
     * Which URLs are considered part of the app
     */
    'scope' => '/',

    /**
     * Display Mode
     * Options: 'fullscreen', 'standalone', 'minimal-ui', 'browser'
     */
    'display' => 'standalone',

    /**
     * Theme Color
     * Used in browser UI and app themes
     */
    'theme_color' => env('PWA_THEME_COLOR', '#007AFF'),

    /**
     * Background Color
     * Shown while app loads
     */
    'background_color' => env('PWA_BACKGROUND_COLOR', '#ffffff'),

    /**
     * Orientation
     * Default: 'portrait-primary'
     */
    'orientation' => 'portrait-primary',

    /**
     * Service Worker Settings
     */
    'service_worker' => [
        /**
         * Cache version
         * Increment this to invalidate caches when you update your app
         */
        'version' => 'v1',

        /**
         * Static Assets to Cache
         * These files are cached on install with cache-first strategy
         */
        'static_assets' => [
            '/assets/css/sfcss.min.css',
            '/assets/js/sfjs.min.js',
            '/',
            '/offline.html',
        ],

        /**
         * API Routes to Handle (No caching by default for privacy)
         * Leave empty [] to disable API caching
         * Add routes like '/api/*' to cache API responses (not recommended for private data)
         */
        'api_routes' => [],

        /**
         * Offline Fallback Page
         * Shown when no connection and page not cached
         */
        'offline_fallback' => '/offline.html',

        /**
         * Enable Push Notifications
         */
        'enable_push_notifications' => false,

        /**
         * Enable Background Sync
         * Syncs offline changes when connection returns
         */
        'enable_background_sync' => false,
    ],

    /**
     * Icons Configuration
     * Multiple sizes for different devices
     */
    'icons' => [
        [
            'src' => '/assets/icons/icon-192x192.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => '/assets/icons/icon-512x512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
    ],
];

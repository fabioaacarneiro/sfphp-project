/**
 * Service Worker Installation & Registration
 *
 * make:pwa copies this file to public/install-sw.js; it does not edit your
 * templates, so include it in your layout yourself:
 * <script src="/install-sw.js" defer></script>
 *
 * Icons are referenced under /assets/icons/, where make:pwa --logo writes them.
 */

(function() {
    // Check if service workers are supported
    if (!('serviceWorker' in navigator)) {
        console.log('Service Workers are not supported in this browser');
        return;
    }

    // Wait for page to load before registering
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', registerServiceWorker);
    } else {
        registerServiceWorker();
    }

    async function registerServiceWorker() {
        try {
            const registration = await navigator.serviceWorker.register('/service-worker.js', {
                scope: '/'
            });

            console.log('✓ Service Worker registered:', registration);

            // Listen for updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New service worker available, notify user
                        notifyUpdate();
                    }
                });
            });

            // Periodic update check (every hour)
            setInterval(() => {
                registration.update();
            }, 60 * 60 * 1000);

        } catch (error) {
            console.error('✗ Service Worker registration failed:', error);
        }
    }

    function notifyUpdate() {
        console.log('New app version available');

        // Emit custom event
        window.dispatchEvent(new CustomEvent('pwa:update-available'));

        // Optional: Show notification to user
        // Checked with `in`: where the API is missing (Safari outside an
        // installed app), naming Notification throws a ReferenceError.
        if ('Notification' in window && Notification.permission === 'granted') {
            // Through the registration, not `new Notification()`, which
            // Chrome on Android refuses with "Illegal constructor".
            navigator.serviceWorker.ready.then(registration => registration.showNotification('App Update Available', {
                body: 'A new version of the app is available. Please refresh to update.',
                icon: '/assets/icons/icon-192x192.png',
                tag: 'app-update',
            }));
        }
    }

    // Request permission for push notifications (if enabled)
    if ('Notification' in window && Notification.permission === 'default') {
        // Don't auto-request, let user decide
        // Uncomment to auto-request:
        // Notification.requestPermission();
    }

    // Expose API for manual operations
    window.pwa = {
        // Unregister service worker
        unregister: async function() {
            const registrations = await navigator.serviceWorker.getRegistrations();
            for (const reg of registrations) {
                await reg.unregister();
            }
            console.log('Service Worker unregistered');
        },

        // Clear all caches
        clearCache: async function() {
            const cacheNames = await caches.keys();
            await Promise.all(cacheNames.map(name => caches.delete(name)));
            console.log('All caches cleared');
        },

        // Request push notification permission
        requestNotifications: async function() {
            if (!('Notification' in window)) {
                console.error('Notifications not supported');
                return false;
            }

            if (Notification.permission === 'granted') {
                console.log('Notifications already enabled');
                return true;
            }

            if (Notification.permission === 'denied') {
                console.error('Notifications denied by user');
                return false;
            }

            const permission = await Notification.requestPermission();
            return permission === 'granted';
        },

        // Send test notification
        testNotification: async function() {
            if (Notification.permission !== 'granted') {
                console.error('Notification permission not granted');
                return;
            }

            const registration = await navigator.serviceWorker.ready;
            await registration.showNotification('SFPHP PWA', {
                body: 'This is a test notification',
                icon: '/assets/icons/icon-192x192.png',
                badge: '/assets/icons/badge-72x72.png',
                tag: 'test-notification',
            });
        },

        // Get cache info
        getCacheInfo: async function() {
            const cacheNames = await caches.keys();
            const info = {};

            for (const name of cacheNames) {
                const cache = await caches.open(name);
                const keys = await cache.keys();
                info[name] = keys.length + ' items';
            }

            return info;
        },

        // Check online status
        isOnline: function() {
            return navigator.onLine;
        },

        // Listen for online/offline events
        onOnline: function(callback) {
            window.addEventListener('online', callback);
        },

        onOffline: function(callback) {
            window.addEventListener('offline', callback);
        },
    };

    // Listen for connection changes
    window.addEventListener('online', () => {
        console.log('✓ Back online');
        window.dispatchEvent(new CustomEvent('pwa:online'));
    });

    window.addEventListener('offline', () => {
        console.log('✗ Went offline');
        window.dispatchEvent(new CustomEvent('pwa:offline'));
    });
})();

<?php

namespace SfphpProject\src\Pwa;

/**
 * Generates a Service Worker for PWA offline support and caching.
 *
 * Handles:
 * - Cache-first strategy (static assets)
 * - Network-first strategy (API calls)
 * - Offline fallback pages
 * - Push notifications
 * - Background sync
 */
final class ServiceWorkerGenerator
{
    private string $appName = 'sfphp-app';

    /** @var array<int, string> */
    private array $staticAssets = [
        '/css/sfcss.min.css',
        '/js/sfjs.min.js',
        '/index.html',
    ];

    /** @var array<int, string> */
    private array $apiRoutes = ['/api/*'];

    private string $offlineFallback = '/offline.html';
    private bool $enablePushNotifications = false;
    private bool $enableBackgroundSync = false;

    public function __construct(private string $version = 'v1') {}

    public function appName(string $name): self
    {
        $this->appName = $name;
        return $this;
    }

    public function staticAssets(array $assets): self
    {
        $this->staticAssets = $assets;
        return $this;
    }

    public function apiRoutes(array $routes): self
    {
        $this->apiRoutes = $routes;
        return $this;
    }

    public function offlineFallback(string $path): self
    {
        $this->offlineFallback = $path;
        return $this;
    }

    public function enablePushNotifications(bool $enable = true): self
    {
        $this->enablePushNotifications = $enable;
        return $this;
    }

    public function enableBackgroundSync(bool $enable = true): self
    {
        $this->enableBackgroundSync = $enable;
        return $this;
    }

    public function generate(): string
    {
        $cacheName = "'{$this->appName}-{$this->version}'";
        $staticAssets = json_encode($this->staticAssets);
        $apiRoutes = json_encode($this->apiRoutes);
        $offlineFallback = json_encode($this->offlineFallback);

        $pushNotifications = $this->enablePushNotifications ? $this->generatePushNotifications() : '';
        $backgroundSync = $this->enableBackgroundSync ? $this->generateBackgroundSync() : '';

        return <<<'JAVASCRIPT'
const CACHE_NAME = {CACHE_NAME};
const STATIC_ASSETS = {STATIC_ASSETS};
const API_ROUTES = {API_ROUTES};
const OFFLINE_FALLBACK = {OFFLINE_FALLBACK};

// Install event: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.log('Some assets failed to cache:', err);
        // Continue even if some assets fail
        return Promise.resolve();
      });
    }).then(() => self.skipWaiting())
  );
});

// Activate event: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(name => {
          if (name !== CACHE_NAME) {
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event: cache-first for static, network-first for API
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Static assets: cache-first
  if (isStaticAsset(url.pathname)) {
    event.respondWith(
      caches.match(request).then(response => {
        return response || fetch(request).then(networkResponse => {
          return caches.open(CACHE_NAME).then(cache => {
            cache.put(request, networkResponse.clone());
            return networkResponse;
          });
        }).catch(() => caches.match(OFFLINE_FALLBACK));
      })
    );
    return;
  }

  // API routes: network-first
  if (isApiRoute(url.pathname)) {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (!response.ok) throw new Error('Network response failed');
          return caches.open(CACHE_NAME).then(cache => {
            cache.put(request, response.clone());
            return response;
          });
        })
        .catch(() => {
          return caches.match(request) || caches.match(OFFLINE_FALLBACK);
        })
    );
    return;
  }

  // Default: network-first
  event.respondWith(
    fetch(request)
      .catch(() => caches.match(request) || caches.match(OFFLINE_FALLBACK))
  );
});

// Check if URL is a static asset
function isStaticAsset(pathname) {
  return /\.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i.test(pathname);
}

// Check if URL matches API routes
function isApiRoute(pathname) {
  return API_ROUTES.some(route => {
    const pattern = route.replace(/\*/g, '.*');
    return new RegExp(`^${pattern}$`).test(pathname);
  });
}

{PUSH_NOTIFICATIONS}{BACKGROUND_SYNC}
JAVASCRIPT;

        return str_replace(
            ['{CACHE_NAME}', '{STATIC_ASSETS}', '{API_ROUTES}', '{OFFLINE_FALLBACK}', '{PUSH_NOTIFICATIONS}', '{BACKGROUND_SYNC}'],
            [$cacheName, $staticAssets, $apiRoutes, $offlineFallback, $pushNotifications, $backgroundSync],
            $this->getTemplate()
        );
    }

    private function getTemplate(): string
    {
        return <<<'JAVASCRIPT'
const CACHE_NAME = {CACHE_NAME};
const STATIC_ASSETS = {STATIC_ASSETS};
const API_ROUTES = {API_ROUTES};
const OFFLINE_FALLBACK = {OFFLINE_FALLBACK};

// Install event: cache static assets
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
      return cache.addAll(STATIC_ASSETS).catch(err => {
        console.log('Some assets failed to cache:', err);
        return Promise.resolve();
      });
    }).then(() => self.skipWaiting())
  );
});

// Activate event: clean old caches
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(name => {
          if (name !== CACHE_NAME) {
            return caches.delete(name);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

// Fetch event: cache-first for static, network-first for API
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  if (request.method !== 'GET') {
    return;
  }

  if (isStaticAsset(url.pathname)) {
    event.respondWith(
      caches.match(request).then(response => {
        return response || fetch(request).then(networkResponse => {
          return caches.open(CACHE_NAME).then(cache => {
            cache.put(request, networkResponse.clone());
            return networkResponse;
          });
        }).catch(() => caches.match(OFFLINE_FALLBACK));
      })
    );
    return;
  }

  if (isApiRoute(url.pathname)) {
    event.respondWith(
      fetch(request)
        .then(response => {
          if (!response.ok) throw new Error('Network response failed');
          return caches.open(CACHE_NAME).then(cache => {
            cache.put(request, response.clone());
            return response;
          });
        })
        .catch(() => {
          return caches.match(request) || caches.match(OFFLINE_FALLBACK);
        })
    );
    return;
  }

  event.respondWith(
    fetch(request)
      .catch(() => caches.match(request) || caches.match(OFFLINE_FALLBACK))
  );
});

function isStaticAsset(pathname) {
  return /\.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i.test(pathname);
}

function isApiRoute(pathname) {
  return API_ROUTES.some(route => {
    const pattern = route.replace(/\*/g, '.*');
    return new RegExp(`^${pattern}$`).test(pathname);
  });
}

{PUSH_NOTIFICATIONS}{BACKGROUND_SYNC}
JAVASCRIPT;
    }

    private function generatePushNotifications(): string
    {
        return <<<'JAVASCRIPT'

// Push notification event
self.addEventListener('push', event => {
  const data = event.data?.json?.() ?? {};
  const title = data.title || 'SFPHP Notification';
  const options = {
    body: data.body || '',
    icon: data.icon || '/icon-192x192.png',
    badge: data.badge || '/badge-72x72.png',
    tag: data.tag || 'sfphp-notification',
    data: data.data || {},
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

// Notification click event
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const data = event.notification.data;
  const url = data.url || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(clientList => {
      for (const client of clientList) {
        if (client.url === url && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(url);
      }
    })
  );
});
JAVASCRIPT;
    }

    private function generateBackgroundSync(): string
    {
        return <<<'JAVASCRIPT'

// Background sync event
self.addEventListener('sync', event => {
  if (event.tag === 'sync-data') {
    event.waitUntil(syncOfflineData());
  }
});

async function syncOfflineData() {
  try {
    const cache = await caches.open(CACHE_NAME);
    const requests = await cache.keys();

    for (const request of requests) {
      if (request.method === 'POST' || request.method === 'PUT') {
        try {
          const response = await fetch(request.clone());
          if (response.ok) {
            await cache.delete(request);
          }
        } catch (err) {
          console.log('Failed to sync:', request.url, err);
        }
      }
    }
  } catch (err) {
    console.error('Background sync failed:', err);
  }
}
JAVASCRIPT;
    }

    public function save(string $path): bool
    {
        return file_put_contents($path, $this->generate()) !== false;
    }
}

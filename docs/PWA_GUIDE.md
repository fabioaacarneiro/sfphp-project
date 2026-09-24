# SFPHP Progressive Web App (PWA) Complete Guide

> **Read in:** [English](PWA_GUIDE.md) · [Português](PWA_GUIDE.pt-BR.md) · [Español](PWA_GUIDE.es.md)

**Transform your SFPHP app into a powerful Progressive Web App with offline support, push notifications, and home screen installation.**

---

## Table of Contents

1. [What is a PWA?](#what-is-a-pwa)
2. [Quick Start (5 minutes)](#quick-start)
3. [Understanding PWA Components](#understanding-pwa-components)
4. [Configuration](#configuration)
5. [Caching Strategies](#caching-strategies)
6. [Offline Support](#offline-support)
7. [Push Notifications](#push-notifications)
8. [Background Sync](#background-sync)
9. [Icons & Installation](#icons--installation)
10. [Testing & Debugging](#testing--debugging)
11. [Deployment](#deployment)
12. [Performance Tips](#performance-tips)
13. [Troubleshooting](#troubleshooting)

---

## What is a PWA?

A **Progressive Web App** is a web application that uses modern web capabilities to deliver an app-like experience to users.

### Key Benefits

```
✅ Install to home screen (no app store needed)
✅ Works offline (cached content)
✅ Fast loading (service worker caching)
✅ Push notifications (keep users engaged)
✅ Background sync (save data offline, sync online)
✅ Full screen experience (no browser UI)
✅ Works on all devices (responsive)
```

### Real-World Examples

- Twitter PWA (64% reduction in data usage)
- Spotify PWA (install from web)
- WhatsApp Web (offline messages)
- Uber Eats PWA (3x faster, 75% smaller)

---

## Quick Start

### 1. Generate PWA Setup

```bash
./sfphp make:pwa \
  --name="My App" \
  --short="App" \
  --color="#007AFF" \
  --logo=path/to/logo.png
```

**What this creates:**
- ✅ `public/manifest.json` - PWA metadata
- ✅ `public/service-worker.js` - Offline support
- ✅ `public/offline.html` - Offline fallback page
- ✅ `public/icons/` - App icons
- ✅ `public/install-sw.js` - Service worker installer
- ✅ `app/pwa/config.php` - Configuration file

### 2. Test in Browser

1. **Open your app:** `http://localhost:8000`
2. **Open DevTools:** F12 → Application tab
3. **Check Manifest:** See "Manifest" section
4. **Check Service Worker:** See "Service Workers" section
5. **Install:** Click "Install app" prompt (or menu)

### 3. Enable Features (Optional)

Edit `.env`:

```env
PWA_ENABLED=true
PWA_PUSH_NOTIFICATIONS=true
PWA_BACKGROUND_SYNC=true
```

Then regenerate:

```bash
./sfphp make:pwa --enable-push --enable-sync
```

---

## Understanding PWA Components

### 1. Manifest File (`manifest.json`)

Defines how your PWA appears on installation, home screen, and splash screen.

**Location:** `public/manifest.json`

```json
{
  "name": "My App",
  "short_name": "App",
  "description": "My awesome app",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "theme_color": "#007AFF",
  "background_color": "#ffffff",
  "orientation": "portrait-primary",
  "icons": [
    {
      "src": "/icons/icon-192x192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/icons/icon-512x512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

**Key Fields:**

| Field | Purpose | Example |
|-------|---------|---------|
| `name` | Full app name | "My Awesome App" |
| `short_name` | Home screen name (max 12 chars) | "MyApp" |
| `description` | App description | "Track tasks and goals" |
| `start_url` | Page to open on install | "/" |
| `display` | UI mode | "standalone", "fullscreen" |
| `theme_color` | Toolbar color | "#007AFF" |
| `background_color` | Splash screen color | "#ffffff" |
| `icons` | App icons (multiple sizes) | See above |

### 2. Service Worker (`service-worker.js`)

**Location:** `public/service-worker.js`

A JavaScript file that runs in the background, intercepting network requests and managing cache.

**Responsibilities:**

```
┌─────────────────────────────────────┐
│    Service Worker                   │
├─────────────────────────────────────┤
│  Install → Cache static assets      │
│  Activate → Clean old caches        │
│  Fetch → Intercept network requests │
│  Push → Handle notifications        │
│  Sync → Sync data offline           │
└─────────────────────────────────────┘
```

### 3. Install Script (`install-sw.js`)

**Location:** `public/install-sw.js`

Registers the service worker and provides PWA JavaScript API.

```html
<!-- In your base layout -->
<script src="/install-sw.js" defer></script>
```

---

## Configuration

### Config File

Edit `app/pwa/config.php`:

```php
return [
    // Enable PWA
    'enabled' => env('PWA_ENABLED', true),

    // App identification
    'name' => 'My App',
    'short_name' => 'App',
    'description' => 'My awesome app',

    // Colors
    'theme_color' => '#007AFF',
    'background_color' => '#ffffff',

    // Installation
    'display' => 'standalone',       // How app appears
    'start_url' => '/',              // Initial page
    'scope' => '/',                  // App scope
    'orientation' => 'portrait-primary',

    // Caching strategy
    'cache' => [
        'name' => 'app-v1',          // Change to bust cache

        'static_assets' => [         // Cache-first
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
            '/index.html',
        ],

        'api_routes' => [            // Network-first
            '/api/*',
        ],

        'offline_fallback' => '/offline.html',
    ],

    // Features
    'push_notifications' => env('PWA_PUSH_NOTIFICATIONS', false),
    'background_sync' => env('PWA_BACKGROUND_SYNC', false),
];
```

### Load Config in Your App

```php
use SfphpProject\src\Pwa\PwaConfig;

$pwaConfig = PwaConfig::fromFile(__DIR__ . '/../app/pwa/config.php');

if ($pwaConfig->isEnabled()) {
    echo sprintf('<meta name="theme-color" content="%s">', 
        $pwaConfig->themeColor()
    );
}
```

---

## Caching Strategies

SFPHP PWA uses two strategies:

### 1. Cache-First (Static Assets)

For **images, CSS, JavaScript, fonts** that change infrequently.

```
User request
    ↓
Check cache
    ↓
Found? ✓ Return from cache
    ↓
Not found? → Fetch from network → Save to cache → Return
```

**Benefits:**
- ✅ Lightning fast (cached)
- ✅ Works offline
- ✅ Reduces bandwidth

**Drawback:**
- ❌ Updates require cache bust (change cache name)

### 2. Network-First (API Calls)

For **API endpoints** that need fresh data.

```
User request
    ↓
Try network
    ↓
Success? ✓ Save to cache → Return
    ↓
Failed? → Check cache → Return cached version
```

**Benefits:**
- ✅ Fresh data when online
- ✅ Works offline with cached response
- ✅ Seamless experience

**Drawbacks:**
- ⚠️ Slightly slower (waits for network)
- ⚠️ Shows potentially stale data offline

### Customizing Strategies

Edit `public/service-worker.js` → `isStaticAsset()` function:

```javascript
function isStaticAsset(pathname) {
  // Add more file types as needed
  return /\.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i.test(pathname);
}
```

### Cache Busting

When you update static assets, change the cache name in config:

```php
'cache' => [
    'name' => 'app-v2',  // Changed from v1
    // ...
],
```

Then regenerate:

```bash
./sfphp make:pwa
```

---

## Offline Support

### How It Works

1. **Install:** Service worker caches static assets
2. **Offline:** When network fails, serve from cache
3. **Online:** Fetch fresh data and sync offline changes

### Offline Fallback Page

When a request fails and isn't cached, users see `/offline.html`.

Edit `public/offline.html` to customize:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Offline</title>
    <style>
        body {
            font-family: sans-serif;
            text-align: center;
            padding: 40px;
        }
    </style>
</head>
<body>
    <h1>📡 You're Offline</h1>
    <p>Your internet connection was lost.</p>
    <button onclick="location.reload()">Retry</button>
    <button onclick="location.href='/'">Go Home</button>
</body>
</html>
```

### Testing Offline

**In DevTools:**

1. Open DevTools → Application tab
2. Check "Offline" checkbox
3. Refresh page
4. Try navigating

You should see offline fallback for uncached pages.

### Handling Offline in JavaScript

SFPHP PWA provides a JavaScript API:

```javascript
// Check connection status
if (pwa.isOnline()) {
    console.log('Online');
} else {
    console.log('Offline');
}

// Listen for connection changes
pwa.onOnline(() => console.log('Back online!'));
pwa.onOffline(() => console.log('Went offline'));

// Listen for custom PWA events
window.addEventListener('pwa:online', () => {
    console.log('Connection restored');
    location.reload(); // Refresh data
});

window.addEventListener('pwa:offline', () => {
    console.log('Connection lost');
    showNotification('Working offline mode...');
});
```

### Persisting Data Offline

Save form data before user loses connection:

```javascript
// Save form data to localStorage when offline
pwa.onOffline(() => {
    const formData = new FormData(document.querySelector('form'));
    localStorage.setItem('pending-form', JSON.stringify({
        timestamp: Date.now(),
        data: Object.fromEntries(formData),
    }));
});

// Restore and submit when online
pwa.onOnline(() => {
    const pending = localStorage.getItem('pending-form');
    if (pending) {
        const { data } = JSON.parse(pending);
        fetch('/api/submit', {
            method: 'POST',
            body: JSON.stringify(data),
            headers: { 'Content-Type': 'application/json' }
        }).then(() => {
            localStorage.removeItem('pending-form');
            showNotification('Data saved!');
        });
    }
});
```

---

## Push Notifications

### Enable Push Notifications

1. **In .env:**

```env
PWA_PUSH_NOTIFICATIONS=true
```

2. **Regenerate PWA:**

```bash
./sfphp make:pwa --enable-push
```

3. **Request Permission:**

```javascript
// User clicks button to enable notifications
async function enableNotifications() {
    const granted = await pwa.requestNotifications();
    if (granted) {
        console.log('Notifications enabled!');
        pwa.testNotification();
    }
}
```

### Send Push from Backend

**PHP:**

```php
use SfphpProject\src\Pwa\PushNotificationService;

$pushService = new PushNotificationService();

// Send to user
$pushService->sendToUser($userId, [
    'title' => 'New Message',
    'body' => 'You have a new message from John',
    'icon' => '/icon-192x192.png',
    'tag' => 'new-message',
    'data' => [
        'url' => '/messages/123',
    ],
]);
```

### Handle Notification Click

In `service-worker.js` (already configured):

```javascript
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data.url || '/';
    
    // Open URL in window
    event.waitUntil(clients.openWindow(url));
});
```

### Testing Notifications

```javascript
// Send test notification
await pwa.testNotification();
```

---

## Background Sync

### Enable Background Sync

1. **In .env:**

```env
PWA_BACKGROUND_SYNC=true
```

2. **Regenerate PWA:**

```bash
./sfphp make:pwa --enable-sync
```

### How It Works

1. **User offline:** POST/PUT requests fail
2. **Service Worker:** Caches the request
3. **User online:** Automatically retry cached requests

### Example: Offline Form Submission

```javascript
// Form submission
document.querySelector('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    try {
        const response = await fetch('/api/submit', {
            method: 'POST',
            body: formData,
        });
        
        if (response.ok) {
            showNotification('✓ Saved!');
        }
    } catch (error) {
        if (!pwa.isOnline()) {
            // Offline: will retry when online
            showNotification('Saved offline - will sync when online');
            
            // Register background sync
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                const registration = await navigator.serviceWorker.ready;
                await registration.sync.register('sync-data');
            }
        }
    }
});
```

### Verify Sync

DevTools → Application → Service Workers → Periodic Background Sync

---

## Icons & Installation

### Icon Requirements

SFPHP generates standard sizes:

| Size | Purpose |
|------|---------|
| 192x192 | Android home screen |
| 512x512 | Android splash screen |
| 180x180 | Apple touch icon |

### Generate Icons

From a logo image (PNG, JPG, WebP, GIF):

```bash
./sfphp make:pwa --logo=path/to/your/logo.png
```

**Requirements:**
- Square image (1:1 ratio)
- At least 512×512 pixels
- PNG recommended (transparent background)
- PHP GD extension required

### Manual Icons

If you can't auto-generate, create them yourself:

```
public/icons/
├─ icon-192x192.png
├─ icon-512x512.png
└─ apple-touch-icon.png (180x180)
```

### Install Prompts

#### Android

1. Open app
2. Menu → "Install app"
3. Select "Install"

#### iOS

1. Open app in Safari
2. Share icon → "Add to Home Screen"
3. Name and add

#### Web

Browser shows install prompt automatically (if PWA criteria met).

### PWA Installation Criteria

For install prompt to appear:

```
✅ HTTPS enabled (required)
✅ Manifest.json present
✅ Service worker registered
✅ Icons included
✅ Theme colors set
✅ Start URL defined
```

All automatically set by `make:pwa`!

---

## Testing & Debugging

### DevTools Inspection

**Application Tab:**

1. **Manifest:** Verify all fields correct
2. **Service Workers:** Check registration status
3. **Cache Storage:** Inspect cached requests
4. **Local Storage:** Check offline data

### Testing Offline

1. DevTools → Network tab
2. Check "Offline" checkbox
3. Refresh page
4. App should work with cached content

### Testing Push Notifications

```javascript
// Send test notification
await pwa.testNotification();
```

### Debugging Service Worker

```javascript
// In your app's JavaScript
console.log('Service Worker status:', navigator.serviceWorker);

navigator.serviceWorker.addEventListener('message', (event) => {
    console.log('Message from SW:', event.data);
});

// Get service worker instance
navigator.serviceWorker.ready.then((registration) => {
    console.log('SW registered:', registration);
});
```

### Cache Debugging

```javascript
// List all caches
async function listCaches() {
    const cacheNames = await caches.keys();
    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const requests = await cache.keys();
        console.log(`Cache: ${name}`);
        requests.forEach(req => console.log(`  - ${req.url}`));
    }
}

// Or use the PWA API
console.log(await pwa.getCacheInfo());
```

### PWA API

SFPHP provides JavaScript API:

```javascript
// Check online status
pwa.isOnline()                    // true/false

// Notifications
await pwa.requestNotifications()  // Ask permission
await pwa.testNotification()      // Send test

// Caching
await pwa.getCacheInfo()          // List caches
await pwa.clearCache()            // Clear all cache

// Service Worker
await pwa.unregister()            // Remove SW

// Events
pwa.onOnline(callback)            // Connection restored
pwa.onOffline(callback)           // Connection lost
```

---

## Deployment

### Requirements

- **HTTPS:** Required for Service Workers
- **Valid Certificate:** No self-signed certs
- **All PWA files:** manifest.json, service-worker.js, icons

### Deployment Checklist

```
✅ Enable HTTPS
✅ Verify manifest.json is accessible
✅ Check service-worker.js has correct paths
✅ Icons uploaded to /public/icons/
✅ offline.html accessible
✅ Test in incognito mode (fresh install)
✅ Test offline mode
✅ Check install prompt appears
✅ Verify push notifications work
```

### Performance Optimization

1. **Compress manifest.json** (auto)
2. **Minify service-worker.js** (auto)
3. **Optimize icons:**
   - Use JPEG/WebP for photos
   - Use PNG for logos (transparency)
   - Compress with TinyPNG
4. **Cache version strategy:**
   - Change cache name per deployment
   - Use timestamp: `app-20240101` 

### Monitoring

Monitor PWA usage and issues:

```php
// Log service worker errors
Route::post('/api/pwa/error', function (Request $request) {
    Log::channel('pwa')->error('SW Error', $request->json());
    return response()->json(['ok' => true]);
});

// Track installation events (via analytics)
Route.post('/api/pwa/install', function (Request $request) {
    // Track app installations
    Log::info('PWA installed by user');
    return response()->json(['ok' => true]);
});
```

---

## Performance Tips

### 1. Cache Version Management

Change cache name to force re-download:

```php
'cache' => [
    'name' => 'app-' . time(), // Force new cache
],
```

Or per deployment:

```php
'cache' => [
    'name' => env('PWA_CACHE_VERSION', 'app-v1'),
],
```

### 2. Selective Caching

Only cache essential assets:

```php
'cache' => [
    'static_assets' => [
        '/css/sfcss.min.css',  // Critical
        '/js/sfjs.min.js',     // Critical
        '/index.html',         // Critical
        // Don't cache images (too large)
    ],
],
```

### 3. API Route Patterns

Be specific with API routes to avoid caching:

```php
'api_routes' => [
    '/api/data/*',        // Cache data
    '/api/search/*',      // Cache search
    // Don't include /api/upload/* (always fresh)
],
```

### 4. Cache Expiration

Manually expire old caches:

```javascript
// In service worker
const CACHE_EXPIRY = 7 * 24 * 60 * 60 * 1000; // 7 days

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(names => {
            return Promise.all(
                names.map(name => {
                    // Delete old caches
                    if (name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                })
            );
        })
    );
});
```

### 5. Monitor Cache Size

```javascript
async function getCacheSize() {
    const cacheNames = await caches.keys();
    let totalSize = 0;

    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const requests = await cache.keys();
        for (const request of requests) {
            const response = await cache.match(request);
            totalSize += response ? response.blob().then(b => b.size) : 0;
        }
    }

    return totalSize;
}
```

---

## Troubleshooting

### Service Worker Won't Register

**Problem:** "Service Worker registration failed"

**Solutions:**

1. **Check HTTPS:** Service Workers require HTTPS
```bash
# Development: use localhost (exempt from HTTPS)
http://localhost:8000
```

2. **Check file exists:** Verify `/public/service-worker.js`
3. **Check path:** Ensure correct path in install script
4. **Check console errors:** DevTools → Console tab
5. **Clear cache:** `Ctrl+Shift+Del` → "Cached images/files"

### Install Prompt Doesn't Appear

**Problem:** "Install app" button missing

**Checklist:**

1. ✅ HTTPS enabled (or localhost)
2. ✅ Manifest.json valid (`[object Object]` error?)
3. ✅ Service Worker registered
4. ✅ Icons present
5. ✅ manifest.json linked in HTML

**Test manifest validity:**

```bash
curl http://localhost:8000/manifest.json | jq .
```

### Offline Page Not Showing

**Problem:** Offline, but not seeing `/offline.html`

**Causes:**

1. `/offline.html` not accessible
2. Service Worker not installed
3. Page was never cached

**Fix:**

1. Verify file exists: `public/offline.html`
2. Re-install app: Clear cache and refresh
3. Manually cache page:

```php
'cache' => [
    'static_assets' => [
        // ...
        '/offline.html',  // Pre-cache
    ],
],
```

### Notifications Don't Work

**Problem:** "Permission denied" or notifications silent

**Solutions:**

1. **Check permission:** DevTools → Security tab
2. **Request permission explicitly:**

```javascript
const permission = await Notification.requestPermission();
if (permission === 'granted') {
    pwa.testNotification();
}
```

3. **Check SW has permission listener** (should be auto-included)
4. **Test on deployed site** (some browsers skip on localhost)

### Cache Stale Data

**Problem:** Updated content not showing

**Solution:** Bust cache by changing version

```php
// app/pwa/config.php
'cache' => [
    'name' => 'app-' . date('Ymd'),  // Change daily
],
```

Then regenerate:

```bash
./sfphp make:pwa
```

### Background Sync Not Working

**Problem:** Offline requests not retrying

**Checklist:**

1. ✅ Background sync enabled (`PWA_BACKGROUND_SYNC=true`)
2. ✅ Service Worker has sync listener
3. ✅ Request method is POST/PUT
4. ✅ Request not in "api_routes" exclusion

**Verify:**

```javascript
// When online
navigator.serviceWorker.ready.then(async (reg) => {
    await reg.sync.register('sync-data');
    console.log('Sync registered');
});
```

---

## Summary

You now have a production-ready PWA! 🎉

**What you can do:**

✅ Install on home screen  
✅ Works offline  
✅ Send push notifications  
✅ Sync data in background  
✅ Fast loading (cached)  
✅ Works on all devices  

**Next steps:**

1. Customize colors and branding
2. Test on different devices
3. Set up push notification backend
4. Monitor performance
5. Deploy to production

**Resources:**

- [MDN Web Docs - PWA](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps)
- [Google PWA Checklist](https://web.dev/pwa-checklist/)
- [web.dev learning PWA](https://web.dev/pwa/)

---

## Questions?

Join the SFPHP community:

- 📚 [Documentation](https://github.com/fabioaacarneiro/sfphp-project)
- 💬 [GitHub Discussions](https://github.com/fabioaacarneiro/sfphp-project/discussions)
- 🐛 [Report Issues](https://github.com/fabioaacarneiro/sfphp-project/issues)

Happy building! 🚀

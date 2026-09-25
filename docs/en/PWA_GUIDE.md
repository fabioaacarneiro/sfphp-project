# SFPHP Progressive Web App (PWA) Guide

> **Read in:** [English](PWA_GUIDE.md) · [Português](../pt-BR/PWA_GUIDE.md) · [Español](../es/PWA_GUIDE.md)

This guide covers what `./sfphp make:pwa` generates, how to configure it, and
what the generated service worker actually does. It describes the code as it
is, including what it leaves to you.

---

## Contents

1. [Overview](#overview)
2. [Requirements](#requirements)
3. [Quick Start](#quick-start)
4. [The make:pwa Command](#the-makepwa-command)
5. [Configuration](#configuration)
6. [Adding the PWA to Your Layout](#adding-the-pwa-to-your-layout)
7. [Caching Strategy](#caching-strategy)
8. [Cache Name and Versioning](#cache-name-and-versioning)
9. [Offline Support](#offline-support)
10. [Icons & Installation](#icons--installation)
11. [Push Notifications](#push-notifications)
12. [Background Sync](#background-sync)
13. [The window.pwa API](#the-windowpwa-api)
14. [Testing & Debugging](#testing--debugging)
15. [Deployment](#deployment)
16. [Troubleshooting](#troubleshooting)

---

## Overview

A Progressive Web App is a website that the browser can install like an app.
It needs three things: a **web app manifest** that describes the app, a
**service worker** that sits between the page and the network, and **HTTPS**.

SFPHP generates the first two with one command. Everything is static files in
`public/`. No PHP runs for the PWA at request time, and nothing is added to
`composer.json`.

| Piece | File | Produced by |
|-------|------|-------------|
| Manifest | `public/manifest.json` | `src/Pwa/ManifestGenerator.php` |
| Service worker | `public/service-worker.js` | `src/Pwa/ServiceWorkerGenerator.php` |
| Offline page | `public/offline.html` | copy of `resources/pwa/offline-template.html` |
| Registration script | `public/install-sw.js` | copy of `resources/pwa/install-sw.js` |
| Icons | `public/assets/icons/*.png` | `src/Pwa/IconGenerator.php`, only with `--logo` |
| Settings | `app/pwa/config.php` | you edit it. `make:pwa` reads it but never writes it |

---

## Requirements

- **PHP 8.1+**, like the rest of the framework.
- **ext-gd**, but only to generate icons from `--logo`. It reads PNG, JPEG and
  GIF, and WebP when your GD build supports it. Without GD, `make:pwa` still
  writes every other file. It prints `⚠ Could not generate icons: The GD
  extension (ext-gd) is required to generate icons.` and you add the PNGs to
  `public/assets/icons/` yourself.
- **ext-fileinfo is not needed.** The image type is read with `getimagesize()`,
  which is part of PHP itself.
- **HTTPS in production.** Browsers only register service workers on secure
  origins. `http://localhost` and `http://127.0.0.1` count as secure, so
  `./sfphp serve` works for development.

---

## Quick Start

```bash
# 1. Generate everything (icons need ext-gd)
./sfphp make:pwa --name="My App" --logo=path/to/logo.png

# 2. Add the four tags from "Adding the PWA to Your Layout" to your layout's <head>

# 3. Run it and look
./sfphp serve
```

Open `http://127.0.0.1:8000`, press F12 and go to **Application**. Under
**Manifest** you should see the name and icons. Under **Service workers**,
`/service-worker.js` should show as *activated and running*.

The project ships with `app/pwa/config.php`, and if that file exists `--name`
is optional. Plain `./sfphp make:pwa` then takes the name from the file, which
reads `APP_NAME` from `.env`.

---

## The make:pwa Command

```bash
./sfphp make:pwa --name="My App" [options]
```

| Option | Effect | Default |
|--------|--------|---------|
| `--name="..."` | The manifest's `name`, and the base of the cache name. **Required when `app/pwa/config.php` does not exist.** | `name` from the config |
| `--short="..."` | The manifest's `short_name` | When `--name` is given: its first 12 characters. Otherwise `short_name` from the config |
| `--description="..."` | The manifest's `description` | `description` from the config, or empty |
| `--color="#hex"` | The manifest's `theme_color` | `theme_color` from the config, or `#007AFF` |
| `--background="#hex"` | The manifest's `background_color` | `background_color` from the config, or `#ffffff` |
| `--logo=path` | Generates the icons from this image | none. Icons are skipped |
| `--enable-push` | Adds the `push` and `notificationclick` handlers to the service worker | `service_worker.enable_push_notifications` |
| `--enable-sync` | Adds the `sync` handler to the service worker | `service_worker.enable_background_sync` |

Values are resolved in this order: **flag, then `app/pwa/config.php`, then the
built-in default.** A flag only applies to that run and does not change the
file. A flag takes its value after `=` or after a space — `--name "My App"`
works — and `--color` and `--background` must be hex colours (`#2563eb`,
`#fff`, or eight digits with alpha); anything else stops the command before a
file is written. The manifest is written with its text as it is, so "Café"
stays "Café" rather than `Caf\u00e9`. `--enable-push` and `--enable-sync` can only switch a feature on. To
switch off a feature the config enables, set it to `false` in the file.

Some settings have no flag: `start_url`, `scope`, `display`, `orientation`,
`icons`, and everything under `service_worker` except the two feature switches.
Set them in the config file.

Every run **overwrites** `manifest.json`, `service-worker.js`, `offline.html`
and `install-sw.js`. If you edit a generated file by hand, running the command
again replaces your edit. The command never edits your templates. It prints the
`<head>` tags at the end, and adding them to your layout is up to you.

Examples:

```bash
# Name, colours and icons
./sfphp make:pwa --name="Task Tracker" --short="Tasks" \
  --description="Track tasks and goals" \
  --color="#2563eb" --background="#ffffff" \
  --logo=resources/logo.png

# With the push notification handlers
./sfphp make:pwa --name="Task Tracker" --enable-push

# Regenerate from app/pwa/config.php after editing it
./sfphp make:pwa
```

---

## Configuration

`app/pwa/config.php` returns an array. Only `make:pwa` reads it, and nothing
reads it at request time, so **a change reaches the browser only after you run
`./sfphp make:pwa` again.**

The shipped file reads the app's name and description from `.env` through
`Config::get()`, the framework's configuration reader:

```php
<?php

use SfphpProject\src\Config;

$appName = Config::get('APP_NAME', 'SFPHP Application');
$appDescription = Config::get('APP_DESCRIPTION', '');

return [
    'name' => $appName,
    'short_name' => mb_substr($appName, 0, 12),
    'description' => $appDescription,
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',        // fullscreen, standalone, minimal-ui, browser
    'theme_color' => '#007AFF',
    'background_color' => '#ffffff',
    'orientation' => 'portrait-primary',

    'service_worker' => [
        'version' => 'v1',             // part of the cache name, see "Cache Name and Versioning"
        'static_assets' => [           // precached when the service worker installs
            '/assets/css/sfcss.min.css',
            '/assets/js/sfjs.min.js',
            '/offline.html',
        ],
        'api_routes' => [],            // path patterns, '*' as a wildcard; never cached
        'offline_fallback' => '/offline.html',
        'enable_push_notifications' => false,
        'enable_background_sync' => false,
    ],

    'icons' => [
        ['src' => '/assets/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ],
];
```

Notes:

- **There are no `PWA_*` environment variables.** `APP_NAME` and
  `APP_DESCRIPTION` are the only values the shipped file takes from `.env`. To
  drive another setting from the environment, read it the same way:
  `'theme_color' => Config::get('PWA_THEME_COLOR', '#007AFF')`. SFPHP has no
  `env()` function.
- **A missing key falls back to its default.** A list replaces the default
  list rather than merging with it. If you set `static_assets` to two URLs, the
  service worker precaches exactly those two.
- **`display` and `orientation` are checked.** A value outside the allowed set
  stops the command with an error and nothing is written. For `orientation`
  the allowed values are `portrait-primary`, `portrait-secondary`,
  `landscape-primary`, `landscape-secondary`, `portrait` and `landscape`.
- **If the file is missing,** `make:pwa` uses the defaults shown above and
  requires `--name`. The package keeps a copy of this file at
  `resources/pwa/config.php`; copy it to `app/pwa/config.php`.

---

## Adding the PWA to Your Layout

`make:pwa` does not edit templates. Add these tags to the `<head>` of your
layout, for example `app/resources/views/layouts/base.sfht`:

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#007AFF">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<script src="/install-sw.js" defer></script>
```

- The `<meta name="theme-color">` value should match `theme_color`. The command
  prints this block with your colour filled in, and prints the
  `apple-touch-icon` line only when it generated that icon.
- `install-sw.js` registers `/service-worker.js` with scope `/` and defines
  `window.pwa`. Without it, no service worker is registered.
- iOS ignores the manifest icons for the home screen and uses
  `apple-touch-icon`.

Put the tags in every layout a user can land on. A page without the manifest
link cannot be installed.

---

## Caching Strategy

The generated `service-worker.js` handles requests like this:

| Request | Strategy |
|---------|----------|
| Anything that is not `GET` (POST, PUT, PATCH, DELETE) | Not intercepted. The browser sends it normally. |
| `GET` whose path ends in `.js`, `.css`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.woff`, `.woff2`, `.ttf` or `.eot` | **Cache-first.** It is served from the cache when present. Otherwise it is fetched, stored if the response is successful (or opaque, from another origin), and returned. If the network fails, the offline fallback is returned. |
| `GET` matching a pattern in `api_routes` | **Network-only, never cached.** On network failure, the offline fallback is returned. |
| Any other `GET`, including every HTML page | **Network-only, never cached.** On network failure, the offline fallback is returned. |

What this means in practice:

- **Static files are cached by extension, not by the `static_assets` list.**
  The list is only precached at install, so those files are available offline
  before the first visit. Any `.css`/`.js`/image/font URL the app loads is
  cached the first time it is fetched, from any origin, CDN included.
- **Cache-first never revalidates.** Once `sfcss.min.css` is cached, the
  service worker serves that copy until the cache is replaced, even after you
  deploy a new one. See [Cache Name and Versioning](#cache-name-and-versioning).
- **Pages and API responses are never stored**, so a logged-in user's HTML or
  JSON never ends up in the cache. `api_routes` states which paths are API
  paths. Right now they are handled the same way as every other non-static
  request: network-only.
- **`.webp`, `.ico`, `.json`, `.mp4` and other extensions are not static** for
  this purpose. They are network-only.
- **An `api_routes` pattern is anchored and `*` matches anything**, so
  `/api/*` covers `/api/users/7`. The rest of the pattern is used as a regular
  expression.
- **Precaching is all or nothing.** `cache.addAll()` fails as a whole if one
  URL fails, for example with a 404. The service worker still installs, but
  with nothing precached, and the console logs `Some assets failed to cache`.
  Keep `static_assets` to URLs that exist. `/offline.html` must be one of them.

---

## Cache Name and Versioning

The cache is named `<slug>-<version>`:

- `<slug>` is the app name in lower case with spaces replaced by hyphens.
  `"My App"` becomes `my-app`.
- `<version>` is `service_worker.version` from the config, `v1` by default.

So `./sfphp make:pwa --name="My App"` with the shipped config uses
`my-app-v1`.

When a new service worker activates, it **deletes every cache on the origin
whose name is not the current one** and takes control of open pages at once
(`skipWaiting()` and `clients.claim()`). That includes any cache your own code
created with the Cache API.

To push new static files to users who already have them cached:

1. Change `service_worker.version` in `app/pwa/config.php`, for example to
   `'v2'`.
2. Run `./sfphp make:pwa`.
3. Deploy. Browsers see that `service-worker.js` changed, install it, precache
   into `my-app-v2` and delete `my-app-v1`.

Changing the app name also changes the cache name. For a single file, a
versioned URL works too. `/assets/css/sfcss.min.css?v=2` is a different cache
entry from the unversioned URL.

---

## Offline Support

When a request that goes to the network fails, the service worker answers with
the cached `offline_fallback` (`/offline.html`). Pages are never cached, so a
user who goes offline sees that page for every navigation, including pages
visited earlier. There is no offline reading of pages you already saw.

`public/offline.html` is a self-contained page with inline CSS and no external
requests. It has a **Retry** button and a **Go Home** button. It reloads itself
when the browser reports it is back online, and it checks `navigator.onLine`
every 3 seconds. If you customise it, edit
`resources/pwa/offline-template.html`, or edit `public/offline.html` and stop
running `make:pwa`, which copies the template over it.

Two consequences to know about:

- **A `fetch()` from your JavaScript gets the offline page too.** A failed
  `GET` to a JSON endpoint resolves with the cached `offline.html` and status
  200. Check `navigator.onLine`, or the response's `Content-Type`, before
  parsing it as JSON.
- **If `/offline.html` was not precached**, because it is missing from
  `static_assets` or because precaching failed, a failed request ends in the
  browser's own network error.

---

## Icons & Installation

`--logo` writes four PNGs to `public/assets/icons/`:

| File | Size | Used by |
|------|------|---------|
| `icon-192x192.png` | 192×192 | manifest (Android home screen) |
| `icon-512x512.png` | 512×512 | manifest (splash screen, install dialog) |
| `apple-touch-icon.png` | 180×180 | iOS home screen, through the `<link rel="apple-touch-icon">` tag |
| `badge-72x72.png` | 72×72 | the default `badge` of push notifications |

A non-square logo is fitted inside the square and centred on a transparent
background, not stretched. Start from a square image of at least 512×512.

The manifest lists the icons from the `icons` key of the config, and by default
that is the 192 and 512 icons with `purpose: "any"`. No maskable icon is
generated. If you make one, with the logo inside the central safe zone and an
opaque background, add it to `icons` with `'purpose' => 'maskable'`. iOS draws
transparent areas of `apple-touch-icon.png` as black, so a logo with an opaque
background looks better there.

**When the browser offers installation.** Chromium-based browsers show their
install prompt when the page is on HTTPS (or localhost), links a manifest with
a name, a 192 and a 512 icon, `start_url` and a `display` other than `browser`,
and is controlled by a service worker with a `fetch` handler. Everything
generated here meets that once the icons exist. On iOS the user installs from
Safari's Share menu with **Add to Home Screen**. Safari shows no prompt.

---

## Push Notifications

`--enable-push` (or `'enable_push_notifications' => true`) adds two handlers
to the service worker, and nothing more:

- **`push`** reads the message payload as JSON and shows a notification:

  ```json
  {
    "title": "New comment",
    "body": "Ana replied to your task",
    "icon": "/assets/icons/icon-192x192.png",
    "badge": "/assets/icons/badge-72x72.png",
    "tag": "comment-42",
    "data": { "url": "/tasks/42" }
  }
  ```

  Missing fields fall back to the title `SFPHP Notification`, the two icons
  shown above and the tag `sfphp-notification`. The payload **must be JSON**.
  Other text makes the handler throw, and no notification is shown.
- **`notificationclick`** closes the notification and focuses an open window
  whose URL is exactly `data.url`, or opens `data.url`, `/` by default.

**What SFPHP does not provide:**

- **No subscription code.** Neither `install-sw.js` nor the service worker
  calls `pushManager.subscribe()`.
- **No server-side sender.** Sending a Web Push message means signing a VAPID
  JWT (RFC 8292) and encrypting the payload (RFC 8291). There is no class for
  that in the framework, and there is no dependency that does it. Use your own
  implementation or an external push service.

A minimal client-side subscription, with your VAPID public key:

```javascript
async function subscribeToPush(vapidPublicKey) {
    if (!(await window.pwa.requestNotifications())) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidPublicKey, // URL-safe base64 or a Uint8Array
    });

    await fetch('/api/push/subscriptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription),
    });
}
```

And a route that stores it:

```php
// app/routes/api.php
use SfphpProject\src\Router;

Router::post('/api/push/subscriptions', [PushSubscriptionController::class, 'store']);
```

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class PushSubscriptionController
{
    public function store(Request $request): Response
    {
        $subscription = $request->json();

        if (!isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
            return Response::json(['error' => 'Invalid subscription'], 422);
        }

        // Store endpoint + keys for the current user, then:
        logger()->info('Push subscription stored', ['endpoint' => $subscription['endpoint']]);

        return Response::json(['ok' => true], 201);
    }
}
```

If the route goes through CSRF protection, send the token in the
`X-CSRF-Token` header. `csrf_meta()` puts it in the page for your script to
read.

To check that the handlers display correctly without a server, call
`window.pwa.testNotification()` after granting permission. It shows a local
notification through the service worker registration.

---

## Background Sync

`--enable-sync` (or `'enable_background_sync' => true`) adds a `sync` handler
for the tag `sync-data`. **Out of the box it does nothing useful.** Know
exactly why before relying on it:

- **Nothing queues failed requests.** The fetch handler ignores every non-GET
  request, so a POST that fails offline simply fails in your page.
- **The handler has nothing to replay.** It looks through the Cache API for
  POST and PUT requests. The Cache API cannot store POST requests, so it never
  finds any.
- **Nothing registers the sync.** The browser only fires `sync` after you call
  `registration.sync.register('sync-data')`.
- **The Background Sync API is Chromium-only.** Firefox and Safari do not fire
  `sync` at all.

For real offline writes, store the request yourself (IndexedDB is the usual
place), register the sync, and replay the stored requests in the `sync` event:

```javascript
// In the page, when a save fails because the user is offline
await saveToIndexedDb({ url: '/api/tasks', body: task }); // your own storage
const registration = await navigator.serviceWorker.ready;
if ('sync' in registration) {
    await registration.sync.register('sync-data');
}
```

The replay code goes into `public/service-worker.js`, in place of
`syncOfflineData()`. **Running `make:pwa` again overwrites that file**, so keep
your version under version control and re-apply it after regenerating, or stop
regenerating once you customise the service worker.

---

## The window.pwa API

`install-sw.js` defines `window.pwa` **only in browsers that support service
workers**, and returns early in the others. Check for it before you use it:
`if (window.pwa) { … }`.

| Member | What it does |
|--------|--------------|
| `pwa.unregister()` | Unregisters every service worker of the origin. Async. |
| `pwa.clearCache()` | Deletes **every** cache of the origin. Async. |
| `pwa.requestNotifications()` | Asks for notification permission. Resolves `true` if granted, `false` if denied or unsupported. |
| `pwa.testNotification()` | Shows a local test notification through the service worker. Needs permission. |
| `pwa.getCacheInfo()` | Resolves an object mapping each cache name to a string such as `"12 items"`. |
| `pwa.isOnline()` | Returns `navigator.onLine`. |
| `pwa.onOnline(callback)` | Adds a listener for the browser's `online` event. |
| `pwa.onOffline(callback)` | Adds a listener for the browser's `offline` event. |

Events dispatched on `window`:

| Event | When |
|-------|------|
| `pwa:update-available` | A new service worker finished installing while an older one controlled the page. If notification permission is granted, an "App Update Available" notification is shown as well. |
| `pwa:online` | The browser went back online. |
| `pwa:offline` | The browser went offline. |

The script also calls `registration.update()` every hour, so a tab left open
picks up a new service worker.

```javascript
window.addEventListener('pwa:update-available', () => {
    // The new worker already took control (skipWaiting + clients.claim),
    // so a reload is enough to get the new assets.
    if (confirm('A new version is available. Reload now?')) {
        location.reload();
    }
});

if (window.pwa) {
    window.pwa.getCacheInfo().then(info => console.table(info));
}
```

---

## Testing & Debugging

In Chrome or Edge DevTools, open **Application**:

- **Manifest** shows the parsed `manifest.json`, the icons and any
  installability problems.
- **Service workers** shows the registration. **Update on reload** is useful
  while you work. **Offline** simulates losing the network, and **Unregister**
  removes the worker.
- **Cache storage** lists `<slug>-<version>` and its entries.
- **Storage → Clear site data** starts from zero.

Things to try:

1. Load a page, tick **Offline** and reload. You should get `offline.html`.
2. Still offline, check that CSS and JS already fetched come from the service
   worker, in the Network panel's *Size* column.
3. Change `service_worker.version`, run `./sfphp make:pwa`, reload and check
   that only the new cache remains.
4. Run **Lighthouse** with the PWA category for an installability report.

From the console: `await pwa.getCacheInfo()`, `await pwa.clearCache()`,
`await pwa.unregister()`.

---

## Deployment

- **Serve over HTTPS.** Without it, nothing registers.
- **Keep `service-worker.js` at the site root.** Its scope is `/`, and a worker
  cannot control URLs above its own path.
- **Send `Cache-Control: no-cache` for `/service-worker.js`**, so the browser
  checks for a new version on each visit. Browsers ignore HTTP caching for it
  after 24 hours anyway, but only after that.
- **Change `service_worker.version` for every release that changes static
  files**, run `./sfphp make:pwa`, and deploy the regenerated
  `service-worker.js`. Otherwise users keep the cached CSS and JS.
- **Commit the generated files** (`public/manifest.json`,
  `public/service-worker.js`, `public/offline.html`, `public/install-sw.js`,
  `public/assets/icons/`), or run `make:pwa` in your build. The server
  generates nothing at runtime.

---

## Troubleshooting

**`Error: --name is required when there is no app/pwa/config.php`**
Pass `--name="..."`, or copy `resources/pwa/config.php` to
`app/pwa/config.php`.

**`⚠ Could not generate icons: The GD extension (ext-gd) is required to generate icons.`**
Install or enable GD (for example `php8.3-gd` on Debian/Ubuntu), or put the
four PNGs in `public/assets/icons/` yourself.

**`⚠ Could not generate icons: Unsupported image format...`**
The logo is not a PNG, JPEG, GIF or WebP file, or your GD cannot read WebP.
Convert it to PNG.

**`⚠ Skipping icon generation: path not found`**
The `--logo` path is resolved from the directory you run the command in.

**No install prompt**
Check **Application → Manifest** for the reason. The usual causes are missing
icons, since without `--logo` the manifest points to PNGs that do not exist, a
page that does not link the manifest, or plain HTTP on a host other than
localhost.

**The service worker does not register**
Check that `install-sw.js` is included and that `/service-worker.js` loads in
the browser. The console shows `✗ Service Worker registration failed` with the
reason.

**Users still see the old CSS/JS after a deploy**
That is cache-first working as designed. Change `service_worker.version`,
regenerate and deploy. See [Cache Name and Versioning](#cache-name-and-versioning).

**My edits to `service-worker.js` or `offline.html` disappeared**
`make:pwa` overwrites both on every run.

**A change in `app/pwa/config.php` has no effect**
The file is only read by `make:pwa`. Run the command again.

**`Some assets failed to cache` in the console**
One of the `static_assets` URLs does not load, so nothing was precached. Fix or
remove that URL.

**Push messages arrive but no notification appears**
The service worker was generated without `--enable-push`, or the payload is
not JSON.

**The `sync` event never fires**
Nothing registers it for you, and only Chromium supports it. See
[Background Sync](#background-sync).

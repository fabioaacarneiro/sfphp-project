# Guía de Progressive Web App (PWA) de SFPHP

> **Lee en:** [English](../en/PWA_GUIDE.md) · [Português](../pt-BR/PWA_GUIDE.md) · [Español](PWA_GUIDE.md)

Esta guía cubre lo que genera `./sfphp make:pwa`, cómo configurarlo y lo que
hace realmente el service worker generado. Describe el código tal como es,
incluido lo que deja en tus manos.

---

## Contenido

1. [Resumen](#resumen)
2. [Requisitos](#requisitos)
3. [Inicio rápido](#inicio-rápido)
4. [El comando make:pwa](#el-comando-makepwa)
5. [Configuración](#configuración)
6. [Añadir la PWA a tu layout](#añadir-la-pwa-a-tu-layout)
7. [Estrategia de caché](#estrategia-de-caché)
8. [Nombre y versionado de la caché](#nombre-y-versionado-de-la-caché)
9. [Soporte offline](#soporte-offline)
10. [Iconos e instalación](#iconos-e-instalación)
11. [Notificaciones push](#notificaciones-push)
12. [Sincronización en segundo plano](#sincronización-en-segundo-plano)
13. [La API window.pwa](#la-api-windowpwa)
14. [Pruebas y depuración](#pruebas-y-depuración)
15. [Despliegue](#despliegue)
16. [Solución de problemas](#solución-de-problemas)

---

## Resumen

Una Progressive Web App es un sitio web que el navegador puede instalar como una
aplicación. Necesita tres cosas: un **manifiesto de aplicación web** que la
describe, un **service worker** que se sitúa entre la página y la red, y
**HTTPS**.

SFPHP genera las dos primeras con un solo comando. Todo son archivos estáticos
en `public/`. No se ejecuta PHP para la PWA en tiempo de petición, y no se añade
nada a `composer.json`.

| Pieza | Archivo | Lo produce |
|-------|------|-------------|
| Manifiesto | `public/manifest.json` | `src/Pwa/ManifestGenerator.php` |
| Service worker | `public/service-worker.js` | `src/Pwa/ServiceWorkerGenerator.php` |
| Página offline | `public/offline.html` | copia de `resources/pwa/offline-template.html` |
| Script de registro | `public/install-sw.js` | copia de `resources/pwa/install-sw.js` |
| Iconos | `public/assets/icons/*.png` | `src/Pwa/IconGenerator.php`, solo con `--logo` |
| Ajustes | `app/pwa/config.php` | lo editas tú. `make:pwa` lo lee pero nunca lo escribe |

---

## Requisitos

- **PHP 8.1+**, como el resto del framework.
- **ext-gd**, pero solo para generar iconos a partir de `--logo`. Lee PNG, JPEG
  y GIF, y WebP cuando tu compilación de GD lo admite. Sin GD, `make:pwa` sigue
  escribiendo todos los demás archivos. Imprime `⚠ Could not generate icons: The GD
  extension (ext-gd) is required to generate icons.` y tú añades los PNG a
  `public/assets/icons/`.
- **ext-fileinfo no hace falta.** El tipo de imagen se lee con
  `getimagesize()`, que forma parte del propio PHP.
- **HTTPS en producción.** Los navegadores solo registran service workers en
  orígenes seguros. `http://localhost` y `http://127.0.0.1` cuentan como
  seguros, así que `./sfphp serve` sirve para el desarrollo.

---

## Inicio rápido

```bash
# 1. Generarlo todo (los iconos necesitan ext-gd)
./sfphp make:pwa --name="My App" --logo=path/to/logo.png

# 2. Añadir las cuatro etiquetas de "Añadir la PWA a tu layout" al <head> de tu layout

# 3. Ejecutarlo y mirar
./sfphp serve
```

Abre `http://127.0.0.1:8000`, pulsa F12 y ve a **Application**. En
**Manifest** deberías ver el nombre y los iconos. En **Service workers**,
`/service-worker.js` debería aparecer como *activated and running*.

El proyecto incluye `app/pwa/config.php`, y si ese archivo existe `--name` es
opcional. Un simple `./sfphp make:pwa` toma entonces el nombre del archivo, que
lee `APP_NAME` de `.env`.

---

## El comando make:pwa

```bash
./sfphp make:pwa --name="My App" [options]
```

| Opción | Efecto | Por defecto |
|--------|--------|---------|
| `--name="..."` | El `name` del manifiesto, y la base del nombre de la caché. **Obligatoria cuando `app/pwa/config.php` no existe.** | `name` de la configuración |
| `--short="..."` | El `short_name` del manifiesto | Cuando se da `--name`: sus primeros 12 caracteres. Si no, `short_name` de la configuración |
| `--description="..."` | La `description` del manifiesto | `description` de la configuración, o vacía |
| `--color="#hex"` | El `theme_color` del manifiesto | `theme_color` de la configuración, o `#007AFF` |
| `--background="#hex"` | El `background_color` del manifiesto | `background_color` de la configuración, o `#ffffff` |
| `--logo=path` | Genera los iconos a partir de esta imagen | ninguno. Los iconos se omiten |
| `--enable-push` | Añade los handlers `push` y `notificationclick` al service worker | `service_worker.enable_push_notifications` |
| `--enable-sync` | Añade el handler `sync` al service worker | `service_worker.enable_background_sync` |

Los valores se resuelven en este orden: **flag, luego `app/pwa/config.php`,
luego el valor por defecto incorporado.** Un flag solo se aplica a esa ejecución
y no cambia el archivo. `--enable-push` y `--enable-sync` solo pueden activar
una funcionalidad. Para desactivar una funcionalidad que la configuración
activa, ponla a `false` en el archivo.

Algunos ajustes no tienen flag: `start_url`, `scope`, `display`,
`orientation`, `icons` y todo lo que está bajo `service_worker` salvo los dos
interruptores de funcionalidad. Fíjalos en el archivo de configuración.

Cada ejecución **sobrescribe** `manifest.json`, `service-worker.js`,
`offline.html` e `install-sw.js`. Si editas a mano un archivo generado, volver a
ejecutar el comando reemplaza tu edición. El comando nunca edita tus
plantillas. Al final imprime las etiquetas del `<head>`, y añadirlas a tu layout
te corresponde a ti.

Ejemplos:

```bash
# Nombre, colores e iconos
./sfphp make:pwa --name="Task Tracker" --short="Tasks" \
  --description="Track tasks and goals" \
  --color="#2563eb" --background="#ffffff" \
  --logo=resources/logo.png

# Con los handlers de notificaciones push
./sfphp make:pwa --name="Task Tracker" --enable-push

# Regenerar a partir de app/pwa/config.php después de editarlo
./sfphp make:pwa
```

---

## Configuración

`app/pwa/config.php` devuelve un array. Solo lo lee `make:pwa`, y nada lo lee
en tiempo de petición, así que **un cambio llega al navegador solo después de
volver a ejecutar `./sfphp make:pwa`.**

El archivo incluido lee el nombre y la descripción de la aplicación de `.env` a
través de `Config::get()`, el lector de configuración del framework:

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
        'version' => 'v1',             // parte del nombre de la caché, véase "Nombre y versionado de la caché"
        'static_assets' => [           // se precachean cuando se instala el service worker
            '/assets/css/sfcss.min.css',
            '/assets/js/sfjs.min.js',
            '/offline.html',
        ],
        'api_routes' => [],            // patrones de ruta, '*' como comodín; nunca se cachean
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

Notas:

- **No hay variables de entorno `PWA_*`.** `APP_NAME` y `APP_DESCRIPTION` son
  los únicos valores que el archivo incluido toma de `.env`. Para controlar otro
  ajuste desde el entorno, léelo de la misma forma:
  `'theme_color' => Config::get('PWA_THEME_COLOR', '#007AFF')`. SFPHP no tiene
  función `env()`.
- **Una clave ausente recurre a su valor por defecto.** Una lista reemplaza la
  lista por defecto en lugar de fusionarse con ella. Si pones en
  `static_assets` dos URLs, el service worker precachea exactamente esas dos.
- **`display` y `orientation` se comprueban.** Un valor fuera del conjunto
  permitido detiene el comando con un error y no se escribe nada. Para
  `orientation` los valores permitidos son `portrait-primary`,
  `portrait-secondary`, `landscape-primary`, `landscape-secondary`, `portrait`
  y `landscape`.
- **Si el archivo no existe,** `make:pwa` usa los valores por defecto mostrados
  arriba y exige `--name`. El paquete guarda una copia de este archivo en
  `resources/pwa/config.php`. En un proyecto que instaló el framework con
  Composer, está en
  `vendor/fabioaacarneiro/sfphp-framework/resources/pwa/config.php`. Cópialo a
  `app/pwa/config.php`.

---

## Añadir la PWA a tu layout

`make:pwa` no edita plantillas. Añade estas etiquetas al `<head>` de tu layout,
por ejemplo `app/resources/views/layouts/base.sfht`:

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#007AFF">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<script src="/install-sw.js" defer></script>
```

- El valor de `<meta name="theme-color">` debe coincidir con `theme_color`. El
  comando imprime este bloque con tu color ya puesto.
- `install-sw.js` registra `/service-worker.js` con scope `/` y define
  `window.pwa`. Sin él no se registra ningún service worker.
- iOS ignora los iconos del manifiesto para la pantalla de inicio y usa
  `apple-touch-icon`.

Pon las etiquetas en todos los layouts a los que pueda llegar un usuario. Una
página sin el enlace al manifiesto no se puede instalar.

---

## Estrategia de caché

El `service-worker.js` generado trata las peticiones así:

| Petición | Estrategia |
|---------|----------|
| Todo lo que no es `GET` (POST, PUT, PATCH, DELETE) | No se intercepta. El navegador la envía con normalidad. |
| `GET` cuya ruta termina en `.js`, `.css`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.woff`, `.woff2`, `.ttf` o `.eot` | **Cache-first.** Se sirve desde la caché si está. Si no, se descarga, se guarda si la respuesta es correcta (u opaca, de otro origen) y se devuelve. Si la red falla, se devuelve la página offline de respaldo. |
| `GET` que coincide con un patrón de `api_routes` | **Solo red, nunca se cachea.** Si la red falla, se devuelve la página offline de respaldo. |
| Cualquier otro `GET`, incluidas todas las páginas HTML | **Solo red, nunca se cachea.** Si la red falla, se devuelve la página offline de respaldo. |

Lo que esto significa en la práctica:

- **Los archivos estáticos se cachean por extensión, no por la lista
  `static_assets`.** La lista solo se precachea en la instalación, para que esos
  archivos estén disponibles offline antes de la primera visita. Cualquier URL
  `.css`/`.js`/imagen/fuente que cargue la aplicación se cachea la primera vez
  que se descarga, desde cualquier origen, CDN incluida.
- **Cache-first nunca revalida.** Una vez cacheado `sfcss.min.css`, el service
  worker sirve esa copia hasta que se reemplaza la caché, incluso después de que
  despliegues uno nuevo. Consulta
  [Nombre y versionado de la caché](#nombre-y-versionado-de-la-caché).
- **Las páginas y las respuestas de API nunca se guardan**, así que el HTML o el
  JSON de un usuario con sesión iniciada nunca acaba en la caché.
  `api_routes` indica qué rutas son de API. Por ahora se tratan igual que
  cualquier otra petición no estática: solo red.
- **`.webp`, `.ico`, `.json`, `.mp4` y otras extensiones no son estáticas** a
  estos efectos. Son solo red.
- **Un patrón de `api_routes` está anclado y `*` coincide con cualquier cosa**,
  así que `/api/*` cubre `/api/users/7`. El resto del patrón se usa como
  expresión regular.
- **El precacheo es todo o nada.** `cache.addAll()` falla en bloque si falla una
  URL, por ejemplo con un 404. El service worker se instala igualmente, pero sin
  nada precacheado, y la consola registra `Some assets failed to cache`.
  Limita `static_assets` a URLs que existan. `/offline.html` debe ser una de
  ellas.

---

## Nombre y versionado de la caché

La caché se llama `<slug>-<version>`:

- `<slug>` es el nombre de la aplicación en minúsculas con los espacios
  sustituidos por guiones. `"My App"` se convierte en `my-app`.
- `<version>` es `service_worker.version` de la configuración, `v1` por
  defecto.

Así que `./sfphp make:pwa --name="My App"` con la configuración incluida usa
`my-app-v1`.

Cuando se activa un service worker nuevo, **elimina todas las cachés del origen
cuyo nombre no es el actual** y toma el control de las páginas abiertas de
inmediato (`skipWaiting()` y `clients.claim()`). Eso incluye cualquier caché que
tu propio código haya creado con la Cache API.

Para hacer llegar archivos estáticos nuevos a usuarios que ya los tienen en
caché:

1. Cambia `service_worker.version` en `app/pwa/config.php`, por ejemplo a
   `'v2'`.
2. Ejecuta `./sfphp make:pwa`.
3. Despliega. Los navegadores ven que `service-worker.js` cambió, lo instalan,
   precachean en `my-app-v2` y eliminan `my-app-v1`.

Cambiar el nombre de la aplicación también cambia el nombre de la caché. Para un
único archivo, una URL versionada también funciona.
`/assets/css/sfcss.min.css?v=2` es una entrada de caché distinta de la URL sin
versión.

---

## Soporte offline

Cuando falla una petición que va a la red, el service worker responde con el
`offline_fallback` cacheado (`/offline.html`). Las páginas nunca se cachean, así
que un usuario que se queda sin conexión ve esa página en cada navegación,
incluidas las páginas visitadas antes. No hay lectura offline de las páginas que
ya viste.

`public/offline.html` es una página autocontenida con CSS en línea y sin
peticiones externas. Tiene un botón **Retry** y un botón **Go Home**. Se recarga
sola cuando el navegador informa de que vuelve a haber conexión, y comprueba
`navigator.onLine` cada 3 segundos. Si la personalizas, edita
`resources/pwa/offline-template.html`, o edita `public/offline.html` y deja de
ejecutar `make:pwa`, que copia la plantilla encima.

Dos consecuencias que conviene conocer:

- **Un `fetch()` desde tu JavaScript también recibe la página offline.** Un
  `GET` fallido a un endpoint JSON se resuelve con el `offline.html` cacheado y
  estado 200. Comprueba `navigator.onLine`, o el `Content-Type` de la
  respuesta, antes de interpretarla como JSON.
- **Si `/offline.html` no se precacheó**, porque falta en `static_assets` o
  porque el precacheo falló, una petición fallida termina en el propio error de
  red del navegador.

---

## Iconos e instalación

`--logo` escribe cuatro PNG en `public/assets/icons/`:

| Archivo | Tamaño | Lo usa |
|------|------|---------|
| `icon-192x192.png` | 192×192 | el manifiesto (pantalla de inicio de Android) |
| `icon-512x512.png` | 512×512 | el manifiesto (pantalla de bienvenida, diálogo de instalación) |
| `apple-touch-icon.png` | 180×180 | la pantalla de inicio de iOS, mediante la etiqueta `<link rel="apple-touch-icon">` |
| `badge-72x72.png` | 72×72 | el `badge` por defecto de las notificaciones push |

Un logo que no es cuadrado se ajusta dentro del cuadrado y se centra sobre un
fondo transparente, sin estirarlo. Parte de una imagen cuadrada de al menos
512×512.

El manifiesto lista los iconos de la clave `icons` de la configuración, y por
defecto son los iconos de 192 y 512 con `purpose: "any"`. No se genera ningún
icono maskable. Si haces uno, con el logo dentro de la zona segura central y un
fondo opaco, añádelo a `icons` con `'purpose' => 'maskable'`. iOS dibuja en
negro las zonas transparentes de `apple-touch-icon.png`, así que un logo con
fondo opaco queda mejor allí.

**Cuándo ofrece el navegador la instalación.** Los navegadores basados en
Chromium muestran su aviso de instalación cuando la página está en HTTPS (o en
localhost), enlaza un manifiesto con un nombre, un icono de 192 y otro de 512,
`start_url` y un `display` distinto de `browser`, y está controlada por un
service worker con un handler `fetch`. Todo lo que se genera aquí cumple eso una
vez que existen los iconos. En iOS el usuario instala desde el menú Compartir de
Safari con **Añadir a pantalla de inicio**. Safari no muestra ningún aviso.

---

## Notificaciones push

`--enable-push` (o `'enable_push_notifications' => true`) añade dos handlers al
service worker, y nada más:

- **`push`** lee el contenido del mensaje como JSON y muestra una notificación:

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

  Los campos ausentes recurren al título `SFPHP Notification`, a los dos iconos
  mostrados arriba y a la etiqueta `sfphp-notification`. El contenido **debe
  ser JSON**. Cualquier otro texto hace que el handler lance una excepción, y no
  se muestra ninguna notificación.
- **`notificationclick`** cierra la notificación y enfoca una ventana abierta
  cuya URL sea exactamente `data.url`, o abre `data.url`, `/` por defecto.

**Lo que SFPHP no proporciona:**

- **Ningún código de suscripción.** Ni `install-sw.js` ni el service worker
  llaman a `pushManager.subscribe()`.
- **Ningún emisor en el servidor.** Enviar un mensaje Web Push implica firmar un
  JWT VAPID (RFC 8292) y cifrar el contenido (RFC 8291). No hay ninguna clase
  para eso en el framework, ni ninguna dependencia que lo haga. Usa tu propia
  implementación o un servicio de push externo.

Una suscripción mínima en el cliente, con tu clave pública VAPID:

```javascript
async function subscribeToPush(vapidPublicKey) {
    if (!(await window.pwa.requestNotifications())) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidPublicKey, // base64 URL-safe o un Uint8Array
    });

    await fetch('/api/push/subscriptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription),
    });
}
```

Y una ruta que la guarda:

```php
// app/routes/api.php
use SfphpProject\src\Router;

Router::post('/api/push/subscriptions', 'PushSubscriptionController', 'store');
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

        // Guarda el endpoint y las claves para el usuario actual, y luego:
        logger()->info('Push subscription stored', ['endpoint' => $subscription['endpoint']]);

        return Response::json(['ok' => true], 201);
    }
}
```

Si la ruta pasa por la protección CSRF, envía el token en la cabecera
`X-CSRF-Token`. `csrf_meta()` lo pone en la página para que tu script lo lea.

Para comprobar que los handlers muestran bien las notificaciones sin un
servidor, llama a `window.pwa.testNotification()` después de conceder el
permiso. Muestra una notificación local a través del registro del service
worker.

---

## Sincronización en segundo plano

`--enable-sync` (o `'enable_background_sync' => true`) añade un handler `sync`
para la etiqueta `sync-data`. **Tal como viene, no hace nada útil.** Entiende
exactamente por qué antes de confiar en él:

- **Nada encola las peticiones fallidas.** El handler de fetch ignora toda
  petición que no sea GET, así que un POST que falla sin conexión simplemente
  falla en tu página.
- **El handler no tiene nada que reproducir.** Busca en la Cache API peticiones
  POST y PUT. La Cache API no puede guardar peticiones POST, así que nunca
  encuentra ninguna.
- **Nada registra la sincronización.** El navegador solo dispara `sync` después
  de que llames a `registration.sync.register('sync-data')`.
- **La Background Sync API es exclusiva de Chromium.** Firefox y Safari no
  disparan `sync` en absoluto.

Para escrituras offline de verdad, guarda tú la petición (IndexedDB es el lugar
habitual), registra la sincronización y reproduce las peticiones guardadas en
el evento `sync`:

```javascript
// En la página, cuando un guardado falla porque el usuario está sin conexión
await saveToIndexedDb({ url: '/api/tasks', body: task }); // tu propio almacenamiento
const registration = await navigator.serviceWorker.ready;
if ('sync' in registration) {
    await registration.sync.register('sync-data');
}
```

El código de reproducción va en `public/service-worker.js`, en lugar de
`syncOfflineData()`. **Volver a ejecutar `make:pwa` sobrescribe ese archivo**,
así que mantén tu versión bajo control de versiones y vuelve a aplicarla tras
regenerar, o deja de regenerar una vez que personalices el service worker.

---

## La API window.pwa

`install-sw.js` define `window.pwa` **solo en los navegadores que admiten
service workers**, y en los demás sale antes. Comprueba que existe antes de
usarlo: `if (window.pwa) { … }`.

| Miembro | Qué hace |
|--------|--------------|
| `pwa.unregister()` | Anula el registro de todos los service workers del origen. Asíncrono. |
| `pwa.clearCache()` | Elimina **todas** las cachés del origen. Asíncrono. |
| `pwa.requestNotifications()` | Pide permiso para las notificaciones. Se resuelve con `true` si se concede, `false` si se deniega o no se admite. |
| `pwa.testNotification()` | Muestra una notificación local de prueba a través del service worker. Necesita permiso. |
| `pwa.getCacheInfo()` | Se resuelve con un objeto que asocia cada nombre de caché a una cadena como `"12 items"`. |
| `pwa.isOnline()` | Devuelve `navigator.onLine`. |
| `pwa.onOnline(callback)` | Añade un listener al evento `online` del navegador. |
| `pwa.onOffline(callback)` | Añade un listener al evento `offline` del navegador. |

Eventos despachados en `window`:

| Evento | Cuándo |
|-------|------|
| `pwa:update-available` | Un service worker nuevo terminó de instalarse mientras uno anterior controlaba la página. Si el permiso de notificaciones está concedido, se muestra además una notificación "App Update Available". |
| `pwa:online` | El navegador volvió a tener conexión. |
| `pwa:offline` | El navegador se quedó sin conexión. |

El script también llama a `registration.update()` cada hora, así que una
pestaña que se deja abierta recoge un service worker nuevo.

```javascript
window.addEventListener('pwa:update-available', () => {
    // El nuevo worker ya tomó el control (skipWaiting + clients.claim),
    // así que basta con recargar para obtener los nuevos recursos.
    if (confirm('A new version is available. Reload now?')) {
        location.reload();
    }
});

if (window.pwa) {
    window.pwa.getCacheInfo().then(info => console.table(info));
}
```

---

## Pruebas y depuración

En las DevTools de Chrome o Edge, abre **Application**:

- **Manifest** muestra el `manifest.json` interpretado, los iconos y cualquier
  problema de instalabilidad.
- **Service workers** muestra el registro. **Update on reload** es útil
  mientras trabajas. **Offline** simula la pérdida de red, y **Unregister**
  elimina el worker.
- **Cache storage** lista `<slug>-<version>` y sus entradas.
- **Storage → Clear site data** empieza desde cero.

Cosas que probar:

1. Carga una página, marca **Offline** y recarga. Deberías obtener
   `offline.html`.
2. Todavía sin conexión, comprueba que el CSS y el JS ya descargados vienen del
   service worker, en la columna *Size* del panel Network.
3. Cambia `service_worker.version`, ejecuta `./sfphp make:pwa`, recarga y
   comprueba que solo queda la caché nueva.
4. Ejecuta **Lighthouse** con la categoría PWA para obtener un informe de
   instalabilidad.

Desde la consola: `await pwa.getCacheInfo()`, `await pwa.clearCache()`,
`await pwa.unregister()`.

---

## Despliegue

- **Sirve por HTTPS.** Sin él no se registra nada.
- **Mantén `service-worker.js` en la raíz del sitio.** Su scope es `/`, y un
  worker no puede controlar URLs por encima de su propia ruta.
- **Envía `Cache-Control: no-cache` para `/service-worker.js`**, para que el
  navegador compruebe si hay una versión nueva en cada visita. De todos modos,
  los navegadores ignoran la caché HTTP para él pasadas 24 horas, pero solo
  entonces.
- **Cambia `service_worker.version` en cada versión que modifique archivos
  estáticos**, ejecuta `./sfphp make:pwa` y despliega el `service-worker.js`
  regenerado. Si no, los usuarios se quedan con el CSS y el JS cacheados.
- **Haz commit de los archivos generados** (`public/manifest.json`,
  `public/service-worker.js`, `public/offline.html`, `public/install-sw.js`,
  `public/assets/icons/`), o ejecuta `make:pwa` en tu build. El servidor no
  genera nada en tiempo de ejecución.

---

## Solución de problemas

**`Error: --name is required when there is no app/pwa/config.php`**
Pasa `--name="..."`, o copia `resources/pwa/config.php` a
`app/pwa/config.php`.

**`⚠ Could not generate icons: The GD extension (ext-gd) is required to generate icons.`**
Instala o activa GD (por ejemplo `php8.3-gd` en Debian/Ubuntu), o pon tú los
cuatro PNG en `public/assets/icons/`.

**`⚠ Could not generate icons: Unsupported image format...`**
El logo no es un archivo PNG, JPEG, GIF o WebP, o tu GD no puede leer WebP.
Conviértelo a PNG.

**`⚠ Skipping icon generation: path not found`**
La ruta de `--logo` se resuelve a partir del directorio en el que ejecutas el
comando.

**No aparece el aviso de instalación**
Consulta el motivo en **Application → Manifest**. Las causas habituales son que
faltan los iconos, ya que sin `--logo` el manifiesto apunta a PNG que no
existen, una página que no enlaza el manifiesto, o HTTP sin cifrar en un host
distinto de localhost.

**El service worker no se registra**
Comprueba que `install-sw.js` está incluido y que `/service-worker.js` carga en
el navegador. La consola muestra `✗ Service Worker registration failed` con el
motivo.

**Los usuarios siguen viendo el CSS/JS antiguo después de un despliegue**
Es cache-first funcionando tal como está diseñado. Cambia
`service_worker.version`, regenera y despliega. Consulta
[Nombre y versionado de la caché](#nombre-y-versionado-de-la-caché).

**Mis cambios en `service-worker.js` u `offline.html` han desaparecido**
`make:pwa` sobrescribe ambos en cada ejecución.

**Un cambio en `app/pwa/config.php` no tiene efecto**
El archivo solo lo lee `make:pwa`. Vuelve a ejecutar el comando.

**`Some assets failed to cache` en la consola**
Una de las URLs de `static_assets` no carga, así que no se precacheó nada.
Corrige o elimina esa URL.

**Llegan mensajes push pero no aparece ninguna notificación**
El service worker se generó sin `--enable-push`, o el contenido no es JSON.

**El evento `sync` nunca se dispara**
Nada lo registra por ti, y solo Chromium lo admite. Consulta
[Sincronización en segundo plano](#sincronización-en-segundo-plano).

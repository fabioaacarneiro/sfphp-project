# Guía Completa PWA - SFPHP

> **Lee en:** [English](PWA_GUIDE.md) · [Português](PWA_GUIDE.pt-BR.md) · [Español](PWA_GUIDE.es.md)

**Transforma tu aplicación SFPHP en una Progressive Web App poderosa con soporte offline, notificaciones push e instalación en pantalla de inicio.**

---

## Tabla de Contenidos

1. [¿Qué es una PWA?](#qué-es-una-pwa)
2. [Inicio Rápido (5 minutos)](#inicio-rápido)
3. [Componentes PWA](#componentes-pwa)
4. [Configuración](#configuración)
5. [Estrategias de Cache](#estrategias-de-cache)
6. [Soporte Offline](#soporte-offline)
7. [Notificaciones Push](#notificaciones-push)
8. [Sincronización en Background](#sincronización-en-background)
9. [Iconos e Instalación](#iconos-e-instalación)
10. [Pruebas y Depuración](#pruebas-y-depuración)
11. [Despliegue en Producción](#despliegue-en-producción)
12. [Consejos de Rendimiento](#consejos-de-rendimiento)
13. [Solución de Problemas](#solución-de-problemas)

---

## ¿Qué es una PWA?

Una **Progressive Web App (PWA)** es una aplicación web que utiliza capacidades modernas para ofrecer una experiencia similar a una aplicación nativa.

### Beneficios Principales

```
✅ Instalar en pantalla de inicio (sin app store)
✅ Funciona offline (contenido en caché)
✅ Carga rápida (caché de service worker)
✅ Notificaciones push (mantén usuarios comprometidos)
✅ Sincronización offline (guarda datos, sincroniza online)
✅ Experiencia pantalla completa (sin UI navegador)
✅ Funciona en todos los dispositivos (responsivo)
```

### Ejemplos Reales

- Twitter PWA (64% menos uso de datos)
- Spotify PWA (instala desde web)
- WhatsApp Web (mensajes offline)
- Uber Eats PWA (3x más rápido, 75% más pequeño)

---

## Inicio Rápido

### 1. Generar Setup PWA

```bash
./sfphp make:pwa \
  --name="Mi App" \
  --short="App" \
  --color="#007AFF" \
  --logo=ruta/a/logo.png
```

**Lo que crea:**
- ✅ `public/manifest.json` - Metadatos PWA
- ✅ `public/service-worker.js` - Soporte offline
- ✅ `public/offline.html` - Página offline personalizada
- ✅ `public/icons/` - Iconos del app
- ✅ `public/install-sw.js` - Instalador service worker
- ✅ `app/pwa/config.php` - Archivo configuración

### 2. Probar en Navegador

1. **Abre tu app:** `http://localhost:8000`
2. **Abre DevTools:** F12 → pestaña Aplicación
3. **Verifica Manifest:** Sección "Manifest"
4. **Verifica Service Worker:** Sección "Service Workers"
5. **Instala:** Haz clic en prompt "Instalar"

### 3. Activar Recursos (Opcional)

Edita `.env`:

```env
PWA_ENABLED=true
PWA_PUSH_NOTIFICATIONS=true
PWA_BACKGROUND_SYNC=true
```

Luego regenera:

```bash
./sfphp make:pwa --enable-push --enable-sync
```

---

## Componentes PWA

### 1. Archivo Manifest (`manifest.json`)

Define cómo tu PWA aparece en instalación, pantalla de inicio y splash screen.

**Ubicación:** `public/manifest.json`

```json
{
  "name": "Mi App",
  "short_name": "App",
  "description": "Mi app increíble",
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

### 2. Service Worker (`service-worker.js`)

**Ubicación:** `public/service-worker.js`

Archivo JavaScript que corre en background, interceptando peticiones de red y gestionando caché.

**Responsabilidades:**

```
┌─────────────────────────────────────┐
│    Service Worker                   │
├─────────────────────────────────────┤
│  Install → Cachear assets estáticos │
│  Activate → Limpiar caches antiguos │
│  Fetch → Interceptar peticiones     │
│  Push → Manejar notificaciones      │
│  Sync → Sincronizar datos offline   │
└─────────────────────────────────────┘
```

### 3. Script Instalación (`install-sw.js`)

**Ubicación:** `public/install-sw.js`

Registra el service worker y proporciona API JavaScript de PWA.

```html
<!-- En tu layout base -->
<script src="/install-sw.js" defer></script>
```

---

## Configuración

### Archivo de Config

Edita `app/pwa/config.php`:

```php
return [
    'enabled' => env('PWA_ENABLED', true),

    'name' => 'Mi App',
    'short_name' => 'App',
    'description' => 'Mi app increíble',

    'theme_color' => '#007AFF',
    'background_color' => '#ffffff',

    'display' => 'standalone',
    'start_url' => '/',
    'scope' => '/',
    'orientation' => 'portrait-primary',

    'cache' => [
        'name' => 'app-v1',

        'static_assets' => [
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
            '/index.html',
        ],

        'api_routes' => ['/api/*'],
        'offline_fallback' => '/offline.html',
    ],

    'push_notifications' => env('PWA_PUSH_NOTIFICATIONS', false),
    'background_sync' => env('PWA_BACKGROUND_SYNC', false),
];
```

---

## Estrategias de Cache

SFPHP PWA usa dos estrategias:

### 1. Cache-First (Assets Estáticos)

Para **imágenes, CSS, JavaScript, fuentes** que cambian raramente.

```
Petición usuario → Verificar caché → Encontrado? ✓ Retornar
                                    No encontrado? → Red → Caché → Retornar
```

**Beneficios:**
- ✅ Extremadamente rápido
- ✅ Funciona offline
- ✅ Reduce ancho de banda

**Desventaja:**
- ❌ Actualizaciones requieren invalidar caché

### 2. Network-First (Llamadas API)

Para **endpoints API** que necesitan datos frescos.

```
Petición usuario → Intentar red → Exitoso? ✓ Caché → Retornar
                               Falló? → Caché → Retornar versión
```

**Beneficios:**
- ✅ Datos frescos online
- ✅ Funciona offline con respuesta cacheada
- ✅ Experiencia fluida

**Desventajas:**
- ⚠️ Ligéramente más lento
- ⚠️ Datos potencialmente antiguos offline

---

## Soporte Offline

### Cómo Funciona

1. **Instalar:** Service worker cachea assets estáticos
2. **Offline:** Cuando falla red, sirve desde caché
3. **Online:** Busca datos frescos y sincroniza cambios offline

### Página de Fallback Offline

Cuando una petición falla y no está en caché, usuarios ven `/offline.html`.

Edita `public/offline.html` para personalizar.

### Testar Offline

**En DevTools:**

1. DevTools → pestaña Red
2. Marca checkbox "Offline"
3. Actualiza página
4. App debe funcionar con contenido cacheado

### API PWA en JavaScript

```javascript
// Verificar estado conexión
if (pwa.isOnline()) {
    console.log('Online');
} else {
    console.log('Offline');
}

// Escuchar cambios conexión
pwa.onOnline(() => console.log('¡Volvimos online!'));
pwa.onOffline(() => console.log('Sin conexión'));

// Escuchar eventos PWA
window.addEventListener('pwa:online', () => {
    console.log('Conexión restaurada');
    location.reload(); // Actualizar datos
});
```

---

## Notificaciones Push

### Activar Notificaciones Push

1. **En .env:**

```env
PWA_PUSH_NOTIFICATIONS=true
```

2. **Regenerar PWA:**

```bash
./sfphp make:pwa --enable-push
```

3. **Pedir Permiso:**

```javascript
async function enableNotifications() {
    const granted = await pwa.requestNotifications();
    if (granted) {
        console.log('¡Notificaciones activadas!');
        pwa.testNotification();
    }
}
```

### Enviar Push desde Backend

**PHP:**

```php
use SfphpProject\src\Pwa\PushNotificationService;

$pushService = new PushNotificationService();

$pushService->sendToUser($userId, [
    'title' => 'Nuevo Mensaje',
    'body' => 'Tienes un nuevo mensaje de Juan',
    'icon' => '/icon-192x192.png',
    'tag' => 'new-message',
    'data' => ['url' => '/messages/123'],
]);
```

---

## Sincronización en Background

### Activar Background Sync

1. **En .env:**

```env
PWA_BACKGROUND_SYNC=true
```

2. **Regenerar PWA:**

```bash
./sfphp make:pwa --enable-sync
```

### Cómo Funciona

1. **Usuario offline:** Peticiones POST/PUT fallan
2. **Service Worker:** Cachea la petición
3. **Usuario online:** Automáticamente reintenta peticiones cacheadas

### Ejemplo: Envío Formulario Offline

```javascript
document.querySelector('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    try {
        const response = await fetch('/api/submit', {
            method: 'POST',
            body: formData,
        });
        
        if (response.ok) {
            showNotification('✓ ¡Guardado!');
        }
    } catch (error) {
        if (!pwa.isOnline()) {
            showNotification('Guardado offline - sincronizará cuando estés online');
            
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                const registration = await navigator.serviceWorker.ready;
                await registration.sync.register('sync-data');
            }
        }
    }
});
```

---

## Iconos e Instalación

### Requisitos de Iconos

SFPHP genera tamaños estándar:

| Tamaño | Propósito |
|--------|-----------|
| 192x192 | Pantalla inicio Android |
| 512x512 | Splash screen Android |
| 180x180 | Apple touch icon |

### Generar Iconos

Desde imagen logo (PNG, JPG, WebP, GIF):

```bash
./sfphp make:pwa --logo=ruta/a/tu/logo.png
```

**Requisitos:**
- Imagen cuadrada (proporción 1:1)
- Mínimo 512×512 píxeles
- PNG recomendado (fondo transparente)
- Extensión PHP GD necesaria

### Prompts de Instalación

#### Android

1. Abre app
2. Menú → "Instalar app"
3. Selecciona "Instalar"

#### iOS

1. Abre app en Safari
2. Ícono Compartir → "Agregar a Pantalla de Inicio"
3. Nombra y agrega

#### Web

Navegador muestra prompt automáticamente (si criterios PWA se cumplen).

---

## Pruebas y Depuración

### Inspección DevTools

**Pestaña Aplicación:**

1. **Manifest:** Verifica todos los campos
2. **Service Workers:** Verifica estado de registro
3. **Cache Storage:** Inspecciona peticiones cacheadas
4. **Local Storage:** Verifica datos offline

### Testar Offline

1. DevTools → pestaña Red
2. Marca "Offline"
3. Actualiza página
4. Prueba navegación

### API PWA

```javascript
pwa.isOnline()                    // true/false
await pwa.requestNotifications()  // Pedir permiso
await pwa.testNotification()      // Enviar test
await pwa.getCacheInfo()          // Listar caches
await pwa.clearCache()            // Limpiar todo
await pwa.unregister()            // Remover SW
pwa.onOnline(callback)            // Conexión restaurada
pwa.onOffline(callback)           // Sin conexión
```

---

## Despliegue en Producción

### Requisitos

- **HTTPS:** Obligatorio para Service Workers
- **Certificado Válido:** Sin certificados auto-firmados
- **Todos archivos PWA:** manifest.json, service-worker.js, iconos

### Checklist Deploy

```
✅ HTTPS activado
✅ Verifica manifest.json accesible
✅ Verifica service-worker.js con rutas correctas
✅ Iconos en /public/icons/
✅ offline.html accesible
✅ Prueba en modo incógnito
✅ Prueba modo offline
✅ Verifica prompt de instalación aparece
```

---

## Consejos de Rendimiento

### 1. Gestión Versión Cache

Cambia nombre cache para forzar descarga:

```php
'cache' => [
    'name' => 'app-' . time(), // Fuerza caché nuevo
],
```

### 2. Cache Selectivo

Cachea solo assets esenciales:

```php
'cache' => [
    'static_assets' => [
        '/css/sfcss.min.css',  // Crítico
        '/js/sfjs.min.js',     // Crítico
        '/index.html',         // Crítico
    ],
],
```

### 3. Patrones Ruta API

Sé específico con rutas API:

```php
'api_routes' => [
    '/api/data/*',        // Cachear datos
    '/api/search/*',      // Cachear búsqueda
],
```

---

## Solución de Problemas

### Service Worker No Registra

**Problema:** "Falla en registro del Service Worker"

**Soluciones:**

1. **Verifica HTTPS:** Service Workers requieren HTTPS
```bash
# Desarrollo: localhost es excepción
http://localhost:8000
```

2. **Verifica archivo:** Confirma `/public/service-worker.js`
3. **Verifica ruta:** Ruta correcta en script instalador
4. **Verifica console:** DevTools → pestaña Console
5. **Limpia caché:** `Ctrl+Shift+Del` → "Imágenes/archivos cacheados"

### Prompt Instalación No Aparece

**Problema:** Botón "Instalar app" faltando

**Checklist:**

1. ✅ HTTPS activado (o localhost)
2. ✅ Manifest.json válido
3. ✅ Service Worker registrado
4. ✅ Iconos presentes
5. ✅ manifest.json linkado en HTML

### Página Offline No Muestra

**Problema:** Offline, pero no viendo `/offline.html`

**Causas:**

1. `/offline.html` no accesible
2. Service Worker no instalado
3. Página nunca fue cacheada

**Corregir:**

1. Verifica si archivo existe: `public/offline.html`
2. Reinstala app: Limpia caché y actualiza
3. Cachea manualmente página:

```php
'cache' => [
    'static_assets' => [
        '/offline.html',  // Pre-cachear
    ],
],
```

### Notificaciones No Funcionan

**Problema:** "Permiso denegado" o notificaciones silenciosas

**Soluciones:**

1. **Verifica permiso:** DevTools → pestaña Seguridad
2. **Pide permiso explícitamente:**

```javascript
const permission = await Notification.requestPermission();
if (permission === 'granted') {
    pwa.testNotification();
}
```

3. **Verifica SW tenga listener**
4. **Prueba en sitio deployado**

### Cache Datos Antiguos

**Problema:** Contenido actualizado no aparece

**Solución:** Invalida cache cambiando versión

```php
'cache' => [
    'name' => 'app-' . date('Ymd'),  // Cambia diariamente
],
```

Luego regenera:

```bash
./sfphp make:pwa
```

---

## Resumen

¡Ahora tienes una PWA lista para producción! 🎉

**Lo que puedes hacer:**

✅ Instalar en pantalla de inicio
✅ Funciona offline
✅ Enviar notificaciones push
✅ Sincronizar datos offline
✅ Carga rápida (cacheada)
✅ Funciona en todos los dispositivos

**Próximos pasos:**

1. Personalizar colores y branding
2. Probar en diferentes dispositivos
3. Configurar backend notificaciones
4. Monitorear rendimiento
5. Desplegar en producción

**Recursos:**

- [MDN Web Docs - PWA](https://developer.mozilla.org/es/docs/Web/Progressive_web_apps)
- [Google PWA Checklist](https://web.dev/pwa-checklist/)
- [web.dev aprendiendo PWA](https://web.dev/pwa/)

---

## ¿Preguntas?

Únete a la comunidad SFPHP:

- 📚 [Documentación](https://github.com/fabioaacarneiro/sfphp-project)
- 💬 [GitHub Discussions](https://github.com/fabioaacarneiro/sfphp-project/discussions)
- 🐛 [Reporta Issues](https://github.com/fabioaacarneiro/sfphp-project/issues)

¡Feliz desarrollo! 🚀

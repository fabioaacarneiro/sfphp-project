# 🚀 SFPHP — Simple Framework PHP

> **¿Te gusta SFPHP?** ⭐ [Danos una estrella en GitHub](https://github.com/fabioaacarneiro/sfphp-project) — ¡nos ayuda a crecer y mantiene vivo el framework!

> **Lee esto en:** [English](README.md) · [Português](README.pt-BR.md) · [Español](README.es.md)

**El framework PHP para desarrolladores a quienes les importan el rendimiento, la seguridad y la simplicidad.**

Un framework PHP full-stack, listo para producción, con **cero dependencias en tiempo de ejecución**, hecho para ser rápido y pensado para llegar a producción. Solo PHP 8.1+, tu base de datos y tu código — no hace falta nada más.

---

## ¿Por qué elegir SFPHP?

### ⚡ **Rendimiento ultrarrápido**
- **Sin dependencias que engorden el proyecto** — solo la biblioteca estándar de PHP y el driver de tu base de datos
- **HTTP concurrente, medido** — tres peticiones salientes cuestan lo que cuesta una; `benchmarks/` tiene los scripts que reproducen las cifras
- **Consultas eficientes** — relaciones cargadas por adelantado con `with()` para evitar consultas N+1, caché inteligente
- **Sobrecarga mínima del framework** — tu código se ejecuta de inmediato, no enterrado bajo capas

### 🔒 **Seguridad incorporada**
- **Confianza cero por defecto** — protección CSRF, binding de SQL y escape de XSS en todas partes
- **Autenticación probada en batalla** — sesiones, tokens JWT, políticas, «recordarme»
- **Validación de peticiones** — parámetros de ruta con tipos, filtrado de la entrada
- **Nada de teatro de seguridad** — implementamos lo que importa y nos saltamos lo que creó el culto a la costumbre

### 🎯 **Experiencia de desarrollo**
- **Tipos en todas partes** — atributos de PHP 8.1, parámetros tipados, autocompletado en el IDE
- **Generadores para ir rápido** — 16 generadores `make:*` para modelos, migraciones, controladores, tests y más
- **CLI completa** — 38 comandos para gestionar tu aplicación
- **API intuitiva** — la aprendes una vez y funciona igual en todas partes

### 🌍 **Multilingüe de verdad**
- **UTF-8 primero** — la longitud, la validación, el enrutamiento y la conversión de mayúsculas y minúsculas son correctos con Unicode
- **i18n incorporada** — catálogos de idioma, reglas de pluralización, negociación de Accept-Language
- **Mensajes del framework en el idioma del visitante** — hasta los errores 404 respetan el locale

### 📦 **Todo lo que necesitas, nada que no necesites**
- Más de 150 funcionalidades de seguridad incorporadas
- Migraciones y seeders de base de datos
- Email con SMTP/TLS
- Caché en Redis y en archivos
- Colas de trabajos en segundo plano
- Gestión de sesiones
- Gestión de subida de archivos
- Logging y depuración

---

## Lo que ofrece SFPHP

### Backend y núcleo

| Funcionalidad | Lo que obtienes |
|---------|-------------|
| **Enrutamiento** | Parámetros tipados, grupos, rutas con nombre, middleware por ruta |
| **HTTP** | Objetos Request/Response, pipeline de middleware, códigos de estado |
| **Base de datos** | Query builder con relaciones, migraciones, seeders, transacciones |
| **ORM (Models)** | Hidratación de objetos, tipos de atributo, with() para carga anticipada |
| **Schema Builder** | Más de 40 tipos de columna, paridad perfecta MySQL 8 ↔ PostgreSQL 12 |
| **Autenticación** | Sesiones, tokens JWT, hash de contraseñas, políticas, rememberme |
| **Autorización** | Control de acceso basado en Gate, clases de política |
| **Validación** | Validación de formularios, reglas personalizadas, mensajes de error |
| **Middleware** | Global, por grupo, por ruta, verificación automática de CSRF |

### Frontend y vistas

| Funcionalidad | Lo que obtienes |
|---------|-------------|
| **Plantillas SFHT** | Escape automático, herencia de layouts, composición de componentes |
| **Componentes .phpx** | Marcado dentro de funciones PHP, compilado en el build |
| **Framework SFCSS** | 3.836 clases — componentes y utilidades — a partir de una sola configuración: formularios, navegación, modales, menús desplegables, tema oscuro, contraste calculado para WCAG AA, 33KB comprimidos |
| **Biblioteca SFJS** | Un solo archivo: AJAX, validación accesible, streaming, modal, menú desplegable, tooltip, pestañas y toasts — 14KB comprimidos |
| **Assets incorporados** | Publicados en `public/` sin ninguna configuración |

### Funcionalidades avanzadas

| Funcionalidad | Lo que obtienes |
|---------|-------------|
| **Sistema async/await** | Fibers de PHP sobre un event loop real: las peticiones HTTP se solapan, los temporizadores y los timeouts son del loop. Las consultas se programan, no se solapan. |
| **Difusión de eventos** | Pub/sub con comodines `user.*`, historial de eventos, despacho asíncrono |
| **Caché** | Drivers de archivo, memoria y Redis con invalidación inteligente |
| **Colas de trabajos** | Workers en segundo plano con reintentos, drivers de base de datos o Redis |
| **Email** | SMTP con TLS, texto plano + HTML, adjuntos |
| **Subida de archivos** | Detección del tipo a partir de los bytes, almacenamiento seguro, rechazo de archivos falsificados |
| **Logging** | Líneas JSON en UTC, trazado de peticiones, ocultación de secretos |
| **Fechas y horas** | UTC en todas partes, la zona horaria solo para mostrar |
| **Depuración** | Páginas de error cuidadas, `dump()` y `dd()`, salida en el terminal |

---

## Perfecto para estos escenarios

### 📱 **APIs de alto tráfico**
Por qué gana SFPHP: llamadas salientes que se solapan, caché inteligente, query builder optimizado, y un footprint sin dependencias que significa memoria mínima por petición.

**Ejemplo:** tu endpoint llama a tres servicios. Medido contra un origen local que responde en 100 ms, un endpoint que hace tres llamadas tiene la misma latencia y el mismo rendimiento que uno que hace una sola — 80 RPS, p50 de 208 ms, bajo la carga descrita en `benchmarks/server.php`.

### 🌐 **Plataformas multilingües**
Por qué gana SFPHP: soporte de i18n de primera clase con negociación de idioma, tratamiento de UTF-8 correcto con Unicode de principio a fin, mensajes del framework en el idioma del visitante.

**Ejemplo:** un marketplace que atiende más de 10 idiomas — reglas de pluralización, localización de contenido y negociación de Accept-Language incorporadas.

### 🛡️ **Aplicaciones críticas en seguridad**
Por qué gana SFPHP: diseño con la seguridad primero — CSRF por defecto, binding de SQL siempre, escape de XSS automático, validación estricta de JWT, regeneración de la sesión al iniciar sesión.

**Ejemplo:** paneles financieros, sistemas de historias clínicas y paneles de administración que no pueden permitirse concesiones.

### ⚡ **Aplicaciones en tiempo real**
Por qué gana SFPHP: streaming HTTP y Server-Sent Events incorporados (`@stream` en SFJS, `Response::stream()` en el servidor), difusión asíncrona de eventos, estado reactivo con invalidación de caché.

**Ejemplo:** paneles en vivo, aplicaciones de chat, herramientas colaborativas en las que las actualizaciones tienen que propagarse al instante.

### 📊 **Sistemas con uso intensivo de datos**
Por qué gana SFPHP: procesamiento de streams con map/filter/reduce, operaciones masivas, procesamiento asíncrono por lotes, paginación eficiente para grandes conjuntos de datos.

**Ejemplo:** importaciones de CSV, generación de informes, herramientas de pipelines de datos que procesan millones de registros sin que la memoria se dispare.

### 🔄 **Aplicaciones monolíticas**
Por qué gana SFPHP: pilas incluidas — autenticación, autorización, validación, logging, email, colas. Sin saltar entre 20 paquetes.

**Ejemplo:** sistemas de gestión de contenidos, plataformas SaaS, aplicaciones de negocio en las que quieres todo en un solo framework.

### 💼 **Integraciones empresariales**
Por qué gana SFPHP: cero dependencias significa CVEs mínimos, facilidad para el análisis estático, nada de infierno de versiones y trazabilidad para el cumplimiento de seguridad.

**Ejemplo:** sistemas que tienen que integrarse con código heredado, APIs bancarias o infraestructura corporativa sin arrastrar árboles de dependencias.

### 🚀 **MVP de startup**
Por qué gana SFPHP: rápido de programar, difícil de romper, nada que configurar, 16 generadores de CLI, migraciones incorporadas, y el despliegue son solo archivos PHP.

**Ejemplo:** lanza un SaaS, un marketplace o un servicio sin semanas de decisiones de infraestructura.

---

## Primeros pasos

### Instalación

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

Eso es todo. Abre `http://localhost:8000` y tendrás:
- ✅ Una aplicación funcionando con código de ejemplo
- ✅ Una migración, un seeder y una factory de usuarios
- ✅ JWT configurado con una clave secreta real
- ✅ CSS y JavaScript publicados y listos
- ✅ La CLI completa disponible en `./sfphp`

### Genera tu primer modelo

```bash
./sfphp make:model Product
./sfphp make:migration create_products name:string price:decimal timestamps
./sfphp migrate
```

### Crea un controlador

```bash
./sfphp make:controller Product     # creates app/controllers/ProductController.php
```

### Define una ruta

```php
Router::get('/products', [ProductController::class, 'index']);
Router::get('/products/id:number', [ProductController::class, 'show']);   // add show() to the controller
```

### Llama a tres servicios a la vez

```php
use SfphpProject\src\Http\Http;
use function SfphpProject\src\Async\await;

$a = Http::getAsync('https://billing.internal/invoices/7');
$b = Http::getAsync('https://catalog.internal/products/42');
$c = Http::getAsync('https://ratings.internal/products/42');

[$invoice, $product, $ratings] = [await($a), await($b), await($c)];
```

Las tres peticiones ya están en la red antes del primer `await`, así que esto
tarda más o menos lo que la más lenta, no la suma de las tres. Medido: 301 ms
para tres peticiones de 300 ms, 309 ms para cincuenta.

> **Las consultas no funcionan así.** `await(User::query()->getAsync())` programa
> la consulta — no la solapa, porque PDO no tiene una API asíncrona y ningún
> Fiber cambia eso. Tres consultas esperadas juntas tardan lo mismo que tres
> consultas: 609 ms frente a 603 ms, medido. Esto muestra la diferencia entre
> *programación asíncrona* y *E/S no bloqueante*.

### Hecho para cualquier idioma

```php
Str::length('日本語');           // 3 (not 9 bytes)
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/products/name:alpha', ...);  // matches /products/café
__('http.not_found_message');  // in the visitor's language
```

---

## La filosofía de SFPHP

**Simple** — Hacer una cosa bien es mejor que hacer muchas a medias.

**Seguro** — La seguridad no se añade, viene por defecto. Sin atajos. Sin «solo por esta vez».

**Rápido** — Con async/await, consultas nativas a la base de datos y nada de peso muerto, tu aplicación es rápida sin esfuerzo.

**Tuyo** — El framework es `/src/` — borra lo que no necesites. Todo lo demás es tu código.

**Transparente** — Sin magia. Sin capas ocultas. Lee el código cuando tengas curiosidad; está todo en un mismo lugar.

---

## Lo que NO se incluye (y por qué)

- **Recuperación de contraseña** — Tu aplicación tiene que enviarla por email de todos modos; nosotros nos ocupamos de la infraestructura
- **Doble factor** — No hay una solución única para todos; Mail cubre la parte que le tocaba al framework
- **Brokers de mensajes** — Las colas incorporadas suelen bastar; conecta RabbitMQ cuando lo necesites
- **ORM completo** — Un query builder con relaciones es mejor para la mayoría de las aplicaciones; te mantiene al mando
- **Relaciones polimórficas** — Un caso límite; no justifica 200 líneas de código en la mayoría de los sistemas

**Principio:** nunca añadas complejidad hasta demostrar que la necesitas. SFPHP te da las piezas para construir lo adecuado para tu aplicación.

---

## Documentación

Completa en tres idiomas — inglés, portugués y español, cada uno una versión íntegra:

| Idioma | Framework | Async | Streaming | PWA | Estilos | Componentes |
|----------|-----------|-------|-----------|-----|---------|------------|
| 🇬🇧 **English** | [Docs](docs/en/DOCUMENTATION.md) | [Async/Await](docs/en/ASYNC.md) | [Streaming](docs/en/STREAMING.md) | [PWA Guide](docs/en/PWA_GUIDE.md) | [SFCSS](docs/en/SFCSS.md) | [.phpx](docs/en/PHPX_COMPONENTS.md) |
| 🇧🇷 **Português** | [Docs](docs/pt-BR/DOCUMENTATION.md) | [Async/Await](docs/pt-BR/ASYNC.md) | [Streaming](docs/pt-BR/STREAMING.md) | [Guia PWA](docs/pt-BR/PWA_GUIDE.md) | [SFCSS](docs/pt-BR/SFCSS.md) | [.phpx](docs/pt-BR/PHPX_COMPONENTS.md) |
| 🇪🇸 **Español** | [Docs](docs/es/DOCUMENTATION.md) | [Async/Await](docs/es/ASYNC.md) | [Streaming](docs/es/STREAMING.md) | [Guía PWA](docs/es/PWA_GUIDE.md) | [SFCSS](docs/es/SFCSS.md) | [.phpx](docs/es/PHPX_COMPONENTS.md) |

**[SFHT](docs/es/DOCUMENTATION.md#vistas-y-sfht)** es marcado en un archivo; **.phpx** es un componente escrito como una función PHP
con su marcado dentro. Los dos vienen incluidos, y la documentación explica
cuándo conviene cada uno.

**¿Inicio rápido?**
- 📱 [Guía de configuración de PWA](docs/es/PWA_GUIDE.md)
- 📖 [Documentación completa](docs/es/DOCUMENTATION.md)

---

## Requisitos del sistema

- **PHP** 8.1 o posterior
- **Composer** 2.0+
- **Base de datos** (opcional) — cualquier base de datos compatible con PDO (MySQL 8+, PostgreSQL 12+, SQLite)
- **Caché** (opcional) — archivo, memoria o Redis
- **Cola** (opcional) — base de datos o Redis

Requiere extensiones que vienen con PHP: `ext-ctype`, `ext-curl`, `ext-fileinfo`, `ext-filter`, `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo`, `ext-session`, `ext-tokenizer` — más el driver PDO de tu base de datos. Opcionales: `ext-redis` para los drivers Redis, `ext-pcntl` para que los workers de la cola terminen de forma ordenada (Unix), `ext-posix`, `ext-gd` para los iconos de la PWA, `ext-readline` para tinker. Sin dependencias de Composer.

---

## Tests

```bash
composer run test       # the unit suite (php tests/run.php)
composer run test:db    # Integration tests on real MySQL & PostgreSQL
composer run lint       # PHP syntax check
composer run docs       # Verify documentation consistency
```

La suite de tests se ejecuta en **PHP 8.1–8.4** en CI.

---

## Seguridad

Nos tomamos la seguridad en serio. Consulta [SECURITY.md](SECURITY.md) para:
- Cómo informar de vulnerabilidades
- Las prácticas de seguridad que usa SFPHP
- Dónde encontrar la documentación detallada de seguridad

---

## Licencia

MIT — Consulta [LICENSE](LICENSE)

**Creado por** Fabio Carneiro  
**Colaboradores** La comunidad

---

## ¿Listo para construir?

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

Tu próxima gran aplicación empieza ahora. 🚀

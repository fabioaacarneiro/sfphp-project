# SFPHP — Simple Framework PHP

> 🌍 **Léelo en:** [English](README.md) ·
> [Português](README.pt-BR.md) · [Español](README.es.md)

Un framework PHP full-stack con **cero dependencias de runtime**, pensado para
usarse en cualquier idioma y cualquier escritura.

`composer.json` exige solo `php ^8.1`, `ext-json` y `ext-pdo`. El directorio
`vendor/` no contiene más que el autoloader de Composer. Ninguna página que
sirva el framework — ni siquiera las de error — carga CSS, tipografías o
JavaScript desde un CDN.

## Requisitos

- PHP 8.1 o posterior
- Composer 2
- PDO con el driver de tu base de datos (opcional — solo si usas base de datos)

## Instalación

Como dependencia:

```bash
composer require fabio/sfphp
```

```php
require __DIR__ . '/../vendor/autoload.php';

SfphpProject\src\Bootstrap::load(dirname(__DIR__));
```

O empieza desde la aplicación de ejemplo, con rutas, vistas y migraciones ya
puestas:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
```

Ajusta el `.env`:

- **`JWT_KEY`** — obligatoria para emitir o validar tokens. Genera una con
  `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- **Base de datos** — opcional. Configúrala solo si la necesitas.

El propio `.env` es opcional: un clon nuevo arranca sin configuración, y cada
funcionalidad que realmente necesita un valor falla con un mensaje que lo
nombra.

## Ejecución

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalente
```

En producción, apunta el `DocumentRoot` a `public/`.

## Qué trae

| | |
|---|---|
| **Enrutamiento** | Parámetros tipados que funcionan en cualquier escritura, grupos, rutas con nombre, generación de URL |
| **HTTP** | Objetos Request/Response y un pipeline de middleware — global, por grupo y por ruta |
| **i18n** | Catálogos por idioma (en, pt_BR, es), negociación de `Accept-Language`, plurales por rango o regla del idioma |
| **Autenticación** | Guards de sesión y de token, hashing sobre `password_hash`, políticas mediante `Gate` |
| **Contenedor DI** | Autoconexión por reflexión, fábricas perezosas, detección de ciclos |
| **Constructor de consultas** | Identificadores en lista blanca, todo valor vinculado, paginación por dialecto, transacciones |
| **Modelos** | Hidratación en objetos, tipos de atributo, relaciones (incluido muchos a muchos) y `with()` contra el N+1 |
| **Constructor de esquemas** | Más de 30 tipos de columna con paridad real MySQL 8 ↔ PostgreSQL 12 |
| **SFHT** | Motor de plantillas con escapado automático, herencia de layout y una caché compatible con OPcache |
| **Registro** | Líneas JSON en UTC, un id de petición que une todas las líneas de una petición, secretos redactados |
| **Tiempo** | UTC en todo, incluida la sesión de la base de datos; la zona es una decisión de presentación |
| **Sesiones** | Plazos de inactividad y absoluto, validación estricta de id, y almacén compartible entre instancias |
| **Caché** | Drivers de archivo, memoria y Redis |
| **Colas** | Workers con reintentos, drivers de base de datos y Redis |
| **SFCSS** | 2.337 clases de utilidad con variantes `hover:` y responsivas, 16,1KB comprimidos |
| **SFJS** | AJAX, DOM, validación y atributos declarativos — 3,0KB comprimidos |
| **CLI** | 32 comandos, 12 generadores de código |

## Hecho para cualquier idioma

El manejo de UTF-8 está construido sobre **PCRE con `/u`**, no sobre `mbstring`
— PCRE siempre está compilado dentro de PHP, `mbstring` es opcional.

```php
Str::length('日本語');            // 3, no 9
Str::truncate('日本語テキスト', 5); // 日本... nunca un byte partido por la mitad
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/productos/nombre:alpha', 'ProductoController', 'show');   // casa /productos/café
```

Y los mensajes del propio framework salen en el idioma del visitante — incluso
en un 404, que nunca llega a un controlador:

```php
__('http.not_found_title');     // sigue el Accept-Language de la petición
trans_choice('app.items', 5);   // formas de plural por rango o por regla del idioma
```

## Seguridad

- **CSRF** — un token de 32 bytes, comparación en tiempo constante con
  `hash_equals`, cookie con `httponly` + `samesite=Lax` + `secure` sobre HTTPS.
  Helpers `csrf_field()`, `csrf_meta()`, `csrf_verify()`
- **JWT** — HS256, valida firma, `alg`, `typ` y `exp`; rechaza `alg: none` y
  exige una clave de 32 bytes
- **SQL** — todo valor se vincula, todo identificador se valida contra una lista
  blanca
- **XSS** — el `{{ }}` de SFHT escapa por defecto; la salida cruda exige
  `{!! !!}`
- **Asignación masiva** — un modelo debe declarar `$fillable`; sin eso,
  rellenarlo desde un arreglo lanza
- **Cabeceras** — `nosniff`, `X-Frame-Options` y `Referrer-Policy` por defecto;
  CSP y HSTS disponibles y apagadas, porque ambas son fáciles de equivocar
- **Limitación de peticiones** — el middleware `RateLimit`, con contadores en la
  caché
- **Proxies** — `X-Forwarded-*` solo se lee de proxies declarados
- **Contraseñas** — `password_hash` con `PASSWORD_DEFAULT`, y `Auth::attempt()`
  iguala el tiempo de respuesta para que una cuenta inexistente no se distinga
  de una contraseña equivocada
- **Sesión** — el id se regenera al entrar y al salir, `use_strict_mode`
  rechaza un id que PHP nunca emitió, y hay plazos de inactividad y absoluto
- **Middleware** — `VerifyCsrfToken` aplica la comprobación CSRF por defecto a
  toda petición que cambia estado

El framework sigue la regla **validar a la entrada, escapar a la salida**. Los
valores de la petición llegan sin modificar a propósito: escapar a la entrada
corrompería el dato en la base de datos sin proteger su destino real.

## Calidad

```bash
composer run lint        # php -l por todo el proyecto
composer run test        # 108 casos unitarios
composer run test:db     # integración contra MySQL/PostgreSQL reales
composer run docs        # los tres idiomas de la documentación concuerdan
```

La CI ejecuta la suite sobre una matriz de PHP 8.1–8.4 **sin `mbstring`**, que
es lo que garantiza que el manejo de Unicode no dependa de la extensión, y las
pruebas de esquema contra MySQL 8 y PostgreSQL 16 reales.

## Qué no trae

Dicho de frente, para que decidas con los datos: no hay sistema de eventos, y la
autenticación cubre inicio de sesión, guards y autorización, pero no
recuperación de contraseña, doble factor ni revocación de tokens. La capa de
Modelos **no es un ORM completo** — sin mapa de identidad, unidad de trabajo,
proxy de carga perezosa, relación polimórfica ni esquema derivado de la clase.
El motivo de cada ausencia está en
[¿ORM o constructor de consultas?](docs/es/DOCUMENTATION.md#orm-o-constructor-de-consultas),
incluido un caso en que la pieza ausente sería un riesgo de seguridad bajo un
runtime persistente, y no solo un coste. La lista completa, con el impacto de
cada ausencia, está en [Seguridad](docs/es/DOCUMENTATION.md#seguridad) y
[Limitaciones conocidas](docs/es/DOCUMENTATION.md#limitaciones-conocidas).

## Documentación

Completa en tres idiomas — ninguno de ellos un resumen de otro:

| Idioma | Framework | SFCSS | Utilidades SFCSS |
|---|---|---|---|
| 🇬🇧 **English** *(principal)* | [Documentation](docs/en/DOCUMENTATION.md) | [SFCSS](docs/en/SFCSS.md) | [Utilities](docs/en/SFCSS_UTILITIES.md) |
| 🇧🇷 **Português** | [Documentação](docs/pt-BR/DOCUMENTATION.md) | [SFCSS](docs/pt-BR/SFCSS.md) | [Utilitários](docs/pt-BR/SFCSS_UTILITIES.md) |
| 🇪🇸 **Español** | [Documentación](docs/es/DOCUMENTATION.md) | [SFCSS](docs/es/SFCSS.md) | [Utilidades](docs/es/SFCSS_UTILITIES.md) |

Empieza por [docs/](docs/README.md) para elegir idioma.

## Licencia

MIT. Creado por Fabio Carneiro.

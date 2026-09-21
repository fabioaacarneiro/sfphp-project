# SFPHP — Documentación

Framework PHP full-stack con **cero dependencias de runtime** y corrección
Unicode en toda su superficie. Esta documentación describe lo que el código
hace hoy. Donde algo no existe, se dice que no existe — véase
[Limitaciones conocidas](#limitaciones-conocidas).

> Verificado contra PHP 8.4 · suite: 90 pruebas, 0 fallos
>
> 🌍 Disponible también en [English](../en/DOCUMENTATION.md) y
> [Português](../pt-BR/DOCUMENTATION.md).

---

## Índice

- [Qué es y qué no es](#qué-es-y-qué-no-es)
- [Requisitos e instalación](#requisitos-e-instalación)
- [Estructura del proyecto](#estructura-del-proyecto)
- [Ciclo de vida de la petición](#ciclo-de-vida-de-la-petición)
- [Enrutamiento](#enrutamiento)
- [Controladores](#controladores)
- [Middleware](#middleware)
- [Vistas y SFHT](#vistas-y-sfht)
- [Contenedor e inyección de dependencias](#contenedor-e-inyección-de-dependencias)
- [Base de datos](#base-de-datos)
- [Modelos](#modelos)
- [¿ORM o constructor de consultas?](#orm-o-constructor-de-consultas)
- [Migraciones y constructor de esquemas](#migraciones-y-constructor-de-esquemas)
- [Seeders y factories](#seeders-y-factories)
- [Caché](#caché)
- [Colas](#colas)
- [Validación](#validación)
- [Internacionalización](#internacionalización)
- [Cadenas UTF-8](#cadenas-utf-8)
- [Autenticación](#autenticación)
- [Seguridad](#seguridad)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Manejo de errores](#manejo-de-errores)
- [Registro](#registro)
- [CLI](#cli)
- [SFCSS](#sfcss)
- [SFJS](#sfjs)
- [Pruebas](#pruebas)
- [Limitaciones conocidas](#limitaciones-conocidas)

---

## Qué es y qué no es

**Es** un framework ligero para aplicaciones web y APIs, con enrutamiento,
objetos Request/Response, una tubería de middleware, un contenedor de
inyección de dependencias, un constructor de consultas, un constructor de
esquemas con paridad MySQL/PostgreSQL, un motor de plantillas, caché, colas y
un CLI con 32 comandos.

**No es** un sustituto de Laravel o Symfony. No hay un ORM completo ni sistema
de eventos, y la autenticación cubre inicio de sesión, guards y autorización,
pero no recuperación de contraseña ni doble factor. Lo que existe es lo
bastante pequeño para leerse de principio a fin.

### Cero dependencias, literalmente

`composer.json` exige solo `php ^8.1`, `ext-json` y `ext-pdo`. El directorio
`vendor/` no contiene **más que el autoloader de Composer**.

Lo mismo vale para el navegador: ninguna página que sirva el framework —
incluidas las páginas de error 404 y 500 — carga CSS, fuentes ni JavaScript
desde un CDN.

Extensiones opcionales, declaradas en `suggest`:

| Extensión | Habilita |
|---|---|
| `ext-mbstring` | Conversión de mayúsculas Unicode más precisa. Sin ella `upper`/`lower` recurren a ASCII; el resto del manejo UTF-8 no depende de ella |
| `ext-redis` | Los drivers Redis de caché y colas |
| `ext-pcntl` | Apagado ordenado del worker de colas |

---

## Requisitos e instalación

- PHP 8.1 o superior
- Composer 2
- PDO con el driver de tu base de datos (opcional — solo si usas una)

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env

# Genera la clave JWT (necesaria para emitir o validar tokens)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalente
```

En producción, apunta el `DocumentRoot` a `public/`.

---

## Estructura del proyecto

```
src/            El framework (namespace SfphpProject\src)
app/            Código de EJEMPLO — ilustrativo, no prescriptivo
public/         Document root: index.php y assets/ (css, js, images)
database/       migrations/, seeders/, factories/ de la aplicación
tools/          El generador de SFCSS
tests/          Suite propia, sin PHPUnit
docs/           Esta documentación
sfphp           Punto de entrada del CLI
server.php      Script de enrutado del servidor incorporado
```

Autocarga PSR-4:

| Prefijo | Directorio |
|---|---|
| `SfphpProject\src\` | `src/` |
| `SfphpProject\app\` | `app/` |
| `Database\Seeders\` | `database/seeders/` |
| `Database\Factories\` | `database/factories/` |

Y cuatro archivos cargados siempre (`autoload.files`): `app/config/config.php`,
`src/utils.php`, `src/http.php`, `src/helpers.php`.

---

## Ciclo de vida de la petición

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ config.php → carga .env (opcional), define APP_NAME/VERSION/ENV/LOCALE
 │      utils.php  → helpers globales: e(), asset(), csrf_*()
 │      http.php   → constantes HTTP_OK, GET, POST, ...
 │      helpers.php→ cache(), logger(), dispatch(), __(), trans_choice(), locale()
 ├─ ErrorHandler::register()  red de seguridad para fatales y arranque
 ├─ require src/routes.php    llena el registro estático de rutas
 ├─ new Container()
 │   └─ set(PDO::class, closure)   conexión perezosa
 ├─ Request::setTrustedProxies()   nada se confía hasta declararlo
 ├─ Request::fromGlobals()    el único punto que lee superglobales
 ├─ Router->dispatch($request)
 │   └─ middleware global → grupo → ruta → acción → Response
 └─ Emitter->emit($response)  el único punto que escribe salida
```

`.env` es **opcional**. Un clon nuevo arranca sin configuración; lo que
realmente necesita un valor (la base de datos, JWT) falla por su cuenta, con un
mensaje concreto.

---

## Enrutamiento

Las rutas viven en `src/routes.php`. La API es **estática**.

```php
use SfphpProject\src\Router;

Router::get('/', 'MainController', 'index')->name('home');
Router::post('/users', 'UserController', 'store')->name('users.store');
```

La firma es siempre `(string $url, string $controller, string $action)` — tres
argumentos separados, no `'Controller@action'`.

El controlador se resuelve bajo `SfphpProject\app\controllers\{Controller}`, y
ese namespace es un parámetro del constructor, así que una aplicación puede
poner sus controladores en otro sitio.

### Métodos

```php
Router::get($url, $controller, $action);
Router::post(...);
Router::put(...);
Router::patch(...);
Router::delete(...);
Router::head(...);
Router::options(...);
```

Una ruta sin coincidencia devuelve **404**. Una ruta que coincide con el método
equivocado devuelve **405** con cabecera `Allow`. `OPTIONS` devuelve **204**
automáticamente cuando la ruta tiene métodos registrados.

### Parámetros

La sintaxis es `nombre:tipo`, **sin llaves**:

```php
Router::get('/posts/id:number', 'PostController', 'show');
Router::get('/users/username:alpha', 'UserController', 'profile');
Router::get('/codes/code:alphanum', 'CodeController', 'show');
```

| Tipo | Coincide con | Nota |
|---|---|---|
| `number` | `[0-9]+` | ASCII a propósito: el valor existe para sobrevivir a un `(int)`, y la conversión de PHP no entiende cifras arábigo-índicas ni devanagari |
| `alpha` | `\p{L}+` | Cualquier alfabeto: `café`, `北京`, `Владимир` |
| `alphanum` | `[\p{L}\p{N}]+` | Letras y dígitos de cualquier escritura |

Los valores llegan a la acción **por posición**, en el orden en que aparecen en
la URL, después de la petición:

```php
Router::get('/tenant/tenantId:number/posts/postId:number', 'PostController', 'show');

public function show(Request $request, string $tenantId, string $postId): Response
{
    // ...
}
```

La ruta de la petición se decodifica segmento a segmento antes de comparar, de
modo que `/productos/caf%C3%A9` coincide con `/productos/nombre:alpha`. Los
separadores codificados (`%2F`, `%5C`) **no** se convierten en separadores
reales: `/a%2Fb` nunca alcanza la ruta `/a/b`.

### Grupos

La función no recibe argumentos — las rutas registradas dentro heredan el
prefijo:

```php
Router::group('/api', function (): void {
    Router::get('/posts', 'ApiPostController', 'index')->name('posts.index');
    Router::post('/posts', 'ApiPostController', 'store')->name('posts.store');
}, 'api.');
```

El tercer argumento es el prefijo de **nombre**, así que esas rutas quedan como
`api.posts.index` y `api.posts.store`. Los grupos se anidan. Un cuarto
argumento acepta middleware — véase [Middleware](#middleware).

### Rutas con nombre y generación de URL

```php
Router::url('posts.show', ['id' => 42]);                   // /posts/42
Router::url('posts.index', [], ['page' => 2]);             // /posts?page=2
Router::url('users.profile', ['username' => 'café']);      // /users/caf%C3%A9
```

`url()` valida los valores contra el tipo del parámetro y lanza
`InvalidArgumentException` si falta, es inválido o es desconocido. Los nombres
duplicados se rechazan al registrarse.

---

## Controladores

Una acción recibe el `Request` como **primer argumento** y devuelve un
`Response`. Los parámetros de ruta vienen después, en el orden en que aparecen
en la URL. Una sola regla, sin excepciones y sin reflexión.

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class PostController extends BaseController
{
    public function show(Request $request, string $id): Response
    {
        return $this->view('posts/show', ['id' => (int) $id]);
    }

    public function store(Request $request): Response
    {
        return Response::json(['id' => 1], HTTP_CREATED);
    }
}
```

El `Response` devuelto es lo que el framework envía. Nada de `echo`, nada de
`header()`, nada de `exit` — y fue precisamente `exit` lo que impedía que
cualquier middleware se ejecutara después del controlador.

### Qué puede devolver una acción

`Response::from()` convierte el valor devuelto, así los casos comunes quedan
cortos:

| Devuelto | Se convierte en |
|---|---|
| `Response` | sí mismo |
| `string` | `Response::html(...)` |
| `array` o `JsonSerializable` | `Response::json(...)` |
| **nada** | **un error**, nombrando `Clase::acción()` |

Devolver nada es un error a propósito. Es como se delata una acción que olvidó
su `return`; un 200 vacío escondería el problema.

### Request

```php
$request->method;                    // 'POST'
$request->path;                      // '/productos/café', ya decodificado
$request->isMethod('post');

$request->query('page');             // cadena de consulta
$request->body('title');             // cuerpo analizado
$request->input('title', 'por defecto'); // cuerpo → JSON → cadena de consulta
$request->all();                     // todo, combinado
$request->filled('title');

$request->header('Authorization');   // búsqueda sin distinguir mayúsculas
$request->bearerToken();
$request->json();                    // decodifica el cuerpo, lanza JsonException
$request->rawBody;

$request->cookie('sesion');
$request->file('avatar');
$request->ip();
$request->isSecure();
$request->expectsJson();

$request->user();                    // lo pone el middleware Authenticate
$request->route('id');               // parámetro de ruta
$request->attribute('locale');       // adjuntado por un middleware
$conUsuario = $request->withAttribute('user', $user);   // clona
```

Los valores vuelven **sin modificar**, por decisión de diseño. El escapado es
propiedad del destino, no del valor: escapar en la entrada corrompe el dato
(`O'Brien` se guardaba como `O&#39;Brien`; una contraseña `a<b` se hasheaba
como `a&lt;b`) y no protege nada, porque un valor escapado para HTML sigue
siendo inseguro en SQL o en un shell.

La regla que sigue el framework es: **validar en la entrada, escapar en la
salida.**

- Validar con `Validator`, que comprueba sin modificar
- Vincular, nunca concatenar, al hablar con la base de datos — `QueryBuilder` y
  `RawQuery` vinculan todo
- Escapar en el punto de salida — `{{ }}` de SFHT escapa solo, y `e()` existe
  para plantillas PHP crudas

`Request` nunca lee una superglobal por su cuenta: el constructor recibe
arreglos, y `Request::fromGlobals()` es el único punto del framework que toca
`$_SERVER`, `$_GET`, `$_POST` y compañía. Eso es lo que hace el enrutamiento
comprobable y lo que un runtime persistente necesita.

### Response

```php
Response::html('<h1>Hola</h1>');
Response::text('ok');
Response::json(['id' => 1], HTTP_CREATED);
Response::view('posts/index', ['posts' => $posts]);
Response::redirect('/posts');
Response::noContent();

$response->withStatus(HTTP_NOT_FOUND);
$response->withHeader('X-Request-Id', $id);   // reemplaza sin duplicar
$response->withBody('otro cuerpo');

$response->status();  $response->body();  $response->header('Content-Type');
```

`Response` es un objeto de valor: no llama a `header()`, no imprime, no toca el
búfer de salida. Convertirlo en bytes es tarea del `Emitter`, y esa separación
es lo que permite probar todo el camino sin búfer de salida.

### BaseController

```php
$this->view('posts/index', ['posts' => $posts]);   // un Response de HTML
$this->redirect('/posts');                          // un Response de redirección
```

### BaseAPIController

```php
$this->json(['ok' => true], HTTP_CREATED);

// Decodifica el cuerpo, o devuelve la respuesta de error ya lista
$data = $this->payload($request);
if ($data instanceof Response) {
    return $data;
}
```

`payload()` responde **415** si el `Content-Type` no es `application/json` y
**400** si el cuerpo no decodifica.

### Helpers globales

Cargados en cada petición por `src/utils.php`:

```php
e($valor);                    // escapa para HTML: <script> → &lt;script&gt;
asset('css/app.css');         // → /assets/css/app.css
asset('js/sfjs.js');          // → /assets/js/sfjs.js
csrf_token();  csrf_field();  csrf_meta();  csrf_verify();
```

`asset()` antepone `/assets/` y **valida la ruta**: el recorrido de directorios
y los caracteres fuera de `[A-Za-z0-9._-]` lanzan
`InvalidArgumentException`.

Los archivos estáticos viven en `public/assets/{css,js,images}/`.

Y por `src/helpers.php`:

```php
cache();                      // un CacheManager con el driver de archivos
logger();                     // un LogManager, configurado desde LOG_*
dispatch(new MiJob());        // encola un trabajo
__('app.welcome', ['name' => 'Ana']);
trans_choice('app.items', 3);
locale();
```

---

## Middleware

Un middleware recibe la petición, puede inspeccionarla o sustituirla, y llama a
`$next` para pasarla adelante. Lo que va antes de `$next` se ejecuta en la
entrada; lo que va después, en la salida, con la respuesta en mano. Devolver
sin llamar a `$next` detiene todo lo que sigue.

```php
<?php

namespace SfphpProject\app\middleware;

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class RequireTokenMiddleware implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->bearerToken() === null) {
            return Response::json(['message' => 'Unauthorized'], HTTP_UNAUTHORIZED);
        }

        return $next($request)->withHeader('X-Served-By', 'sfphp');
    }
}
```

```bash
./sfphp make:middleware RequireToken
```

### Registro

Tres niveles, ejecutados en este orden: **global → grupo → ruta → acción.**

```php
// Global, en public/index.php — se aplica también a 404 y 405
$router = (new Router($container))->middleware(
    SecurityHeaders::class,
    StartSession::class,
    VerifyCsrfToken::class
);

// Por grupo, en src/routes.php
Router::group('/admin', function (): void {
    Router::get('/panel', 'AdminController', 'index');
}, 'admin.', [RequireTokenMiddleware::class]);

// Por ruta
Router::get('/informe', 'ReportController', 'show')
    ->middleware(RequireTokenMiddleware::class)
    ->name('informe');
```

El middleware global envuelve el despacho entero, **incluidas las peticiones
que no coinciden con ninguna ruta**. Es deliberado: una cabecera CORS o un
registro de peticiones que se saltan los 404 son un error, no una
optimización.

Un middleware puede ser un nombre de clase, una instancia o un callable. Un
nombre de clase se resuelve por el **contenedor**, así que el middleware puede
declarar dependencias en su constructor y recibirlas por autowiring.

### Los middleware que trae el framework

| Middleware | Hace |
|---|---|
| `LogRequests` | Da un id a la petición y registra su desenlace |
| `SecurityHeaders` | Añade `nosniff`, `X-Frame-Options`, `Referrer-Policy`; CSP y HSTS bajo demanda |
| `SetLocale` | Negocia el idioma a partir de `Accept-Language` |
| `StartSession` | Inicia la sesión con cookie `httponly` + `samesite=Lax` + `secure` bajo HTTPS |
| `VerifyCsrfToken` | Rechaza una petición que cambia estado sin un token válido |
| `Authenticate` | Identifica al usuario, y rechaza anónimos cuando se exige |
| `RateLimit` | Limita cuántas veces el mismo cliente golpea una ruta |

`VerifyCsrfToken` deja pasar los métodos seguros y las peticiones con token
Bearer — un navegador nunca adjunta un Bearer por su cuenta, así que no hay
petición entre sitios que falsificar. Se pueden eximir prefijos:

```php
new VerifyCsrfToken(['/api'])
```

> Hasta esta versión la verificación CSRF existía pero **nada en el framework
> la llamaba**: cada aplicación tenía que acordarse de comprobar en cada
> acción, y olvidarlo no producía error alguno. Ahora se aplica por defecto.

---

## Vistas y SFHT

### Renderizado

```php
use SfphpProject\src\View;

View::make('posts/index', ['posts' => $posts]);    // devuelve una cadena
View::makePartial('header', ['title' => 'Mi Sitio']);

// O directamente a una respuesta:
Response::view('posts/index', ['posts' => $posts]);
```

`View::render()` y `View::partial()` siguen existiendo e imprimen, pero están
**obsoletas**: un `Response` necesita un cuerpo que pueda transportar, no una
salida que ya escapó hacia el cliente.

Los nombres de vista se validan contra el recorrido de directorios. Las
plantillas viven en `app/resources/views/` con extensión **`.sfht`**.

Para controlar rutas y caché directamente:

```php
use SfphpProject\src\View\SfhtEngine;

$engine = new SfhtEngine([__DIR__ . '/views'], '/tmp/sfht-cache');
echo $engine->render('home', ['title' => 'Hola']);
```

### Salida

```sfht
{{ $name }}              escapa HTML — usa este
{!! $html !!}            salida cruda — solo para HTML que tú produjiste
{{-- comentario --}}     se elimina al compilar, nunca llega al HTML
```

**`{{ }}` escapa por defecto** (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8). La forma
segura es la corta; esquivarla exige escribir más.

La expresión es PHP real — llamadas a funciones, operadores e índices
funcionan:

```sfht
{{ count($items) }}
{{ $user['name'] }}
{{ $total > 0 ? 'sí' : 'no' }}
```

### Condicionales

```sfht
@if($user->isAdmin())
  <p>Admin</p>
@elseif($user->isPremium())
  <p>Premium</p>
@else
  <p>Visitante</p>
@endif

@unless($autorizado)
  <p>Acceso denegado</p>
@endunless
```

### Bucles

```sfht
@foreach($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@endforeach

@forelse($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@empty
  <p>Todavía no hay publicaciones.</p>
@endforelse

@for($i = 0; $i < 10; $i++)
  <p>{{ $i }}</p>
@endfor

@while($cola->tieneElementos())
  {{ $cola->siguiente() }}
@endwhile
```

### Herencia de plantillas

```sfht
{{-- layouts/base.sfht --}}
<!DOCTYPE html>
<html>
<head><title>@block('title')SFPHP@endblock</title></head>
<body>@block('content')@endblock</body>
</html>
```

```sfht
{{-- pages/home.sfht --}}
@extends('layouts/base')

@block('title')Inicio@endblock

@block('content')
  <h1>Bienvenido</h1>
@endblock
```

El hijo se renderiza primero y sus bloques ganan. Un bloque que el hijo no
define usa el contenido por defecto de la plantilla base. La plantilla base
también se renderiza sola. Los ciclos de `@extends` se detectan, con un límite
de 16 niveles.

### Parciales y componentes

```sfht
@include('partials/header')
@include('partials/card', ['title' => 'Hola'])
@includeWhen($mostrarForm, 'partials/form')
@component('components/button', ['label' => 'Enviar'])
```

Un parcial hereda las variables en alcance en el punto de inclusión; el arreglo
explícito gana. `@component` es sinónimo de `@include`.

### PHP incrustado

```sfht
@php
    $total = array_sum($valores);
@endphp

<p>Total: {{ $total }}</p>
```

### Filtros

Encadenables con `|`:

```sfht
{{ $texto | upper }}
{{ $texto | truncate(50) }}
{{ $texto | upper | truncate(20, '…') }}
{{ $precio | format('%.2f') }}
{{ $nombre | default('Anónimo') }}
```

| Filtro | Efecto |
|---|---|
| `upper` / `lower` | Mayúsculas y minúsculas |
| `capitalize` | Pone en mayúscula el primer carácter |
| `truncate(n, sufijo)` | Acorta a `n` **caracteres**; el sufijo cuenta dentro del límite |
| `length` | Caracteres de una cadena, o elementos de un arreglo |
| `reverse` | Invierte respetando los caracteres multibyte |
| `escape` | Escapa HTML explícitamente |
| `json` | JSON con `UNESCAPED_UNICODE` |
| `format(fmt)` | `sprintf` |
| `trim` | Quita los espacios de los extremos |
| `abs` / `round(n)` | Numéricos |
| `default(v)` | Sustituye `null` y la cadena vacía |

Los filtros de cadena cuentan **caracteres, no bytes**: `truncate(5)` sobre
`日本語テキスト` devuelve `日本...`, nunca un byte partido por la mitad.

`||` no se confunde con un filtro — `{{ $a || $b ? 's' : 'n' }}` funciona.

Registrar un filtro propio:

```php
$engine->addFilter('slug', fn (string $v): string
    => strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '-', $v)));
```

### Una `@` que no es directiva

Solo los nombres de directiva conocidos se convierten en sintaxis. Todo lo
demás es texto:

```sfht
<link href="...family=Inter:wght@300;400">   {{-- se conserva --}}
Escribe a soporte@ejemplo.com                {{-- se conserva --}}
@media (min-width: 40rem) { ... }            {{-- se conserva --}}
```

### Variables globales

```php
$engine->setGlobal('siteName', 'Mi Sitio');
$engine->setGlobals(['version' => '1.0.0', 'anio' => date('Y')]);
```

### Caché de compilación

Las plantillas se compilan a PHP en disco y se ejecutan con `include`, de modo
que **OPcache funciona** y los errores de ejecución señalan un archivo y una
línea reales. La escritura es atómica e invalida OPcache en esa ruta exacta. La
caché se revalida por marca de tiempo.

```php
$engine->clearCache();
```

### Errores de plantilla

Las directivas desbalanceadas fallan al compilar, indicando la línea:

```
Unclosed @if opened on line 12.
@endforeach on line 20 closes @if opened on line 12.
@empty on line 8 must appear inside @forelse.
Unclosed "{{" expression on line 3.
Filter not registered: noexiste
```

---

## Contenedor e inyección de dependencias

```php
use SfphpProject\src\Container;

$container = new Container();

// Una instancia lista
$container->set(Mailer::class, new Mailer());

// Una fábrica perezosa — solo se ejecuta cuando alguien la pide
$container->set(PDO::class, fn (): PDO => Database::connect());

$mailer = $container->get(Mailer::class);
$container->has(Mailer::class);
```

Las claves son el **nombre completamente cualificado** de la clase
(`PDO::class`, no `'pdo'`), porque así es como el resolvedor busca un servicio
al rellenar un parámetro del constructor.

La autoconexión por reflexión resuelve los controladores y sus dependencias:

```php
final class PostController extends BaseController
{
    public function __construct(private PDO $pdo) {}
}
```

El contenedor resuelve tipos de unión, usa valores por defecto cuando existen,
acepta `null` en parámetros que lo admiten, y detecta dependencias circulares
con una `RuntimeException`.

---

## Base de datos

### Conexión

Configúrala en `.env`. Drivers admitidos: `mysql`, `pgsql`, `sqlite`, `sqlsrv`,
`oci`, `firebird`, `dblib`. Para cualquier otro, indica `DB_DSN` directamente.

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=app
DB_USER=root
DB_PASS=secret
DB_CHARSET=utf8mb4
```

La conexión usa `ERRMODE_EXCEPTION`, `FETCH_ASSOC` y **sentencias preparadas
reales** (`EMULATE_PREPARES => false`). Un fallo de conexión registra el
detalle en el log y lanza una excepción genérica — el host, la base de datos y
el usuario nunca llegan al visitante.

### Constructor de consultas

```php
use SfphpProject\src\Database;

Database::table('users')->get();
Database::table('users')->where('age', '>', 18)->get();
Database::table('users')->where('email', 'juan@ejemplo.com')->first();
Database::table('users')->count();
```

Métodos disponibles:

```php
->select('id', 'name')            // o ->select(['id', 'name'])
->select('name AS etiqueta')
->where('age', '>', 18)           // = != <> > >= < <= LIKE "NOT LIKE"
->where('status', 'activo')       // dos argumentos: igualdad
->orWhere('role', 'admin')
->whereNull('deleted_at')
->whereNotNull('verified_at')
->whereIn('id', [1, 2, 3])        // un arreglo vacío no coincide con nada
->join('posts', 'users.id', '=', 'posts.user_id')
->join('posts', 'users.id', '=', 'posts.user_id', 'LEFT')
->orderBy('created_at', 'desc')
->limit(10)->offset(20)

->get()        // un arreglo de filas
->first()      // la primera fila, o null
->count()      // int
->insert(['name' => 'Juan'])      // devuelve el id generado (string)
->update(['name' => 'Pérez'])     // devuelve las filas afectadas
->delete()                        // devuelve las filas afectadas
->toSql()      // inspecciona el SQL sin ejecutarlo
->bindings()   // los valores vinculados
```

**Seguridad.** Todo valor se vincula con el tipo PDO correcto. Todo
identificador — tabla, columna, alias — se valida contra
`^[A-Za-z_][A-Za-z0-9_]*$` y se entrecomilla según el driver; un identificador
inválido lanza `InvalidArgumentException` en vez de llegar al SQL.

La paginación se traduce por dialecto: `LIMIT/OFFSET` en MySQL, PostgreSQL y
SQLite, `TOP` en SQL Server, `FIRST` en Firebird, `OFFSET … FETCH NEXT` en
Oracle. Un driver sin soporte falla explícitamente.

### Transacciones

```php
use SfphpProject\src\Database;

Database::transaction(function (): void {
    $pedido = Pedido::create(['cliente_id' => 7]);

    foreach ($items as $item) {
        ItemPedido::create(['pedido_id' => $pedido->id] + $item);
    }
});
```

Confirma cuando la función retorna y deshace cuando lanza, **relanzando** el
error después. El valor devuelto por la función se propaga.

```php
$id = Database::transaction(fn (): int => Pedido::create([...])->id);
Database::inTransaction();   // true mientras estás dentro
```

Una llamada anidada **se une** a la transacción ya abierta en vez de comenzar
una segunda, porque PDO no tiene transacciones anidadas. La consecuencia vale
conocerla: un fallo dentro de la función interna deshace también el trabajo
externo. Los savepoints lo evitarían, pero su sintaxis varía entre drivers, y
degradar en silencio en los que no los tienen sería peor que ser explícito.

El helper resuelve además un detalle fácil de equivocar a mano: una sentencia
que falla puede dejar al driver **sin** transacción activa, y un `rollBack()`
crudo en ese estado lanza `There is no active transaction` desde dentro del
`catch` — sustituyendo el error que realmente causó el fallo. Aquí el rollback
solo ocurre si hay una transacción activa, así que el error original
sobrevive.

### SQL crudo

```php
use SfphpProject\src\Database;

Database::query('SELECT * FROM users WHERE age > ?', [18])->get();
Database::query('SELECT name FROM users WHERE id = :id', ['id' => 1])->first();
Database::query('SELECT COUNT(*) FROM users')->scalar();
Database::query('DELETE FROM users WHERE id = ?', [1])->rowCount();
Database::query('INSERT INTO logs (msg) VALUES (?)', ['hola'])->lastInsertId();
```

Acepta marcadores posicionales y con nombre. Los métodos son `get()`,
`first()`, `scalar()`, `execute()`, `rowCount()` y `lastInsertId()`.

---

## Modelos

Una capa delgada sobre el constructor de consultas: las filas llegan como
objetos tipados, las relaciones se declaran una vez en lugar de convertirse en
un JOIN escrito a mano en cada lugar de uso, y `with()` carga esas relaciones
en **una** consulta en vez de una por fila.

**Esto no es un ORM completo.** No hay mapa de identidad, ni unidad de trabajo,
ni proxy de carga perezosa, ni esquema derivado de la clase — y es
deliberado, porque cada uno de ellos marca la diferencia entre algo que se
puede leer de una sentada y algo que no.

```bash
./sfphp make:model Post
```

```php
<?php

namespace SfphpProject\app\models;

use SfphpProject\src\Database\Model;
use SfphpProject\src\Database\Relation;

final class Post extends Model
{
    protected static string $table = 'posts';

    /** Obligatorio antes de poder rellenar este modelo desde un arreglo. */
    protected static array $fillable = ['title', 'body'];

    public function author(): Relation
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function comments(): Relation
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
```

Sin `$table`, el nombre se deduce de la clase: `Post` → `posts`, `Category` →
`categories`, `Box` → `boxes`. La deducción es simple a propósito — un nombre
irregular debería declarar `$table`.

### Lectura

```php
Post::all();                       // array<Post>
Post::find(1);                     // Post|null
Post::findOrFail(1);               // Post, o RuntimeException
Post::query()->where('published', 1)->orderBy('created_at', 'desc')->limit(10)->get();
Post::query()->count();

$post->title;                      // un atributo
$post->author;                     // una relación, resuelta al leerla
$post->toArray();
```

`Model` implementa `JsonSerializable`, así que un modelo va directo a una
respuesta:

```php
return Response::json(Post::findOrFail($id));
```

### Escritura

```php
$post = new Post(['title' => 'Hola']);   // solo las columnas listadas en $fillable
$post->save();                     // INSERT, y la clave vuelve rellenada

$post = Post::find(1);
$post->title = 'Otro título';
$post->save();                     // UPDATE solo de lo que cambió

Post::create(['title' => 'Directo']);
$post->forceFill(['published_at' => now()]);   // ignora $fillable
$post->delete();
```

`$fillable` es **obligatorio**: un modelo que no lo declara lanza al ser
rellenado. Consulta [Seguridad](#seguridad) para saber por qué.

Un `save()` sobre un modelo existente escribe **solo los atributos que
cambiaron** — tocar un campo no reescribe la fila entera. Un `save()` sin nada
sucio no emite ninguna consulta.

### Tipos de atributo

PDO devuelve lo que le da el driver: una columna `DATETIME` llega como cadena,
y una columna JSON también. Declarar el tipo hace que la conversión ocurra una
vez en lugar de en cada lugar de uso:

```php
final class Article extends Model
{
    protected static array $casts = [
        'published' => 'bool',
        'meta' => 'json',
        'published_at' => 'datetime',
        'price' => 'decimal:2',
        'views' => 'int',
    ];
}
```

```php
$article->published;      // true, no '1'
$article->meta;           // ['color' => 'azul'], no '{"color":"azul"}'
$article->published_at;   // DateTimeImmutable
$article->views;          // 42, no '42'
```

Disponibles: `int`, `float`, `bool`, `string`, `json`, `array`, `datetime`,
`date` y `decimal:N`. Una columna nula sigue siendo nula — no se convierte en
un valor cero.

La conversión funciona en ambos sentidos: `$article->meta = ['color' =>
'verde']` se almacena como JSON, y un `DateTimeImmutable` se almacena en el
formato de la base de datos.

En `toArray()` y en JSON, una fecha sale como **ISO 8601** en vez del objeto
`DateTimeImmutable` — que `json_encode` representaría como una estructura de
campos internos, inútil para quien consume la API.

Dos excepciones que vale la pena conocer:

```php
$article->getAttribute('published');   // '1' — el valor crudo, sin conversión
$article->cast('published');           // true — con la conversión
```

`getAttribute()` es crudo **a propósito**: las relaciones se unen por esos
valores, y una conversión cambiaría lo que comparan — una clave leída como
`int` de un lado y como `string` del otro dejaría de coincidir en silencio.

### Relaciones

```php
$this->hasMany(Comment::class, 'post_id');        // uno a muchos
$this->hasOne(Profile::class, 'user_id');         // uno a uno
$this->belongsTo(User::class, 'user_id');         // la inversa

// muchos a muchos, a través de una tabla pivote
$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
```

Leer la propiedad resuelve la relación en el acto. Dentro de un bucle, eso es
el problema N+1:

```php
// 1 consulta para las publicaciones + 1 por publicación = 101 para 100
foreach (Post::all() as $post) {
    echo $post->author->name;
}

// 1 consulta para las publicaciones + 1 para todos los autores = 2
foreach (Post::query()->with('author')->get() as $post) {
    echo $post->author->name;
}
```

`with()` acepta varias relaciones: `->with('author', 'comments')`, y también
funciona para muchos a muchos:

```php
final class Post extends Model
{
    public function tags(): Relation
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}

foreach (Post::query()->with('tags')->get() as $post) {
    foreach ($post->tags as $tag) { echo $tag->name; }
}
```

Dos consultas, haya las publicaciones que haya. La columna del pivote se
selecciona con un alias, y así es como las filas unidas se reagrupan por
publicación — sin eso, la carga anticipada a través de un pivote volvería a
una consulta por fila.

### La salida de emergencia

Todo lo que `Model` no hace está a una llamada de distancia, y vuelve como
arreglos:

```php
Post::query()->builder();          // el QueryBuilder de debajo
Database::table('posts');          // sin pasar por Model
Database::query('SELECT ...');     // SQL crudo
```

### Qué falta, y por qué

Los motivos están detallados en
[¿ORM o constructor de consultas?](#orm-o-constructor-de-consultas).

| Ausente | Por qué |
|---|---|
| Mapa de identidad | Buscar la misma fila dos veces devuelve dos objetos. Rastrear la identidad exige una unidad de trabajo |
| Proxies de carga perezosa | La relación se resuelve al leer la propiedad; no hay un proxy que sustituya al objeto ausente |
| Relaciones polimórficas | `hasMany`, `hasOne`, `belongsTo` y `belongsToMany` sí existen |
| Migraciones derivadas de la clase | El esquema viene de las migraciones, no del modelo |

---

## ¿ORM o constructor de consultas?

La respuesta corta: **un constructor de consultas, con objetos encima.** Ni un
constructor de consultas a secas ni un ORM — y el límite es deliberado, no un
asunto pendiente. Esta sección existe porque un medio ORM confundido con uno
completo es peor que cualquiera de los dos: empiezas a apoyarte en una
transacción implícita que no existe, o en una identidad de objeto que no está
garantizada.

### Los dos extremos

Un **constructor de consultas** ensambla el SQL por ti. Sigues pensando en
tablas, columnas y uniones; él se encarga de entrecomillar identificadores,
vincular valores y traducir la paginación entre dialectos. El resultado son
filas — arreglos.

```php
Database::table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->where('posts.published', 1)
    ->get();                                  // un arreglo de arreglos
```

Un **ORM** (mapeador objeto-relacional) invierte eso. Piensas en objetos y en
las relaciones entre ellos; el mapeador decide el SQL. Para lograrlo tiene que
mantener **identidad** (la misma fila es el mismo objeto), **ciclo de vida**
(rastrear lo que cambió y escribirlo en el orden correcto) y a menudo una
**transacción implícita**.

```php
$post->author->name = 'Ana';
$entityManager->flush();      // el ORM deduce el UPDATE, el orden y la transacción
```

### Dónde se sitúa SFPHP

| | Constructor a secas | **SFPHP** | ORM completo |
|---|:--:|:--:|:--:|
| SQL seguro, vinculado y entrecomillado | ✓ | ✓ | ✓ |
| Filas como objetos tipados | ✗ | **✓** | ✓ |
| Tipos de atributo declarados (fecha, JSON, bool) | ✗ | **✓** | ✓ |
| Relaciones declaradas una vez | ✗ | **✓** | ✓ |
| Carga por lotes contra el N+1 | ✗ | **✓** | ✓ |
| Transacción explícita | ✗ | **✓** | ✓ |
| Mapa de identidad | ✗ | ✗ | ✓ |
| Unidad de trabajo / `flush()` | ✗ | ✗ | ✓ |
| Proxy de carga perezosa | ✗ | ✗ | ✓ |
| Relaciones polimórficas | ✗ | ✗ | ✓ |
| Esquema derivado de la clase | ✗ | ✗ | ✓ |

La línea divisoria tiene una lógica: **SFPHP mapea filas de ida y vuelta, pero
no gestiona el ciclo de vida de los objetos.** Todo lo que está por encima de
la línea es traducción de datos; todo lo que está por debajo exige que el
framework guarde estado sobre tus objetos entre una llamada y la siguiente.

### Por qué paramos justo ahí

Lo que queda bajo la línea no se dejó fuera por falta de tiempo. Cada elemento
cobra un precio concreto, y en uno de ellos el precio es un riesgo de
seguridad.

#### Mapa de identidad — no, y aquí el motivo es el riesgo

La idea: `Post::find(1)` dos veces devuelve el **mismo** objeto, de modo que
una edición en un sitio aparece en el otro.

El problema: un mapa de identidad es una caché, con todos los problemas que
tiene una caché — invalidación, memoria, y la sorpresa de que `find()` no
llegue a la base de datos cuando esperabas datos frescos.

Y el motivo decisivo: **un runtime persistente es un objetivo declarado de este
framework** (Swoole, FrankenPHP). En un proceso que sirve muchas peticiones, un
mapa de identidad que no se reinicie rigurosamente por petición se convierte en
una fuga de datos **entre usuarios** — alguien viendo la fila que cargó otra
persona. Es el único punto de esta lista donde el propio objetivo del proyecto
argumenta *en contra*, y no simplemente no a favor.

#### Unidad de trabajo — no, porque `transaction()` entrega lo que importa

La idea: cambias objetos libremente, llamas a `flush()` una vez, y el mapeador
deduce el conjunto mínimo de INSERT/UPDATE/DELETE, en el orden correcto para
las dependencias de claves foráneas, dentro de una transacción.

El precio: es la pieza más grande de un ORM como Doctrine. Depende del mapa de
identidad, del cálculo del conjunto de cambios, de un grafo de dependencias y
de reglas de cascada. Y hace que **no sea obvio cuándo se ejecuta tu consulta**
— la causa número uno de «¿por qué no se guardó mi cambio?».

Lo que hacemos en su lugar: `save()` por objeto, escribiendo solo lo que
cambió, y una transacción **explícita** cuando necesitas atomicidad:

```php
Database::transaction(function (): void {
    $order = Order::create(['customer_id' => 7]);

    foreach ($items as $item) {
        OrderItem::create(['order_id' => $order->id, ...]);
    }
});
```

Eso da la atomicidad sin la ambigüedad. Puedes ver dónde empieza y dónde
termina la transacción.

#### Proxies de carga perezosa — no, porque ya tenemos el valor

La idea: `$post->author` devuelve un objeto que *parece* un `User` y solo
consulta la base de datos cuando algo lo toca de verdad.

Pero leer la propiedad **ya** resuelve la relación bajo demanda — eso *es*
carga perezosa, y es lo que hace esta capa. El proxy solo añade el caso en que
necesitas un `User` tipado en la mano antes de la consulta, y lo cobra caro:
rompe `get_class()`, vuelve sutil `instanceof`, complica la serialización, y
`var_dump` empieza a mostrar un proxy en lugar del objeto que querías
inspeccionar.

#### Relaciones polimórficas — no, por dónde viven los datos

La idea: `$comment->commentable` apunta a un `Post` o a un `Video`, según una
columna `commentable_type`.

El problema es lo que contiene esa columna: **un nombre de clase PHP dentro de
la base de datos**. Eso acopla el esquema a tu espacio de nombres — renombrar
una clase pasa a necesitar una migración — y si ese valor llega a instanciarse
a partir de entrada no confiable, deja de ser una cuestión de diseño y se
convierte en una de seguridad.

Muchos a muchos, que es el caso común y no tiene nada de ese problema, **sí
existe**: `belongsToMany()`.

#### Esquema derivado de la clase — no, y sería un mal intercambio aunque fuese barato

La idea: los atributos de la clase generan las migraciones, de modo que la
forma de la tabla vive en un solo lugar.

El problema es que invierte la fuente de verdad. Y el constructor de esquemas
es el **subsistema más sólido de este framework**: cubre MySQL y PostgreSQL con
paridad real, emula ENUM y `ON UPDATE` en PostgreSQL mediante una restricción y
un disparador, y **falla explícitamente** cuando un dialecto no puede honrar la
semántica que se le pide, en vez de cambiarla en silencio. Subordinar eso a
anotaciones en una clase cambiaría la pieza más fiable del proyecto por
comodidad.

### Saber de qué lado escribir

Una regla práctica:

- **Modelo** cuando trabajas con entidades y relaciones — CRUD, formularios,
  una API de recursos. Ahí es donde los objetos y `with()` rinden.
- **Constructor de consultas** cuando trabajas con conjuntos — informes,
  agregados, `GROUP BY`, actualizaciones masivas. Hidratar en objetos no ayuda,
  y a veces estorba.
- **SQL crudo** (`Database::query()`) cuando la consulta es el producto: un
  CTE, una función de ventana, algo específico del dialecto.

Los tres conviven, y salir del modelo cuesta una llamada:

```php
Post::query()->builder();   // devuelve el QueryBuilder de debajo
```

Si algún día necesitas un mapa de identidad o una unidad de trabajo, el camino
honesto no es esperar a que SFPHP crezca hasta convertirse en uno — es usar
Doctrine, que hace eso bien, y aceptar las dependencias que trae consigo.

---

## Migraciones y constructor de esquemas

El subsistema más completo del framework: `Blueprint` cubre MySQL 8+ y
PostgreSQL 12+ con paridad real, y **falla explícitamente** cuando un dialecto
no puede honrar la semántica que se le pide, en vez de cambiarla en silencio.

### Crear y ejecutar

```bash
./sfphp make:migration create_users_table
./sfphp make:migration:create users        # prerrellenada con id + timestamps

./sfphp migrate
./sfphp migrate --step=2
./sfphp rollback
./sfphp rollback --step=3
./sfphp status
./sfphp db:fresh                           # borra todo y reconstruye
```

Las migraciones son clases anónimas devueltas por el archivo:

```php
<?php

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Migration;
use SfphpProject\src\Migrations\Schema;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

### Schema

```php
$schema->create('posts', fn (Blueprint $t) => /* ... */);
$schema->table('posts', fn (Blueprint $t) => /* modificaciones */);
$schema->drop('posts');
$schema->dropIfExists('posts');
$schema->rename('posts', 'articles');
$schema->hasTable('posts');
$schema->hasColumn('posts', 'title');
$schema->hasIndex('posts', 'posts_title_index');
$schema->statement('SET ...', $bindings);
$schema->driver();
```

### Tipos de columna

```php
// Claves
$table->id();                    $table->increments('id');
$table->bigIncrements('id');     $table->smallIncrements('id');
$table->mediumIncrements('id');  $table->uuid('uuid');    $table->ulid('ulid');

// Enteros
$table->integer('n');            $table->bigInteger('n');
$table->mediumInteger('n');      $table->smallInteger('n');
$table->tinyInteger('n');        $table->unsignedInteger('n');
$table->unsignedBigInteger('n'); $table->unsignedDecimal('v', 8, 2);

// Decimales
$table->decimal('price', 8, 2);  $table->float('f');      $table->double('d');

// Texto
$table->string('name', 255);     $table->char('state', 2);
$table->text('body');            $table->mediumText('c');  $table->longText('c');

// Fecha y hora
$table->date('d');               $table->dateTime('dt');   $table->dateTimeTz('dt');
$table->time('t');               $table->timeTz('t');      $table->year('y');
$table->timestamp('ts');         $table->timestampTz('ts');
$table->timestamps();            $table->timestampsTz();
$table->softDeletes();           $table->softDeletesTz();

// Otros
$table->boolean('active');       $table->json('meta');     $table->jsonb('meta');
$table->binary('blob');          $table->enum('st', ['a','b']);  $table->set('tags', [...]);
$table->ipAddress('ip');         $table->macAddress('mac');
$table->rememberToken();         $table->rawColumn('tags', 'TEXT[]');
```

### Modificadores

```php
$table->string('slug')->nullable()->default('')->comment('URL amigable');
$table->integer('views')->unsigned()->default(0);
$table->string('email')->unique();
$table->string('name')->collation('en_US.utf8')->charset('utf8mb4');
$table->timestamp('updated')->useCurrent()->useCurrentOnUpdate();
$table->string('extra')->after('name');     // MySQL
$table->string('first')->first();           // MySQL
$table->integer('total')->storedAs('a + b');
$table->integer('calc')->virtualAs('a * 2');
```

### Índices y claves

```php
$table->primary('id');
$table->unique(['email', 'tenant_id']);
$table->index('created_at');
$table->fullText('body');
$table->index('name')->algorithm('btree');
$table->check('price >= 0');

$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->foreign('user_id')->references('id')->table('users')->nullOnDelete();

$table->morphs('owner');            // owner_id + owner_type + índice
$table->nullableMorphs('owner');
$table->uuidMorphs('owner');        $table->ulidMorphs('owner');
```

`onDelete`/`onUpdate` aceptan `cascadeOnDelete()`, `restrictOnDelete()`,
`nullOnDelete()`, `noActionOnDelete()` y los equivalentes de actualización.

### Modificaciones y eliminaciones

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->string('title', 500)->change();
    $table->renameColumn('body', 'content');
    $table->renameIndex('idx_old', 'idx_new');
    $table->dropColumn('obsolete');
    $table->dropIndex('posts_slug_index');
    $table->dropUnique('posts_email_unique');
    $table->dropForeign('posts_user_id_foreign');
    $table->dropPrimary();
    $table->dropCheck('posts_price_check');
    $table->dropTimestamps();
    $table->dropSoftDeletes();
    $table->dropRememberToken();
    $table->dropMorphs('owner');
});
```

Los nombres generados respetan el límite de identificadores del driver (63 en
PostgreSQL, 64 en MySQL) y son **deterministas**: el nombre que genera `create`
es el que busca `drop`.

### Paridad entre dialectos

| Característica | MySQL 8+ | PostgreSQL 12+ |
|---|:--:|:--:|
| Tipos de columna | ✓ | ✓ |
| Restricciones (FK, unique, check, primary) | ✓ | ✓ |
| Índices (simple, único, de texto completo) | ✓ | ✓ |
| Columnas generadas | ✓ (STORED/VIRTUAL) | ✓ (STORED) |
| `ON UPDATE CURRENT_TIMESTAMP` | ✓ nativo | ✓ vía disparador |
| `ENUM` | ✓ nativo | ✓ emulado con CHECK |
| `SET` | ✓ | ✗ falla explícitamente |
| `COMMENT` | ✓ en línea | ✓ vía `COMMENT ON` |
| Calificación `schema.table` | ✓ | ✓ |
| FK diferible | ✗ | ✓ |

---

## Seeders y factories

### Seeders

```bash
./sfphp make:seeder UserSeeder
```

```php
<?php

namespace Database\Seeders;

use SfphpProject\src\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        Database::table('users')->insert([
            'name' => 'Juan',
            'email' => 'juan@ejemplo.com',
        ]);
    }
}
```

Encadénalos desde `DatabaseSeeder`:

```php
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([UserSeeder::class, PostSeeder::class]);
    }
}
```

```bash
./sfphp db:seed                       # ejecuta DatabaseSeeder
./sfphp db:seed --class=UserSeeder    # ejecuta uno concreto
```

Un nombre desconocido lista los seeders disponibles y sale con código 1.

### Factories

```bash
./sfphp make:factory User
```

```php
<?php

namespace Database\Factories;

use SfphpProject\src\Database\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Usuario ' . mt_rand(1000, 9999),
            'email' => 'usuario' . mt_rand(1000, 9999) . '@ejemplo.com',
            'password' => password_hash('password', PASSWORD_BCRYPT),
        ];
    }
}
```

```php
$data  = (new UserFactory())->make();                      // un arreglo, sin guardar
$user  = (new UserFactory())->create();                    // guardado
$many  = (new UserFactory())->count(50)->create();
$admin = (new UserFactory())->create(['role' => 'admin']);  // sobrescrituras
```

`make()` y `create()` devuelven **arreglos**, no objetos. Para trabajar con
objetos, consulta [Modelos](#modelos).

---

## Caché

```php
$cache = cache();                    // helper global, driver de archivos

$cache->put('clave', $valor, 300);   // TTL en segundos; null nunca expira
$cache->get('clave');
$cache->get('clave', 'por defecto');
$cache->has('clave');
$cache->forget('clave');
$cache->flush();
$cache->pull('clave');                            // lee y elimina
$cache->remember('users', 600, fn () => /* ... */);   // calcula si falta

$cache->increment('hits');           // atómico; devuelve el nuevo valor
$cache->increment('hits', 5);        // suma más de uno
$cache->decrement('slots');

$cache->increment('window', 1, 60);  // un contador que expira en 60 segundos
$cache->ttl('window');               // segundos restantes, o null
```

### Contadores

`increment()` no es `get()` más `put()`, y la diferencia es justamente el
punto. Dos peticiones que llegan a la vez leen 4 las dos y escriben 5 las dos
— un acceso se pierde. Eso es inofensivo en una página cacheada y no lo es en
un limitador de peticiones, que cuenta precisamente cuando varias llegan al
mismo tiempo.

La suma ocurre donde vive el dato: dentro de un bloqueo exclusivo en el driver
de archivo, y como `INCRBY` en Redis, de modo que quien suma es el driver y no
PHP.

La vida útil se aplica **solo cuando el contador se crea**. Un contador que ya
existe conserva la expiración que tenía, así que un cliente que sigue llamando
no puede empujar su propia ventana hacia delante y quedarse dentro del límite
para siempre.

El corolario vale conocerlo: un contador creado **sin** vida útil nunca recibe
una. `increment('hits')` seguido de `increment('hits', 1, 60)` deja un contador
que no expira nunca, y `ttl()` responde `null`. Pasa la vida útil en la llamada
que crea el contador, o en todas — el limitador de peticiones hace lo segundo.

> Añadir `increment()` y `ttl()` a la interfaz `Cache` es un **cambio que
> rompe** para una aplicación que traiga su propio driver: una clase que
> implementa `Cache` pasa a tener que implementar ambos.

Cambiar el driver:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Cache\RedisDriver;

$cache = new CacheManager(new MemoryDriver());   // solo para esta petición
$cache = new CacheManager(new RedisDriver());    // necesita ext-redis
```

```bash
./sfphp cache:clear
./sfphp cache:flush
```

---

## Colas

```php
use SfphpProject\src\Queue\Job;

final class SendEmailJob extends Job
{
    public function __construct(private string $to) {}

    public function handle(): void
    {
        mail($this->to, 'Hola', 'Cuerpo');
    }
}
```

```php
dispatch(new SendEmailJob('a@b.com'));         // helper global
dispatch(new SendEmailJob('a@b.com'), 300);    // con un retraso en segundos

(new SendEmailJob('a@b.com'))->tries(5)->timeout(120);
```

```bash
./sfphp queue:work                 # por defecto: 3600s
./sfphp queue:work --timeout=7200
./sfphp queue:failed
```

El worker procesa hasta agotar el tiempo, incrementa los intentos al fallar, y
mueve un trabajo a `failed_jobs` cuando se le acaban los intentos. Con
`ext-pcntl`, `SIGTERM` y `SIGINT` lo apagan de forma ordenada.

Las tablas `jobs` y `failed_jobs` se crean bajo demanda, en la primera
operación que las necesita — instanciar el driver no abre ninguna conexión.

---

## Validación

```php
use SfphpProject\src\Validator;

$result = Validator::validate($_POST, [
    'name'  => 'required|min:3|max:255',
    'email' => 'required|email',
    'age'   => 'required|number',
]);

if ($result->fails()) {
    foreach ($result->errors() as $field => $messages) {
        echo $field . ': ' . implode(', ', $messages);
    }
}

$clean = $result->validated();
```

Las reglas son una **cadena separada por barras verticales**, no un arreglo.
Los argumentos van tras dos puntos.

| Regla | Comprueba |
|---|---|
| `required` | No nulo y no vacío |
| `email` | `FILTER_VALIDATE_EMAIL` |
| `min:N` | Al menos N **caracteres** |
| `max:N` | Como mucho N **caracteres** |
| `alpha` | Solo letras, en **cualquier escritura** (`\p{L}`) |
| `alphanum` | Letras y dígitos de cualquier escritura |
| `number` | Solo dígitos ASCII (seguro para `(int)`) |

Una regla desconocida lanza `InvalidArgumentException` — una errata falla
pronto en lugar de pasar la validación en silencio.

`ValidationResult`: `passes()`, `fails()`, `errors()`, `validated()`.

Mensajes personalizados:

```php
Validator::validate($data, ['name' => 'required|min:3'], [
    'name' => [
        'required' => 'Introduce tu nombre, por favor.',
        'min' => 'El nombre necesita al menos 3 letras.',
    ],
]);
```

---

## Internacionalización

Los mensajes viven en catálogos por idioma; el idioma viene del
`Accept-Language` de la petición. El framework trae sus propios catálogos, y
una aplicación sobrescribe lo que quiera sin editarlos.

**El valor por defecto es el inglés.** Hasta esta versión las páginas 404 y 405
del framework estaban escritas en portugués en el código — un desarrollador
alemán que adoptara SFPHP enviaba una página de error en portugués a sus
usuarios. Un framework pensado para usarse en cualquier lugar no puede hacer
eso.

### Dónde viven los mensajes

```
src/I18n/lang/            catálogos del framework (prioridad más baja)
  en/http.php
  en/validation.php
  pt_BR/…
  es/…

lang/                     catálogos de tu aplicación (estos ganan)
  en/app.php
  pt_BR/app.php
  es/app.php
```

Un catálogo es un archivo PHP que devuelve un arreglo:

```php
<?php   // lang/es/app.php

return [
    'welcome' => '¡Bienvenido, :name!',
    'items' => '{0} Sin elementos|{1} Un elemento|[2,*] :count elementos',
];
```

Nada se compila ni se analiza: un catálogo cuesta un `require` y acaba en
OPcache como cualquier otro archivo.

La sobrescritura es **clave por clave**. Para cambiar solo el mensaje del 404,
crea `lang/es/http.php` con únicamente `not_found_message` — el resto sigue
viniendo del framework.

### Traducir

```php
__('http.not_found_title');                    // 404 - Página no encontrada
__('app.welcome', ['name' => 'Ana']);          // ¡Bienvenido, Ana!
__('app.welcome', ['name' => 'Ana'], 'pt_BR'); // en un idioma concreto
locale();                                      // 'es'
```

La clave es `grupo.entrada`, y puede anidarse más hondo (`app.form.title`).
**Una clave sin traducción vuelve tal cual** — el hueco aparece donde está, en
lugar de renderizar una página vacía.

### Plural

Formas separadas por `|`. Una forma puede llevar una condición explícita —
`{0}` para un número exacto, `[2,4]` para un rango, `[5,*]` para uno abierto:

```php
'items' => '{0} Sin elementos|{1} Un elemento|[2,*] :count elementos',
```

```php
trans_choice('app.items', 0);   // Sin elementos
trans_choice('app.items', 1);   // Un elemento
trans_choice('app.items', 5);   // 5 elementos
```

Sin condición, decide la regla del idioma: la primera forma para uno, la
segunda para todo lo demás.

#### Cuando los rangos no bastan

Los rangos cubren la mayoría de los idiomas, **pero no todos**. El polaco elige
su forma a partir de los últimos dígitos y no de un rango: 22 y 12 toman formas
distintas aunque ambos superen el cinco. El árabe tiene seis formas; el ruso,
tres.

Hacer eso correctamente exige los datos de pluralización de CLDR, que es lo que
lleva la extensión `intl`. Como `intl` es opcional y el framework tiene cero
dependencias, incrustar una copia incompleta de esas reglas significaría estar
**equivocado en silencio** justo para esos idiomas. En su lugar, la regla es un
punto de extensión:

```php
Translator::pluralizer('pl', function (int $count): int {
    if ($count === 1) {
        return 0;
    }

    $mod10 = $count % 10;
    $mod100 = $count % 100;

    return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 1 : 2;
});
```

Quien conoce el idioma escribe su regla. Ese es el intercambio honesto: el
framework no finge saber lo que no sabe.

### Elegir el idioma de la petición

El middleware `SetLocale` resuelve el idioma una sola vez, en el borde:

```php
$router = (new Router($container))->middleware(
    new SetLocale(APP_LOCALES, APP_LOCALE),
    // ...
);
```

Lee `Accept-Language` respetando los valores de calidad
(`pt-BR,pt;q=0.9,en;q=0.8`), descarta lo que venga con `q=0`, y elige la mejor
coincidencia entre lo que pidió el cliente y lo que ofrece la aplicación. Pedir
`pt` y recibir `pt_BR` es mejor que recibir inglés, así que eso es lo que
ocurre.

También añade la cabecera `Content-Language`, y las páginas de error del
framework ahora declaran el `lang` correcto en el documento — antes decían
`lang="en"` fuese cual fuese el contenido:

```html
<html lang="pt-BR">   <!-- sigue el idioma negociado -->
```

Eso no es cosmético: los lectores de pantalla eligen la pronunciación a partir
de `lang`, y el navegador usa el atributo para decidir si ofrece una
traducción.

Registrarlo **globalmente** importa por dos motivos.

El primero: una petición que no casa con ninguna ruta nunca llega a un
controlador, y esa es la única razón por la que un 404 puede salir en el idioma
del visitante.

El segundo aparece bajo un runtime persistente (Swoole, FrankenPHP). El
traductor guarda el idioma activo en un `static`, así que un worker que
respondió una petición en portugués **respondería la siguiente en portugués**
si nada lo reiniciara. Este middleware es lo que lo reinicia. Construye el
pipeline sin él y llama a `Translator::setLocale()` desde dentro de un
controlador, y el idioma se filtra de la petición de un visitante a la del
siguiente.

Es el mismo tipo de cuidado que mantuvo el *mapa de identidad* fuera de la capa
de modelos: el estado estático en un proceso que sirve muchas peticiones
necesita un dueño explícito que lo reinicie.

Directamente desde la petición, cuando lo necesites:

```php
$request->acceptedLanguages();                      // ['pt-BR', 'pt', 'en']
$request->preferredLanguage(['en', 'pt_BR'], 'en'); // 'pt_BR'
$request->attribute('locale');                      // lo define SetLocale
```

### Configuración

```ini
APP_LOCALE=en
APP_LOCALES=en,pt_BR,es
```

`APP_LOCALE` es el idioma que se usa cuando el cliente no pide ninguno de los
que ofrece la aplicación; `APP_LOCALES` son los que ofrece, en orden de
preferencia. Sin configuración, ambos son inglés por defecto.

La ruta `lang/` de la aplicación se registra en `app/config/config.php`, que se
ejecuta desde el autoloader — así que la CLI, un worker de cola y la suite de
pruebas ven los mismos mensajes que vería una petición web.

### Validación

Los mensajes de `Validator` vienen del catálogo, y un mensaje pasado por quien
llama sigue ganando sin tocarse:

```php
Validator::validate($data, ['name' => 'required|min:5']);
// en:    "name is required."   / "name must be at least 5 characters long."
// es:    "name es obligatorio." / "name debe tener al menos 5 caracteres."

Validator::validate($data, ['name' => 'required'], [
    'name' => ['required' => 'Introduce tu nombre, por favor.'],   // gana
]);
```

Las reglas de longitud se flexionan según el número, de modo que `min:1` dice
«al menos un carácter» en vez de «al menos 1 caracteres».

### Qué se queda en inglés a propósito

Solo el texto que llega a un **usuario final** pasa por el traductor. Las
excepciones dirigidas a quien escribe el código — la CLI, el constructor de
consultas, el constructor de esquemas, el contenedor — se quedan en inglés:

```
Unknown validation rule "nosuchrule" for field "name".
Cannot resolve parameter $foo in App\Service. Bind a service or provide a default value.
```

Se leen en una traza de pila o en un log, por una persona desarrolladora, y
traducirlas haría más difícil buscar una, no más fácil.

### Qué falta

| Ausente | Situación |
|---|---|
| Reglas de plural CLDR incrustadas | Exigiría `ext-intl` o una copia de los datos. `pluralizer()` es el punto de extensión |
| Formato de fechas y números por idioma | `ext-intl` lo hace bien; el framework no lo intenta |
| Traducción de rutas (`/products` ↔ `/productos`) | No existe |
| Extracción de cadenas a los catálogos | Ningún comando escanea el código |
| Dirección del texto (RTL) | Una decisión de plantilla, no del traductor |

---

## Cadenas UTF-8

`Str` ofrece las operaciones de cadena que PHP a secas solo hace por bytes.

```php
use SfphpProject\src\Str;

Str::length('日本語');                  // 3, no 9
Str::substr('日本語', 1, 1);            // 本
Str::truncate('日本語テキスト', 5);      // 日本...
Str::reverse('日本語');                 // 語本日
Str::isAlpha('José');                   // true
Str::isAlpha('Владимир');               // true
Str::isAlphanumeric('José99');          // true
Str::isNumeric('123');                  // true
Str::isNumeric('١٢٣');                  // false — no sobreviviría a (int)
Str::isUtf8($value);
Str::upper('acción');  Str::lower('ACCIÓN');  Str::ucfirst('acción');
```

Construido sobre **PCRE con `/u`**, no sobre `mbstring`. PCRE siempre está
compilado dentro de PHP; `mbstring` es opcional, y exigirlo pondría una
dependencia dura delante de cada instalación. La excepción es la conversión de
mayúsculas y minúsculas, que necesita tablas por idioma que PCRE no expone:
ahí se usa `mbstring` cuando está presente y el repliegue a ASCII cuando no —
degradando un detalle de presentación en vez de corromper datos.

---

## Autenticación

Tres piezas, separadas a propósito:

- un **proveedor** dice dónde se buscan los usuarios;
- un **guard** dice cómo una petición demuestra quién es;
- **`Auth`** une las dos y guarda al usuario resuelto.

Separar el proveedor del guard es lo que permite que el mismo flujo de inicio
de sesión funcione contra una tabla, un directorio LDAP o una lista en memoria
en una prueba.

### El contrato del usuario

```php
use SfphpProject\src\Auth\Authenticatable;
use SfphpProject\src\Database\Model;

final class User extends Model implements Authenticatable
{
    protected static string $table = 'users';
    protected static array $fillable = ['name', 'email'];

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthPassword(): string { return (string) $this->password; }
}
```

Tres métodos, porque eso es todo lo que el framework necesita saber: cómo se
llama la clave, cuál es la clave, y contra qué comparar una contraseña. El
nombre, el correo y los roles pertenecen a tu aplicación, y el framework nunca
los lee.

### Configuración

```php
use SfphpProject\src\Auth\{Auth, ModelUserProvider, SessionGuard, TokenGuard};

Auth::provider(new ModelUserProvider(User::class));
Auth::guard('web', new SessionGuard(Auth::provider()));
Auth::guard('api', new TokenGuard(Auth::provider()));
Auth::setDefaultGuard('web');
```

### Entrar y salir

```php
if (Auth::attempt(['email' => $email, 'password' => $password])) {
    return $this->redirect('/dashboard');
}

return $this->view('login', ['error' => __('auth.failed')]);
```

```php
Auth::user();        // Authenticatable|null
Auth::check();       // bool
Auth::guest();       // bool
Auth::id();          // la clave, o null
Auth::login($user);  // sin comprobar contraseña
Auth::logout();
```

Dentro de un controlador el usuario llega también en la petición:

```php
public function dashboard(Request $request): Response
{
    return $this->view('dashboard', ['user' => $request->user()]);
}
```

### Contraseñas

```php
use SfphpProject\src\Auth\Hash;

Hash::make($password);                // para almacenar
Hash::check($password, $storedHash);  // para verificar
Hash::needsRehash($storedHash);       // para actualizar
```

Una envoltura delgada sobre `password_hash()` de PHP, a propósito: ya elige un
algoritmo sólido, genera la sal y codifica los parámetros en el resultado.
Escribir algo más ingenioso aquí sería un paso atrás.

Usa `PASSWORD_DEFAULT` en vez de nombrar un algoritmo, así que una
actualización de PHP que adopte un valor por defecto mejor se aprovecha
automáticamente para las contraseñas nuevas. Las antiguas se ponen al día con
`needsRehash()`, justo después de un inicio de sesión correcto — el único
momento en que se puede actualizar el algoritmo de una contraseña sin pedirle
al usuario que la escriba otra vez:

```php
if (Auth::attempt($credentials) && Hash::needsRehash($user->password)) {
    $user->password = Hash::make($credentials['password']);
    $user->save();
}
```

### El middleware

```php
// Global: identifica a quien puede, deja pasar las peticiones anónimas
$router->middleware(new Authenticate('web'));

// Por ruta: rechaza una petición anónima
Router::get('/dashboard', 'DashboardController', 'index')
    ->middleware(new Authenticate('web', required: true));

// Una API usa el guard de token
Router::group('/api', function (): void {
    Router::get('/me', 'ApiController', 'me');
}, 'api.', [new Authenticate('api', required: true)]);
```

Al rechazar, responde **401** a un cliente que espera JSON y **redirige a
`/login`** para un navegador. La redirección es deliberada: un 401 sin cabecera
`WWW-Authenticate` hace que algunos navegadores abran su propio diálogo de
credenciales, que no es el formulario de tu aplicación.

> **Bajo un runtime persistente este middleware es obligatorio.** `Auth` guarda
> al usuario resuelto en un `static` para que preguntar dos veces no consulte
> dos veces. En un worker que sirve muchas peticiones, ese mismo `static`
> llevaría la identidad de un visitante a la petición siguiente. El middleware
> llama a `Auth::forgetUser()` al inicio de cada petición y es el dueño
> explícito de ese reinicio — exactamente como `SetLocale` lo es del idioma
> activo.

### Dos guards, dos naturalezas

| | `SessionGuard` | `TokenGuard` |
|---|---|---|
| Prueba | cookie de sesión | `Authorization: Bearer` |
| Estado | en el servidor | ninguno |
| Sirve para | páginas | APIs, workers, otro proceso |
| Revocar antes de expirar | sí, borra la sesión | **no** |
| `Auth::login()` | sí | no — lanza |

`SessionGuard` guarda **solo el identificador** en la sesión, nunca al usuario.
Serializar el modelo congelaría una copia de la fila: alguien a quien se le
revocaron permisos los conservaría hasta que expirase la sesión, y renombrar
una columna rompería la deserialización de todas las sesiones vivas.

También **regenera el id de sesión** al entrar y al salir. Al entrar, eso es lo
que detiene la fijación de sesión: quien plantó de antemano un id conocido no
puede usarlo después, porque el id con el que acaba la víctima es nuevo.

`TokenGuard` no guarda nada en el servidor, que es lo que lo hace utilizable
fuera de una petición web — y también lo que significa que **un token no se
puede revocar antes de que expire**. Si necesitas revocación, necesitas una
lista de tokens invalidados, que el framework no proporciona.

### Autorización

```php
use SfphpProject\src\Auth\Gate;

Gate::policy(Post::class, PostPolicy::class);
Gate::define('access-admin', fn (?Authenticatable $u): bool
    => $u !== null && $u->role === 'admin');
```

```php
Gate::allows('update', $post);     // llama a PostPolicy::update($user, $post)
Gate::denies('update', $post);
Gate::authorize('update', $post);  // lanza AuthorizationException
Gate::forUser($other, 'update', $post);
```

```bash
./sfphp make:policy Post
```

```php
final class PostPolicy
{
    public function update(?Authenticatable $user, Post $post): bool
    {
        return $user !== null && $user->getAuthIdentifier() === $post->user_id;
    }
}
```

Dos decisiones que vale la pena conocer:

**Una capacidad que nadie declaró se deniega.** Permitir por defecto haría que
una errata en el nombre de una capacidad abriera una puerta en silencio.

**Una política recibe `null` cuando la petición es anónima**, en vez de ser
rechazada de antemano. Eso es lo que permite que una regla pública — leer una
publicación publicada, por ejemplo — viva junto a las demás en el mismo lugar.

`AuthorizationException` es distinta de no estar autenticado: significa que el
framework sabe quién eres y la respuesta sigue siendo no. Una es **403**, la
otra **401**.

### Enumeración de cuentas

`Auth::attempt()` verifica una contraseña **incluso cuando ningún usuario
coincidió**, contra un hash desechable. Sin eso, un intento de inicio de sesión
para una cuenta inexistente volvería más rápido que uno para una cuenta
existente con la contraseña equivocada — y esa diferencia basta para descubrir
qué cuentas existen.

El hash desechable tiene que haberse generado con los mismos parámetros que usa
`password_hash()` hoy. PHP 8.4 subió el coste por defecto de bcrypt de 10 a 12,
y un hash dejado en 10 se verifica unas cuatro veces más rápido que uno real —
lo que reabriría justamente la diferencia que existe para ocultar. Una prueba
afirma que la constante no necesita rehash, de modo que un cambio futuro del
valor por defecto de PHP lo detecta la CI.

### Mensajes

`auth.failed`, `auth.unauthenticated`, `auth.unauthorized` y `auth.logged_out`
vienen en los tres idiomas que trae el framework. Consulta
[Internacionalización](#internacionalización).

### Qué falta

| Ausente | Situación |
|---|---|
| «Recordarme» | La migración trae la columna `remember_token`; nada la usa |
| Recuperación de contraseña | Sin tabla de tokens, sin flujo de correo |
| Verificación de correo | La columna `email_verified_at` existe; el flujo no |
| Revocación de tokens | Un JWT es válido hasta que expira; no hay lista de revocación |
| Doble factor | No existe |
| Roles y permisos | `Gate` decide; almacenar roles es tarea de tu aplicación |

La limitación de intentos en el formulario de inicio de sesión **sí** existe —
consulta [Seguridad](#seguridad).

---

## Seguridad

Lo que el framework hace por defecto, lo que hay que configurar, y lo que
deliberadamente no hace.

### Asignación masiva

Un modelo solo puede rellenarse desde un arreglo después de declarar **qué
columnas** acepta:

```php
final class User extends Model
{
    protected static array $fillable = ['name', 'email'];
}
```

Sin la lista, rellenar lanza `MassAssignmentException`. Eso es deliberado, y el
motivo es la línea más natural que cualquiera escribe:

```php
User::create($request->all());
```

Sin lista, eso almacena **todas las columnas que el atacante decidió enviar**.
Un formulario de registro que nunca mostró un campo `is_admin` lo escribe de
todas formas si la petición lo lleva:

```php
// enviado: name, email, is_admin=1, balance=999999
$user = new User($request->all());
$user->is_admin;   // null — descartado
```

Las claves fuera de la lista se **descartan**, no dan error, así que un
formulario que lleve un campo extra añadido por el navegador sigue funcionando.
Un modelo que no declara nada, en cambio, **falla ruidosamente** la primera vez
que se usa — mucho antes de llegar a producción.

Permitir por defecto protegería solo a quienes ya sabían que había que declarar
la lista, que es exactamente el conjunto equivocado de personas.

Para los valores que la propia aplicación eligió:

```php
$user->forceFill(['email_verified_at' => now()]);
```

### Proxies de confianza

Las cabeceras `X-Forwarded-*` las controla el cliente: cualquiera puede
enviarlas. Solo significan algo cuando la conexión viene de una máquina que se
sabe que las reescribe, así que **no se confía en nada** hasta que el despliegue
diga en qué:

```php
Request::setTrustedProxies(['10.0.0.0/8', '172.16.0.5']);
```

```ini
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.5
```

> **Detrás de un balanceador que termina TLS esto no es un detalle.** El
> proceso PHP ve HTTP plano, así que `isSecure()` responde false y **la cookie
> de sesión pierde su marca `secure`** — entonces viaja en claro en cuanto un
> visitante llega al sitio por HTTP. Configurar los proxies es lo que lo
> arregla.

Con los proxies declarados:

```php
$request->ip();         // la dirección real del cliente, no la del balanceador
$request->isSecure();   // true, leyendo X-Forwarded-Proto
```

Sin ellos, o viniendo de fuera del rango de confianza, las cabeceras se
ignoran — un visitante no puede falsificar su propia dirección. Eso importa en
cuanto algo limita o registra por IP.

### Limitación de peticiones

```php
Router::post('/login', 'AuthController', 'login')
    ->middleware(new RateLimit(maxAttempts: 5, decaySeconds: 60));
```

Responde **429** con `Retry-After` una vez alcanzado el límite, y añade
`X-RateLimit-Limit` y `X-RateLimit-Remaining` a las respuestas normales.

Esto es lo que hace que valgan la pena las otras defensas del formulario de
inicio de sesión. Igualar el tiempo que tarda un intento fallido impide que un
atacante **descubra qué cuentas existen**; no hace nada contra el simple hecho
de probar contraseñas. Sin un límite, el atacante no necesita enumerar nada.

Los contadores viven en la caché, así que el límite se mantiene entre procesos
cuando hay un driver compartido configurado. Una petición autenticada cuenta
**por usuario**, de modo que varias personas tras la misma dirección de oficina
no consumen el cupo de las demás.

El conteo es un `Cache::increment()` **atómico**, no una lectura seguida de una
escritura. Esa distinción es el middleware entero: las peticiones contadas con
`get()` y `put()` se sobrescriben entre sí, y el límite se escapa justo bajo el
tráfico paralelo que existe para rechazar — quien prueba contraseñas abre
varias conexiones a la vez en lugar de esperar cada respuesta. Consulta
[Contadores](#contadores).

La ventana **no** se renueva en cada intento: renovarla dejaría que un cliente
que siga llamando mantuviera su propia ventana abierta indefinidamente, y el
contador nunca perdonaría. `Retry-After` informa la vida útil restante del
contador, así que decrece hacia el cierre de la ventana en vez de reiniciarse
en cada rechazo.

> La dirección del cliente solo es tan fiable como la configuración de proxies.
> Tras un balanceador sin proxies declarados, **todo el sitio comparte un solo
> cubo**.

### Cabeceras de respuesta

```php
$router->middleware(new SecurityHeaders());
```

Se envían por defecto:

| Cabecera | Cierra |
|---|---|
| `X-Content-Type-Options: nosniff` | Que un archivo subido servido como `text/plain` se ejecute como JavaScript porque sus primeros bytes parecen un script |
| `X-Frame-Options: DENY` | El clickjacking — el sitio enmarcado de forma invisible sobre algo que el visitante pretende pulsar |
| `Referrer-Policy: strict-origin-when-cross-origin` | Que la URL completa, incluido lo que haya en la cadena de consulta, se filtre a cada sitio al que el visitante siga un enlace |

Dos están **apagadas** hasta que se piden:

```php
new SecurityHeaders(
    contentSecurityPolicy: "default-src 'self'; style-src 'self' 'unsafe-inline'",
    hstsMaxAge: 31536000,
    hstsIncludeSubdomains: true,
);
```

**Content-Security-Policy** es la más potente y la más fácil de equivocar: una
política que no coincide con tus propios recursos rompe la página **sin ningún
error que la persona desarrolladora vea**, y el framework no puede saber cuáles
son esos recursos.

> Fíjate en el `'unsafe-inline'` de `style-src` del ejemplo: las páginas de
> error del framework usan CSS en línea, precisamente para no necesitar red.
> Una política sin él deja la página 404 sin estilos.

**Strict-Transport-Security** está apagada porque encenderla es difícil de
deshacer — un navegador que la ha visto rechaza HTTP plano durante todo el
`max-age`, incluso para un sitio que después tenga que servir HTTP por algún
motivo. Y solo se envía por HTTPS: un navegador ignora HSTS en una conexión
insegura, así que enviarla ahí parecería protección sin serlo.

### Sesión y CSRF

- Cookie con `httponly`, `samesite=Lax` y `secure` cuando la conexión es HTTPS
  — lo decide la petición, respetando los proxies de confianza
- Id de sesión **regenerado al entrar y al salir**, contra la fijación de
  sesión
- Un token CSRF de 32 bytes, comparado con `hash_equals`
- `VerifyCsrfToken` aplica la comprobación **por defecto** a toda petición que
  cambia estado; los métodos seguros y las peticiones con token bearer pasan

### Contraseñas e inicio de sesión

- `password_hash` con `PASSWORD_DEFAULT`, y `Hash::needsRehash()` para
  actualizar sin volver a pedir la contraseña
- `Auth::attempt()` verifica una contraseña **incluso sin usuario
  coincidente**, contra un hash desechable, de modo que una cuenta inexistente
  no responde más rápido que una contraseña equivocada
- La sesión guarda **solo el identificador**, nunca el usuario serializado

### Base de datos

- Todo valor se vincula; ninguno se concatena
- Todo identificador — tabla, columna, alias — se valida contra una lista
  blanca y se entrecomilla para el driver; uno inválido lanza en vez de llegar
  al SQL
- `EMULATE_PREPARES => false`, para que el driver prepare de verdad
- Una conexión fallida registra el detalle en el log y lanza una excepción
  genérica: el host, la base de datos y el usuario nunca llegan al visitante

### Salida

- El `{{ }}` de SFHT escapa por defecto; la salida cruda exige `{!! !!}`
- `e()` para plantillas PHP puras
- El detalle de la excepción solo aparece con `APP_ENV=development`

### Qué falta

| Ausente | Situación |
|---|---|
| Revocación de tokens | Un JWT es válido hasta que expira; no hay lista de revocación |
| Recuperación de contraseña, verificación de correo, 2FA | Fuera de alcance |
| «Recordarme» | La columna `remember_token` existe; nada la usa |
| Caducidad de sesión por inactividad o absoluta | Lo que diga `php.ini` |
| Protección contra subidas maliciosas | `$_FILES` se expone en crudo; validar tipo y destino es tarea de la aplicación |
| Registro de auditoría / seguridad | Solo `error_log()` |

---

## CSRF

```php
csrf_token();     // el token de la sesión
csrf_field();     // <input type="hidden" name="_token" value="...">
csrf_meta();      // <meta name="csrf-token" content="...">
csrf_verify();    // valida el token de la petición actual
```

```sfht
<form method="post" action="/posts">
    {!! csrf_field() !!}
    <input name="title">
</form>
```

```php
if (!csrf_verify()) {
    http_response_code(HTTP_FORBIDDEN);
    return;
}
```

El token son 32 bytes de `random_bytes`, comparados con `hash_equals` en tiempo
constante, y la sesión usa `httponly`, `samesite=Lax` y `secure` sobre HTTPS.
El token se acepta desde el campo `_token` o desde las cabeceras
`X-CSRF-Token` / `X-XSRF-Token`.

En la práctica rara vez llamas a `csrf_verify()` tú mismo: el middleware
`VerifyCsrfToken` aplica la comprobación por defecto. Consulta
[Middleware](#middleware).

---

## JWT

```php
use SfphpProject\src\JWT;

$token = JWT::generate(['id' => 1, 'email' => 'juan@ejemplo.com']);

if (JWT::validate($token)) {
    // el token está intacto y no ha expirado
}

$claims = JWT::claims($token);   // valida y devuelve la carga útil, o null
```

Lo que la firma impone:

- `generate()` **exige** las reclamaciones `id` y `email`; sin ellas lanza
  `InvalidArgumentException`
- `validate()` devuelve un **`bool`**, no las reclamaciones, y no lanza ante un
  token inválido
- `claims()` valida y devuelve la carga útil en una sola pasada, que es lo que
  necesita un guard — comprobar la firma por separado significaría verificar
  dos veces, o leer una carga útil que nunca se verificó
- Valida la firma, `alg` (solo `HS256`), `typ` y `exp`. `alg: none` se rechaza
- La expiración está fijada en una hora
- `JWT_KEY` debe tener al menos 32 bytes; el marcador de posición de
  `.env-example` se rechaza a propósito

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

---

## Manejo de errores

Una excepción lanzada dentro de una acción la captura el router, en un límite
que está **fuera** del pipeline. `ErrorHandler::toResponse()` es el
renderizador compartido.

Ese "fuera" importa, y esta página afirmaba lo contrario. La mitad de salida de
un middleware nunca se ejecuta en una petición que falló: la excepción se
desenrolla por encima, así que una cabecera que habría añadido no se añade. Por
eso el router adjunta `X-Request-Id` a la respuesta de error él mismo —
consulta [Registro](#registro) — y conviene saberlo antes de escribir un
middleware que dé por hecho que siempre tiene su turno a la vuelta.

El límite deliberadamente no es un middleware. Un middleware puede registrarse
en el orden equivocado y dejar de capturar en silencio; un `try/catch` alrededor
del pipeline estructuralmente no puede.

El registro global (`ErrorHandler::register()`) se mantiene, porque cubre lo
que un `try/catch` no alcanza: un aviso durante el arranque, y un error fatal
reportado al apagar — memoria agotada, tiempo de ejecución excedido, un error
de análisis en un archivo incluido. Sin él, eso se convierte en páginas en
blanco.

Por ambos caminos la respuesta es:

- **500** con un `Content-Type` negociado — JSON si la petición pidió o envió
  JSON, HTML en caso contrario
- El mensaje real **solo** con `APP_ENV=development`; en producción, el
  `http.server_error_message` traducido
- El detalle siempre va al logger, con el id de la petición adjunto — consulta
  [Registro](#registro)

Las páginas 404, 405 y 500 usan CSS en línea, no hacen ninguna petición
externa, y respetan `prefers-color-scheme`. Las tres se renderizan en el idioma
del visitante; hasta esta versión solo la de 500 no lo hacía, entregando un
título en portugués y `lang="pt-br"` pidiera lo que pidiera la petición.

---

## Registro

Un objeto JSON por línea, con marca de tiempo en UTC.

```php
logger()->info('pedido creado', ['order_id' => $order->id]);
logger()->warning('pago reintentado', ['attempt' => 3]);
logger()->error('la pasarela rechazó', ['code' => $code]);
logger()->exception($throwable);
```

```json
{"timestamp":"2026-09-21T23:34:46.472Z","level":"info","message":"request handled","context":{"request_id":"cc13917b45e763edf3476b9e02818b09","method":"GET","path":"/","ip":"127.0.0.1","status":200,"duration_ms":3.488}}
```

JSON en lugar de una frase, porque una línea de registro la lee un programa
antes de que la lea una persona: cualquier colector la analiza, y se puede
filtrar por un campo sin una expresión regular que se rompa con el primer
mensaje que contenga dos puntos. UTC, porque las líneas con hora local no se
pueden ordenar, y en cuanto hay dos máquinas ese orden es lo único que hace que
los registros valgan algo.

### Niveles

Los ocho de RFC 5424, que son los mismos de PSR-3 — `debug`, `info`, `notice`,
`warning`, `error`, `critical`, `alert`, `emergency`. Coincidir con esos
nombres importa incluso sin depender del paquete: todo colector ya clasifica
los registros por ellos.

Todo lo que esté por debajo de `LOG_LEVEL` se descarta antes de llegar al
driver, así que una llamada a `debug()` en un camino caliente cuesta una
comparación en producción, no una escritura.

### Configuración

```ini
LOG_CHANNEL=stream          # stream (el valor por defecto), error_log, o null
LOG_PATH=php://stderr       # un stream o un archivo, para el canal stream
LOG_LEVEL=info              # debug en desarrollo, info en los demás casos
```

`stderr` es el valor por defecto porque no exige que exista un directorio ni
que se conceda un permiso, y es donde un contenedor espera encontrar los
registros de una aplicación. Una ruta también funciona, y su directorio se crea
si falta.

`error_log` escribe a través del registro de errores de PHP, para un despliegue
donde algo ya recoge eso. `null` descarta, que es lo que usa la suite de
pruebas para que los fallos deliberados no entierren la salida en trazas.

### El id de la petición

Es el objetivo de toda la sección. Un fallo en producción nunca es una sola
línea: es la petición que entró, la consulta que tardó y la excepción que
salió, escritas en momentos distintos e intercaladas con todas las demás
peticiones que el servidor estaba atendiendo. Sin algo que las una, leer el
registro es adivinar.

```php
$router->middleware(new LogRequests());
```

Ese middleware da un id a cada petición y lo pone en cuatro sitios: en el
contexto compartido del registro, para que toda línea escrita después lo lleve;
en la propia petición, como el atributo `request_id`; en la respuesta, como
`X-Request-Id`; y en el registro que escribe cuando la petición termina, con el
estado y la duración.

Como llega a la respuesta, el id está en la pantalla del visitante cuando algo
se rompe — un ticket de soporte puede llevar la única cadena que lo encuentra
todo.

Un `X-Request-Id` que llega del cliente se respeta, que es como una traza sigue
a una petición de un servicio al siguiente. También es entrada controlada por
el cliente que va directa a los registros, así que debe coincidir con
`[A-Za-z0-9._-]{1,128}`: una longitud sin límite convierte un registro en una
factura de disco, y los caracteres de control convierten un visor de registros
en algo que ya no muestra lo que dice mostrar. Un id que no coincide se
sustituye, no se rechaza, porque la petición en sí no es el problema.

**Regístralo primero**, o tan cerca del primero como permita el pipeline. Solo
se cubre lo que se ejecuta después, y una petición que no casa con ninguna ruta
nunca llega a un controlador — un 404 merece tener registros.

> **Bajo un runtime persistente este middleware es obligatorio.** El contexto
> compartido vive en un objeto que sobrevive a la petición en un worker de
> Swoole o FrankenPHP, así que el id de un visitante seguiría a los registros
> del siguiente. Llama a `forgetContext()` al inicio de cada petición y es el
> dueño explícito de ese reinicio — exactamente como `SetLocale` lo es del
> idioma activo y `Authenticate` del usuario.

### Fallos

Una petición que falla se reporta **una vez**, por el límite del propio router
y no por el middleware. El límite tiene ejecución garantizada y un middleware
puede registrarse en el orden equivocado, así que registrar en ambos
significaría un duplicado siempre que los dos estuvieran presentes y nada
siempre que no lo estuviera ninguno.

El registro sigue llevando el id de la petición, porque una excepción que
desenrolla el pipeline no toca el contexto compartido. Lleva la clase de la
excepción, el archivo, la línea y la traza como campos separados, para que un
colector pueda agrupar por clase sin analizar un mensaje.

Una excepción se salta el resto del pipeline, así que el middleware nunca tiene
su turno para añadir la cabecera a la respuesta. El router la adjunta en su
lugar: quien ve un 500 es la persona que más necesita el id.

### Secretos

El registro estructurado invita a pasar arreglos enteros, y el cuerpo de un
formulario de inicio de sesión es el primer arreglo al que alguien recurre. Los
valores bajo estas claves se sustituyen por `[redacted]`, a cualquier
profundidad:

`password` `password_confirmation` `current_password` `new_password` `secret`
`token` `_token` `access_token` `refresh_token` `api_key` `apikey`
`authorization` `auth` `cookie` `set-cookie` `credit_card` `card_number` `cvv`
`ssn` `cpf`

```php
logger()->redact('pin', 'account_number');
```

Redactar por clave es tosco, y es la diferencia entre que una contraseña llegue
a un agregador de registros y que no llegue.

### Qué falta

| Ausente | Situación |
|---|---|
| Métricas | No se recogen contadores ni tiempos; una línea de registro lleva una duración, que no es lo mismo |
| Muestreo | Se escribe todo registro que supera el nivel; no hay "uno de cada cien" |
| Varios destinos a la vez | Un driver cada vez — sin repartir a un archivo y a un colector juntos |
| Rotación de registros | El archivo crece; la rotación es de `logrotate` o de la plataforma |

---

## CLI

`./sfphp` expone **32 comandos**.

### Generación (12 generadores)

```bash
./sfphp make:controller Post
./sfphp make:model Post
./sfphp make:repository Post
./sfphp make:service Post
./sfphp make:request StorePost
./sfphp make:test PostTest
./sfphp make:middleware CheckAdmin
./sfphp make:event UserCreated
./sfphp make:listener SendWelcome
./sfphp make:policy PostPolicy
./sfphp make:seeder UserSeeder
./sfphp make:factory User

./sfphp make:scaffold Post     # controlador + modelo + repositorio + servicio
```

> `make:middleware` y `make:policy` ahora generan contra contratos que existen
> y se ejecutan. `make:event` y `make:listener` siguen produciendo código para
> una infraestructura ausente: no hay despachador de eventos. Consulta
> [Limitaciones conocidas](#limitaciones-conocidas).

### Base de datos

```bash
./sfphp make:migration create_users_table
./sfphp make:migration:create users
./sfphp migrate [--step=N] [--path=dir]
./sfphp rollback [--step=N]
./sfphp status
./sfphp db:fresh
./sfphp db:seed [--class=UserSeeder]
```

### Caché y colas

```bash
./sfphp cache:clear
./sfphp cache:flush
./sfphp queue:work [--timeout=3600]
./sfphp queue:failed
```

### Servidor y utilidades

```bash
./sfphp serve          # http://localhost:8000
./sfphp routes         # una tabla de las rutas registradas
./sfphp env:example    # crea .env a partir de .env-example
./sfphp css:build      # construye SFCSS desde la configuración
./sfphp tinker         # REPL — solo para desarrollo local
./sfphp list
./sfphp version
./sfphp help [comando]
```

`tinker` evalúa la entrada con `eval()`. Es una herramienta de desarrollo
local; nunca expongas la CLI a entrada no confiable.

---

## SFCSS

Un framework CSS de utilidades generado a partir de
`tools/css-builder/sfcss.config.json`. Las variantes `hover:` y los puntos de
ruptura `sm`/`md`/`lg`/`xl` se generan desde esa configuración.

| | |
|---|---|
| Clases en total | **2.337** |
| — utilidades base | 1.209 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Clases de color | 600 de paleta (20 familias × 10 tonos × `bg`/`text`/`border`) + 25 del tema |
| Tamaño | 110KB en crudo · 92KB minificado · **16,1KB comprimido** |
| Dependencias | ninguna |

```bash
./sfphp css:build     # construye public/assets/css/sfcss.css y .min.css
```

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

Referencia completa: [SFCSS](SFCSS.md) y
[referencia de utilidades](SFCSS_UTILITIES.md).

---

## SFJS

Una biblioteca JavaScript sin dependencias — 12KB en crudo, **3,0KB
comprimidos**. Expuesta como `window.sf`.

```html
<script src="/assets/js/sfjs.js"></script>
```

### API programática

```js
sf.ajax.get('/api/posts');
sf.ajax.post('/api/posts', { title: 'Hola' });
sf.ajax.put('/api/posts/1', { title: 'Editado' });
sf.ajax.delete('/api/posts/1');
sf.ajax.patch('/api/posts/1', { title: 'X' });

sf.form.serialize(formEl);
sf.form.submit(formEl);
sf.form.validate(inputEl);

sf.dom.addClass(el, 'active');   sf.dom.removeClass(el, 'active');
sf.dom.toggleClass(el, 'active'); sf.dom.hasClass(el, 'active');
sf.dom.show(el); sf.dom.hide(el); sf.dom.toggle(el);
sf.dom.on(el, 'click', fn);     sf.dom.off(el, 'click', fn);
sf.dom.ready(fn);

sf.validate.email(v);  sf.validate.required(v);  sf.validate.number(v);
sf.validate.url(v);    sf.validate.minLength(v, 5);  sf.validate.maxLength(v, 50);
sf.validate.pattern(v, '^[a-z]+$');

sf.storage.set('k', {a: 1});  sf.storage.get('k');
sf.storage.remove('k');       sf.storage.clear();

sf.util.debounce(fn, 300);  sf.util.throttle(fn, 300);  sf.util.wait(500);
```

### Atributos declarativos

```html
<button @hxGet="/api/data" @hxTarget="#content">Cargar</button>
<button @hxDelete="/api/item/1" @hxTarget="#item" @hxSwap="outerHTML">Borrar</button>

<form @hxPost="/users" @hxTarget="#list">
  <input name="email" @validate="email">
  <button type="submit">Crear</button>
</form>

<button @toggle="menu">Menú</button>
<div id="menu">...</div>
```

`@hxSwap` acepta `innerHTML` (el valor por defecto), `outerHTML`,
`beforebegin`, `afterbegin`, `beforeend` y `afterend`.

`@validate` se ejecuta en `blur` y acepta `required`, `email`, `number`, `url`,
`minLength:N`, `maxLength:N` y `pattern:regex`.

---

## Pruebas

Un ejecutor propio, sin PHPUnit — coherente con las cero dependencias.

```bash
composer run lint        # php -l por todo el proyecto
composer run test        # 90 casos unitarios
composer run test:db     # integración contra MySQL/PostgreSQL reales
composer run test:all
composer run docs        # los tres idiomas concuerdan, y todo enlace resuelve
```

`tests/db.php` necesita los DSN en el entorno y se omite con un aviso cuando no
los hay:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
  composer run test:db
```

La CI ejecuta dos trabajos: `unit` sobre una matriz de PHP 8.1–8.4 **sin
`mbstring`**, que es lo que impide que el manejo de UTF-8 dependa de la
extensión; e `integration` con MySQL 8 y PostgreSQL 16 como servicios.

`composer run docs` se ejecuta también en el trabajo `unit`. La documentación
existe en tres idiomas, y la prosa no se puede comparar mecánicamente — pero la
estructura sí. Afirma que las tres versiones tienen las mismas secciones,
subsecciones, tablas y bloques de código, en el mismo orden y con el mismo
lenguaje de cerca, y que todo enlace relativo y toda ancla interna resuelven.
Eso detecta las dos cosas que de verdad salen mal cuando tres archivos se editan
a mano: una sección añadida en un idioma y olvidada en los otros, y un enlace
que quedó apuntando a un archivo que cambió de sitio.

---

## Limitaciones conocidas

Estas son las ausencias reales. No son errores — son cosas que el framework no
hace, y que deberías conocer antes de elegirlo.

| Ausente | Impacto |
|---|---|
| **Caducidad de sesión** | Por inactividad o absoluta: lo que diga `php.ini`. Consulta [Seguridad](#seguridad) |
| **Recuperación de contraseña y doble factor** | El inicio de sesión existe; estos flujos no. Consulta [Autenticación](#autenticación) |
| **Sistema de eventos** | `make:event` y `make:listener` generan clases sin despachador |
| **Un ORM completo** | Hay una capa de [Modelos](#modelos) con hidratación, tipos de atributo, relaciones (incluido muchos a muchos) y `with()`. No hay mapa de identidad, unidad de trabajo, proxy de carga perezosa, relación polimórfica ni esquema derivado de la clase — y [¿ORM o constructor de consultas?](#orm-o-constructor-de-consultas) explica el motivo de cada uno |
| **Formato por idioma** | Las fechas y los números no se formatean por idioma; `ext-intl` hace eso bien y el framework no lo intenta. Consulta [Internacionalización](#internacionalización) |
| **Zonas horarias** | Sin manejo dedicado |
| **Métricas** | Los registros llevan duraciones; no se recogen contadores ni tiempos. Consulta [Registro](#registro) |
| **Caché de rutas** | El despacho es O(n), un `preg_match` por ruta. Bien para decenas, no para centenares |
| **Sesión conectable** | `$_SESSION` nativo. Varias instancias necesitan sesiones pegajosas |
| **Distribución como paquete** | El espacio de nombres de los controladores ya es un parámetro del Router, pero `composer.json` sigue describiendo una aplicación en vez de una biblioteca |

SFHT tampoco tiene variables automáticas de bucle (`$loop`) ni herencia parcial
de bloques (`@parent`).

---

*Documentación revisada el 2026-09-21 contra el código en ejecución.*

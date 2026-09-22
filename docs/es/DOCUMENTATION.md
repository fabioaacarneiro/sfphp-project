# SFPHP — Documentación

Framework PHP full-stack con **cero dependencias de runtime** y corrección
Unicode en toda su superficie. Esta documentación describe lo que el código
hace hoy. Donde algo no existe, se dice que no existe — véase
[Limitaciones conocidas](#limitaciones-conocidas).

> Verificado contra PHP 8.4 · suite: 154 pruebas, 0 fallos
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
- [Componentes y .phpx](#componentes-y-phpx)
- [Contenedor e inyección de dependencias](#contenedor-e-inyección-de-dependencias)
- [Base de datos](#base-de-datos)
- [Modelos](#modelos)
- [¿ORM o constructor de consultas?](#orm-o-constructor-de-consultas)
- [Migraciones y constructor de esquemas](#migraciones-y-constructor-de-esquemas)
- [Seeders y factories](#seeders-y-factories)
- [Caché](#caché)
- [Colas](#colas)
- [Eventos](#eventos)
- [Cliente HTTP](#cliente-http)
- [Correo](#correo)
- [Validación](#validación)
- [Subida de archivos](#subida-de-archivos)
- [Internacionalización](#internacionalización)
- [Tiempo y zonas horarias](#tiempo-y-zonas-horarias)
- [Cadenas UTF-8](#cadenas-utf-8)
- [Autenticación](#autenticación)
- [Seguridad](#seguridad)
- [Sesiones](#sesiones)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Depuración](#depuración)
- [Manejo de errores](#manejo-de-errores)
- [Registro](#registro)
- [Health check y métricas](#health-check-y-métricas)
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
esquemas con paridad MySQL/PostgreSQL, un motor de plantillas, componentes en
`.phpx`, un cliente HTTP, eventos, caché, colas y un CLI con 35 comandos.

**No es** un sustituto de Laravel o Symfony. No hay un ORM completo, los
eventos se despachan en el proceso y de forma síncrona, sin broker de mensajes,
y la autenticación cubre inicio de sesión, guards y autorización, pero no
recuperación de contraseña ni doble factor. Lo que existe es lo bastante pequeño
para leerse de principio a fin.

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

- PHP 8.1 o posterior
- Composer 2
- PDO con el driver de tu base de datos (opcional — solo si usas base de datos)

### Empezar un proyecto

```bash
composer create-project fabioaacarneiro/sfphp-framework mi-app
cd mi-app
./sfphp serve
```

Esa es toda la configuración. <http://localhost:8000> responde, la consola está
en `./sfphp` en la raíz del proyecto en vez de enterrada en `vendor/bin`, y lo
que tienes delante es una aplicación que funciona y que puedes editar:

```
mi-app/
  app/controllers/        un controlador, que responde la portada
  app/models/             un modelo
  app/resources/views/    las plantillas de las que está hecha esa página
  src/routes.php          las rutas
  src/                    el framework
  database/migrations/    users y sessions, listas para ejecutar
  public/index.php        el controlador frontal
  resources/assets/       SFCSS y SFJS
  sfphp                   la consola
  .env                    escrito por ti, con la clave JWT generada
```

Crear el proyecto también copia `.env-example` a `.env` — **con un `JWT_KEY` de
verdad**, porque el marcador de posición se rechaza a propósito y generar una
clave no debería ser lo primero sobre lo que tengas que leer — y publica SFCSS y
SFJS en `public/assets`.

Todo eso es **tuyo**. Borra el controlador de ejemplo y sus vistas; el framework
es `src/` y no se inmuta.

### Dónde vive cada cosa

Nada de lo que editas está dentro de `vendor/`, y esa es la regla sobre la que
está construida la disposición: `vendor/` guarda el autoloader y nada más,
porque el framework no tiene dependencias y un proyecto creado lleva su propia
copia de él.

| | |
|---|---|
| `public/` | Lo que sirve el servidor web — el controlador frontal y los assets publicados |
| `app/` | Tu código y tus plantillas: controladores, modelos, servicios, vistas |
| `src/` | El framework, y lo que lo configura: rutas, middleware, migraciones, la capa de ajustes |
| `database/` | Migraciones, seeders y factories |
| `lang/` | Tus catálogos de mensajes |
| `.env` | Configuración, nunca versionada |
| `vendor/` | El autoloader. Nada que abrir, nada que editar |

La única llamada que el framework pide ya está en el controlador frontal que
vino hecho, y vale conocerla porque es lo que ata las dos mitades:

```php
require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Bootstrap;

Bootstrap::load(dirname(__DIR__));
```

Carga tu `.env` si lo tienes, define los ajustes que el framework lee a menos
que ya los hayas definido, y registra dónde viven tus vistas y catálogos. Un
proyecto con una disposición poco común lo dice:

```php
Bootstrap::load(dirname(__DIR__), [
    'views' => 'resources/views',
    'lang' => 'resources/lang',
    'env' => null,               // la configuración viene del entorno
]);
```

### Desde un clon

Para trabajar **en** el framework, no con él:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
./sfphp serve
```

Un clon añade lo que un proyecto creado deja atrás: la suite de pruebas, la
documentación en tres idiomas y la definición de CI.

En producción, apunta el `DocumentRoot` a `public/`.

### Qué es de quién

La línea pasa entre el framework y la aplicación, y conviene conocerla porque
todo lo anterior depende de ella.

| | |
|---|---|
| El paquete autocarga | Solo `src/`, más cuatro archivos dentro de él |
| La aplicación es dueña de | `.env`, sus constantes, sus vistas, sus catálogos, sus rutas |
| `Bootstrap::load()` | Es cómo la segunda le cuenta de sí al primero |

Todo ajuste que el framework lee pasa por `defined()`, así que un proyecto que
nunca llame a `Bootstrap::load()` arranca igualmente con los valores por
defecto — y una prueba afirma que ningún archivo del framework lee uno sin esa
guarda.

La zona horaria del runtime es la excepción: se pone en UTC cuando carga el
paquete, antes de que nada pueda preguntar, porque es una regla de corrección y
no un ajuste. Consulta [Tiempo y zonas horarias](#tiempo-y-zonas-horarias).

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

El primer mapeo es del paquete; los otros tres son de este repositorio,
declarados bajo `autoload-dev` para que nunca lleguen a un proyecto que instala
el framework.

Cuatro archivos se cargan siempre (`autoload.files`), y los cuatro viven en
`src/`: `runtime.php`, `utils.php`, `http.php` y `helpers.php`. El
`app/config/config.php` de la aplicación de ejemplo también se carga, mediante
`autoload-dev`, y lo único que hace es llamar a `Bootstrap::load()`.

---

## Ciclo de vida de la petición

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ runtime.php→ date_default_timezone_set('UTC')
 │      utils.php  → helpers globales: e(), asset(), csrf_*()
 │      http.php   → constantes HTTP_OK, GET, POST, ...
 │      helpers.php→ cache(), logger(), mailer(), now(), dispatch(), __(), ...
 │      config.php → Bootstrap::load(): .env, constantes, rutas de vista y lang
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

### Leer un ajuste

`Bootstrap` convierte `.env` en constantes, y el framework las lee a través de
`Config` en vez de llamar a `constant()` directamente:

```php
use SfphpProject\src\Config;

Config::get('APP_ENV', 'production');
Config::int('SESSION_LIFETIME', 7200);
Config::string('MAIL_FROM_ADDRESS');
Config::has('JWT_KEY');
```

Un `set()` explícito gana, luego la constante, luego el valor por defecto que
pasó quien llamó. Nada que lea `APP_ENV` directamente ha cambiado — las
constantes siguen definidas y siguen funcionando.

La razón del rodeo es que una constante no se puede quitar. Eso está bien para
una aplicación, que decide sus ajustes una vez al arrancar, y resulta incómodo
para un test, que quiere saber qué pasa con otra duración de sesión sin levantar
un proceso aparte para averiguarlo:

```php
Config::set('SESSION_LIFETIME', 60);
// ...
Config::forget('SESSION_LIFETIME');   // vuelve a la constante
```

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

Una ruta **sin parámetros** se compara como dos cadenas, nunca ejecutando una
expresión regular, y una ruta con parámetros compila su patrón una vez y lo
guarda. La mayoría de las aplicaciones son sobre todo rutas estáticas, así que
la mayor parte del bucle de despacho cuesta una comparación. Lo que sigue siendo
lineal es el bucle en sí: el router recorre la tabla hasta que algo coincide, y
nada se compila de antemano a un archivo.

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

final class PostController
{
    public function show(Request $request, string $id): Response
    {
        return Response::view('posts/show', ['id' => (int) $id]);
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

### Sin clase base

Un controlador no hereda nada. El framework pide una acción que devuelva un
`Response`, y `Response` es una fábrica, así que cualquier tipo de respuesta se
alcanza desde cualquier clase:

```php
final class PostController
{
    public function index(Request $request): Response
    {
        return Response::view('posts/index', ['posts' => $posts]);
    }
}
```

| | |
|---|---|
| `Response::view($view, $data, $status)` | Una página HTML |
| `Response::json($data, $status)` | JSON |
| `Response::html($html, $status)` · `Response::text()` | Un cuerpo que construiste |
| `Response::redirect($url, $status)` | Una redirección |
| `Response::route($nombre, $parametros, $query)` | Una redirección a una ruta con nombre |
| `Response::back($request, $fallback)` | Una redirección a donde venía el visitante |
| `Response::noContent()` | 204 |

> **`back()` no sale de tu sitio.** El referer es una cabecera, así que lo elige
> el visitante, lo que lo convierte en un destino de redirección que un atacante
> puede dictar. Seguirlo a otro origen es una redirección abierta — la forma
> clásica en que un enlace de phishing toma prestado el buen nombre de tu
> dominio. Un referer que nombra otro host cae al valor de respaldo, y lo que no
> sea una ruta, también.

Aquí había un `BaseController` que ofrecía `$this->view()` y
`$this->redirect()`. Pedía heredar una clase para acortar dos llamadas que ya
existían, que es herencia sin pagar nada, y vivía en la aplicación de ejemplo,
donde un `composer require` nunca llegaba — así que la línea que enseñaba
lanzaba un fatal en un proyecto instalado. `route()` y `back()` eran lo único
suyo que no estaba ya en otro sitio, y ahora están en `Response`.

### Endpoints JSON

Un endpoint que responde JSON tampoco necesita clase base. Lo que necesita es
que un cuerpo que no sea JSON se rechace **antes** de que corra la acción, y
para eso está el pipeline:

```php
use SfphpProject\src\Http\Middleware\RequireJson;

Router::group('/api', function (): void {
    Router::post('/posts', 'PostController', 'store');
}, 'api.', [new RequireJson()]);
```

```php
public function store(Request $request): Response
{
    $data = $request->attribute('json');   // ya decodificado, ya válido

    return Response::json(['id' => 1], HTTP_CREATED);
}
```

`RequireJson` responde **415** cuando el `Content-Type` no es
`application/json` y **400** cuando el cuerpo no decodifica, y deja lo que
decodificó en la petición. Vale la pena distinguir esos dos códigos: quien
depura "no hablo ese formato" mira en un sitio muy distinto de quien depura "eso
no era JSON válido".

`GET`, `HEAD`, `OPTIONS` y `DELETE` pasan de largo, porque no llevan cuerpo — si
no, el middleware sería inservible en un grupo que lee y escribe, que es la
mayoría. Pasa `new RequireJson(required: true)` para rechazar una escritura que
llegue sin `Content-Type` alguno.

Esto sustituyó a un `ApiController` que había que extender. La comprobación
corría solo donde alguien se acordaba de llamarla, y ponía una clase entre el
framework y cada endpoint para hacer un trabajo que el pipeline ya hacía.

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
mailer();                     // un MailManager, configurado desde MAIL_*
now();                        // el instante actual, en UTC
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
| `StartSession` | Inicia la sesión y aplica los plazos de inactividad y absoluto |
| `VerifyCsrfToken` | Rechaza una petición que cambia estado sin un token válido |
| `Authenticate` | Identifica al usuario, y rechaza anónimos cuando se exige |
| `RateLimit` | Limita cuántas veces el mismo cliente golpea una ruta |
| `RequireJson` | Rechaza con 415 un cuerpo que no es JSON, y con 400 uno que no decodifica |

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

Hay exactamente una excepción, y la lleva un tipo, no una sintaxis: un valor que
sea `Sfht` se imprime tal cual, porque `Sfht` significa marcado que este
framework produjo. Es lo que permite componer un componente con `{{ }}` mientras
una cadena en la misma posición sigue escapada — véase
[Componentes y .phpx](#componentes-y-phpx). Todo lo que no sea `Sfht` se escapa,
incluida una cadena de la que estés seguro.

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
línea reales. La escritura es atómica e invalida OPcache en esa ruta exacta.

El archivo compilado se llama por la ruta de la plantilla **y por su versión** —
su fecha de modificación y su tamaño —, así que una plantilla que cambia compila
a otro archivo y un compilado solo responde por los bytes con los que se hizo.
Antes era solo la ruta, con una comparación de "más nuevo que", que únicamente
vale mientras el tiempo avanza: extraer un archivo comprimido lo hace retroceder,
y una plantilla actualizada instalada por `composer create-project` llegaba más
vieja que una caché escrita minutos antes y nunca se recompilaba.

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

## Componentes y .phpx

Hay dos formas de escribir una página, ambas distribuidas, y cada una es mejor en
algo. La aplicación de ejemplo usa una en sus páginas y la otra en la
demostración en `/phpx`.

| | `.sfht` | `.phpx` |
|---|---|---|
| Qué es | Un archivo de marcado | Una función PHP cuyo marcado vive dentro de ella |
| Composición | `@include`, `@extends`, `@block` | Llamar a la función |
| Qué recibe | Lo que haya en el ámbito, más lo que se le pase | Sus parámetros, y nada más |
| Editado por | Quien sabe HTML | Quien lee PHP |
| Mejor para | Páginas y layouts | Piezas reutilizables |
| Paso de compilación | Ninguno — compila bajo demanda | `./sfphp build --phpx` |

### Escribir un componente

```php
<?php

namespace App\Components;

use SfphpProject\src\View\Sfht;

function Card(string $titulo, string $cuerpo, string $color = 'blue'): Sfht
{
    return sfht(
        <div class="card border-{{ $color }}-500">
            <div class="card-header"><h3 class="m-0">{{ $titulo }}</h3></div>
            <div class="card-body"><p>{{ $cuerpo }}</p></div>
        </div>
    );
}
```

El `sfht(` abre una región de marcado y el `)` que le corresponde la cierra. Entre
los dos es SFHT, así que `{{ }}`, `{!! !!}`, `@if` y `@foreach` funcionan y el
escapado es el mismo que en el resto del framework.

```bash
./sfphp build --phpx                       # todo .phpx bajo app/components
./sfphp build --phpx --from=src/ui         # desde otra carpeta
./sfphp build --phpx --to=build/components # salida en otra carpeta
```

La compilación escribe el PHP junto al fuente y ejecuta `php -l` sobre cada
resultado, así que un error de sintaxis aparece al compilar con el número de línea
del `.phpx` — el compilador rellena la salida para mantener esa alineación.

### Un componente por archivo

Un archivo contiene un componente y lleva su nombre, y los componentes de una
misma página viven en una carpeta propia. La compilación recorre el árbol entero
y lo refleja, así que lo compilado se parece a lo escrito:

```
app/components/
├── Card.phpx
├── BulletList.phpx
└── postcode/
    ├── PostcodePage.phpx
    ├── layout/
    │   ├── PageHeader.phpx
    │   └── PageFooter.phpx
    ├── lookup/
    │   ├── PostcodeLookup.phpx
    │   ├── Address.phpx
    │   ├── Field.phpx
    │   └── Notice.phpx
    └── explain/
        └── HowItWorks.phpx
```

Los componentes de una misma carpeta comparten el espacio de nombres, así que se
componen por su nombre — sin import y sin prefijo. Cruzar una carpeta, o alcanzar
uno desde un controlador, es un `use function`, igual que para cualquier otra
función en PHP:

```php
use function SfphpProject\app\components\postcode\lookup\Address;
```

### Por qué un componente devuelve Sfht

```php
{{ Card('Hola', $cuerpo) }}    la tarjeta se renderiza
{{ $cuerpo }}                   el texto se escapa
```

Ambos en la misma posición, con lo correcto ocurriendo en cada caso, porque el
**tipo** dice cuál es cuál. `Sfht` significa "marcado que este framework produjo";
cualquier otra cosa es texto de origen desconocido.

La alternativa — devolver una cadena y escribir `{!! Card(...) !!}` — le pide al
autor que recuerde qué valores son de confianza, y ahí es donde alguien acaba
escribiendo `{!! $comentario !!}` y publica un agujero de cross-site scripting.

> **Envolver una cadena en `Sfht` evita el escapado**, que es justo para lo que
> sirve y justo por lo que `new Sfht($loQueSea)` merece una segunda mirada. El
> compilador construye estos objetos a partir de marcado que escribió un autor;
> uno construido a partir de una petición es la decisión de confiar en ella.

### Cuál elegir

Usa `.sfht` cuando la cosa es una **página**: layout, bloque, algo que un
diseñador pueda abrir. Usa `.phpx` cuando la cosa es una **pieza**: una tarjeta,
un campo, una fila de tabla — cualquier cosa que reciba argumentos y aparezca más
de una vez.

La diferencia práctica es el contrato. Un parcial ve lo que por casualidad estaba
en el ámbito donde se incluyó, así que lo que necesita se descubre leyendo el
archivo. Los parámetros de un componente son sus props, así que lo que necesita
es la firma.

### Cargar los componentes

PHP autocarga clases, no funciones, así que un componente compilado no puede
encontrarse bajo demanda. El front controller lo incluye una vez:

```php
$compiled = __DIR__ . '/../app/components/compiled';

if (is_dir($compiled)) {
    $components = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($compiled, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($components as $component) {
        if ($component->getExtension() === 'php') {
            require_once $component->getPathname();
        }
    }
}
```

### Soporte del editor

Un `.phpx` es PHP con marcado donde PHP no lo espera, así que hay que avisar al
editor de tres cosas distintas. El proyecto ya trae la configuración, y un
proyecto creado la recibe lista:

| Archivo | Cubre |
|---|---|
| `.editorconfig` | Espacios en blanco y codificación, en cualquier editor |
| `.vscode/settings.json` | Asociación de lenguaje, Emmet y la lista de archivos de Intelephense |
| `.zed/settings.json` | Lo mismo, en el formato de Zed |

Intelephense mantiene una lista de archivos a indexar que es **independiente** de
la asociación de lenguaje del editor, y por eso el autocompletado parece
imposible hasta que `intelephense.files.associations` menciona `*.phpx`.

El coste de mapear `.phpx` al lenguaje `php` es que su marcado se lee como error
de sintaxis, así que el diagnóstico queda apagado también para todo `.php`.
`./sfphp build --phpx` y `composer run lint` siguen atrapando los de verdad.

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
final class PostController
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

Un atributo de fecha es **siempre UTC**, en ambos sentidos — consulta
[Tiempo y zonas horarias](#tiempo-y-zonas-horarias) para saber por qué la regla
es estricta.

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

Las migraciones toman un bloqueo antes de leer la lista de pendientes, así que
dos instancias migrando al desplegar no pueden decidir ambas que el mismo
archivo está pendiente y ejecutarlo las dos. MySQL y PostgreSQL tienen cada uno
un bloqueo consultivo — un bloqueo con nombre, atado a la conexión, liberado
cuando la conexión desaparece, así que un despliegue muerto a mitad de la
migración no deja nada atascado. Un driver sin él no se rechaza: registra que
está ejecutándose sin bloqueo, porque hacer fallar las migraciones en SQLite
sería peor que prescindir de una guarda donde un único escritor es la norma de
todos modos.

Ejecutar las migraciones como un paso del pipeline sigue siendo la mejor forma.
El bloqueo está porque el framework no debería depender de que todo el mundo lo
haga.

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

#### Las sentencias se ejecutan en el orden en que las escribiste

Eso importa en cuanto hay un renombrado, porque un renombrado cambia cómo debe
llamar a la columna toda sentencia posterior:

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->renameColumn('code', 'sku');
    $table->string('sku', 10)->change();     // el nombre nuevo, y funciona
});
```

El constructor emitía todas las sentencias de columna antes que todas las
operaciones, lo que ponía esa modificación antes del renombrado que creó el
nombre que usa. Ningún agrupamiento fijo puede ser correcto — poner los
renombrados primero rompe el orden contrario igual de bien — así que las
sentencias salen en el orden en que las declara el blueprint.

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

### Elegir el driver

```ini
CACHE_DRIVER=file          # el valor por defecto
CACHE_DRIVER=redis         # necesita ext-redis
CACHE_DRIVER=array         # memoria, se pierde al acabar la petición

CACHE_PATH=storage/cache   # dónde escribe el driver de archivo
CACHE_PREFIX=sfphp:cache:  # para que dos aplicaciones compartan un Redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
```

> **Este ajuste decide más que la caché.** Los contadores de limitación de
> peticiones, la lista de tokens revocados y — con `SESSION_DRIVER=cache` — las
> sesiones viven todos aquí. Con el driver de archivo cada máquina guarda su
> propia copia, así que detrás de un balanceador un token revocado sigue
> funcionando en las demás instancias y un límite de 60 peticiones es en
> realidad 60 *por instancia*. **Más de una instancia significa `redis`.**

Elegir `redis` sin `ext-redis` **falla al arrancar** en vez de caer al driver de
archivo. Una caída silenciosa dejaría a quien opera creyendo que esas tres cosas
están compartidas mientras cada máquina guarda la suya — un agujero que aparece
meses después y nunca como error.

Se abre una conexión por proceso, compartida por la caché, la cola y el handler
de sesión, en lugar de un socket para cada uno. Una aplicación que construye la
suya — un socket TLS, un cliente de clúster — la entrega:

```php
use SfphpProject\src\RedisConnection;

RedisConnection::use($miRedis);
```

Construir un manager a mano sigue valiendo, y es como se obtiene una segunda
caché distinta de la configurada:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$borrador = new CacheManager(new MemoryDriver());   // solo para esta petición
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
        mailer()->send(
            (new Message())->to($this->to)->subject('Hola')->text('Cuerpo')
        );
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

El worker procesa hasta el tiempo límite, incrementa los intentos al fallar y
mueve el trabajo a `failed_jobs` cuando se le acaban los intentos. Con
`ext-pcntl`, `SIGTERM` y `SIGINT` lo apagan de forma ordenada.

Las tablas `jobs` y `failed_jobs` se crean bajo demanda, en la primera operación
que las necesita — instanciar el driver no abre ninguna conexión.

### Elegir el driver

```ini
QUEUE_DRIVER=database          # el valor por defecto
QUEUE_DRIVER=redis             # necesita ext-redis

QUEUE_RESERVATION_SECONDS=900  # más largo que tu trabajo más lento
QUEUE_TABLE=jobs
QUEUE_FAILED_TABLE=failed_jobs
```

`dispatch()` y `./sfphp queue:work` leen el mismo ajuste, que es la razón de que
exista en vez de ser un argumento del constructor: un worker que construyera su
propio driver vaciaría la base de datos mientras las peticiones encolaban en
Redis, y ninguno de los dos lados avisaría de nada.

El driver de base de datos no necesita ningún servicio extra y sobrevive a un
reinicio, así que es el valor por defecto. Redis es más rápido y saca la tabla
de trabajos de la base de datos; los dos entregan un trabajo a exactamente un
worker.

### Más de un worker

Un trabajo se entrega a exactamente un worker. Vale decirlo porque no era
cierto hasta esta versión, y porque el fallo era invisible con un solo worker:
`pop()` seleccionaba una fila y luego la actualizaba, así que dos workers leían
el mismo trabajo, ambos lo marcaban como reservado y **ambos lo ejecutaban**.
Para una cola eso no es lentitud, es un efecto secundario duplicado — el mismo
correo dos veces, la misma tarjeta cobrada dos veces.

La reserva ahora es una reclamación. El `UPDATE` lleva la condición de que el
trabajo siga sin reservar, y solo lo tiene el worker cuya sentencia afecta a una
fila; quien pierde busca el siguiente en vez de ejecutar el de otro. Un update
condicional en lugar de `SELECT … FOR UPDATE SKIP LOCKED`, porque el framework
soporta siete drivers y no todos lo tienen.

```php
new DatabaseDriver(reservationSeconds: 900);
```

**Un trabajo reservado por un worker que murió vuelve.** Un worker muerto entre
reservar y terminar deja el trabajo marcado como tomado sin nadie trabajando en
él. Todo trabajo reservado más de `reservationSeconds` — quince minutos por
defecto — se libera para que otro worker lo reclame. Ponlo por encima de lo más
que un trabajo pueda tardar legítimamente, o un trabajo lento se tomará dos
veces.

El driver de Redis tenía el mismo defecto por el mismo motivo: `zRem` informa
cuántos miembros quitó y nada comprobaba la respuesta. Ahora sí.

### Qué falta

| Ausente | Situación |
|---|---|
| Varias colas con nombre | Todo va a `default`; la columna existe y nada la lee |
| Reprocesar un trabajo fallido | `failed_jobs` los registra; volver a ponerlo es manual |
| Espera entre intentos | Un reintento espera 60 segundos fijos |
| Supervisor | Mantener el worker vivo es tarea de `systemd`, `supervisor` o la plataforma |

---

## Subida de archivos

```php
$file = $request->file('avatar');

if ($file === null || !$file->isValid()) {
    return Response::json(['message' => $file?->errorMessage()], HTTP_UNPROCESSABLE_ENTITY);
}

$file->assertType(['image/png', 'image/jpeg'])
     ->assertExtension(['png', 'jpg', 'jpeg'])
     ->assertSmallerThan(2 * 1024 * 1024)
     ->assertImage();

$path = $file->store('/var/app/storage/avatars');
```

`$_FILES` se exponía en crudo antes de que esto existiera, lo que dejaba a cada
aplicación escribir desde cero el mismo código crítico de seguridad. Subir un
archivo es una vía clásica para entrar en un servidor, y los errores son
específicos y repetibles — así que vale la pena nombrarlos en vez de resumirlos.

### Tres mentiras que cuenta un navegador

**El tipo informado es una afirmación.** `$_FILES['x']['type']` es una cabecera
que envió el cliente, así que un script PHP anunciado como `image/png` llega
como `image/png`. Comprobarlo no prueba nada. `mimeType()` lee los bytes del
propio archivo con `ext-fileinfo`, y `assertType()` rechaza en vez de adivinar
cuando esa extensión falta.

**El nombre informado también es una afirmación.** Usarlo para construir una
ruta es cómo se escribe `../../public/shell.php`. `clientName()` quita todo lo
que parezca una ruta, incluido el byte nulo que hace que `shell.php\0.png` pase
una comprobación de extensión y aterrice como `shell.php` — y `store()` no lo
usa.

**Un archivo que no se subió no es un archivo.** `$_FILES` se puede falsificar
cuando un script queda alcanzable de una forma que su autor no previó, apuntando
`tmp_name` a `/etc/passwd`. `is_uploaded_file()` distingue los dos, y se
comprueba antes de leer o mover nada; `store()` usa entonces
`move_uploaded_file()`, que aplica la misma guarda en el momento que importa.

### Comprobaciones

Cada una lanza `UploadException` con un mensaje que nombra lo que rechazó, así
que un controlador decide si eso es un error de formulario o un fallo:

| | |
|---|---|
| `assertType(['image/png'])` | Lo que el archivo **contiene**, por sus bytes |
| `assertExtension(['png'])` | Cómo se **llama** el archivo |
| `assertSmallerThan($bytes)` | Por campo, a diferencia de `upload_max_filesize` |
| `assertImage()` | Decodifica la cabecera, así que una imagen falsa se rechaza |

Merece la pena comprobar tipo y extensión, porque son mentiras distintas: lo que
un archivo contiene decide cómo lo lee una biblioteca, y en qué termina su
nombre decide cómo lo trata un servidor web. Un PNG real llamado `avatar.php`
sigue siendo un problema si cae donde se ejecuta PHP.

```php
try {
    $file->assertType(['application/pdf'])->assertSmallerThan(5 * 1024 * 1024);
} catch (UploadException $e) {
    $errors['factura'] = $e->getMessage();
}
```

`Validator` queda deliberadamente fuera. Trabaja con escalares de un formulario,
y el tipo real de una subida es algo que solo el propio archivo responde.

### Almacenar

```php
$path = $file->store('/var/app/storage/facturas');
// /var/app/storage/facturas/9f2c…a41.pdf

$path = $file->store($directorio, 'informe.csv');   // igualmente saneado
```

El nombre guardado es **aleatorio**, y eso es el objetivo y no una comodidad: el
nombre del cliente es entrada del cliente. La extensión se traslada solo cuando
es alfanumérica simple, así que nada en ella puede ser una ruta ni una segunda
extensión. Un nombre que pases tú se reduce a algo que no puede ser una ruta, y
se rechaza del todo cuando no queda nada utilizable.

> **Guarda las subidas fuera del document root.** Nada de esto impide que un
> archivo se ejecute si se escribe donde el servidor web lo ejecutará. `public/`
> es el único sitio al que una subida nunca debería ir.

### Varios archivos

```php
foreach ($request->files('fotos') as $foto) {
    $foto->assertImage()->store($directorio);
}
```

`$_FILES['fotos']` para `name="fotos[]"` no es una lista de archivos — es un
archivo cuyas propiedades son todas listas. `files()` le da la vuelta, y `file()`
responde `null` para un campo así en lugar de devolver algo inservible.
`hasFile()` pregunta si llegó un archivo **utilizable**, no si vino el campo.

### Por qué falló una subida

PHP informa de los fallos como enteros `UPLOAD_ERR_*`, y la diferencia importa a
quien rellena el formulario: "el archivo es demasiado grande" es algo sobre lo
que puede actuar y "el servidor no tiene directorio temporal" no lo es.
`errorMessage()` devuelve el mensaje correcto, traducido, del catálogo `upload.*`
que el framework trae en los tres idiomas.

### Qué falta

| Ausente | Situación |
|---|---|
| Abstracción de almacenamiento | `store()` escribe en una ruta local. S3 o un volumen compartido es de la aplicación, y el disco local no se comparte entre instancias |
| Procesamiento de imagen | Sin redimensionar ni recodificar. `ext-gd` hace eso y el framework no lo envuelve |
| Quitar metadatos | El EXIF, incluido dónde se tomó una foto, se conserva tal como llegó |
| Antivirus | Fuera de alcance; es tarea de ClamAV, sobre el archivo ya guardado |
| Subida por partes o reanudable | Una petición, un archivo |

---

## Cliente HTTP

Llamar a otro servicio significaba `curl_setopt_array` con una docena de
constantes, decodificar el cuerpo a mano y acordarse — o, mucho más a menudo,
olvidarse — de poner un tiempo de espera.

```php
use SfphpProject\src\Http\Http;

$respuesta = Http::get('https://api.ejemplo.com/users', ['page' => 2]);
$respuesta = Http::post('https://api.ejemplo.com/users', ['name' => 'Ana']);

$respuesta->ok();       // true para 2xx
$respuesta->status();   // 200
$respuesta->json();     // el cuerpo decodificado
```

Un cuerpo pasado como array va como JSON, con las cabeceras `Content-Type` y
`Accept` que eso implica. `->asForm()` lo manda como formulario, y una cadena va
tal cual — quien codificó el cuerpo es dueño de su tipo.

Cada verbo existe en la fachada y en un cliente, con la misma firma:

```php
Http::get($url, $query);       // valores de query, añadidos a la URL
Http::post($url, $cuerpo);
Http::put($url, $cuerpo);
Http::patch($url, $cuerpo);
Http::delete($url, $cuerpo);   // se permite cuerpo, y a menudo se ignora

Http::client();                // un cliente sin nada configurado
```

Para un método que estos no cubren — `OPTIONS`, `HEAD`, o algo que un servicio
inventó — `send()` lo acepta:

```php
Http::client()->send('OPTIONS', 'https://api.ejemplo.com/users');
Http::client()->send('REPORT', $url, $cuerpo, ['page' => 2]);
```

### Un cliente para un servicio que llamas a menudo

```php
$billing = Http::base('https://billing.interno')
    ->token($jwt)
    ->timeout(5);

$factura = $billing->get('/invoices/7')->throw()->json();
```

Un cliente es un **valor**: cada método devuelve uno nuevo, así que un cliente
configurado para un servicio puede circular sin que nada pueda cambiarlo.

| En la fachada | En un cliente | Hace |
|---|---|---|
| `Http::base($url)` | `->base($url)` | Las rutas relativas cuelgan de aquí |
| `Http::withToken($jwt)` | `->token($jwt)` | Un bearer token |
| `Http::withBasic($usuario, $clave)` | `->basic($usuario, $clave)` | Credenciales HTTP basic |
| `Http::withHeaders([...])` | `->headers([...])` | Cualquier otra cabecera |
| `Http::timeout($segundos, $conexion)` | `->timeout($segundos, $conexion)` | Cuánto esperar |
| — | `->asForm()` | Mandar cuerpos como formulario en vez de JSON |
| — | `->insecure()` | Dejar de verificar certificados |

Cada una en la fachada equivale a `Http::client()` seguido del método de
instancia, y todas devuelven un cliente, así que encadenan en cualquier orden.

### Leer la respuesta

Un error **es** una respuesta: se alcanzó el servidor, entendió y dijo que no.
Así que un 404 y un 500 vuelven para ser inspeccionados, no lanzados.

| | |
|---|---|
| `status()` | El código |
| `ok()` | 2xx |
| `failed()` · `clientError()` · `serverError()` | 4xx o 5xx, 4xx, 5xx |
| `body()` · `json()` | El cuerpo, crudo o decodificado |
| `header($nombre)` · `headers()` | Sin distinguir mayúsculas |
| `url()` | La URL que respondió, **después** de las redirecciones |
| `throw()` | Lanza en 4xx y 5xx, y devuelve `$this` en los demás casos |

`json()` responde `null` cuando el cuerpo no es JSON, porque que un servicio
devuelva una página de error en su lugar es algo que pasa; `json(strict: true)`
lanza.

Una petición que **no** produjo respuesta — conexión rechazada, un nombre que no
resuelve, un tiempo agotado, un certificado que no verificó — lanza
`ClientException`. No hay nada que devolver.

### Lo que no hace

**Reintentar.** Cuántas veces intentar, cuánto esperar entre intentos y qué
fallos merecen otro son decisiones sobre el servicio llamado, no sobre HTTP —
una petición que cobra una tarjeta no es de las que se repiten porque la
respuesta tardó. Eso pertenece a la integración, junto al conocimiento que puede
responderlo.

### Lo que defiende

Se pone un tiempo de espera lo pidas o no: 5 segundos para conectar y 15 para el
intercambio entero. Una llamada sin tiempo de espera retiene un worker hasta el
límite del propio PHP, así que un servicio lento se lleva la aplicación entera.

Los certificados se verifican. `->insecure()` lo apaga y tiene un nombre
incómodo a propósito, porque `CURLOPT_SSL_VERIFYPEER => false` copiado de una
respuesta de foro está entre los agujeros más comunes en PHP.

Las redirecciones se siguen, con un tope de cinco, y **nunca** de `https://` a
`http://` — una degradación que el servidor pide y el cliente debe rechazar, ya
que todo lo posterior viaja en claro, incluida la cabecera `Authorization` que la
petición pueda llevar.

> **Una URL que vino de un visitante es una petición que eligió un atacante.**
> Apuntada a `169.254.169.254`, o a algo que solo tu red alcanza, esto la busca y
> devuelve la respuesta — el ataque llamado SSRF. Nada aquí distingue una URL que
> construiste de una que alguien escribió, así que comprueba las que no
> construiste tú.

---

## Correo

```php
use SfphpProject\src\Mail\Message;

mailer()->send(
    (new Message())
        ->to('ana@ejemplo.com', 'Ana')
        ->subject('Tu pedido')
        ->text('Gracias por tu compra.')
        ->html('<p>Gracias por tu compra.</p>')
);
```

El framework sabe poner bytes en un servidor de correo. No sabe por qué los
envías: aquí no hay correo de bienvenida ni recuperación de contraseña, porque
eso es una decisión sobre para qué sirve una aplicación. Lo que hay es el
transporte, con la misma forma que la caché y la cola — un contrato, un manager
y drivers.

### Configuración

```ini
MAIL_DRIVER=smtp
MAIL_HOST=smtp.proveedor.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls              # tls para STARTTLS, ssl para TLS implícito
MAIL_FROM_ADDRESS=no-responder@tudominio.com
MAIL_FROM_NAME="Tu Producto"
```

| Driver | Envía por | Úsalo para |
|---|---|---|
| `smtp` | Un servidor de correo | Producción, con un servicio contratado |
| `mail` | El `mail()` de PHP | Una máquina de desarrollo, y nada más |
| `log` | El logger | El valor por defecto; muestra lo que habría salido |
| `array` | Memoria | Pruebas, mediante `ArrayDriver::messages()` |

El valor por defecto es `log`, no `mail`. Un framework cuyo comportamiento de
fábrica es entregar mensajes a un MTA local sin configurar no envía nada y no
avisa de nada; escribirlos en el registro al menos dice qué habría salido, y no
puede alcanzar a una persona real por accidente.

### Un driver, todos los proveedores

`smtp` es el único transporte que el framework necesita, y eso no es una
concesión. Todo servicio que alguien contrata — SES, Postmark, SendGrid,
Mailgun, Resend, Brevo — acepta SMTP, así que cambiar de proveedor son cuatro
valores en el entorno y no un driver nuevo. Un cliente HTTP por proveedor sería
más código llegando a menos de ellos.

Los dos caminos hacia TLS funcionan, porque los proveedores se reparten entre
ellos:

| `MAIL_ENCRYPTION` | Puerto, por lo general | Qué ocurre |
|---|---|---|
| `tls` | 587 | Conexión limpia, elevada con `STARTTLS` |
| `ssl` | 465 | Cifrada desde el primer byte |
| `none` | 25, 1025 | Ninguno de los dos — solo un servidor local |

`AUTH PLAIN` y `AUTH LOGIN` están ambos soportados; lo que anuncia el servidor
decide cuál se usa. El certificado se verifica por defecto.

### Enviar no es llegar

Configura las credenciales y los mensajes salen correctamente. Que lleguen a la
bandeja de entrada depende de tres cosas que son DNS y panel del proveedor, no
código:

- **Registros SPF, DKIM y DMARC** en tu dominio de envío. El proveedor te da los
  valores. Sin ellos el mensaje se puntúa como spam o se rechaza de entrada.
- **Un remitente verificado.** Casi todo servicio rechaza un `From` que no hayas
  demostrado que es tuyo.
- **Rebotes y quejas**, que el proveedor informa por webhook. Nada de aquí los
  consume, e ignorarlos quema tu reputación de envío.

Ningún framework hace eso por la aplicación. Se configura una vez por proyecto.

### Escribir un mensaje

```php
(new Message())
    ->from('no-responder@tudominio.com', 'Tu Producto')   // suele quedarse con MAIL_FROM_*
    ->to('ana@ejemplo.com', 'Ana')
    ->cc('registros@tudominio.com')
    ->bcc('auditoria@tudominio.com')
    ->replyTo('soporte@tudominio.com', 'Soporte')
    ->subject('Tu pedido')
    ->text('La versión en texto.')
    ->html('<p>La versión en HTML.</p>')
    ->attach('factura.pdf', $bytes, 'application/pdf')
    ->attachFile('/tmp/informe.csv', 'informe.csv', 'text/csv')
    ->header('X-Campana', 'octubre');
```

Definir `text()` y `html()` envía un `multipart/alternative` y deja elegir al
cliente de quien lee. HTML sin alternativa en texto es una de las cosas que hace
que un mensaje se puntúe como spam, así que vale la pena rellenarlo.

Una **dirección en Bcc llega al servidor y nunca llega a una cabecera**.
Escribirla mostraría cada destinatario oculto a todos los demás, que es
justamente lo que Bcc promete no hacer.

### Dos cosas que no son comodidad

**Un salto de línea en una cabecera se rechaza.** Un salto en un nombre, una
dirección o un asunto permite a quien lo suministró añadir cabeceras propias —
`Bcc:` a una dirección que nunca quisiste es la clásica, y el valor suele venir
de un formulario. `Message` lanza en vez de quitarlo, porque enviar en silencio
un mensaje distinto del pedido es la respuesta equivocada tanto a un ataque como
a un error.

**Todo es UTF-8 hasta el final.** Un asunto con acento se codifica según RFC
2047 y un cuerpo según RFC 2045, así que "Confirmación de inscripción" llega
como sí mismo y no como mojibake. El ASCII puro se deja tal cual, lo que
mantiene legible un mensaje en crudo.

### Enviar en segundo plano

La cola ya está, y una petición no debería esperar a un servidor de correo:

```php
final class SendInvoice extends Job
{
    public function __construct(private int $orderId) {}

    public function handle(): void
    {
        mailer()->send(/* ... */);
    }
}

dispatch(new SendInvoice($order->id));
```

### Probar

```php
$sent = new ArrayDriver();
mailer()->driver($sent);

// ... ejercita el código bajo prueba

$sent->last()->recipients();      // ['ana@ejemplo.com']
$sent->last()->subjectLine();
```

`MAIL_ALWAYS_TO` redirige todo mensaje a una dirección, conservando el
destinatario previsto en una cabecera `X-Intended-For`. Es para un entorno de
preproducción que trabaja sobre una copia de datos de producción, donde las
direcciones de la base de datos pertenecen a personas reales.

### Qué falta

| Ausente | Situación |
|---|---|
| Retorno de entrega | Los rebotes y quejas llegan por webhook al proveedor; nada los consume |
| Imágenes incrustadas (`cid:`) | Los adjuntos se envían como adjuntos, sin referencia desde el HTML |
| Plantillas | Renderiza una vista y pasa el resultado a `html()`; el mailer recibe una cadena |
| Firma DKIM en el cliente | La hace el proveedor, a partir de los registros DNS que publicas |
| Conexión reutilizada | Una conexión por mensaje. El envío masivo pertenece a la cola |

---

## Eventos

```php
use SfphpProject\src\Events\Dispatcher;

Dispatcher::listen(OrderPlaced::class, SendReceipt::class);
Dispatcher::listen(OrderPlaced::class, fn (OrderPlaced $e) => Metrics::count('orders.placed'));

Dispatcher::dispatch(new OrderPlaced($order));
```

`make:event` y `make:listener` generaron clases durante cuatro versiones sin
nada que las despachara. Un generador que produce código para una
infraestructura que no existe es peor que ningún generador, porque parece una
funcionalidad.

Un evento es **cualquier objeto**. No hay clase base que extender ni interfaz
que implementar, porque ninguna de las dos aportaría información: lo que hace
que algo sea un evento es que alguien lo escuche.

### Listeners

Un listener es un callable, o el nombre de una clase con un método `handle()`.
La forma con nombre de clase se resuelve por el contenedor **cuando el evento se
dispara**, así que un listener que necesita una conexión a la base de datos no
abre una al arrancar por un evento que quizá nunca ocurra.

```bash
./sfphp make:listener SendReceipt
```

Registrar contra una clase padre o una interfaz alcanza a sus hijas, que es lo
que hace expresable "registrar todo evento de dominio" sin nombrar cada uno:

```php
Dispatcher::listen(DomainEvent::class, AuditTrail::class);
```

### Un listener que lanza

Se registra en el log, con el evento y el listener nombrados, y los demás siguen
ejecutándose. Despachar es contar, no preguntar: un evento cuyo tercer listener
falló ha ocurrido igualmente, y hacer fallar la acción que lo disparó pondría el
error de un listener en el camino de quien llamó.

```php
Dispatcher::dispatchOrFail($event);   // cuando quien llama sí depende de ellos
```

Es un método aparte y no una bandera, porque el comportamiento por defecto
importa más que la excepción: una bandera invita a pasar `true` sin decidir.

### Bajo un runtime persistente

Los listeners viven en un estático y se registran una vez, al arrancar, como las
rutas. Es la forma correcta para algo que la aplicación declara. Lo que no puede
ir en un listener es estado por petición capturado en una closure — sobreviviría
a la petición que lo creó y lo vería la siguiente.

### Qué falta

| Ausente | Situación |
|---|---|
| Listener en cola | Un listener se ejecuta en la petición que disparó el evento; despacha un trabajo desde él para mover la carga |
| Detener la propagación | Se ejecutan todos los listeners; no hay un "atendido, para" |
| Nombres con comodín | El registro es por clase, y una clase padre ya generaliza |

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

La ruta `lang/` de la aplicación la registra `Bootstrap::load()`, que llama todo
punto de entrada — así que la CLI, un worker de cola y la suite de pruebas ven
los mismos mensajes que vería una petición web.

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
| Formato fiel a CLDR sin `ext-intl` | `Time::localised()` y `Time::number()` usan la extensión cuando está y degradan cuando no |
| Traducción de rutas (`/products` ↔ `/productos`) | No existe |
| Extracción de cadenas a los catálogos | Ningún comando escanea el código |
| Dirección del texto (RTL) | Una decisión de plantilla, no del traductor |

---

## Tiempo y zonas horarias

Todo lo que el framework almacena, calcula y registra es **UTC**.

```php
now();                                   // el instante actual, en UTC
Time::now();                             // lo mismo
Time::parse('2026-09-21 23:00:00');      // un valor guardado, leído como UTC
Time::in($order->created_at, 'Asia/Tokyo');   // el mismo instante, visto allí
Time::display($order->created_at);       // renderizado en APP_TIMEZONE
Time::toDatabase($instant);              // el valor UTC que guarda la columna
```

### Por qué aquí la regla es estricta

Un `2026-09-21 23:00:00` ingenuo en una columna de base de datos solo es un
instante si algo dice en qué zona se escribió. Cuando esa respuesta es "la que
tuviera el servidor", mover el servidor — o añadir un segundo — cambia en
silencio lo que significa cada fila existente.

El daño es **retroactivo**, y eso es lo que distingue este caso de una
funcionalidad que falta. Una funcionalidad se puede añadir después. Un año de
marcas de tiempo escritas en una zona desconocida no se puede reparar después,
porque la información necesaria para repararlas nunca se registró.

Por eso la zona del runtime es UTC y **no es configurable**. Un ajuste que
cambia cómo se interpretan las marcas de tiempo guardadas es un ajuste capaz de
reescribir el significado de datos existentes, y no es una perilla que valga la
pena ofrecer.

### Mostrar una hora a una persona

Esa es una decisión aparte, tomada donde el valor se renderiza y no donde se
guarda:

```php
Time::display($order->created_at);                 // APP_TIMEZONE
Time::display($order->created_at, 'd/m/Y H:i');
Time::in($order->created_at, $user->timezone);     // por usuario
```

```ini
APP_TIMEZONE=America/Sao_Paulo
```

`APP_TIMEZONE` decide cómo se **muestran** las horas. No decide cómo se
guardan, y cambiarlo no cambia una sola fila.

### En el idioma de quien lee

`Time::display()` recibe un formato de `date()`, que es texto fijo: `d/m/Y` está
mal para un lector estadounidense, y `F` imprime "September" a quien lee en
español. Para cualquier cosa que lea un visitante, pide un estilo en vez de un
formato y deja que el idioma decida el orden y las palabras:

```php
Time::localised($order->created_at);                         // 21 sept 2026, 10:00
Time::localised($order->created_at, 'full', 'none');         // lunes, 21 de septiembre de 2026
Time::localised($order->created_at, 'short', 'short', 'en'); // 9/21/26, 10:00 AM
Time::number(1234.56, 2);                                    // 1.234,56 — o 1,234.56 en inglés
```

Ambos leen el idioma activo cuando no se les pasa ninguno, así que una página
que ya corre bajo `SetLocale` no necesita argumento. Los estilos son `none`,
`short`, `medium`, `long` y `full`, para la fecha y para la hora de forma
independiente.

`Time::number()` está aquí y no en el traductor porque los separadores se
intercambian: 1.234,56 en español frente a 1,234.56 en inglés. Imprimir uno por
el otro no es una diferencia cosmética — se lee como otro número.

> **Con `ext-intl` esto sale correcto; sin él, degrada.** La extensión es la que
> lleva los datos de CLDR, así que el framework la usa cuando está y cae a una
> fecha estilo ISO y a un separador adivinado por el idioma cuando no — el mismo
> arreglo que `Str` tiene con mbstring. El respaldo falla en la cola larga, pero
> falla como un número legible en la convención equivocada, nunca como un número
> equivocado.

### Lectura de valores

`Time::parse()` acepta lo que le den una base de datos, un formulario o una API:

| Recibe | Lo lee como |
|---|---|
| Una cadena con desplazamiento o zona (`2026-09-21T10:00:00+02:00`) | Ese instante, convertido a UTC |
| Una cadena ingenua (`2026-09-21 23:00:00`) | UTC, porque es lo que escribió el framework |
| Una cadena ingenua con una zona nombrada en el segundo argumento | Esa zona, convertida a UTC |
| Una marca de tiempo Unix | Ya es un instante; no hay zona que adivinar |
| Un `DateTimeInterface` en cualquier zona | Convertido a UTC |
| Cualquier cosa imposible de interpretar | `null`, en vez de una excepción |

### Atributos de modelo

Los casts `datetime` y `date` siguen las mismas reglas, en ambos sentidos:

```php
$article->published_at;              // DateTimeImmutable, siempre UTC
$article->toArray()['published_at']; // "2026-09-21T23:00:00+00:00"

// 08:00 en Tokio se guarda como el instante que nombra, no como el reloj
$article->published_at = new DateTimeImmutable('2026-09-22 08:00', new DateTimeZone('Asia/Tokyo'));
// guardado: 2026-09-21 23:00:00
```

La forma JSON lleva el desplazamiento, así que quien la consume no puede
adivinar mal la zona — que es la misma razón por la que el valor es UTC de
entrada.

### La base de datos también tiene un reloj

Que PHP esté en UTC es solo la mitad. `CURRENT_TIMESTAMP` lee el reloj del
servidor de base de datos, así que un valor por defecto de `useCurrent()` o un
disparador `ON UPDATE` escribe en la zona que tenga **esa** máquina. Deja a los
dos en desacuerdo y una columna acaba guardando dos significados distintos, sin
nada en los datos que diga cuál fila es cuál.

Por eso la conexión pone su propia sesión en UTC:

| Driver | Sentencia |
|---|---|
| MySQL | `SET time_zone = '+00:00'` |
| PostgreSQL | `SET TIME ZONE 'UTC'` |
| Oracle | `ALTER SESSION SET TIME_ZONE = '+00:00'` |
| Otros | Sin tocar — configura tú la zona de la sesión, o mantén el servidor en UTC |

Solo se cambia la sesión, nunca el servidor: una conexión que declara lo que
espera es correcta, y una biblioteca que reconfigura una base compartida para
todos los demás clientes no lo es. Un driver que rechace la sentencia se
registra como aviso en vez de rechazarse, porque una inconsistencia de marcas de
tiempo no debería convertirse en una caída.

### Pruebas

Una prueba que afirma sobre "ahora" compite con el reloj. Se puede detener:

```php
Time::freeze('2026-01-01T12:00:00+00:00');
// ... now() devuelve ese instante
Time::unfreeze();
```

**Solo para pruebas.** El valor congelado es estático, así que bajo un runtime
persistente sobreviviría a la petición que lo fijó y toda petición posterior
recibiría la hora equivocada.

### Qué falta

| Ausente | Situación |
|---|---|
| Datos de CLDR propios | `Time::localised()` los lee de `ext-intl`; sin la extensión el respaldo es estilo ISO, no incorrecto |
| Tiempo relativo ("hace 3 horas") | No existe; la frase es por idioma y pertenece a la aplicación |
| Columna de zona por usuario | `Time::in()` acepta una; dónde se guarda la zona del usuario es decisión de la aplicación |

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
    return Response::redirect('/dashboard');
}

return Response::view('login', ['error' => __('auth.failed')]);
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
    return Response::view('dashboard', ['user' => $request->user()]);
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
| Revocar antes de expirar | sí, borra la sesión | sí, por la lista de revocados |
| `Auth::login()` | sí | no — lanza |

`SessionGuard` guarda **solo el identificador** en la sesión, nunca al usuario.
Serializar el modelo congelaría una copia de la fila: alguien a quien se le
revocaron permisos los conservaría hasta que expirase la sesión, y renombrar
una columna rompería la deserialización de todas las sesiones vivas.

También **regenera el id de sesión** al entrar y al salir. Al entrar, eso es lo
que detiene la fijación de sesión: quien plantó de antemano un id conocido no
puede usarlo después, porque el id con el que acaba la víctima es nuevo.

`TokenGuard` no guarda nada en el servidor, que es lo que lo hace utilizable
fuera de una petición web. También es lo que convierte la revocación en una
decisión en vez de algo dado: un token se acepta porque su firma es válida, así
que nada del propio token puede echarse atrás.

### Revocar un token

```php
use SfphpProject\src\Auth\TokenDenylist;

TokenDenylist::revoke($token);            // este token
TokenDenylist::revokeUser($usuario->id);  // todo token emitido antes de ahora
```

La única forma de revocar un token sin estado es dejar de ser sin estado
respecto a los que has revocado, y el intercambio merece verse claro: el guard
pasa a consultar la caché en cada petición, así que verificar un token ya no es
gratis.

Lo que mantiene eso barato es que un token revocado solo hay que recordarlo
hasta el momento en que habría expirado de todos modos. Una lista de todo lo
revocado alguna vez crecería para siempre; esta son entradas con vencimiento,
así que se queda del tamaño de "revocado hace poco".

`revokeUser()` es el "cerrar sesión en todas partes". No puede listar los tokens
del usuario — nada los registró nunca — así que registra el momento, y un token
cuyo `iat` es anterior a ese momento se rechaza. Un inicio de sesión *posterior*
sigue funcionando, que es lo que impide que cerrar sesión en todas partes deje a
alguien fuera de volver a entrar.

Los tokens se guardan con hash, nunca enteros: una caché que alguien pueda leer
— un Redis compartido, un volcado tomado al depurar — entregaría credenciales
funcionando para todo token que aún no haya expirado.

> **La lista de revocados tiene que ser compartida entre instancias.** Vive en
> la caché, así que con el `CACHE_DRIVER=file` por defecto es local a una
> máquina y un token revocado en una instancia sigue funcionando en otra.
> `CACHE_DRIVER=redis` lo arregla por completo. En otros sitios una caché por
> instancia es una decisión de rendimiento; aquí es un agujero.

La comprobación se puede apagar por guard, en un servicio donde los tokens sean
lo bastante cortos como para que la lectura extra no compense:

```php
$guard = new TokenGuard($provider, 'id', checkRevocation: false);
```

### Recordar el inicio de sesión

```php
use SfphpProject\src\Auth\RememberToken;

$token = RememberToken::issue();
// ['cookie' => 'selector:verifier', 'selector' => ..., 'hash' => ..., 'expires' => ...]
```

Una cookie de "recuérdame" es una contraseña que nunca expira y que el usuario
no sabe que tiene, así que su forma importa. La cookie lleva un **selector** en
claro, que es la clave de búsqueda, y un **verifier**, guardado solo como hash
sha256. Una base de datos que alguien lea no le entrega, por tanto, cookies
funcionando, y encontrar la fila sigue costando una búsqueda por índice en vez
de un recorrido.

```php
$partes = RememberToken::parse($_COOKIE['remember'] ?? '');

if ($partes !== null && RememberToken::matches($partes['verifier'], $fila->remember_token)) {
    // Autentica y emite un token nuevo: una cookie funciona exactamente una vez.
}
```

Rotar en cada uso es lo que limita el daño. Si se usa una cookie robada, la
siguiente petición del usuario real falla y el robo se vuelve visible, en vez de
dos personas compartiendo una cuenta en silencio durante un mes.

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
| El flujo de «recordarme» | `RememberToken` emite y verifica la cookie; leerla en una petición y reemitirla es de la aplicación |
| Recuperación de contraseña | Sin tabla de tokens, sin flujo de correo |
| Verificación de correo | La columna `email_verified_at` existe; el flujo no |
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
- `session.use_strict_mode` activo, así que un id que PHP nunca emitió se
  rechaza en vez de adoptarse
- Un plazo por **inactividad** y uno **absoluto**, ambos aplicados en el
  pipeline — consulta [Sesiones](#sesiones)
- Un almacén que puede compartirse entre instancias, así que la sesión no queda
  atada a una máquina
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

### Subidas

- Un archivo se rechaza salvo que `is_uploaded_file()` confirme que lo es, así
  que un `$_FILES` falsificado no hace que el framework lea una ruta arbitraria
- El tipo se lee de los bytes del propio archivo, nunca de la cabecera que envió
  el cliente
- El nombre guardado se genera; al nombre del cliente se le quitan las rutas y
  los bytes nulos y solo sirve para mostrarlo

Consulta [Subida de archivos](#subida-de-archivos), incluido por qué el archivo
guardado sigue perteneciendo fuera del document root.

### Salida

- El `{{ }}` de SFHT escapa por defecto; la salida cruda exige `{!! !!}`
- `e()` para plantillas PHP puras
- El detalle de la excepción solo aparece con `APP_ENV=development`

### Qué falta

| Ausente | Situación |
|---|---|
| Recuperación de contraseña, verificación de correo, 2FA | Los flujos son de la aplicación; [Correo](#correo) es la pieza que el framework les debía |
| Un valor por defecto seguro para más de una instancia | `CACHE_DRIVER` viene como `file`, que está bien para una máquina y mal para varias. El framework no puede saber cuál es tu caso, así que lo dice en vez de adivinar. Consulta [Elegir el driver](#elegir-el-driver) |
| Abstracción de almacenamiento para subidas | Los archivos se validan y se guardan localmente; S3 o un volumen compartido es de la aplicación. Consulta [Subida de archivos](#subida-de-archivos) |
| Registro de auditoría | Los registros son estructurados y llevan id de petición, pero nada escribe un rastro deliberado de "quién cambió qué". Consulta [Registro](#registro) |

---

## Sesiones

```php
use SfphpProject\src\Session\Session;

Session::put('cart_id', 42);
Session::get('cart_id');
Session::get('ausente', 'por defecto');
Session::has('cart_id');
Session::forget('cart_id');
Session::all();
Session::regenerate();     // id nuevo, los mismos datos
Session::invalidate();     // id nuevo, sin datos
Session::id();
```

`$_SESSION` sigue existiendo y funcionando, pero ya nada del framework lo toca.
Pasar por `Session` es lo que vuelve inevitables los dos plazos de abajo: un
código que iniciara la sesión de otra forma se los habría saltado.

### Dos plazos

```ini
SESSION_LIFETIME=7200             # inactividad: segundos sin petición
SESSION_ABSOLUTE_LIFETIME=43200   # absoluto: segundos desde que empezó la sesión
```

Antes de esto una sesión duraba lo que dijera `php.ini`, que en un alojamiento
compartido es un número que nadie de la aplicación eligió.

El plazo por **inactividad** cierra una sesión dejada abierta en una máquina de
la que alguien se alejó. El **absoluto** cierra una sesión viva demasiado tiempo
por mucho movimiento que haya tenido, y es el que pregunta una auditoría: es el
que limita cuánto vale una cookie robada. Cualquiera se desactiva con `0`, y los
dos los aplica `StartSession`, que es el único sitio donde se pueden aplicar una
vez y cubrir todas las rutas.

Cuando vence un plazo, los datos se van **y el id cambia con ellos**. Vaciar los
datos conservando el id dejaría al visitante con una cookie que sigue nombrando
una sesión viva, que es casi todo lo que caducar una pretendía evitar.

```php
if (Session::expiredReason() === 'idle') {
    // mostrar "se cerró tu sesión tras un periodo de inactividad"
}
```

Eso se lee una vez y se olvida, así que el aviso aparece en la petición
siguiente a la caducidad y no en todas las posteriores.

### Dónde se guardan las sesiones

```ini
SESSION_DRIVER=native      # native, database o cache
```

| Driver | Guarda en | Úsalo cuando |
|---|---|---|
| `native` | Los archivos del propio PHP | Una máquina. El valor por defecto |
| `cache` | La caché, mediante `CacheManager` | Varias instancias, con Redis configurado |
| `database` | Una tabla `sessions` | Varias instancias, y ya tienes base de datos |

Los archivos nativos son locales a una máquina, así que dos instancias de la
aplicación no ven las sesiones de la otra. Eso es lo que obliga a usar sesiones
pegajosas en un balanceador, y por eso un despliegue que añade una segunda
máquina cierra la sesión de todo el mundo. Un handler compartido lo elimina, y
es el único cambio que hace al framework utilizable detrás de más de un proceso.

`cache` es más rápido y no es duradero — una caché vaciada es todo el mundo
fuera. `database` cuesta una lectura y una escritura por petición en la conexión
que la aplicación ya usa, y sobrevive a un reinicio. Con el driver de caché de
archivo, `cache` se comporta exactamente como `native`: eso lo decide el driver,
no el handler.

El driver de base de datos necesita su tabla:

```bash
./sfphp migrate
```

También se puede pasar un handler directamente, que es como un despliegue
conecta uno propio:

```php
$router->middleware(new StartSession(new CacheHandler(), 1800, 28800));
```

Sirve cualquier clase que implemente el `SessionHandlerInterface` del propio
PHP. Implementar además `SessionUpdateTimestampHandlerInterface` — los dos
handlers incluidos lo hacen — es lo que hace funcionar la sección siguiente.

### Fijación de sesión

Dos defensas, y cierran mitades distintas del mismo ataque.

El id se **regenera al entrar y al salir**, así que un id que un atacante haya
plantado antes no es el id con el que acaba la víctima.

Y `session.use_strict_mode` ya está activo. Sin él PHP adopta cualquier id que
lleve la cookie, incluido uno que nunca emitió — que es la puerta a la que llama
el atacante antes que nada. Con él, un id desconocido se rechaza y se emite uno
nuevo:

```
GET / con Cookie: PHPSESSID=un-id-que-nadie-emitio
→ Set-Cookie: PHPSESSID=636dbac9b2f1bc09c3d335c16115bc89
```

Por eso un handler debería implementar `validateId()`: así es como PHP le
pregunta al almacén si un id nombra una sesión que existe.

### Qué falta

| Ausente | Situación |
|---|---|
| Listar o revocar la sesión de otro dispositivo | La tabla del driver `database` permite construirlo; no viene nada hecho |
| Rotación periódica del id | El id cambia al entrar, al salir y al caducar, no por tiempo |
| Cifrado en reposo | La carga se guarda tal como PHP la serializa; una base de datos o caché con cifrado propio es la respuesta |
| Datos de una sola petición | No hay un helper de "guarda esto exactamente una petición más" |

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

## Depuración

```php
dump($pedido);             // muéstralo y sigue
dump($a, $b, $c);          // varios a la vez
dd($request->all());       // muéstralo y para
```

`dd()` **sustituye la respuesta** por una página que enseña solo lo que se
volcó. Esa es la diferencia respecto a imprimir un valor dentro de la página que
ya estabas renderizando: pediste parar y mirar, así que lo que miras no está
mezclado con una maqueta a medio hacer.

La pantalla está hecha con SFCSS — la misma hoja de estilos con la que una
aplicación escribe sus propias páginas — y el CSS va incrustado, no enlazado,
porque una pantalla que el framework renderiza tiene que renderizar cuando la
aplicación de alrededor es lo que está roto.

Qué enseña, y por qué está cada parte:

| | |
|---|---|
| La línea que llamó | Un volcado que no puedes localizar es un acertijo. `app/controllers/PedidoController.php:42` |
| Visibilidad de la propiedad | Un `private $token` leído como público te manda a buscar al sitio equivocado |
| Longitud de la cadena | Un valor que parece correcto y tiene 11 caracteres cuando esperabas 10 es el error |
| `already shown above` | Un valor que se apunta a sí mismo se informa, no se sigue |
| `uninitialised` | Una propiedad tipada nunca asignada. Leerla lanza; ese estado suele ser justo la respuesta que se busca |
| `only the first 200 shown` | Lo que se cortó se declara. Un volcado truncado que lo admite es mejor que un navegador que deja de responder |

Las ramas se pliegan. Usan `<details>`, así que plegar funciona sin ningún
script — incluso tras un Content-Security-Policy que bloquee el script en línea.

### En un terminal

```bash
./sfphp queue:work
```

Un worker de cola, un comando de consola y una tanda de pruebas no tienen
navegador. Allí el mismo volcado va a la salida estándar como texto indentado,
coloreado con ANSI cuando la salida es un terminal y en crudo cuando se redirige
o se canaliza — los códigos de escape en un archivo que vas a pasar por `grep`
son ruido.

### En producción

```php
dd($usuario);   // APP_ENV=production
```

El volcado se **escribe en el registro** y el visitante recibe la página de
error normal. `dd()` sigue parando, lanzando.

Un volcado entregado a un visitante enseña lo que le hayan pasado: un registro
de usuario, las cabeceras de la petición, un array de configuración. Funcionar
igual en todos los entornos significaría que un `dd()` olvidado es una fuga de
datos; así es una entrada en tu registro y un 500 para él. El registro pasa por
`LogManager`, así que las contraseñas y los tokens se redactan por el camino.

`dump()` en producción también escribe en el registro, y no para.

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
| Trazado | El id de petición ata los registros de una petición; seguir una llamada entre servicios exige un trace id propagado entre ellos |
| Muestreo | Se escribe todo registro que supera el nivel; no hay "uno de cada cien" |
| Varios destinos a la vez | Un driver cada vez — sin repartir a un archivo y a un colector juntos |
| Rotación de registros | El archivo crece; la rotación es de `logrotate` o de la plataforma |

---

## Health check y métricas

### Health check

Un balanceador necesita un sitio donde preguntar si mandar tráfico aquí va a
funcionar, y "el proceso está corriendo" es la pregunta equivocada: una
instancia cuya base de datos está inalcanzable sigue aceptando conexiones y
sigue sirviendo errores a todo el que se enrute hacia ella.

```php
use SfphpProject\src\Health;

Health::registerDefaults(['database', 'cache']);
Health::register('pagos', fn (): bool => $gateway->ping());

$report = Health::check();
// ['healthy' => true, 'checks' => ['database' => ['ok' => true, 'ms' => 1.4], ...]]
```

El framework trae las comprobaciones y no la ruta, porque dónde vive y quién
puede verla son decisiones de la aplicación:

```php
Router::get('/health', 'HealthController', 'show');

public function show(Request $request): Response
{
    $report = Health::check();

    return Response::json($report, $report['healthy'] ? HTTP_OK : 503);
}
```

Cada comprobación se cronometra, porque "la base de datos respondió" y "la base
de datos respondió en cuatro segundos" son estados distintos y solo uno de ellos
se ve en un booleano. Una comprobación que lanza cuenta como fallo y se informa
su **mensaje** — no su traza, que nombra rutas y clases que no son asunto de
nadie más.

> **Un endpoint de health describe tu infraestructura.** Dejado público, le
> cuenta a cualquiera qué dependencias tienes y cuáles están caídas ahora mismo,
> que es lo primero que conviene saber antes de atacar algo. Ponlo detrás de la
> red del balanceador, o detrás de un token.

No se registra nada por defecto: un endpoint que informe sobre una base de datos
que la aplicación no usa estaría respondiendo la pregunta equivocada.

### Métricas

```php
use SfphpProject\src\Log\Metrics;

Metrics::count('orders.placed');
Metrics::count('payments.failed', ['gateway' => 'stripe']);

$report = Metrics::time('report.build', fn () => $builder->run());
```

Una línea de registro lleva una duración, lo que responde "cuánto tardó esta
petición". No responde "cuánto tardan las peticiones", y la diferencia es la
razón de que existan las métricas: una es una anécdota, la otra es la forma del
sistema.

`time()` registra la llamada que **lanzó**, además de la que retornó — algo que
solo se pone lento cuando está fallando es justamente lo que conviene ver.

El colector es en proceso. `snapshot()` lo lee como arreglo, y `prometheus()`
renderiza el formato de texto que entiende un scraper, montado aquí y no por una
biblioteca cliente:

```
orders_placed 2
payments_failed{gateway="stripe"} 1
report_build_ms_count 2
report_build_ms_sum 41.882
report_build_ms_min 18.204
report_build_ms_max 23.678
```

### Qué falta

| Ausente | Situación |
|---|---|
| Agregación entre instancias | Cada proceso guarda sus propias cuentas; un scraper o un push gateway hace la unión |
| Histogramas y percentiles | Se registran cuenta, suma, mínimo y máximo; un p99 necesita buckets que esto no guarda |
| Persistencia | Las cuentas se pierden cuando el proceso termina, lo que bajo php-fpm es cada petición. Haz scraping de un runtime persistente, o empuja |

---

## CLI

`./sfphp` expone **35 comandos**.

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

> Todo generador produce ya código contra algo que existe y se ejecuta —
> `make:event` y `make:listener` incluidos, desde que llegó `Dispatcher`. Lo que
> un archivo generado aún te debe es su registro: un listener hay que entregarlo
> a `Dispatcher::listen()` donde arranca la aplicación. Consulta
> [Eventos](#eventos).

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

Los cuatro siguen `CACHE_DRIVER` y `QUEUE_DRIVER`. Un `cache:clear` que vaciara
una caché de archivo mientras la aplicación usa Redis informaría de un éxito sin
haber cambiado nada.

### Assets

```bash
./sfphp assets:publish                     # a public/assets
./sfphp assets:publish --path=web/static   # a otro sitio
./sfphp assets:publish --force             # sobrescribe lo que haya
./sfphp assets:publish --symlink           # enlaza en vez de copiar
```

Copia SFCSS y SFJS desde dentro del paquete a un directorio que el proyecto
sirva. `composer install` y `./sfphp serve` ya lo ejecutan, así que el comando
es para una actualización o una disposición poco común; una ejecución que
encuentra los mismos archivos no copia nada y lo dice.

> **Por qué los archivos existen dos veces.** El paquete los guarda donde están
> versionados y donde una actualización los reemplaza; el navegador solo puede
> leer lo que está bajo el document root, y ningún paquete puede escribir en tu
> `public/` al instalarse. Así que uno es la fuente y el otro una copia
> publicada — `public/assets/css` y `public/assets/js` van en `.gitignore`, como
> `vendor/`.
>
> `--symlink` lo convierte en un solo archivo donde los enlaces simbólicos
> funcionan. No es el valor por defecto porque un enlace es una decisión de
> despliegue: se rompe cuando el despliegue copia en vez de mover, exige cuidado
> en Windows, y entonces una actualización cambia lo que sirve un sitio en
> marcha en lugar de esperar a que publiques.

### Servidor y utilidades

```bash
./sfphp serve          # http://localhost:8000
./sfphp routes         # una tabla de las rutas registradas; --path= para una disposición rara
./sfphp env:example    # crea .env a partir de .env-example
./sfphp css:build      # construye SFCSS desde la configuración; --config= --output=
./sfphp js:build       # minifica SFJS
./sfphp build --phpx   # compila los componentes .phpx
./sfphp reset          # elimina la aplicación de ejemplo; --force omite la pregunta
./sfphp tinker         # REPL — solo para desarrollo local
./sfphp list
./sfphp version
./sfphp help [comando]
```

`tinker` evalúa la entrada con `eval()`. Es una herramienta de desarrollo
local; nunca expongas la CLI a entrada no confiable.

### Empezar desde cero

El paquete trae una aplicación: una portada, controladores, componentes, un
modelo, un seeder. Está ahí para leerse y ejecutarse, y estorba en el momento en
que empiezas a escribir la tuya.

```bash
./sfphp reset            # pregunta antes
./sfphp reset --force    # para un script
```

Vacía `app/components`, `app/controllers`, `app/models`, `app/Jobs`,
`app/resources/views`, `database/migrations`, `database/seeders` y
`database/factories`, y reescribe el archivo de rutas sin ninguna ruta — de lo
contrario la aplicación arrancaría apuntando a un controlador que ya no está.
Las carpetas se quedan, porque son donde va lo siguiente.

**Las dos migraciones que el framework trae se conservan**: las tablas de
usuarios y de sesiones, contra las que están escritos el guard de autenticación
y el driver de sesión en base de datos, y cuya falta un proyecto notaría en el
primer inicio de sesión, no aquí. Una migración que escribiste es tuya, y se va
con el resto de lo que escribiste. Un `.gitkeep` también se queda — existe para
sostener una carpeta vacía, que es lo que queda.

Antes de borrar nada imprime lo que va a borrar, con el recuento por carpeta, y
espera a que escribas la palabra `reset`. Sin terminal donde responder — una
tubería, un trabajo de CI — se niega en lugar de seguir en silencio. No hay
deshacer y nada va a una papelera.

---

## SFCSS

Un framework CSS de utilidades. **Llega construido** — `composer require`
entrega la hoja de estilos, y `composer create-project` y `sfphp serve`
la copian a `public/assets`, así que usarla es una línea de HTML:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

No hay que generar nada para usar SFCSS. El generador está para **cambiarlo**,
que es lo que viene [más abajo](#cambiar-sfcss).

| | |
|---|---|
| Clases en total | **2.339** |
| — utilidades base | 1.211 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Clases de color | 600 de paleta (20 familias × 10 tonos × `bg`/`text`/`border`) + 25 del tema |
| Tamaño | 112KB en crudo · 94KB minificado · **16,4KB comprimido** |
| Dependencias | ninguna |

### Cambiar SFCSS

Los colores, la escala de espaciado, la escala tipográfica y los puntos de
ruptura vienen de una configuración, y está en tu proyecto —
`tools/css-builder/sfcss.config.json`, junto al generador que la lee. Edítala y
reconstruye:

```bash
# tools/css-builder/sfcss.config.json — paletas, espaciado, puntos, fuentes
./sfphp css:build
```

`css:build` escribe en `public/assets/css`, que es lo que lee el navegador. Una
configuración puesta junto a `composer.json` tiene precedencia sobre la de
`tools/`, para un proyecto que prefiera mantener su diseño aparte del
generador.

```bash
./sfphp css:build --config=design/sfcss.json --output=web/css
```

> **Una hoja de estilos que construiste no se sobrescribe.** `composer install` y
> `serve` publican los assets del framework, y cuando uno de los tuyos es
> distinto dicen que lo conservaron en vez de reemplazarlo. `assets:publish
> --force` recupera la versión del framework.

Para cambiar solo un color, editar la configuración es más de lo que necesitas:
el tema lee variables CSS, así que sobrescribirlas en tu propia hoja basta.

```css
:root { --primary: #ff6600; }
```

### Lo que usan las pantallas del propio framework

`code`, `pre` y `kbd` tienen estilo, `font-mono` y `font-sans` fijan la familia,
y las superficies neutras son variables en vez de hex fijo:

```css
--surface  --surface-raised  --surface-sunken
--surface-border  --surface-border-strong
--body-color  --body-color-muted  --code-color
```

La página elige el tema con `data-theme` en el elemento raíz — `light` (el
valor por defecto), `dark`, o `auto` para seguir el ajuste de quien lee. Solo
esas ocho cambian. Los colores de marca y de paleta conservan su significado en
ambos temas; lo que tiene que cambiar es el papel sobre el que se apoyan, y una
página que no dice nada se queda clara.

Ese conjunto existe porque la página de error y la pantalla de volcado están
hechas con SFCSS y lo incrustan — un framework con su propia hoja de estilos no
debería tener sus propias pantallas escritas en una segunda.

Referencia completa: [SFCSS](SFCSS.md) y
[referencia de utilidades](SFCSS_UTILITIES.md).

---

## SFJS

Una biblioteca JavaScript sin dependencias — 11KB en crudo, 8KB minificada,
**2,4KB comprimida**. Expuesta como `window.sf`.

```html
<script src="/assets/js/sfjs.min.js"></script>
<script src="/assets/js/sfjs.js"></script>     <!-- legible, para depurar -->
```

```bash
./sfphp js:build         # regenera sfjs.min.js a partir de sfjs.js
./sfphp assets:publish   # copia ambos a public/assets
```

El minificador quita comentarios y colapsa espacios, y a propósito no reescribe
tokens — nada de acortar nombres, quitar puntos y comas o unir instrucciones en
una línea. Ahí es donde un minificador cambia el sentido de un programa, y el
kilobyte de más no compensa mantener un parser de JavaScript en un framework sin
dependencias. Una prueba comprueba que ambas versiones exponen la misma API y
que la minificada sigue siendo analizable.

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

Un formulario funciona con cualquiera de ellos: `@hxGet` y `@hxDelete` envían
sus campos como query string, los demás los envían en el cuerpo. Lo que vuelve se
intercambia como marcado, así que lo que responde a uno de estos es un fragmento
— renderizado por el mismo componente que lo renderiza dentro de la página
entera, y no JSON que JavaScript tenga que reconstruir en HTML. La página en
`/phpx` hace exactamente eso, y por eso no tiene script propio.

`@hxSwap` acepta `innerHTML` (el valor por defecto), `outerHTML`,
`beforebegin`, `afterbegin`, `beforeend` y `afterend`.

`@validate` se ejecuta en `blur` y acepta `required`, `email`, `number`, `url`,
`minLength:N`, `maxLength:N` y `pattern:regex`.

---

## Pruebas

Un ejecutor propio, sin PHPUnit — coherente con las cero dependencias.

```bash
composer run lint        # php -l por todo el proyecto
composer run test        # 154 casos unitarios
composer run test:db     # integración contra MySQL/PostgreSQL reales
composer run test:all
composer run docs        # los tres idiomas concuerdan, y todo enlace resuelve
```

`tests/db.php` necesita los DSN en el entorno y se omite con un aviso cuando no
los hay:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
SFPHP_TEST_REDIS_HOST=127.0.0.1 \
  composer run test:db
```

Cubre el constructor de esquemas en los dos dialectos, la cola en base de datos,
el bloqueo de migraciones y — cuando se indica un host de Redis — la caché, el
handler de sesión sobre ella y el driver de cola de Redis. Esos tres no tenían
ninguna prueba que se ejecutara contra un servidor hasta esta versión, y por eso
el driver de cola perdía el id de cada trabajo.

Todo aquello cuyo trabajo ocurre **en el servidor** pertenece aquí y no a la
suite unitaria, porque ese código se lee bien y aun así no hace nada: el bloqueo
de migraciones es `GET_LOCK` y `pg_try_advisory_lock`, así que solo una segunda
conexión real siendo rechazada demuestra que sujeta.

La CI ejecuta dos trabajos: `unit` sobre una matriz de PHP 8.1–8.4 **sin
`mbstring`**, que es lo que impide que el manejo de UTF-8 dependa de la
extensión; e `integration` con MySQL 8, PostgreSQL 16 y Redis 7 como servicios.

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
| **Recuperación de contraseña y doble factor** | El inicio de sesión existe; estos flujos no, y son de la aplicación. Consulta [Autenticación](#autenticación) y [Correo](#correo) |
| **Un bus de eventos entre procesos** | `Dispatcher` entrega en el mismo proceso, de forma síncrona. Avisar a otro servicio de que algo pasó es un trabajo en cola o un broker de mensajes, no esto |
| **Un ORM completo** | Hay una capa de [Modelos](#modelos) con hidratación, tipos de atributo, relaciones (incluido muchos a muchos) y `with()`. No hay mapa de identidad, unidad de trabajo, proxy de carga perezosa, relación polimórfica ni esquema derivado de la clase — y [¿ORM o constructor de consultas?](#orm-o-constructor-de-consultas) explica el motivo de cada uno |
| **Fechas relativas** | "hace 3 horas" no existe: la frase es por idioma y pertenece a la aplicación. Las fechas y los números localizados sí, con `Time::localised()` y `Time::number()`. Consulta [Tiempo y zonas horarias](#tiempo-y-zonas-horarias) |
| **Un backend de métricas** | `Metrics` cuenta y cronometra dentro del proceso e imprime el texto de Prometheus; llevarlo a un colector, y conservarlo entre peticiones, es del despliegue. Consulta [Health y métricas](#health-check-y-métricas) |
| **Caché de rutas en disco** | Una ruta estática se compara en vez de pasar por `preg_match`, pero una ruta con parámetro sigue costando un match, y nada se compila de antemano. Bien para centenares, no para millares |
| **Un language server para `.phpx`** | El editor obtiene coloreado, Emmet y autocompletado por la configuración que trae el paquete, pero un `.phpx` no es PHP válido, así que el diagnóstico queda apagado — y apagado para todo `.php` a su lado. Lo que atrapa un error de verdad es `./sfphp build --phpx` y `composer run lint`. Véase [Componentes y .phpx](#componentes-y-phpx) |
| **Revocar sesión desde otro sitio** | Cerrar la sesión de otro dispositivo se puede construir sobre la tabla del driver `database`; no viene nada hecho. Consulta [Sesiones](#sesiones) |

SFHT tampoco tiene variables automáticas de bucle (`$loop`) ni herencia parcial
de bloques (`@parent`).

---

*Documentación revisada el 2026-09-22 contra el código en ejecución.*

# SFPHP — Documentation

A full-stack PHP framework with **zero runtime dependencies** and Unicode
correctness across the whole surface. This documentation describes what the
code does today. Where something does not exist, it says so — see
[Known limitations](#known-limitations).

> **Love SFPHP?** ⭐ [Give us a star on GitHub](https://github.com/fabioaacarneiro/sfphp-project) — it helps us grow and keeps the framework thriving!
>
> Verified against PHP 8.4 · run the suite with `composer run test`
>
> 🌍 Also available in [Português](../pt-BR/DOCUMENTATION.md) and
> [Español](../es/DOCUMENTATION.md).

---

## Contents

- [What it is, what it is not](#what-it-is-what-it-is-not)
- [Requirements and installation](#requirements-and-installation)
- [Project layout](#project-layout)
- [Request lifecycle](#request-lifecycle)
- [Routing](#routing)
- [Controllers](#controllers)
- [Middleware](#middleware)
- [Views and SFHT](#views-and-sfht)
- [Components and .phpx](#components-and-phpx)
- [Container and dependency injection](#container-and-dependency-injection)
- [Database](#database)
- [Models](#models)
- [ORM or query builder?](#orm-or-query-builder)
- [Migrations and schema builder](#migrations-and-schema-builder)
- [Seeders and factories](#seeders-and-factories)
- [Cache](#cache)
- [Queue](#queue)
- [File uploads](#file-uploads)
- [HTTP client](#http-client)
- [Mail](#mail)
- [Events](#events)
- [Validation](#validation)
- [Internationalisation](#internationalisation)
- [Time and time zones](#time-and-time-zones)
- [UTF-8 strings](#utf-8-strings)
- [Authentication](#authentication)
- [Security](#security)
- [Sessions](#sessions)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Debugging](#debugging)
- [Async](#async)
- [Error handling](#error-handling)
- [Logging](#logging)
- [Health and metrics](#health-and-metrics)
- [CLI](#cli)
- [SFCSS](#sfcss)
- [SFJS](#sfjs)
- [Tests](#tests)
- [Known limitations](#known-limitations)
- [Related Guides](#related-guides)

---

## What it is, what it is not

**It is** a lean framework for web applications and APIs, with routing,
Request/Response objects, a middleware pipeline, a DI container, a query
builder, a schema builder with MySQL/PostgreSQL parity, a template engine,
components in `.phpx`, an HTTP client, events, cache, queues, and a CLI with 38
commands.

**It is not** a replacement for Laravel or Symfony. There is no full ORM,
events are dispatched in-process and synchronously with no message broker, and
authentication covers login, guards and authorization but not password recovery
or two-factor. What exists is small enough to read end to end.

### Zero dependencies, literally

`composer.json` requires PHP and extensions that ship with it — no package. The
`vendor/` directory contains **nothing but Composer's autoloader**.

| Required extension | Used by |
|---|---|
| `ext-ctype` | Argument checks in the CLI |
| `ext-curl` | The HTTP client and every `Http::*Async` request |
| `ext-fileinfo` | An upload's real media type, read from its bytes |
| `ext-filter` | E-mail and URL validation, and mail addresses |
| `ext-json` | Responses, logs, the queue payloads, the config |
| `ext-mbstring` | Unicode case conversion (`upper`, `lower`, `capitalize`) |
| `ext-openssl` | TLS for SMTP (`MAIL_ENCRYPTION=tls` or `ssl`) |
| `ext-pdo` | The database layer (plus the PDO driver for your database) |
| `ext-session` | Sessions, the session guard and CSRF |
| `ext-tokenizer` | Compiling `.sfht` templates |

Each of these is part of a standard PHP build; `composer install` stops, naming
the missing one, on a server that lacks it — at install time rather than in
production.

The same holds for the browser runtime: no page the framework serves —
including the 404 and 500 error pages — loads CSS, fonts or JavaScript from a
CDN.

Optional extensions, declared under `suggest`:

| Extension | Enables |
|---|---|
| `ext-pdo_mysql`, `ext-pdo_pgsql`, `ext-pdo_sqlite` | The driver for the database you use |
| `ext-redis` | The Redis cache, session and queue drivers — not bundled with PHP (PECL) |
| `ext-pcntl` | Graceful shutdown of the queue worker, and its per-job `timeout`. Unix only, which is why it is not required |
| `ext-posix` | Terminal detection for coloured `dump()` output in the console |
| `ext-gd` | The PWA icons that `make:pwa` generates |
| `ext-intl` | Locale-correct dates and numbers; without it they fall back to ISO dates and guessed separators |
| `ext-readline` | The `./sfphp tinker` shell |

---

## Requirements and installation

- PHP 8.1 or later
- Composer 2
- PDO with your database's driver (optional — only if you use a database)

### Starting a project

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

That is the whole setup. <http://localhost:8000> answers, the console is at
`./sfphp` in the project root rather than buried in `vendor/bin`, and what you
are looking at is a working application you can edit:

```
my-app/
  app/controllers/        a controller, answering the home page
  app/models/             a model
  app/resources/views/    the templates that page is made of
  app/routes/             web.php and api.php, the routes
  src/                    the framework
  database/migrations/    the users table, ready to run
  public/index.php        the front controller
  resources/assets/       SFCSS and SFJS
  sfphp                   the console
  .env                    written for you, with a JWT key generated
  .gitignore              written for you: .env, vendor/ and storage/ stay out
```

Creating the project runs `./sfphp init`, which does four things:

- copies `.env-example` to `.env` — **with a real `JWT_KEY`**, because the
  placeholder is refused on purpose and generating a key should not be the first
  thing you have to read about;
- publishes SFCSS and SFJS into `public/assets`;
- writes a `.gitignore` for the project, so the first `git add .` does not
  commit `.env` and its key, `vendor/` or the published assets;
- replaces the composer scripts that belong to the framework's own repository:
  `composer test` runs `./sfphp test`, your tests.

Each step only adds what is missing, so `./sfphp init` is safe to run again.

`composer create-project` is the way to install SFPHP. The package is a project
template, not a library: `composer require` puts a second application inside
`vendor/`, and the console commands, the example seeders and the server script
all assume the project root is the package's.

Everything there is **yours**. Delete the example controller and its views; the
framework is `src/` and does not mind.

### Where everything lives

Nothing you edit is inside `vendor/`, and that is the rule the layout is built
on: `vendor/` holds the autoloader and nothing else, because the framework has
no dependencies and a created project carries its own copy of it.

| | |
|---|---|
| `public/` | What the web server serves — the front controller, and the published assets |
| `app/` | Your code and your templates: controllers, models, services, views |
| `app/routes/` | The routes: `web.php` for pages, `api.php` for JSON endpoints |
| `src/` | The framework itself, including the settings layer (`Bootstrap`, `Config`) |
| `database/` | Migrations, seeders and factories |
| `lang/` | Your message catalogs |
| `storage/` | Created on first use, private (`0700`): the file cache and the compiled templates |
| `.env` | Configuration, never committed |
| `vendor/` | The autoloader. Nothing to open, nothing to edit |

The one call the framework asks for is already in the front controller the
project ships with (`public/index.php`), and it is worth knowing because it is what ties the two halves together:

```php
require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Bootstrap;

Bootstrap::load(dirname(__DIR__));
```

That loads your `.env` if you have one, defines the settings the framework reads
unless you already defined them, and registers where your views and message
catalogs live. A project with an unusual layout says so:

```php
Bootstrap::load(dirname(__DIR__), [
    'views' => 'resources/views',
    'lang' => 'resources/lang',
    'env' => null,               // configuration comes from the environment
    'cache' => 'var/cache/views', // compiled templates (default: storage/cache/sfht)
]);
```

### From a clone

To work on the framework itself, rather than with it:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
./sfphp serve
```

A clone adds what a created project leaves behind: the test suite, the
documentation in three languages, and the CI definition.

In production, point the `DocumentRoot` at `public/`.

### What belongs to whom

The line runs between the framework and the application, and it is worth
knowing because everything above depends on it.

| | |
|---|---|
| The package autoloads | `src/`, `app/` and `database/` (PSR-4), plus five files inside `src/` |
| The application owns | `.env`, its constants, its views, its catalogs, its routes |
| `Bootstrap::load()` | Is how the second tells the first about itself |

Every setting the framework reads is consulted through `defined()`, so a project
that never calls `Bootstrap::load()` still boots on the defaults — and a test
asserts that no framework file reads one without that guard.

The runtime time zone is the exception: it is set to UTC when the package loads,
before anything can ask, because it is a correctness rule rather than a setting.
See [Time and time zones](#time-and-time-zones).

---

## Project layout

```
src/            The framework (namespace SfphpProject\src)
app/            EXAMPLE application code — illustrative, not prescriptive
public/         Document root: index.php and assets/ (css, js, images)
resources/      SFCSS and SFJS sources, published into public/assets
lang/           Message catalogs (en, pt_BR, es)
database/       The application's migrations/, seeders/, factories/
tools/          The SFCSS and SFJS builders, and the docs-parity check
tests/          A bespoke suite, no PHPUnit (a created project's own tests run with ./sfphp test)
docs/           This documentation
sfphp           The CLI entry point
server.php      Router script for the built-in server
```

PSR-4 autoloading:

| Prefix | Directory |
|---|---|
| `SfphpProject\src\` | `src/` |
| `SfphpProject\app\` | `app/` |
| `Database\Seeders\` | `database/seeders/` |
| `Database\Factories\` | `database/factories/` |

All four mappings are declared under `autoload`; the last three point at
directories a created project owns (`app/`, `database/`).

Five files are always loaded (`autoload.files`), all in `src/`: `runtime.php`,
`utils.php`, `http.php`, `helpers.php` and `Async/functions.php` (`async()`,
`await()`, …). Nothing in `app/` is autoloaded as a file: the front controller
calls `Bootstrap::load()` itself.

---

## Request lifecycle

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ runtime.php→ date_default_timezone_set('UTC')
 │      utils.php  → global helpers: e(), asset(), csrf_*()
 │      http.php   → HTTP_OK, GET, POST, ... constants
 │      helpers.php→ cache(), logger(), mailer(), now(), dispatch(), __(), ...
 │      Async/functions.php → async(), await(), delay(), ...
 ├─ Bootstrap::load()         .env, constants, view and lang paths
 ├─ ErrorHandler::register()  safety net for fatals and bootstrap failures
 ├─ require app/components/compiled/**.php   compiled .phpx components
 ├─ require app/routes/web.php, app/routes/api.php   fill the route registry
 ├─ new Container()
 │   └─ set(PDO::class, closure)   lazy connection
 ├─ Request::setTrustedProxies()   from TRUSTED_PROXIES; nothing until declared
 ├─ new Router(): global middleware
 │   └─ LogRequests, SecurityHeaders, SetLocale, StartSession, VerifyCsrfToken
 ├─ Request::fromGlobals()    the only place that reads superglobals
 ├─ Router->dispatch($request)
 │   └─ global → group → route middleware → action → Response
 └─ Emitter->emit($response, $request->method)   the only place that writes output
```

`.env` is **optional**. A fresh clone boots with no configuration; whatever
genuinely needs a value (the database, JWT) fails on its own, with a specific
message.

### Reading a setting

`Bootstrap` turns `.env` into constants, and the framework reads them through
`Config` rather than with `constant()` directly:

```php
use SfphpProject\src\Config;

Config::get('APP_ENV', 'production');
Config::int('SESSION_LIFETIME', 7200);
Config::string('MAIL_FROM_ADDRESS');
Config::has('JWT_KEY');
```

An explicit `set()` wins, then the constant, then the default the caller passed.
Nothing that reads `APP_ENV` directly has changed — the constants are still
defined and still work.

The reason for the indirection is that a constant cannot be unset. That is fine
for an application, which decides its settings once at boot, and awkward for a
test, which wants to know what happens with a different session lifetime without
starting a separate process to find out:

```php
Config::set('SESSION_LIFETIME', 60);
// ...
Config::forget('SESSION_LIFETIME');   // back to the constant
```

---

## Routing

Routes live in `app/routes/web.php` (pages) and `app/routes/api.php` (JSON
endpoints), both required by the front controller. The API is **static**.

```php
use SfphpProject\app\controllers\MainController;
use SfphpProject\app\controllers\UserController;
use SfphpProject\src\Router;

Router::get('/', [MainController::class, 'index'])->name('home');
Router::post('/users', [UserController::class, 'store'])->name('users.store');
```

A route is a path and an **action**: the controller class and its method, as a
pair — `[UserController::class, 'store']`. The class is imported with `use`
like any other, so the editor follows it, renames it with the rest of the code
and flags one that does not exist; the router adds no namespace of its own, so
controllers can live anywhere.

> **Upgrading from 0.30.** Routes used to name the controller as a string —
> `Router::get('/users', 'UserController', 'index')`, resolved under
> `SfphpProject\app\controllers\`. That form is refused when the route is
> registered, and the message gives the new spelling:
> `[UserController::class, 'index']`.

### Methods

```php
Router::get($url, [Controller::class, 'method']);
Router::post(...);
Router::put(...);
Router::patch(...);
Router::delete(...);
Router::head(...);
Router::options(...);
```

An unmatched route answers **404**. A path that matches with the wrong method
answers **405** with an `Allow` header. `OPTIONS` answers **204** automatically
when a path has routes. Both refusals are the framework's error page, or JSON
for a client that asks for it (`Accept: application/json`), like every other
error.

A `HEAD` request is answered by the `GET` route for the same path, with the
headers and without the body — which is what link checkers and uptime probes
send. `Allow` lists `HEAD` beside `GET`, and `OPTIONS`.

### Parameters

The syntax is `name:type`, **without braces**:

```php
Router::get('/posts/id:number', [PostController::class, 'show']);
Router::get('/users/username:alpha', [UserController::class, 'profile']);
Router::get('/codes/code:alphanum', [CodeController::class, 'show']);
```

| Type | Matches | Note |
|---|---|---|
| `number` | `[0-9]+` | ASCII on purpose: the value exists to survive an `(int)` cast, and PHP's cast does not understand Eastern Arabic or Devanagari digits. A number larger than PHP's integer is a 404, not a `TypeError` in an `int $id` action |
| `alpha` | `\p{L}[\p{L}\p{M}]*` | Any script, combining marks included: `café`, `北京`, `Владимир`, `हिन्दी` |
| `alphanum` | `[\p{L}\p{N}][\p{L}\p{M}\p{N}]*` | Letters and digits from any script |

Values reach the action **positionally**, in the order they appear in the URL,
after the request:

```php
Router::get('/tenant/tenantId:number/posts/postId:number', [PostController::class, 'show']);

public function show(Request $request, string $tenantId, string $postId): Response
{
    // ...
}
```

Inside a middleware, or anywhere the request is at hand, the same values are
read with `$request->route('id')`; `$request->routeParameters()` has them all
and `$request->routePattern()` the route that matched. They are kept apart
from the request's attributes, so a route declared as `/profile/user:alpha`
does not replace the signed-in user that `$request->user()` returns.

The request path is decoded segment by segment before matching, so
`/products/caf%C3%A9` matches `/products/name:alpha`. Encoded separators
(`%2F`, `%5C`) are **not** turned into real ones: `/a%2Fb` never reaches the
route `/a/b`. Repeated slashes are collapsed, as a web server does:
`//admin/panel` is the path `/admin/panel`.

A route **without parameters** is matched by comparing two strings, never by
running a regular expression, and a route with them compiles its pattern once
and keeps it. Most applications are mostly static paths, so most of the
dispatch loop costs a comparison. What is still linear is the loop itself: the
router walks the table until something matches, and nothing is compiled ahead of
time to a file.

### Groups

The callback takes no arguments — routes registered inside it inherit the
prefix:

```php
Router::group('/api', function (): void {
    Router::get('/posts', [ApiPostController::class, 'index'])->name('posts.index');
    Router::post('/posts', [ApiPostController::class, 'store'])->name('posts.store');
}, 'api.');
```

The third argument is the **name** prefix, so those routes become
`api.posts.index` and `api.posts.store`. Groups nest. A fourth argument takes
middleware — see [Middleware](#middleware).

### Named routes and URL generation

```php
Router::url('posts.show', ['id' => 42]);                   // /posts/42
Router::url('posts.index', [], ['page' => 2]);             // /posts?page=2
Router::url('users.profile', ['username' => 'café']);      // /users/caf%C3%A9
```

`url()` validates values against the parameter's type and throws
`InvalidArgumentException` for a missing, invalid or unknown one. An unknown
route name throws `RuntimeException`. Duplicate names are rejected at
registration.

---

## Controllers

An action receives the `Request` as its **first argument** and returns a
`Response`. Route parameters follow, in the order they appear in the URL. One
rule, no exception, no reflection.

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class PostController
{
    public function show(Request $request, string $id): Response
    {
        return Response::sfht('posts/show', ['id' => (int) $id]);
    }

    public function store(Request $request): Response
    {
        return Response::json(['id' => 1], HTTP_CREATED);
    }
}
```

The returned `Response` is what the framework sends. No `echo`, no `header()`,
no `exit` — and it was `exit` in particular that used to stop any middleware
from running after the controller.

### What an action may return

`Response::from()` coerces the return value, so the common cases stay short:

| Returned | Becomes |
|---|---|
| `Response` | itself |
| `string` | `Response::html(...)` |
| `array` or `JsonSerializable` | `Response::json(...)` |
| **nothing** | **an error**, naming `Class::action()` |

Returning nothing is an error on purpose. It is how an action that forgot its
`return` announces itself; an empty 200 would hide the problem.

### Request

```php
$request->method;                    // 'POST'
$request->path;                      // '/products/café', already decoded
$request->isMethod('post');

$request->query('page');             // query string
$request->body('title');             // parsed body
$request->input('title', 'default'); // body → JSON → query string
$request->all();                     // everything, merged
$request->filled('title');

$request->header('Authorization');   // case-insensitive lookup
$request->bearerToken();
$request->json();                    // decodes the body; a malformed one is a 400 (InvalidJsonException)
$request->rawBody;

$request->cookie('session');
$request->file('avatar');
$request->ip();                      // the client, through trusted proxies only
$request->isSecure();
$request->expectsJson();

$request->user();                    // set by the Authenticate middleware
$request->route('id');               // route parameter
$request->routeParameters();         // all of them, in URL order
$request->attribute('locale');       // attached by a middleware
$withUser = $request->withAttribute('user', $user);   // clones
$request->isFragment();              // true when SFJS asked for a fragment

Request::create('POST', '/posts', ['body' => ['title' => 'Hi']]);   // one built without superglobals, for tests and the CLI
```

Values come back **unchanged**, by design. Escaping is a property of a
destination, not of a value: escaping on the way in corrupts the data
(`O'Brien` became `O&#39;Brien` in the database; a password of `a<b` was hashed
as `a&lt;b`) and protects nothing, because a value escaped for HTML is still
unsafe in SQL or in a shell.

The rule the framework follows is: **validate on the way in, escape on the way
out.**

- Validate with `Validator`, which checks without modifying
- Bind, never concatenate, when talking to the database — `QueryBuilder` and
  `RawQuery` bind everything
- Escape at the point of output — SFHT's `{{ }}` escapes automatically, and
  `e()` exists for raw PHP templates

`Request` never reads a superglobal on its own: the constructor takes arrays,
and `Request::fromGlobals()` is the only place in the framework that touches
`$_SERVER`, `$_GET`, `$_POST` and company. That is what makes routing testable
and what a persistent runtime needs.

### Response

```php
Response::html('<h1>Hello</h1>');
Response::text('ok');
Response::json(['id' => 1], HTTP_CREATED);
Response::sfht('posts/index', ['posts' => $posts]);
Response::redirect('/posts');
Response::noContent();

$response->withStatus(HTTP_NOT_FOUND);
$response->withHeader('X-Request-Id', $id);   // replaces without duplicating
$response->withBody('another body');

$response->status();  $response->body();  $response->header('Content-Type');
```

`Response` is a value object: it never calls `header()`, never echoes, never
touches the output buffer. Turning it into bytes is the `Emitter`'s job, and
that separation is what lets the whole path be tested without output buffering.

### No base class

A controller inherits nothing. The framework asks for an action that returns a
`Response`, and `Response` is a factory, so every kind of response is reachable
from any class:

```php
final class PostController
{
    public function index(Request $request): Response
    {
        return Response::sfht('posts/index', ['posts' => $posts]);
    }
}
```

| | |
|---|---|
| `Response::sfht($template, $data, $status)` | A page from an `.sfht` template |
| `Response::phpx($component, $status)` | A page from a `.phpx` component: `Response::phpx(PostPage($post))` |
| `Response::json($data, $status)` | JSON |
| `Response::html($html, $status)` · `Response::text()` | A body you built |
| `Response::redirect($url, $status)` | A redirect |
| `Response::route($name, $parameters, $query)` | A redirect to a named route |
| `Response::back($request, $fallback)` | A redirect to where the visitor came from |
| `Response::noContent()` | 204 |
| `Response::stream($producer, $status, $headers)` | A body written chunk by chunk — see [STREAMING.md](./STREAMING.md) |
| `Response::fragment($request, $fragment, page: …)` | A fragment for SFJS, or the whole page without JavaScript — see [Answering with a fragment](#answering-with-a-fragment) |

> **`back()` will not leave your site.** The referer is a header, so the visitor
> chooses it, which makes it a redirect destination an attacker can pick.
> Following one to another origin is an open redirect — how a phishing link
> borrows your domain's good name. A referer naming a different host or port
> falls back, and so does one whose path a browser would read as another host —
> `//evil.example`, `/\evil.example` — or that carries a backslash or a control
> character.

There was a `BaseController` here offering `$this->view()` and
`$this->redirect()`. It asked you to inherit a class in order to shorten two
calls that already existed, which is inheritance that buys nothing, and it
lived in the example application where a `composer require` never reached it —
so the line it taught threw a fatal in an installed project. `route()` and
`back()` were the only things on it that were not already somewhere else, and
they are on `Response` now.

### JSON endpoints

An endpoint that answers JSON needs no base class either. What it does need is
for a body that is not JSON to be refused **before** the action runs, which is
what the pipeline is for:

```php
use SfphpProject\src\Http\Middleware\RequireJson;

Router::group('/api', function (): void {
    Router::post('/posts', [PostController::class, 'store']);
}, 'api.', [new RequireJson()]);
```

```php
public function store(Request $request): Response
{
    $data = $request->attribute('json');   // already decoded, already valid

    return Response::json(['id' => 1], HTTP_CREATED);
}
```

`RequireJson` answers **415** when the `Content-Type` is not
`application/json` and **400** when the body does not decode, and puts what it
decoded on the request. Those two codes are worth telling apart: a client
debugging "I do not speak that" is looking somewhere very different from one
debugging "that was not valid JSON".

`GET`, `HEAD`, `OPTIONS` and `DELETE` pass straight through, since they carry no
body — otherwise the middleware would be unusable on a group that both reads and
writes, which is most groups. A write with no `Content-Type` and no body reaches
the action with `json` set to `[]`; one with a body but no `Content-Type` is a
415. Pass `new RequireJson(required: true)` to refuse a write with no body at
all.

This replaced the base class every JSON controller used to extend. The check
then ran only where somebody remembered to call it, and it put a class between
the framework and every endpoint to do a job the pipeline already existed for.

### Global helpers

Loaded on every request by `src/utils.php`:

```php
e($value);                    // escapes for HTML: <script> → &lt;script&gt;
asset('css/app.css');         // → /assets/css/app.css?v=3f2a9c1b
asset('js/sfjs.js');          // → /assets/js/sfjs.js?v=81d0e4aa
csrf_token();  csrf_field();  csrf_meta();  csrf_verify();
```

`asset()` prefixes `/assets/` and **validates the path**: directory traversal
and characters outside `[A-Za-z0-9._-]` throw `InvalidArgumentException`. A file
that exists under `public/assets` gets `?v=` and a short hash of its date and
size, so a browser that cached the previous `sfjs.min.js` fetches the new one as
soon as it changes.

Static files live in `public/assets/{css,js,images}/`.

And by `src/helpers.php`:

```php
cache();                      // a CacheManager for CACHE_DRIVER (file by default)
logger();                     // a LogManager, configured from LOG_*
mailer();                     // a MailManager, configured from MAIL_*
queue();                      // the QueueManager for QUEUE_DRIVER
now();                        // the current instant, in UTC
dispatch(new MyJob());        // queues a job
__('app.welcome', ['name' => 'Ana']);
trans_choice('app.items', 3);
locale();
lang_tag();                   // the locale as a BCP 47 tag ('pt-BR'), for <html lang>
dump($x);  dd($x);            // see Debugging
```

---

## Middleware

A middleware receives the request, may inspect or replace it, and calls `$next`
to hand it on. Work before the `$next` call runs on the way in; work after it
runs on the way out, with the response in hand. Returning without calling
`$next` stops everything below.

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

### Registering

Three levels, running in this order: **global → group → route → action.**

```php
// Global, in public/index.php — applies to 404 and 405 as well
$router = (new Router($container))->middleware(
    new LogRequests(),            // first, so every record carries the request id
    new SecurityHeaders(),
    new SetLocale(APP_LOCALES, APP_LOCALE),
    StartSession::class,
    VerifyCsrfToken::class
);

// Per group, in app/routes/web.php (or api.php)
Router::group('/admin', function (): void {
    Router::get('/panel', [AdminController::class, 'index']);
}, 'admin.', [RequireTokenMiddleware::class]);

// Per route
Router::get('/report', [ReportController::class, 'show'])
    ->middleware(RequireTokenMiddleware::class)
    ->name('report');
```

Global middleware wraps the entire dispatch, **including requests that match no
route at all**. That is deliberate: CORS headers and request logging that skip
404s are a bug, not an optimisation.

A middleware may be a class name, an instance or a callable. A class name is
resolved through the **Container**, so a middleware can declare its
dependencies in its constructor and have them autowired.

### The middleware that ships with the framework

| Middleware | Does |
|---|---|
| `LogRequests` | Gives the request an id and records its outcome |
| `SecurityHeaders` | Adds `nosniff`, `X-Frame-Options`, `Referrer-Policy`; CSP and HSTS on request |
| `SetLocale` | Negotiates the language from `Accept-Language` |
| `StartSession` | Starts the session, applies the idle and absolute deadlines |
| `VerifyCsrfToken` | Refuses a state-changing request with no valid token |
| `Authenticate` | Identifies the user, and refuses anonymous requests when required |
| `RateLimit` | Limits how often the same client may hit a route |
| `RequireJson` | Refuses a body that is not JSON with 415, one that does not decode with 400 |
| `EnableAsync` | Optional: gives each request its own async scheduler, so nothing it left pending carries over — see [ASYNC.md](./ASYNC.md) |

`VerifyCsrfToken` lets safe methods through, and a request with a bearer token
and no session cookie — a browser never attaches a bearer token by itself, so
there is no cross-site request to forge. A request that carries the session
cookie is checked even with a bearer token, because a browser can send that one
on its own. Paths can be exempted, by whole segment — `/api` exempts `/api` and
`/api/posts`, not `/apikeys`:

```php
new VerifyCsrfToken(['/api'])
```

The check runs before routing, so a `POST` to a path with no route answers
403 rather than 404 when it has no token: saying which paths exist to a request
that could not prove where it came from would be a small leak, and nothing is
lost by refusing it first.

> Until this version CSRF verification existed but **nothing in the framework
> called it**: every application had to remember to check in each action, and
> forgetting produced no error at all. It now applies by default.

---

## Views and SFHT

### Rendering

```php
use SfphpProject\src\View;

View::make('posts/index', ['posts' => $posts]);    // returns a string
View::makePartial('header', ['title' => 'My Site']);

// Or straight into a response:
Response::sfht('posts/index', ['posts' => $posts]);
```

`View::render()` and `View::partial()` still exist and echo, but they are
**deprecated**: a `Response` needs a body it can carry, not output that has
already escaped to the client.

View names are validated against directory traversal. Templates live in
`app/resources/views/` with the **`.sfht`** extension.

To control paths and cache directly:

```php
use SfphpProject\src\View\SfhtEngine;

$engine = new SfhtEngine([__DIR__ . '/views'], '/tmp/sfht-cache');
echo $engine->render('home', ['title' => 'Hello']);
```

### Output

```sfht
{{ $name }}              escapes HTML — use this one
{!! $html !!}            raw output — only for HTML you produced
{{-- comment --}}        removed at compile time, never reaches the HTML
```

**`{{ }}` escapes by default** (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8). The safe
form is the short one; bypassing it takes more typing.

There is exactly one exception, and it is carried by a type rather than by a
syntax: a value that is an `Sfht` is printed as it stands, because `Sfht` means
markup this framework produced. That is what lets a component be composed with
`{{ }}` while a string in the same position is still escaped — see
[Components and .phpx](#components-and-phpx). Anything that is not an `Sfht` is
escaped, including a string you are certain about.

The expression is real PHP — function calls, operators and indexes all work:

```sfht
{{ count($items) }}
{{ $user['name'] }}
{{ $total > 0 ? 'yes' : 'no' }}
```

### Conditionals

```sfht
@if($user->isAdmin())
  <p>Admin</p>
@elseif($user->isPremium())
  <p>Premium</p>
@else
  <p>Visitor</p>
@endif

@unless($authorised)
  <p>Access denied</p>
@endunless
```

### Loops

```sfht
@foreach($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@endforeach

@forelse($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@empty
  <p>No posts yet.</p>
@endforelse
```

`@forelse` nests: an inner loop's `@empty` answers for the inner list only.

A directive that takes no arguments — `@else`, `@empty`, `@endif` — reads a
`(` only when it is right against its name, so `@else (optional)` is `@else`
followed by the text `(optional)`.

```sfht

@for($i = 0; $i < 10; $i++)
  <p>{{ $i }}</p>
@endfor

@while($queue->hasItems())
  {{ $queue->next() }}
@endwhile
```

### Layout inheritance

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

@block('title')Home@endblock

@block('content')
  <h1>Welcome</h1>
@endblock
```

The child renders first and its blocks win. A block the child leaves alone uses
the layout's default content. The layout also renders on its own. `@extends`
cycles are caught, with a limit of 16 levels.

### Partials and components

```sfht
@include('partials/header')
@include('partials/card', ['title' => 'Hello'])
@includeWhen($showForm, 'partials/form')
@component('components/button', ['label' => 'Send'])
```

A partial inherits the variables in scope at the point of inclusion; the
explicit array wins. `@component` is a synonym of `@include`.

A `.phpx` component is a function, not a partial: import it once with
`@use(function SfphpProject\app\components\Card)` — anywhere in the
template — and call it as `{{ Card('Hello', $body) }}`. See
[Components and .phpx](#components-and-phpx).

### Inline PHP

```sfht
@php
    $total = array_sum($values);
@endphp

<p>Total: {{ $total }}</p>
```

### Filters

Chainable with `|`:

```sfht
{{ $text | upper }}
{{ $text | truncate(50) }}
{{ $text | upper | truncate(20, '…') }}
{{ $price | format('%.2f') }}
{{ $name | default('Anonymous') }}
```

| Filter | Effect |
|---|---|
| `upper` / `lower` | Upper and lower case |
| `capitalize` | Upper-cases the first character |
| `truncate(n, suffix)` | Shortens to `n` **characters**; the suffix counts towards the limit |
| `length` | Characters of a string, or items of an array |
| `reverse` | Reverses, respecting multi-byte characters |
| `escape` | Escapes HTML explicitly — returns markup, so `{{ }}` does not escape it twice |
| `json` | JSON with `UNESCAPED_UNICODE` |
| `format(fmt)` | `sprintf` |
| `trim` | Strips surrounding whitespace |
| `abs` / `round(n)` | Numeric |
| `default(v)` | Replaces `null` and the empty string — and a variable the view was never given |

The string filters count **characters, not bytes**: `truncate(5)` over
`日本語テキスト` returns `日本...`, never a byte cut in half.

`||` is not mistaken for a filter — `{{ $a || $b ? 'y' : 'n' }}` works — and a
`}}` inside a quoted string does not end the expression:
`{{ $open ? '}}' : '' }}` works too.

`default()` covers a variable that was never passed: `{{ $title | default('Home') }}`
with no `$title` prints `Home` instead of failing on an undefined variable. That
holds for a plain variable or a path into one (`$user['name']`, `$post->title`);
a longer expression is evaluated as written.

Registering your own filter:

```php
$engine->addFilter('slug', fn (string $v): string
    => strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '-', $v)));
```

### An `@` that is not a directive

Only known directive names become syntax. Everything else is text:

```sfht
<link href="...family=Inter:wght@300;400">   {{-- preserved --}}
Write to support@example.com                 {{-- preserved --}}
Write to webmaster@php.net or me@if.io       {{-- preserved, although php and if are directives --}}
@media (min-width: 40rem) { ... }            {{-- preserved --}}
Type @@if to start a condition               {{-- @@ writes a literal @: "Type @if to start…" --}}
```

An e-mail address is text even when its domain starts with a directive's name:
an `@` after a letter or a digit, followed by the name and then `.` or `-`, is
part of the address. `Ola @if($x)sim@endif` is still a condition.

### Global variables

```php
$engine->setGlobal('siteName', 'My Site');
$engine->setGlobals(['version' => '1.0.0', 'year' => date('Y')]);
```

### Compilation cache

Templates compile to PHP on disk and are executed with `include`, so **OPcache
works**. The write is atomic and invalidates OPcache for that exact path.

The compiled files live in `storage/cache/sfht` inside the project, created
private (`0700`), or wherever `Bootstrap::load()`'s `cache` option says. They
used to live under a fixed name in the system temporary directory, shared by
every user on the machine — and a compiled template is PHP that gets
`include`d, so whoever created that directory first could plant code in it. A
directory another user owns is refused; one others can write to is made
private before it is used.

A compiled file is named after the template's path **and version** — its
modification time and size — so a template that changes compiles to a different
file and a compiled file only ever answers for the bytes it was made from. This
used to be the path alone with a newer-than comparison, which holds only while
time moves forward: extracting an archive moves it backwards, so an updated
template installed by `composer create-project` arrived older than a cache file
written minutes before and was never recompiled.

```php
$engine->clearCache();
```

### Template errors

Unbalanced directives fail at compile time, with the line:

```
Unclosed @if opened on line 12.
@endforeach on line 20 closes @if opened on line 12.
@empty on line 8 must appear inside @forelse.
Unclosed "{{" expression on line 3.
```

What fails while the page renders — an exception in the page, a filter that was
never registered (`Filter not registered: nosuchfilter`), an `@extends` chain
deeper than 16 levels — is reported naming the template it happened in:
`Error in template app/resources/views/home.sfht (compiled line 40): …`.
`View::make()` reports "not found" only for a template that does not exist; any
other failure keeps its own message.

---

## Components and .phpx

There are two ways to write a page, both shipped, and each is better at
something. The example application uses one for its pages and the other for the
demonstration at `/phpx`.

| | `.sfht` | `.phpx` |
|---|---|---|
| What it is | A file of markup | A PHP function whose markup lives inside it |
| Composition | `@include`, `@extends`, `@block` | Calling the function |
| What it receives | Whatever is in scope, plus what is passed | Its parameters, and what it assigns before the markup |
| Edited by | Anyone who knows HTML | Somebody who reads PHP |
| Best at | Pages and layouts | Reusable pieces |
| Build step | None — compiled on demand | `./sfphp build --phpx` |

### Writing a component

```php
<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

function Card(string $title, string $body, string $colour = 'blue'): Sfht
{
    return Sfht(
        <div class="card border-{{ $colour }}-500">
            <div class="card-header"><h3 class="m-0">{{ $title }}</h3></div>
            <div class="card-body"><p>{{ $body }}</p></div>
        </div>
    );
}
```

`Sfht(` opens a markup region, and the `)` that balances it outside every
element closes it, so text inside may hold an apostrophe or a lone parenthesis.
Between them is SFHT, so `{{ }}`, `{!! !!}`, the standard filters, `@if` and
`@foreach` all work and escaping is the same as everywhere else in the
framework; `@include`, `@extends` and `@block` are refused at build time.
`Sfht(` written in a comment or a string — a docblock explaining how regions
open — is not a region. A component that throws while rendering leaves no
output behind: its buffer is closed before the exception carries on.

```bash
./sfphp build --phpx                       # every .phpx under app/components
./sfphp build --phpx --from=src/ui         # somewhere else
./sfphp build --phpx --to=build/components # output somewhere else
```

The build writes PHP into `app/components/compiled/`, mirroring the source tree,
and runs `php -l` over each result, so a
syntax error is reported at build time with the line number of the `.phpx` — the
compiled file keeps every line where it was written, inside a region and after
it.

### One component per file

A file holds one component and is named after it, and the components of one page
live in a folder of their own. The build walks the whole tree and mirrors it, so
what is compiled looks like what was written:

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

Components in the same folder share a namespace, so they compose each other by
name — no import and no prefix. Crossing a folder, or reaching one from a
controller, is a `use function`, the same as for any other function in PHP:

```php
use function SfphpProject\app\components\postcode\lookup\Address;
```

From a `.sfht` template, import it with
`@use(function SfphpProject\app\components\Card)` and call it by name.

A controller answers with a component through `Response::phpx()`, and hands it
its data as arguments — the component's parameters are its props:

```php
public function show(Request $request, string $id): Response
{
    return Response::phpx(PostPage(Post::query()->find($id), $request->user()));
}
```

See [.phpx components](PHPX_COMPONENTS.md#from-a-controller) for the whole of it.

### Why a component returns Sfht

```php
{{ Card('Hello', $body) }}    the card renders
{{ $body }}                    the text is escaped
```

Both in the same position, with the right thing happening to each, because the
**type** says which is which. `Sfht` means "markup this framework produced";
anything else is text of unknown origin.

The alternative — returning a string and writing `{!! Card(...) !!}` — asks the
author to remember which values are trusted, and that is the moment somebody
eventually writes `{!! $comment !!}` and ships a cross-site scripting hole.

> **Wrapping a string in `Sfht` bypasses escaping**, which is what it is for and
> why `new Sfht($whatever)` deserves a second look. The compiler builds these
> from markup an author wrote; one built from a request is a decision to trust
> it.

### Which to reach for

Use `.sfht` when the thing is a **page**: a layout, a block, something a
designer might open. Use `.phpx` when the thing is a **piece**: a card, a field,
a table row — anything that takes arguments and appears more than once.

The practical difference is the contract. A partial sees whatever happened to be
in scope where it was included, so what it needs is discovered by reading it. A
component's parameters are its props, so what it needs is its signature.

### Loading components

PHP autoloads classes, not functions, so a compiled component cannot be found on
demand. The front controller includes them once:

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

### Editor support

A `.phpx` file is PHP with markup where PHP does not expect it, so an editor has
to be told three separate things. The project ships the settings, and a created
project has them already:

| File | Covers |
|---|---|
| `.editorconfig` | Whitespace and encoding, in every editor |
| `.vscode/settings.json` | Language association, Emmet, and Intelephense's own file list |
| `.zed/settings.json` | The same, in Zed's format |

Intelephense keeps a list of files to index that is **separate** from the
editor's language association, which is why completion appears impossible until
`intelephense.files.associations` names `*.phpx`.

The cost of mapping `.phpx` onto the `php` language is that its markup reads as a
syntax error, so diagnostics are turned off for every `.php` as well. `./sfphp
build --phpx` and `composer run lint` still catch real ones.

---

## Container and dependency injection

```php
use SfphpProject\src\Container;

$container = new Container();

// A ready instance
$container->set(Mailer::class, new Mailer());

// A lazy factory — only runs when something asks
$container->set(PDO::class, fn (): PDO => Database::connect());

$mailer = $container->get(Mailer::class);
$container->has(Mailer::class);
```

Keys are the **fully qualified class name** (`PDO::class`, not `'pdo'`),
because that is how the resolver looks a service up when filling a constructor
parameter.

Reflection-based autowiring resolves controllers and their dependencies:

```php
final class PostController
{
    public function __construct(private PDO $pdo) {}
}
```

The container resolves union types, uses default values when available, accepts
`null` for nullable parameters, and detects circular dependencies with a
`RuntimeException`.

---

## Database

### Connection

Configure it in `.env`. Supported drivers: `mysql`, `pgsql`, `sqlite`,
`sqlsrv`, `oci`, `firebird`, `dblib`. For anything else, give `DB_DSN`
directly.

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=app
DB_USER=root
DB_PASS=secret
DB_CHARSET=utf8mb4
```

The connection uses `ERRMODE_EXCEPTION`, `FETCH_ASSOC` and **real prepared
statements** (`EMULATE_PREPARES => false`). A failed connection logs the
driver's message and throws one that says what can be said without it — the
PDO extension that is missing (`the PHP extension pdo_mysql is not installed`),
or the driver and host that did not answer. The password and the driver's own
text stay in the log.

A relative SQLite `DB_NAME` is read from the project root, so the console and
the web server open the same file whatever directory each started in.

### Query builder

```php
use SfphpProject\src\Database;

Database::table('users')->get();
Database::table('users')->where('age', '>', 18)->get();
Database::table('users')->where('email', 'john@example.com')->first();
Database::table('users')->count();
```

Available methods:

```php
->select('id', 'name')            // or ->select(['id', 'name'])
->select('name AS label')
->where('age', '>', 18)           // = != <> > >= < <= LIKE "NOT LIKE"
->where('status', 'active')       // two arguments: equality
->orWhere('role', 'admin')
->where(function (QueryBuilder $q): void {   // a group, in parentheses
    $q->where('status', 'draft')->orWhere('status', 'review');
})
->whereNull('deleted_at')
->whereNotNull('verified_at')
->whereIn('id', [1, 2, 3])        // an empty array matches no rows
->join('posts', 'users.id', '=', 'posts.user_id')
->join('posts', 'users.id', '=', 'posts.user_id', 'LEFT')
->orderBy('created_at', 'desc')
->limit(10)->offset(20)

->get()        // an array of rows
->first()      // the first row, or null
->count()      // int
->insert(['name' => 'John'])      // returns the generated id, as a string
->update(['name' => 'Smith'])     // returns affected rows
->delete()                        // returns affected rows
->toSql()      // inspect the SQL without running it
->bindings()   // the bound values
```

An `orWhere()` joins everything written before it: `where('user_id', 7)->where('a', 1)->orWhere('b', 2)`
is `user_id = 7 AND a = 1 OR b = 2`, which returns other users' rows. Put the
alternatives in a closure, as above, and they are one group:
`user_id = 7 AND (status = 'draft' OR status = 'review')`.

**Security.** Every value is bound with the right PDO type. Every identifier —
table, column, alias — is validated against `^[A-Za-z_][A-Za-z0-9_]*$` and
quoted for the driver; an invalid identifier throws
`InvalidArgumentException` rather than reaching the SQL.

Pagination is translated per dialect: `LIMIT/OFFSET` on MySQL, PostgreSQL and
SQLite, `TOP` on SQL Server, `FIRST` on Firebird, `OFFSET … FETCH NEXT` on
Oracle. An `offset()` without a `limit()` is valid on each of them — MySQL and
SQLite accept `OFFSET` only after a `LIMIT`, so one that does not limit is
written for them. A driver without support fails explicitly.

The builder has no `groupBy()`, `having()`, `distinct()` or aggregates other
than `count()`, and `select()` takes columns rather than expressions. A report
with `GROUP BY` or `SUM()` is written with `Database::query()` — see
[Raw SQL](#raw-sql).

### Transactions

```php
use SfphpProject\src\Database;

Database::transaction(function (): void {
    $order = Order::create(['customer_id' => 7]);

    foreach ($items as $item) {
        OrderItem::create(['order_id' => $order->id] + $item);
    }
});
```

Commits when the callback returns and rolls back when it throws, **re-throwing**
afterwards. The callback's return value is passed through.

```php
$id = Database::transaction(fn (): int => Order::create([...])->id);
Database::inTransaction();   // true while inside
```

A nested call **joins** the transaction already open instead of starting a
second one, because PDO has no nested transactions. The consequence is worth
knowing: a failure inside the inner callback rolls back the outer work too —
even when the outer callback catches the exception and carries on. The inner
failure marks the transaction for rollback, and the outer one then rolls back
and throws `NestedTransactionFailed` instead of committing. Savepoints would
avoid that, but their syntax differs between drivers, and degrading silently on
the ones that lack them would be worse than being explicit.

A transaction that is already open on the connection — the migration runner's,
or one started with `beginTransaction()` — is joined the same way.

The helper also handles a detail that is easy to get wrong by hand: a failed
statement can leave the driver with **no** active transaction, and a bare
`rollBack()` in that state throws `There is no active transaction` from inside
the `catch` — replacing the error that actually caused the failure. Here the
rollback only happens when a transaction is active, so the original error
survives.

### Raw SQL

```php
use SfphpProject\src\Database;

Database::query('SELECT * FROM users WHERE age > ?', [18])->get();
Database::query('SELECT name FROM users WHERE id = :id', ['id' => 1])->first();
Database::query('SELECT COUNT(*) FROM users')->scalar();
Database::query('DELETE FROM users WHERE id = ?', [1])->rowCount();
Database::query('INSERT INTO logs (msg) VALUES (?)', ['hi'])->lastInsertId();
```

Accepts positional and named placeholders. The methods are `get()`, `first()`,
`scalar()`, `execute()`, `rowCount()` and `lastInsertId()`.

---

## Models

A thin layer over the query builder: rows arrive as typed objects, relations
are declared once instead of becoming a JOIN written by hand at each call site,
and `with()` loads those relations in **one** query instead of one per row.

**This is not a full ORM.** There is no identity map, no unit of work, no
lazy-loading proxy and no schema derived from the class — and that is
deliberate, because each of them is the difference between something that can
be read in one sitting and something that cannot.

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

    /** Required before this model can be filled from an array. */
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

Without `$table`, the name is inferred from the class: `Post` → `posts`,
`Category` → `categories`, `Box` → `boxes`. The inference is simple on purpose
— an irregular name should declare `$table`.

### Reading

```php
Post::all();                       // array<Post>
Post::find(1);                     // Post|null
Post::findOrFail(1);               // Post, or ModelNotFoundException — a 404
Post::query()->where('published', 1)->orderBy('created_at', 'desc')->limit(10)->get();
Post::query()->count();

$post->title;                      // an attribute
$post->author;                     // a relation, resolved on read
$post->toArray();
```

`Model` implements `JsonSerializable`, so a model goes straight into a
response:

```php
return Response::json(Post::findOrFail($id));
```

A record that does not exist answers **404** by itself: `findOrFail()` throws
`ModelNotFoundException`, which the error handler maps to that status.

Every column goes into that JSON, so the ones that must never leave the server
are named in `$hidden`:

```php
final class User extends Model
{
    protected static array $hidden = ['password', 'remember_token'];
}
```

A property is an attribute, a loaded relation, or a relation to resolve — and a
method is only run for it when it declares that it returns `Relation`. Reading
`$post->delete` used to run `delete()`.

### Writing

```php
$post = new Post(['title' => 'Hello']);   // only columns listed in $fillable
$post->save();                     // INSERT, and the key comes back filled, as an int

$post = Post::find(1);
$post->title = 'Another title';
$post->save();                     // UPDATE of what changed only

Post::create(['title' => 'Direct']);
$post->forceFill(['published_at' => now()]);   // ignores $fillable
$post->delete();
```

`$fillable` is **required**: a model that does not declare it throws when
filled. See [Security](#security) for why.

A `save()` on an existing model writes **only the attributes that changed** —
touching one field does not rewrite the whole row. A `save()` with nothing
dirty issues no query. The row is found by the key it had when it was read, so
changing the key and saving moves that row rather than writing into another.

A table with `created_at` and `updated_at` — what a migration's `timestamps()`
creates — has them kept by `save()` when the model says so:

```php
protected static bool $timestamps = true;   // insert sets both; update refreshes updated_at, in UTC
```

### Attribute types

PDO hands back whatever the driver gives it: a `DATETIME` column arrives as a
string, and so does a JSON column. Declaring the type makes the conversion
happen once instead of at every call site:

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
$article->published;      // true, not '1'
$article->meta;           // ['colour' => 'blue'], not '{"colour":"blue"}'
$article->published_at;   // DateTimeImmutable
$article->views;          // 42, not '42'
```

Available: `int`, `float`, `bool`, `string`, `json`, `array`, `datetime`,
`date` and `decimal:N`. A null column stays null — it does not become a zero
value.

The conversion works both ways: `$article->meta = ['colour' => 'green']` is
stored as JSON, and a `DateTimeImmutable` is stored in the database's format.

A date attribute is **always UTC**, in both directions — see
[Time and time zones](#time-and-time-zones) for why that is strict.

In `toArray()` and in JSON, a date comes out as **ISO 8601** rather than the
`DateTimeImmutable` object — which `json_encode` would render as a structure of
internal fields, useless to whoever consumes the API.

Two exceptions worth knowing:

```php
$article->getAttribute('published');   // '1' — the raw value, no cast
$article->cast('published');           // true — with the cast
```

`getAttribute()` is raw **on purpose**: relations join on those values, and a
cast would change what they compare — a key read as an `int` on one side and a
`string` on the other would silently stop matching.

### Relations

```php
$this->hasMany(Comment::class, 'post_id');        // one to many
$this->hasOne(Profile::class, 'user_id');         // one to one
$this->belongsTo(User::class, 'user_id');         // the inverse

// many to many, through a pivot table
$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
```

Reading the property resolves the relation on the spot. Inside a loop, that is
the N+1 problem:

```php
// 1 query for the posts + 1 per post = 101 queries for 100 posts
foreach (Post::all() as $post) {
    echo $post->author->name;
}

// 1 query for the posts + 1 for every author = 2 queries
foreach (Post::query()->with('author')->get() as $post) {
    echo $post->author->name;
}
```

`with()` takes several relations: `->with('author', 'comments')`. A name that is
not a relation of the model — a typo, or a nested `'author.posts'` — throws,
instead of quietly falling back to one query per row. The keys are sent in
batches of a thousand, so a large page stays under PostgreSQL's limit of bound
parameters. It works for many-to-many too:

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

Two queries, however many posts there are. The pivot column is selected under
an alias, and that is how the joined rows are regrouped by post — without it,
eager loading through a pivot would fall back to one query per row.

### The escape hatch

Everything `Model` does not do is one call away, and comes back as arrays:

```php
Post::query()->builder();          // the QueryBuilder underneath
Database::table('posts');          // without going through Model
Database::query('SELECT ...');     // raw SQL
```

### What is missing, and why

The reasons are spelled out in [ORM or query builder?](#orm-or-query-builder).

| Missing | Why |
|---|---|
| Identity map | Fetching the same row twice returns two objects. Tracking identity needs a unit of work |
| Lazy-loading proxies | The relation resolves when the property is read; there is no proxy standing in for the absent object |
| Polymorphic relations | `hasMany`, `hasOne`, `belongsTo` and `belongsToMany` do exist |
| Migrations derived from the class | The schema comes from the migrations, not from the model |

---

## ORM or query builder?

The short answer: **a query builder, with objects on top.** Neither a plain
query builder nor an ORM — and the boundary is deliberate, not unfinished
business. This section exists because a half-ORM mistaken for a full one is
worse than either: you start relying on an implicit transaction that is not
there, or on object identity that is not guaranteed.

### The two ends

A **query builder** assembles SQL for you. You still think in tables, columns
and joins; it handles quoting identifiers, binding values and translating
pagination between dialects. The result is rows — arrays.

```php
Database::table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->where('posts.published', 1)
    ->get();                                  // an array of arrays
```

An **ORM** (object-relational mapper) inverts that. You think in objects and
the relations between them; the mapper decides the SQL. To do so it has to
maintain **identity** (the same row is the same object), **lifecycle** (track
what changed and write it in the right order) and often an **implicit
transaction**.

```php
$post->author->name = 'Ana';
$entityManager->flush();      // the ORM works out the UPDATE, the order and the transaction
```

### Where SFPHP sits

| | Plain query builder | **SFPHP** | Full ORM |
|---|:--:|:--:|:--:|
| Safe SQL, bound and quoted | ✓ | ✓ | ✓ |
| Rows as typed objects | ✗ | **✓** | ✓ |
| Declared attribute types (date, JSON, bool) | ✗ | **✓** | ✓ |
| Relations declared once | ✗ | **✓** | ✓ |
| Batch loading against N+1 | ✗ | **✓** | ✓ |
| Explicit transaction | ✗ | **✓** | ✓ |
| Identity map | ✗ | ✗ | ✓ |
| Unit of work / `flush()` | ✗ | ✗ | ✓ |
| Lazy-loading proxy | ✗ | ✗ | ✓ |
| Polymorphic relations | ✗ | ✗ | ✓ |
| Schema derived from the class | ✗ | ✗ | ✓ |

The dividing line has a logic: **SFPHP maps rows in and out, but does not
manage object lifecycle.** Everything above the line is data translation;
everything below requires the framework to hold state about your objects
between one call and the next.

### Why we stop exactly there

What is below the line was not left out for lack of time. Each item charges a
concrete price, and for one of them the price is a security risk.

#### Identity map — no, and here the reason is risk

The idea: `Post::find(1)` twice returns the **same** object, so an edit in one
place shows up in the other.

The problem: an identity map is a cache, with every problem a cache has —
invalidation, memory, and the surprise of `find()` not reaching the database
when you expected fresh data.

And the deciding reason: **a persistent runtime is a stated goal of this
framework** (Swoole, FrankenPHP). In a process serving many requests, an
identity map that is not rigorously reset per request becomes a data leak
**between users** — someone seeing the row another person loaded. It is the
only item on this list where the project's own goal argues *against*, rather
than merely not for.

#### Unit of work — no, because `transaction()` delivers what matters

The idea: you change objects freely, call `flush()` once, and the mapper works
out the minimal set of INSERT/UPDATE/DELETE, in the right order for the foreign
key dependencies, inside a transaction.

The price: it is the largest piece of an ORM like Doctrine. It depends on the
identity map, on change-set computation, on a dependency graph and on cascade
rules. And it makes it **non-obvious when your query runs** — the number one
source of "why didn't my change save?".

What we do instead: `save()` per object, writing only what changed, and an
**explicit** transaction when you need atomicity:

```php
Database::transaction(function (): void {
    $order = Order::create(['customer_id' => 7]);

    foreach ($items as $item) {
        OrderItem::create(['order_id' => $order->id, ...]);
    }
});
```

That gives the atomicity without the ambiguity. You can see where the
transaction begins and ends.

#### Lazy-loading proxies — no, because we already have the value

The idea: `$post->author` returns an object that *looks* like a `User` and only
queries the database when something really touches it.

But reading the property **already** resolves the relation on demand — that
*is* lazy loading, and it is what this layer does. The proxy only adds the case
where you need a typed `User` in hand before the query, and it charges dearly
for it: it breaks `get_class()`, makes `instanceof` subtle, complicates
serialisation, and `var_dump` starts showing a proxy instead of the object you
wanted to inspect.

#### Polymorphic relations — no, because of where the data lives

The idea: `$comment->commentable` points at either a `Post` or a `Video`,
according to a `commentable_type` column.

The problem is what that column holds: **a PHP class name inside the database**.
That couples the schema to your namespace — renaming a class now needs a
migration — and if that value is ever instantiated from untrusted input, it
stops being a design question and becomes a security one.

Many-to-many, which is the common case and has none of that problem, **does
exist**: `belongsToMany()`.

#### Schema derived from the class — no, and this would be a bad trade even if it were cheap

The idea: attributes on the class generate the migrations, so the shape of the
table lives in one place.

The problem is that it inverts the source of truth. And the schema builder is
the **strongest subsystem in this framework**: it covers MySQL and PostgreSQL
with real parity, emulates ENUM and `ON UPDATE` on PostgreSQL through a
constraint and a trigger, and **fails explicitly** when a dialect cannot honour
the semantics asked of it, rather than silently changing them. Subordinating
that to annotations on a class would trade the project's most reliable piece
for convenience.

### Knowing which side to write on

A rule of thumb:

- **Model** when you work with entities and relations — CRUD, forms, a resource
  API. That is where objects and `with()` pay off.
- **Query builder** when you work with sets — filtered lists, joins, counts,
  bulk updates and deletes. Hydrating into objects does not help, and sometimes
  gets in the way.
- **Raw SQL** (`Database::query()`) when the query is the product: aggregates
  and `GROUP BY`, a CTE, a window function, something specific to the dialect.
  The builder deliberately stops short of those.

All three coexist, and leaving the model costs one call:

```php
Post::query()->builder();   // hands back the QueryBuilder underneath
```

If one day you need an identity map or a unit of work, the honest path is not
to wait for SFPHP to grow into one — it is to use Doctrine, which does that
well, and accept the dependencies that come with it.

---

## Migrations and schema builder

The most complete subsystem in the framework: `Blueprint` covers MySQL 8+ and
PostgreSQL 12+ with real parity, and **fails explicitly** when a dialect cannot
honour the semantics asked of it, rather than silently changing them.

### Creating and running

Migrations take a lock before they read the pending list, so two instances
migrating on deploy cannot both decide the same file is pending and both run it.
MySQL and PostgreSQL each have an advisory lock — a named lock tied to the
connection, released when the connection goes away, so a deploy killed
mid-migration leaves nothing stuck. A driver without one is not refused: it logs
that it is running unlocked, because failing migrations on SQLite would be worse
than leaving off a guard where a single writer is the norm anyway.

Running migrations as one step of a pipeline is still the better shape. The lock
is there because the framework should not depend on everyone having it.

```bash
./sfphp make:migration create_posts title:string body:text author_id:foreignId:constrained timestamps
./sfphp make:migration add_slug_to_posts slug:string:unique
./sfphp make:migration drop_drafts_table

./sfphp migrate
./sfphp migrate --step=2
./sfphp rollback
./sfphp rollback --step=3                  # the three most recent migrations, whatever batch they ran in
./sfphp status
./sfphp db:fresh                           # rolls every migration back, then runs them again
```

`db:fresh` asks before it does anything, and refuses in production without
`--force`. It runs each migration's `down()`, newest first, so it drops what the
migrations created and nothing else — a table made another way, such as the
queue's, stays. A recorded migration whose file is gone is named in a warning,
because what it created cannot be undone without it.

Migrations made one after the other in the same second used to share a
timestamp, and file names are the run order: `add_status_to_orders` could sort
before `create_orders`. A new migration's stamp is now always later than the
newest one in the directory.

**The name is the instruction.** `create_users` creates a table,
`add_phone_to_users` alters one, `drop_sessions_table` drops one — the same
words you would use to say what the change is. A name that says none of those
gets an empty migration to fill in.

**A field is `name:type`.** Numbers after it are the type's arguments, words
after it are modifiers:

| | |
|---|---|
| `surname:string:255` | `$table->string('surname', 255)` |
| `email:string:unique` | `$table->string('email')->unique()` |
| `price:decimal:8,2` | `$table->decimal('price', 8, 2)` |
| `active:boolean:default=true` | `$table->boolean('active')->default(true)` |
| `bio:text:nullable` | `$table->text('bio')->nullable()` |
| `author_id:foreignId:constrained` | `$table->foreignId('author_id')->constrained()` — the table is read from the name: `authors` |
| `timestamps` | `$table->timestamps()` — a bare word takes no column name |


#### Every type, and every modifier

Nothing to guess at — this is the whole vocabulary the command accepts, and a
name outside it is refused with this list before any file is written.

**Types that take no argument**

`id` · `increments` · `smallIncrements` · `mediumIncrements` · `bigIncrements` ·
`foreignId` · `foreignUuid` · `foreignUlid` · `tinyInteger` · `smallInteger` ·
`mediumInteger` · `integer` · `bigInteger` · `unsignedTinyInteger` ·
`unsignedSmallInteger` · `unsignedMediumInteger` · `unsignedInteger` ·
`unsignedBigInteger` · `text` · `mediumText` · `longText` · `binary` ·
`boolean` · `date` · `time` · `timeTz` · `dateTime` · `dateTimeTz` ·
`timestamp` · `timestampTz` · `json` · `jsonb` · `uuid` · `ulid` ·
`ipAddress` · `macAddress` · `year` · `float` · `double`

**Types that take a length** — `string`, `char`. `title:string:120`.

**Types that take precision and scale** — `decimal`, `unsignedDecimal`.
`price:decimal:8,2`.

**Modifiers with no value** — `nullable`, `unique`, `index`, `unsigned`,
`primary`, `autoIncrement`, `useCurrent`, `useCurrentOnUpdate`, `constrained`,
`first`. As many as you like: `slug:string:120:unique:index`.

**Modifiers that take a value** — `default=`, `comment=`, `after=`. A value of
`true`, `false`, `null` or a number is written as that literal; anything else
becomes a quoted string. `role:string:default=editor`, `active:boolean:default=true`.

**Bare words, which take no column name** — `id`, `timestamps`, `timestampsTz`,
`softDeletes`, `softDeletesTz`, `rememberToken`.

Colons rather than parentheses on purpose: `surname:varchar(255)` is a syntax
error in a shell unless it is quoted, and an argument that only works in quotes
is an argument people get wrong.

The types are the schema builder's own names, not SQL's — `string`, not
`varchar`; `boolean`, not `bool`. What you type is what appears in the file, so
you are learning the API you are about to edit rather than a second vocabulary.
A name it does not know is refused with the list of the ones it does, **before**
anything is written: a typo in the fourth column does not leave half a migration
behind.

A table being created gets `id()` whether or not you asked, and an alter writes
its own `down()` that drops what it added.

> This covers the common columns. Foreign keys with their own `onDelete`,
> composite indexes, check constraints and generated columns are not here: on a
> command line they are longer and harder to read than the PHP they produce, and
> the file is open in front of you. The point is to save typing, not to become a
> second schema language.


Migrations are anonymous classes returned by the file:

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
$schema->table('posts', fn (Blueprint $t) => /* alters */);
$schema->drop('posts');
$schema->dropIfExists('posts');
$schema->rename('posts', 'articles');
$schema->hasTable('posts');          // MySQL, PostgreSQL and SQLite
$schema->hasColumn('posts', 'title');
$schema->hasIndex('posts', 'posts_title_index');
$schema->statement('SET ...', $bindings);
$schema->driver();
```

### Column types

```php
// Keys
$table->id();                    $table->increments('id');
$table->bigIncrements('id');     $table->smallIncrements('id');
$table->mediumIncrements('id');  $table->uuid('uuid');    $table->ulid('ulid');

// Integers
$table->integer('n');            $table->bigInteger('n');
$table->mediumInteger('n');      $table->smallInteger('n');
$table->tinyInteger('n');        $table->unsignedInteger('n');
$table->unsignedBigInteger('n'); $table->unsignedDecimal('v', 8, 2);

// Decimals
$table->decimal('price', 8, 2);  $table->float('f');      $table->double('d');

// Text
$table->string('name', 255);     $table->char('state', 2);
$table->text('body');            $table->mediumText('c');  $table->longText('c');

// Date and time
$table->date('d');               $table->dateTime('dt');   $table->dateTimeTz('dt');
$table->time('t');               $table->timeTz('t');      $table->year('y');
$table->timestamp('ts');         $table->timestampTz('ts');
$table->timestamps();            $table->timestampsTz();
$table->softDeletes();           $table->softDeletesTz();

// Others
$table->boolean('active');       $table->json('meta');     $table->jsonb('meta');
$table->binary('blob');          $table->enum('st', ['a','b']);  $table->set('tags', [...]);
$table->ipAddress('ip');         $table->macAddress('mac');
$table->rememberToken();         $table->rawColumn('tags', 'TEXT[]');
```

### Modifiers

```php
$table->string('slug')->nullable()->default('')->comment('Friendly URL');
$table->integer('views')->unsigned()->default(0);
$table->string('email')->unique();
$table->string('name')->collation('utf8mb4_unicode_ci')->charset('utf8mb4');   // MySQL's names
$table->timestamp('updated')->useCurrent()->useCurrentOnUpdate();
$table->string('extra')->after('name');     // MySQL
$table->string('first')->first();           // MySQL
$table->integer('total')->storedAs('a + b');
$table->integer('calc')->virtualAs('a * 2');
```

### Indexes and keys

```php
$table->primary('id');
$table->unique(['email', 'tenant_id']);
$table->index('created_at');
$table->fullText('body');
$table->index('name')->algorithm('btree');
$table->check('price >= 0', 'posts_price_check');   // named, so dropCheck() can find it

$table->foreignId('user_id')->constrained()->cascadeOnDelete();   // users, read from the name
$table->foreignId('editor_id')->constrained('users');             // or named
$table->foreign('user_id')->references('users', 'id')->nullOnDelete();

$table->morphs('owner');            // owner_id + owner_type + index
$table->nullableMorphs('owner');
$table->uuidMorphs('owner');        $table->ulidMorphs('owner');
```

`onDelete`/`onUpdate` accept `cascadeOnDelete()`, `restrictOnDelete()`,
`nullOnDelete()`, `noActionOnDelete()` and the update equivalents.

### Alterations and drops

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->string('title', 500)->change();
    $table->renameColumn('body', 'content');
    $table->renameIndex('idx_old', 'idx_new');
    $table->dropColumn('obsolete');
    $table->dropIndex('slug');               // the column(s), as index() took them
    $table->dropUnique(['email', 'tenant_id']);
    $table->dropForeign('user_id');
    $table->dropIndex([], 'idx_custom');      // or the index's own name, second
    $table->dropPrimary();
    $table->dropCheck('posts_price_check');   // the name given to check()
    $table->dropTimestamps();
    $table->dropSoftDeletes();
    $table->dropRememberToken();
    $table->dropMorphs('owner');
});
```

Generated names respect the driver's identifier limit (63 on PostgreSQL, 64 on
MySQL) and are **deterministic**: the name `create` generates is the one `drop`
looks for.

#### Statements run in the order you wrote them

That matters as soon as a rename is involved, because a rename changes what
every later statement has to call the column:

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->renameColumn('code', 'sku');
    $table->string('sku', 10)->change();     // the new name, and it works
});
```

The builder used to emit every column statement before every operation, which
put that modification before the rename that created the name it uses. No fixed
grouping can be right — putting renames first breaks the opposite order just as
surely — so the statements come out in the order the blueprint declares them.

### Dialect parity

| Feature | MySQL 8+ | PostgreSQL 12+ |
|---|:--:|:--:|
| Column types | ✓ | ✓ |
| Constraints (FK, unique, check, primary) | ✓ | ✓ |
| Indexes (plain, unique, full-text) | ✓ | ✓ |
| Generated columns | ✓ (STORED/VIRTUAL) | ✓ (STORED) |
| `ON UPDATE CURRENT_TIMESTAMP` | ✓ native | ✓ via trigger |
| `ENUM` | ✓ native | ✓ emulated with CHECK |
| `SET` | ✓ | ✗ fails explicitly |
| `COMMENT` | ✓ inline | ✓ via `COMMENT ON` |
| `schema.table` qualification | ✓ | ✓ |
| Deferrable FK | ✗ | ✓ |

---

## Seeders and factories

### Seeders

```bash
./sfphp make:seeder User      # writes UserSeeder; "UserSeeder" works too
```

```php
<?php

namespace Database\Seeders;

use SfphpProject\src\Database;
use SfphpProject\src\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        Database::table('users')->insert([
            'name' => 'John',
            'email' => 'john@example.com',
        ]);
    }
}
```

Chain them from `DatabaseSeeder`:

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
./sfphp db:seed                       # runs DatabaseSeeder
./sfphp db:seed --class=UserSeeder    # runs a specific one
```

An unknown name lists the available seeders and exits with code 1. The shipped
`DatabaseSeeder` calls `UserSeeder`, which creates ten users through
`UserFactory`, each with the password `password`.

### Factories

```bash
./sfphp make:factory User
```

```php
<?php

namespace Database\Factories;

use SfphpProject\app\models\User;
use SfphpProject\src\Database\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        $id = bin2hex(random_bytes(4));

        return [
            'name' => 'User ' . $id,
            'email' => 'user-' . $id . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ];
    }

    protected function model(): string
    {
        return User::class;
    }
}
```

`model()` names the Model that `create()` saves through; the generator writes
it for you.

```php
$data  = (new UserFactory())->make();                      // an array, unsaved
$user  = (new UserFactory())->create();                    // saved, a User
$admin = (new UserFactory())->create(['role' => 'admin']);  // overrides
$many  = (new UserFactory())->count(50)->create();             // fifty users, fifty e-mails
$ranked = (new UserFactory())->count(3)->create([
    'position' => fn (Factory $factory, int $index): int => $index + 1,
]);
```

`make()` returns arrays, unsaved. `create()` saves each one through the model
named by `model()` and returns Model instances — a list of them when `count()`
is above 1. See [Models](#models).

`definition()` runs **once per row**, so a random value differs from row to row
and a unique index holds. A value given as a closure is called for each row,
with the factory and the row's index; only a closure is called — a string that
happens to name a PHP function, such as `'key'` or `'date'`, stays a string.

`create()` saves with `forceFill()`: the values are the factory's, not a
visitor's, so a column outside `$fillable` — the password, usually — is written
like the rest.

---

## Cache

```php
$cache = cache();                    // global helper, driver from CACHE_DRIVER

$cache->put('key', $value, 300);     // TTL in seconds; null or 0 never expires
$cache->get('key');
$cache->get('key', 'default');
$cache->has('key');                  // true for a live entry, even one holding null
$cache->forget('key');
$cache->flush();                     // everything
$cache->prune();                     // only what has expired; returns how many
$cache->pull('key');                            // reads and removes
$cache->remember('users', 600, fn () => /* ... */);   // computes when missing

$cache->increment('hits');           // atomic; returns the new value
$cache->increment('hits', 5);        // add more than one
$cache->decrement('slots');

$cache->increment('window', 1, 60);  // a counter that expires in 60 seconds
$cache->ttl('window');               // seconds left, or null
```

Every driver reads a lifetime the same way — `null` or `0` never expires, a
positive number is seconds, a negative one throws — and stores values as
**JSON**: `null`, scalars and arrays. An object put in comes back as an array,
from the file driver, from Redis and from memory alike, so code tested against
one behaves the same against the others. The file driver used to lose every
entry stored without a lifetime; the Redis driver used to `unserialize()`
whatever the server held.

### Counters

`increment()` is not `get()` plus `put()`, and the difference is the point.
Two requests that arrive together both read 4 and both write 5 — one hit is
lost. That is harmless for a cached page and not harmless for a rate limiter,
which counts precisely when several requests arrive at once.

The addition happens where the data lives: inside an exclusive lock for the
file driver, and in one Lua script for Redis — the add and the lifetime
together, so a key cannot expire between them and come back without one.

The lifetime is applied **only when the counter is created**. A counter that
already exists keeps the expiry it had, so a client that keeps knocking cannot
push its own window forward and sit inside a limit forever.

The corollary is worth knowing: a counter first created **without** a lifetime
never gets one. `increment('hits')` followed by `increment('hits', 1, 60)`
leaves a counter that never expires, and `ttl()` answers `null`. Pass the
lifetime on the call that creates the counter, or on every call — the rate
limiter does the latter.

> The `Cache` interface has `increment()`, `ttl()` and `prune()`. An application
> that ships its own driver implements all three.

### Choosing the driver

```ini
CACHE_DRIVER=file          # the default
CACHE_DRIVER=redis         # needs ext-redis
CACHE_DRIVER=array         # memory, gone at the end of the request

CACHE_PATH=storage/cache   # where the file driver writes (the default); relative to the project
CACHE_PREFIX=sfphp:cache:  # so two applications can share one Redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
```

> **This setting decides more than caching.** Rate limit counters, the token
> denylist and — with `SESSION_DRIVER=cache` — sessions all live here. On the
> file driver each machine keeps its own copy, so behind a load balancer a
> revoked token still works on the other instances and a limit of 60 requests is
> really 60 *per instance*. **More than one instance means `redis`.**

Selecting `redis` without `ext-redis` **fails on first use** — the first
`cache()`, `queue()` or cache-backed session — with an explicit error, rather
than falling back to the file driver. A silent fallback would leave an operator believing
those three things are shared when each machine is keeping its own — a hole that
surfaces months later and never as an error.

One connection is opened per process and shared by the cache, the queue and the
session handler, instead of one socket each. An application that builds its own
— a TLS socket, a cluster client — hands it over:

```php
use SfphpProject\src\RedisConnection;

RedisConnection::use($myRedis);
```

Constructing a manager by hand still works, and is how you get a second cache
that differs from the configured one:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$scratch = new CacheManager(new MemoryDriver());   // for this request only
```

The file driver's directory is private (`0700`, files `0600`) and inside the
project. It used to be a fixed name under the system temporary directory, which
every application and every user on the machine shared.

```bash
./sfphp cache:clear      # removes what has expired
./sfphp cache:flush      # removes everything
```

`cache:clear` used to flush. The cache holds the revoked-token list, the
rate-limit counters and, with `SESSION_DRIVER=cache`, the sessions — so clearing
"expired entries" brought revoked tokens back, reset every limit and signed
everybody out. It prunes now; `cache:flush` is the command that empties it, and
says what that resets.

---

## Queue

```php
use SfphpProject\src\Queue\Job;

final class SendEmailJob extends Job
{
    protected int $tries = 5;          // the class default; ->tries() overrides it

    public function __construct(private string $to) {}

    public function handle(): void
    {
        mailer()->send(
            (new Message())->to($this->to)->subject('Hello')->text('Body')
        );
    }
}
```

```php
dispatch(new SendEmailJob('a@b.com'));         // global helper
dispatch(new SendEmailJob('a@b.com'), 300);    // with a delay in seconds

dispatch((new SendEmailJob('a@b.com'))->tries(10)->timeout(120)->delay(300));
```

The worker rebuilds a job **without calling its constructor** — that ran once,
at dispatch — and puts its properties back from what was stored, so a job may
take constructor arguments like any other class. `tries`, `timeout` and `delay`
set at dispatch are stored with the job and honoured by the worker.

A job's properties are stored as JSON, so they hold what JSON holds: an id, a
string, a number, an array of those. An object — a `DateTimeImmutable`, an enum,
a model — is refused at dispatch with a message saying so. It used to go in as
its fields and come back as an array the typed property refused, inside the
worker; a model also copied its whole row, password hash included, into the
jobs table. Store the id and load the rest in `handle()`. A stored payload that
can no longer be rebuilt — a class renamed since — goes to the failed jobs
instead of stopping the worker.

```bash
./sfphp queue:work                 # default: 3600s
./sfphp queue:work --timeout=7200
./sfphp queue:failed
```

The worker processes until the timeout, counts each failed attempt once, and
moves a job to `failed_jobs` once its tries run out. With `ext-pcntl`, `SIGTERM`
and `SIGINT` stop it gracefully — the running job finishes and no other is
taken — and a job that runs past its `timeout` (60 seconds unless set) fails
that attempt with a `JobTimedOutException`. Without `ext-pcntl` there is no
graceful stop and no per-job limit: a job runs until it returns.

The `jobs` and `failed_jobs` tables are created by `./sfphp queue:table`, or on
the first operation that needs them — instantiating the driver opens no
connection. Creating a table inside an open transaction would commit it on
MySQL, so the driver refuses to and asks for `queue:table` instead. A failed job
keeps its payload and the exception's class, message and stack trace;
`queue:failed` lists the message.

### Choosing the driver

```ini
QUEUE_DRIVER=database          # the default
QUEUE_DRIVER=redis             # needs ext-redis

QUEUE_RESERVATION_SECONDS=900  # longer than your slowest job
QUEUE_TABLE=jobs
QUEUE_FAILED_TABLE=failed_jobs
```

`dispatch()` and `./sfphp queue:work` read the same setting, which is the reason
it exists rather than being a constructor argument: a worker that built its own
driver would drain the database while requests pushed to Redis, and neither side
would report anything wrong.

The database driver needs no extra service and survives a restart, so it is the
default. Redis is faster and keeps the job table out of the database; both hand
a job to exactly one worker.

### More than one worker

A job is handed to exactly one worker. That is worth stating because it was not
true until this version, and because the failure was invisible with a single
worker: `pop()` selected a row and then updated it, so two workers read the same
job, both marked it reserved, and **both ran it**. For a queue that is not a
slowdown, it is a duplicated side effect — the same e-mail twice, the same card
charged twice.

The reservation is now a claim. The `UPDATE` carries the condition that the job
is still unreserved, and only the worker whose statement affects one row has it;
a worker that loses looks for the next job instead of running someone else's. A
conditional update rather than `SELECT … FOR UPDATE SKIP LOCKED`, because the
framework supports seven drivers and not all of them have it.

```php
new DatabaseDriver(reservationSeconds: 900);
```

**A job reserved by a worker that died comes back.** A worker killed between
reserving a job and finishing it leaves the job marked as taken with nobody
working on it. Any job reserved longer than `reservationSeconds` — fifteen
minutes by default — is released for another worker to claim. Set it above the
longest a job can legitimately take, or a slow job will be picked up twice.

The Redis driver had the same flaw for the same reason: `zRem` reports how many
members it removed and nothing checked the answer. It does now.

In Redis the queue is three keys: `queue:default`, a sorted set of job ids
scored by when each becomes available; `queue:jobs`, a hash of payloads by id;
and `queue:failed`, a hash of the jobs that ran out of tries. Deleting,
releasing and claiming a job are each one operation on its id, and
`flush()` removes those keys and nothing else — it used to call `flushDb()`,
which erased the whole database, cache and sessions included. Jobs queued with
the earlier layout, which kept the payload in the sorted set, are still run.

### What is missing

| Missing | Situation |
|---|---|
| Several named queues | Everything goes to `default`; the column exists and nothing reads it |
| Recovering a job whose Redis worker died | A popped id leaves the sorted set; its payload stays in `queue:jobs`, but nothing puts it back — the database driver's reservation timeout has no Redis counterpart yet |
| Retrying a failed job | `failed_jobs` records them; putting one back is manual |
| Backoff between attempts | A retry waits a fixed 60 seconds |
| A supervisor | Keeping the worker alive is `systemd`, `supervisor` or the platform's job |

---

## File uploads

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

`$_FILES` was exposed raw before this existed, which left every application to
write the same security-critical code from scratch. File upload is a classic way
onto a server, and the mistakes are specific and repeatable — so they are worth
naming rather than summarising.

### Three lies a browser tells

**The reported type is a claim.** `$_FILES['x']['type']` is a header the client
sent, so a PHP script announced as `image/png` arrives as `image/png`. Checking
it proves nothing. `mimeType()` reads the file's own bytes with `ext-fileinfo`,
and `assertType()` refuses rather than guessing when that extension is absent.

**The reported name is a claim too.** Using it to build a path is how
`../../public/shell.php` gets written. `clientName()` strips anything path-like,
including the null byte that makes `shell.php\0.png` pass an extension check and
land as `shell.php` — and `store()` does not use it at all.

**A file that was not uploaded is not a file.** `$_FILES` can be forged when a
script is reachable in a way its author did not expect, pointing `tmp_name` at
`/etc/passwd`. `is_uploaded_file()` tells the two apart, and it is checked
before anything is read or moved; `store()` then uses `move_uploaded_file()`,
which applies the same guard at the moment it matters.

### Checks

Each one throws `UploadException` with a message naming what it refused, so a
controller decides whether that is a form error or a failure:

| | |
|---|---|
| `assertType(['image/png'])` | What the file **contains**, from its bytes |
| `assertExtension(['png'])` | What the file is **called** |
| `assertSmallerThan($bytes)` | Per field, unlike `upload_max_filesize` |
| `assertImage()` | Decodes the header, so a file that is not an image is refused |

Type and extension are both worth checking, because they are different lies:
what a file contains decides how a library reads it, and what its name ends in
decides how a web server treats it. A real PNG called `avatar.php` is still a
problem if it lands somewhere PHP is executed — and a PNG can carry PHP after
its pixels and pass both `assertType()` and `assertImage()`, which is why the
stored name below never keeps an extension a server would run.

```php
try {
    $file->assertType(['application/pdf'])->assertSmallerThan(5 * 1024 * 1024);
} catch (UploadException $e) {
    $errors['invoice'] = 'Send a PDF of at most 5 MB.';   // the exception's message is in English
    logger()->info('upload refused', ['reason' => $e->getMessage()]);
}
```

The messages of the checks are written for the developer and are in English.
What the visitor reads is yours to word, in their language; `errorMessage()`,
below, is the one message the framework translates.

`Validator` is deliberately not involved. It works on scalars from a form, and
an upload's real type is something only the file itself can answer.

### Storing

```php
$path = $file->store('/var/app/storage/invoices');
// /var/app/storage/invoices/9f2c…a41.pdf

$path = $file->store($directory, 'report.csv');   // still sanitised
```

The stored name is **random**, and that is the point rather than a convenience:
the client's name is the client's input. The extension comes from what the file
**contains** when that is a common type — `jpg`, `png`, `pdf`, `txt` and the
like — and otherwise from the client's name, only when it is plain alphanumeric
and never one a server might run or render as a page: `php`, `phtml`, `phar`,
`html`, `svg`, `js` and their kin are dropped. A name you pass yourself is
reduced to something that cannot be a path — letters in any script are kept, so
`relatório.pdf` stays `relatório.pdf` — gets `.txt` added when it ends in one
of those extensions, and is refused outright when nothing usable is left.

> **Store uploads outside the document root.** None of this stops a file being
> executed if it is written somewhere the web server will run it. `public/` is
> the one place an upload should never go.

### Several files

```php
foreach ($request->files('photos') as $photo) {
    $photo->assertImage()->store($directory);
}
```

`$_FILES['photos']` for `name="photos[]"` is not a list of files — it is one
file whose every property is a list. `files()` turns it the right way round,
leaving out the inputs that were left empty, and `file()` answers `null` for
such a field rather than handing back something unusable. `hasFile()` asks
whether a **usable** file arrived, not whether the field was present.

### Why an upload failed

PHP reports failures as `UPLOAD_ERR_*` integers, and the difference matters to
whoever is filling in the form: "the file is too large" is something they can
act on and "the server has no temporary directory" is not.
`errorMessage()` returns the right one, translated, from the `upload.*` catalog
the framework ships in all three languages.

### What is missing

| Missing | Situation |
|---|---|
| A storage abstraction | `store()` writes to a local path. S3 or a shared volume is the application's to arrange, and local disk is not shared between instances |
| Image processing | No resizing or re-encoding. `ext-gd` does that and the framework does not wrap it |
| Stripping metadata | EXIF, including where a photograph was taken, is kept as it arrived |
| Virus scanning | Out of scope; that is ClamAV's job, on the stored file |
| Chunked or resumable uploads | One request, one file |

---

## HTTP client

Calling another service used to mean `curl_setopt_array` with a dozen constants,
decoding the body by hand, and remembering — or, far more often, forgetting — to
set a timeout.

```php
use SfphpProject\src\Http\Http;

$response = Http::get('https://api.example.com/users', ['page' => 2]);
$response = Http::post('https://api.example.com/users', ['name' => 'Ana']);

$response->ok();       // true for 2xx
$response->status();   // 200
$response->json();     // the decoded body
```

A body given as an array is sent as JSON, with the `Content-Type` and `Accept`
headers that implies. `->asForm()` sends it as a form instead, and a string is
sent as it is — a caller who encoded the body owns its type.

The client is built on `ext-curl`. Without the extension every call throws
`ClientException` rather than degrading.

Every verb exists on the facade and on a client, with the same signature:

```php
Http::get($url, $query);       // query values, appended to the URL
Http::post($url, $body);
Http::put($url, $body);
Http::patch($url, $body);
Http::delete($url, $body);     // a body is allowed, and often ignored

Http::client();                // a client with nothing configured
```

For a method these do not cover — `OPTIONS`, `HEAD`, or something a service
invented — `send()` takes it:

```php
Http::client()->send('OPTIONS', 'https://api.example.com/users');
Http::client()->send('HEAD', $url);                 // headers only; returns as soon as they arrive
Http::client()->send('REPORT', $url, $body, ['page' => 2]);
```

A header name must be a header name and a value may not contain a line break:
`withHeaders(['X-A' => "v\r\nX-Injected: yes"])` throws `ClientException`
instead of sending a second header of the caller's choosing.

### A client for a service you call often

```php
$billing = Http::base('https://billing.internal')
    ->token($jwt)
    ->timeout(5);

$invoice = $billing->get('/invoices/7')->throw()->json();
```

A client is a **value**: every method returns a new one, so a client configured
for a service can be handed around without anything being able to change it.

| On the facade | On a client | Does |
|---|---|---|
| `Http::base($url)` | `->base($url)` | Relative paths start from this, and credentials go to its origin only |
| `Http::withToken($jwt)` | `->token($jwt)` | A bearer token |
| `Http::withBasic($user, $pass)` | `->basic($user, $pass)` | HTTP basic credentials |
| `Http::withHeaders([...])` | `->headers([...])` | Anything else |
| `Http::timeout($seconds, $connect)` | `->timeout($seconds, $connect)` | How long to wait |
| — | `->asForm()` | Send bodies as forms rather than JSON |
| — | `->insecure()` | Stop verifying certificates |

Each one on the facade is the same as `Http::client()` followed by the instance
method, and each returns a client, so they chain in any order.

### Reading the answer

An error **is** an answer: the server was reached, understood and said no. So a
404 and a 500 come back to be inspected rather than thrown.

| | |
|---|---|
| `status()` | The code |
| `ok()` | 2xx |
| `failed()` · `clientError()` · `serverError()` | 4xx or 5xx, 4xx, 5xx |
| `body()` · `json()` | The body, raw or decoded |
| `header($name)` · `headers()` | Case-insensitive; the final response's only, a repeated header joined with commas |
| `url()` | The URL that answered, **after** any redirects |
| `throw()` | Raise on 4xx and 5xx, and return `$this` otherwise |

`json()` answers `null` when the body is not JSON, because a service returning
an error page instead is a thing that happens; `json(strict: true)` throws
instead.

A request that produced **no** answer — a refused connection, a name that does
not resolve, a timeout, a certificate that failed to verify — throws
`ClientException`. There is nothing to return.

### Streaming a response

A large export or a feed that never ends is read chunk by chunk rather than
held in memory. A listener receives the status first, then each chunk, then the
end; returning `false` from `onStatus()` or `onChunk()` aborts the transfer:

```php
use SfphpProject\src\Http\AbstractClientStreamListener;

Http::client()
    ->idleTimeout(30)      // abort after 30 seconds without a byte
    ->stream('https://api.example.com/export', new class extends AbstractClientStreamListener {
        public function onChunk(string $chunk): bool
        {
            file_put_contents('/tmp/export.csv', $chunk, FILE_APPEND);

            return true;   // keep receiving
        }
    });

Http::client()->streamRequest('POST', $url, $listener, $body);   // any method
```

`idleTimeout()` watches for silence, not slowness: a stream at 100 bytes a
second is fine. See [STREAMING.md](./STREAMING.md#client-side-streaming-http--php)
for the listener API and proxying a stream to the browser.

### What it does not do

**Retry.** How many times to try, how long to wait between attempts and which
failures deserve another one are decisions about the service being called rather
than about HTTP — a request that charges a card is not one to repeat because a
response was slow. That belongs in the integration, next to the knowledge that
can answer it.

### What it defends

A timeout is set whether you ask for one or not: 5 seconds to connect and 15
for the whole exchange. A call with no timeout holds a worker until PHP's own
limit, so one slow service takes the whole application with it.

Certificates are verified. `->insecure()` turns that off and is named to be
uncomfortable to type, because `CURLOPT_SSL_VERIFYPEER => false` copied from a
forum answer is among the most common holes in PHP.

Redirects are followed, capped at five, and **never** from `https://` to
`http://` — a downgrade the server asks for and the client should refuse, since
everything after it travels in the clear, including the `Authorization` header
this may be carrying. They are followed as a browser follows them: a `POST`
answered with 301, 302 or 303 continues as a `GET`, and 307 and 308 repeat the
`POST` with its body. The headers of the redirects themselves — a `Location`, a
`Set-Cookie` — are not mixed into the response that comes back.

A client built with `base()` sends its token or its basic credentials to that
origin only. Given an absolute URL somewhere else, it makes the request without
the `Authorization` header rather than handing the credentials over.

> **A URL that came from a visitor is a request an attacker chose.** Pointed at
> `169.254.169.254`, or at something only your network can reach, this fetches
> it and hands back the answer — the attack called SSRF. Nothing here can tell a
> URL you built from one somebody typed, so check the ones you did not build.

---

## Mail

```php
use SfphpProject\src\Mail\Message;

mailer()->send(
    (new Message())
        ->to('ana@example.com', 'Ana')
        ->subject('Your order')
        ->text('Thank you for your purchase.')
        ->html('<p>Thank you for your purchase.</p>')
);
```

The framework knows how to put bytes on a mail server. It does not know why you
are sending them: there is no welcome e-mail here and no password reset, because
those are decisions about what an application is for. What is here is the
transport, in the same shape as the cache and the queue — a contract, a manager
and drivers.

### Configuration

```ini
MAIL_DRIVER=smtp
MAIL_HOST=smtp.provider.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls              # tls for STARTTLS, ssl for implicit TLS
MAIL_FROM_ADDRESS=no-reply@yourdomain.com
MAIL_FROM_NAME="Your Product"
```

| Driver | Sends through | Use it for |
|---|---|---|
| `smtp` | A mail server | Production, with a contracted service |
| `mail` | PHP's `mail()` | A development machine, and nothing else |
| `log` | The logger | The default; shows what would have gone out |
| `array` | Memory | Tests, through `ArrayDriver::messages()` |

The default is `log`, not `mail`. A framework whose out-of-the-box behaviour is
to hand messages to an unconfigured local MTA sends nothing and says nothing;
writing them to the log at least tells you what would have left, and cannot
reach a real person by accident. Outside production the log keeps the body, so a
reset link can be followed from it; with `APP_ENV=production` the body is left
out and the record is a warning, because there it means mail is not being sent.
A `MAIL_DRIVER` that is not one of the four still logs, and says so in a warning
— a misspelt `smpt` used to send nothing without a word.

The `mail` driver keeps a Bcc blind by handing it to `sendmail -t` in a `Bcc:`
header, which sendmail removes before sending; PHP's default `sendmail_path` is
that. With a `sendmail_path` that does not read the recipients from the headers,
a message with Bcc is refused rather than sent with the blind copies shown to
everyone.

### One driver, every provider

`smtp` is the only transport the framework needs, and that is not a compromise.
Every service anyone contracts — SES, Postmark, SendGrid, Mailgun, Resend,
Brevo — accepts SMTP, so changing provider is four values in the environment
rather than a new driver. An HTTP client per vendor would be more code reaching
fewer of them.

Both routes to TLS work, because providers are split between them:

| `MAIL_ENCRYPTION` | Port, usually | What happens |
|---|---|---|
| `tls` (or `starttls`) | 587 | Plain connection, upgraded with `STARTTLS` |
| `ssl` | 465 | Encrypted from the first byte |
| `none` | 25, 1025 | Neither — a local server only |

The value is read regardless of case, and anything else is refused with that
list: `TLS` used to fall through every comparison and mean `none`, sending the
password in the clear while the configuration said otherwise.

`AUTH PLAIN` and `AUTH LOGIN` are both supported; the server's own announcement
decides which is used. The certificate is verified by default. A password is
never sent over an unencrypted connection to another machine: with
`MAIL_ENCRYPTION=none` and a username, only a server on this machine
(`localhost`, `127.0.0.1`) is logged into. `MAIL_ALLOW_PLAINTEXT_AUTH=true`
lifts that, for a relay on a network you trust.

### Sending is not arriving

Configure the credentials and messages leave correctly. Whether they reach an
inbox depends on three things that are DNS and a provider's control panel, not
code:

- **SPF, DKIM and DMARC** records on your sending domain. The provider gives
  you the values. Without them a message is scored as spam or refused outright.
- **A verified sender.** Almost every service refuses a `From` you have not
  proved is yours.
- **Bounces and complaints**, which the provider reports by webhook. Nothing
  here consumes them, and ignoring them burns your sending reputation.

No framework can do those on an application's behalf. They are configured once
per project.

### Writing a message

```php
(new Message())
    ->from('no-reply@yourdomain.com', 'Your Product')   // usually left to MAIL_FROM_*
    ->to('ana@example.com', 'Ana')
    ->cc('records@yourdomain.com')
    ->bcc('audit@yourdomain.com')
    ->replyTo('support@yourdomain.com', 'Support')
    ->subject('Your order')
    ->text('The plain text version.')
    ->html('<p>The HTML version.</p>')
    ->attach('invoice.pdf', $bytes, 'application/pdf')
    ->attachFile('/tmp/report.csv', 'report.csv', 'text/csv')
    ->header('X-Campaign', 'october');
```

Setting both `text()` and `html()` sends a `multipart/alternative` and lets the
reader's client choose. HTML with no text alternative is one of the things that
gets a message scored as spam, so it is worth filling in.

A **Bcc address reaches the server and never reaches a header**. Writing one
would show every blind recipient to everyone else, which is the one thing Bcc
promises not to do.

A display name with a comma, a quote or an `@` is written as a quoted string, so
`replyTo('visitor@example.com', 'Visitor, attacker@evil.com')` is one address
with an odd name rather than two addresses. A long subject is cut into encoded
words and folded, as RFC 2047 and RFC 5322 require, and an attachment whose name
is not ASCII is named with RFC 2231's `filename*=UTF-8''…`.

### Two things that are not conveniences

**A line break in a header is refused.** A newline in a name, an address or a
subject lets whoever supplied it append headers of their own — `Bcc:` to an
address you never intended is the classic one, and the value usually comes from
a form. `Message` throws instead of stripping it, because quietly sending a
different message than the one asked for is the wrong answer to both an attack
and a mistake.

**Everything is UTF-8 all the way out.** A subject with an accent is encoded per
RFC 2047 and a body per RFC 2045, so "Confirmação de inscrição" arrives as
itself rather than as mojibake. Pure ASCII is left alone, which keeps a raw
message readable. An address is accepted in any script — `josé@exemplo.com.br`,
`user@münchen.de` — and a domain outside ASCII is sent in its ASCII form when
`ext-intl` is installed. A local part outside ASCII needs a server that speaks
SMTPUTF8, which not every one does.

### Sending in the background

The queue is already there, and a request should not wait on a mail server:

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

### Testing

```php
$sent = new ArrayDriver();
mailer()->driver($sent);

// ... exercise the code under test

$sent->last()->recipients();      // ['ana@example.com']
$sent->last()->subjectLine();
```

`MAIL_ALWAYS_TO` redirects every message to one address while keeping the
intended recipients in an `X-Intended-For` header. It is for a staging
environment working from a copy of production data, where the addresses in the
database belong to real people. Only the recipients change: attachments,
Reply-To and headers travel as in production, so staging sends what production
would. `mailer()->send()` works on a copy, so the `Message` you passed is left
as you built it.

### What is missing

| Missing | Situation |
|---|---|
| Delivery feedback | Bounces and complaints arrive by webhook at the provider; nothing consumes them |
| Inline images (`cid:`) | Attachments are sent as attachments, not referenced from the HTML |
| Templates | Render a view and pass the result to `html()`; the mailer takes a string |
| DKIM signing in the client | Done by the provider, from the DNS records you publish |
| A pooled connection | One connection per message. Sending in bulk belongs on the queue |

---

## Events

```php
use SfphpProject\src\Events\Dispatcher;

Dispatcher::listen(OrderPlaced::class, SendReceiptListener::class);
Dispatcher::listen(OrderPlaced::class, fn (OrderPlaced $e) => Metrics::count('orders.placed'));

Dispatcher::dispatch(new OrderPlaced($order));
```

`make:event` and `make:listener` generated classes for four versions with
nothing to dispatch them. A generator producing code for infrastructure that
does not exist is worse than no generator, because it looks like a feature.

An event is **any object**. There is no base class to extend and no interface to
implement, because neither would carry information: what makes something an
event is that somebody listens for it.

### Listeners

A listener is a callable, or the name of a class with a `handle()` method. The
class name form is resolved through the container **when the event fires**, so
a listener that needs a database connection does not open one at boot for an
event that may never happen.

```bash
./sfphp make:listener SendReceipt   # creates app/listeners/SendReceiptListener.php
./sfphp make:event OrderPlaced      # creates app/events/OrderPlacedEvent.php
```

Both generators append the suffix — and leave it alone when you typed it, so
`make:listener SendReceiptListener` writes the same class. The class to
register is `SendReceiptListener`.

The container is the one you hand over. Without it, each listener is built by
an empty `Container`, which knows nothing of the application's bindings — a
listener with a `PDO` parameter cannot be built. Hand it the application's at
boot:

```php
Dispatcher::useContainer($container);   // in public/index.php, after the bindings
```

For tests, `Dispatcher::hasListeners(OrderPlaced::class)` says whether anything
would hear that event — a listener for it, or for a parent class or interface of
it — and `Dispatcher::forget()` removes the listeners of one event, or of all of
them with no argument.

Registering against a parent class or an interface catches its children, which
is what makes "record every domain event" expressible without naming each one:

```php
Dispatcher::listen(DomainEvent::class, AuditTrail::class);
```

Listeners run in the order they were registered, whichever class each was
registered against.

### A listener that throws

It is logged, with the event and the listener named, and the others still run.
Dispatching is telling, not asking: an event whose third listener failed has
still happened, and making the action that fired it fail would put one
listener's bug in the caller's path.

```php
Dispatcher::dispatchOrFail($event);   // when the caller does depend on them
```

That is a separate method rather than a flag, because the default matters more
than the exception: a flag invites passing `true` without deciding.

### Under a persistent runtime

Listeners live in a static and are registered once, at boot, like routes. That
is the right shape for something an application declares. What must not go in a
listener is per-request state captured in a closure — it would outlive the
request that created it and be seen by the next one.

### What is missing

| Missing | Situation |
|---|---|
| Queued listeners | A listener runs in the request that fired the event; dispatch a job from it to move the work |
| Stopping propagation | Every listener runs; there is no "handled, stop" |
| Wildcard names | Registration is by class, which a parent class already generalises |

---

## Validation

```php
public function store(Request $request): Response
{
    $result = $request->validate([
        'name'  => 'required|min:3|max:255',
        'email' => 'required|email',
        'age'   => 'required|number',
    ]);

    if ($result->fails()) {
        return Response::json(['errors' => $result->errors()], HTTP_UNPROCESSABLE_ENTITY);
    }

    $clean = $result->validated();   // name, email and age — nothing else

    // ...
}
```

The data is the request's own — query string, body, JSON, whatever this request
actually carried. **Nothing reaches into `$_POST`**, and that matters beyond
taste: a superglobal is process-wide state, so a test has to fake it, a second
request in the same worker inherits it, and a controller written against it
cannot be called twice with different input.

Outside a request — a console command, a queue job, a value you built yourself —
the validator takes any array:

```php
use SfphpProject\src\Validator;

$result = Validator::validate($row, ['email' => 'required|email']);
```

Rules are a pipe-separated string or an array of rule strings. Arguments follow
a colon. Rule names are read regardless of case — `minlength:5` is
`minLength:5` — on the server and in the browser alike.

| Rule | Checks |
|---|---|
| `required` | Not null, not empty, not only whitespace, not an empty array |
| `email` | An address, in **any script**: `josé@exemplo.com.br` passes, `a@b..com` does not |
| `url` | An `http` or `https` address with a host, in any script — `javascript:` and `foo:bar` do not pass |
| `number` | ASCII digits only (safe for `(int)`) |
| `alpha` | Letters only, **any alphabet**, combining marks included (`\p{L}`, `\p{M}`) |
| `alphanum` | Letters and digits from any script |
| `min:N` | **Follows the value**: at least N as a number, at least N characters, or at least N items of an array |
| `max:N` | At most N as a number, at most N characters, or at most N items |
| `minLength:N` | At least N characters, **always** — whatever the value looks like; items for an array |
| `maxLength:N` | At most N characters, always; items for an array |
| `pattern:REGEX` | Matches, with `u` and the delimiters supplied for you; an invalid pattern throws |

A character is what a reader sees as one: "José" typed with a combining accent
is four characters, not five, and an emoji family is one.

An array fails every rule that is about a single value — `email`, `alpha`,
`pattern` and the rest — and is counted by its items by the four bounds. An
array used to read as an empty string, so `['a', 'b', 'c']` passed
`maxLength:1`.

**`required` comes first, and the other rules only when there is a value.** A
field that is absent, empty or only whitespace is judged by `required` alone,
wherever it sits in the list: with it, "is required" is the one message — there
is no length to check in a value that is not there; without it, the field is
optional and nothing is checked. Once the field has a value, `required` has
nothing to say and every other rule applies, each with its own message. `"0"`
is a value. The browser (`@validate`) decides the same way.

```php
$rules = ['name' => 'required|min:3', 'nickname' => 'min:3|max:20'];

Validator::validate([], $rules)->errors();
// ['name' => ['name is required.']]            — nickname is optional: not checked

Validator::validate(['name' => 'Jo', 'nickname' => 'Al'], $rules)->errors();
// ['name' => ['name must be at least 3 characters long.'],
//  'nickname' => ['nickname must be at least 3 characters long.']]
```

**`min` and `max` follow the value**, which is what people mean when they write
them:

```php
'age'  => 'required|number|min:18',   // at least eighteen years old
'name' => 'required|min:3',           // at least three characters
```

Before 0.19.0 they counted characters whatever the value was, so `min:18` on an
age passed for `7` and failed for `21` — and said nothing. When the distinction
matters, `minLength` and `maxLength` count characters always: a postcode is a
number that is really a string.

```php
'zip' => 'required|minLength:5',      // 01001 is five characters, not 1,001
```

**The browser checks the same rules.** `@validate` takes these names, with the
same arguments and the same meaning, so a form says one thing once — and the
suite compares the two lists so they cannot drift apart. The browser's answer is
a convenience; the server's is the one that counts.

```html
<input name="age" @validate="required|number|min:18">
```

A pattern containing a `|` cannot go in a pipe-separated string, so pass the
rules as an array instead:

```php
'colour' => ['required', 'pattern:^(blue|green)$'],
```

An unknown rule throws `InvalidArgumentException` — a typo fails early rather
than silently passing validation.

`ValidationResult`: `passes()`, `fails()`, `errors()`, `validated()`.

`validated()` returns **only the fields that had rules and were present** in the
input; anything else the client sent is left out, so the result is safe to hand
to `Model::create()` without leaning on `$fillable` alone. It throws
`LogicException` when validation failed — check `passes()` first.

Custom messages:

```php
Validator::validate($data, ['name' => 'required|min:3'], [
    'name' => [
        'required' => 'Please enter your name.',
        'min' => 'The name needs at least 3 letters.',
    ],
]);
```

Messages are keyed by rule name, with one exception: when the value is a number,
`min` and `max` look their message up as `minValue` and `maxValue`. `minLength`
and `maxLength` use the keys `min` and `max`.

```php
['age' => ['minValue' => 'You must be at least 18.']]   // for 'number|min:18'
```

On an array the four bounds look theirs up as `minItems` and `maxItems`.

`:field` in a message is the field's name as the form sends it, unless the
catalog names it in the visitor's language:

```php
// lang/pt_BR/validation.php
return ['attributes' => ['name' => 'nome', 'email' => 'e-mail']];
// "nome é obrigatório." rather than "name é obrigatório."
```

---

## Internationalisation

Messages live in per-language catalogs; the language comes from the request's
`Accept-Language`. The framework ships its own catalogs, and an application
overrides whatever it likes without editing them.

**The default is English.** Until this version the framework's 404 and 405
pages were hardcoded in Portuguese — a German developer adopting SFPHP shipped
a Portuguese error page to their users. A framework meant to be used anywhere
cannot do that.

### Where messages live

```
src/I18n/lang/            framework catalogs (lowest priority)
  en/http.php
  en/validation.php
  pt_BR/…
  es/…

lang/                     your application's catalogs (these win)
  en/app.php
  pt_BR/app.php
  es/app.php
```

A catalog is a PHP file returning an array:

```php
<?php   // lang/en/app.php

return [
    'welcome' => 'Welcome, :name!',
    'items' => '{0} No items|{1} One item|[2,*] :count items',
];
```

Nothing is compiled or parsed: a catalog costs a `require` and lands in OPcache
like any other file.

Overriding is **key by key**. To change only the 404 message, create
`lang/en/http.php` containing just `not_found_message` — the rest keeps coming
from the framework.

### Translating

```php
__('http.not_found_title');                    // 404 - Page not found
__('app.welcome', ['name' => 'Ana']);          // Welcome, Ana!
__('app.welcome', ['name' => 'Ana'], 'pt_BR'); // in a specific language
locale();                                      // 'en'
lang_tag();                                    // 'en' — the same, as a BCP 47 tag for <html lang="…">
Translator::has('app.welcome');                // whether a translation exists
```

The key is `group.entry`, and it may nest deeper (`app.form.title`). **A key
with no translation comes back as it is** — the gap shows up where it is,
rather than rendering an empty page.

A regional language reads its base language before the fallback: with
catalogs in `es` and `en`, `es_MX` reads `es` first and `en` only after — it
used to go straight to `en`.

### Plural

Forms separated by `|`. A form may carry an explicit condition — `{0}` for an
exact number, `[2,4]` for a range, `[5,*]` for an open one:

```php
'items' => '{0} No items|{1} One item|[2,*] :count items',
```

```php
trans_choice('app.items', 0);   // No items
trans_choice('app.items', 1);   // One item
trans_choice('app.items', 5);   // 5 items
```

Without a condition, the language's rule chooses: the first form for one, the
second for everything else. A count that no explicit condition covers — zero
against `{1}…|[2,*]…` — takes the last form, the general one, rather than the
whole string with its bars and brackets.

#### When ranges are not enough

Ranges cover most languages, **but not all**. Polish picks its form from the
last digits rather than from a range: 22 and 12 take different forms although
both exceed five. Arabic has six forms; Russian has three.

Doing that correctly needs the CLDR pluralisation data, which is what the
`intl` extension carries. Since `intl` is optional and the framework has zero
dependencies, embedding an incomplete copy of those rules would mean being
**silently wrong** for exactly those languages. Instead, the rule is a hook:

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

Whoever knows the language writes its rule. That is the honest trade: the
framework does not pretend to know what it does not.

### Choosing the request's language

The `SetLocale` middleware resolves the language once, at the edge:

```php
$router = (new Router($container))->middleware(
    new SetLocale(APP_LOCALES, APP_LOCALE),
    // ...
);
```

It reads `Accept-Language`, respecting the quality values
(`pt-BR,pt;q=0.9,en;q=0.8`), drops anything sent with `q=0`, and picks the best
match between what the client asked for and what the application offers. Asking
for `pt` and being served `pt_BR` beats being served English, so that happens.

It also adds the `Content-Language` header and `Vary: Accept-Language`, so a
shared cache keys the page on the language instead of serving the first
visitor's to everyone after them. The framework's error pages declare the
negotiated `lang` on the document — before, they said `lang="en"` regardless of
content:

```html
<html lang="pt-BR">   <!-- follows the negotiated language -->
```

That is not cosmetic: screen readers choose pronunciation from `lang`, and the
browser uses the attribute to decide whether to offer a translation.

Registering it **globally** matters for two reasons.

The first: a request that matches no route never reaches a controller, and that
is the only reason a 404 can come out in the visitor's language.

The second appears under a persistent runtime (Swoole, FrankenPHP). The
translator holds the active language in a `static`, so a worker that answered a
Portuguese request **would answer the next one in Portuguese** unless something
reset it. This middleware is what resets it. Build the pipeline without it and
call `Translator::setLocale()` from inside a controller instead, and the
language leaks from one visitor's request into the next.

It is the same class of care that kept the *identity map* out of the model
layer: static state in a process serving many requests needs an explicit owner
that resets it.

Straight from the request, when you need it:

```php
$request->acceptedLanguages();                      // ['pt-BR', 'pt', 'en']
$request->preferredLanguage(['en', 'pt_BR'], 'en'); // 'pt_BR'
$request->attribute('locale');                      // set by SetLocale
```

### Configuration

```ini
APP_LOCALE=en
APP_LOCALES=en,pt_BR,es
```

`APP_LOCALE` is the language used when the client asks for none of the ones the
application offers; `APP_LOCALES` are the ones it offers, in order of
preference. With no configuration, `APP_LOCALE` is `en` and `APP_LOCALES` is
`en,pt_BR,es`: the three languages the framework ships catalogs for.

The application's `lang/` path is registered by `Bootstrap::load()`, which every
entry point calls — so the CLI, a queue worker and the test suite all see the
same messages a web request would.

### Validation

`Validator` messages come from the catalog, and a message passed in by the
caller still wins untouched:

```php
Validator::validate($data, ['name' => 'required|min:5']);
// en:    "name is required."   / "name must be at least 5 characters long."
// pt_BR: "name é obrigatório." / "name deve ter ao menos 5 caracteres."
//        "nome é obrigatório." once validation.attributes names the field

Validator::validate($data, ['name' => 'required'], [
    'name' => ['required' => 'Please enter your name.'],   // wins
]);
```

The length rules inflect by number, so `min:1` says "at least one character"
rather than "at least 1 characters".

### What stays in English on purpose

Only text that reaches an **end user** goes through the translator. Exceptions
aimed at whoever is writing the code — the CLI, the query builder, the schema
builder, the container — stay in English:

```
Unknown validation rule "nosuchrule" for field "name".
Cannot resolve parameter $foo in App\Service. Bind a service or provide a default value.
```

They are read in a stack trace or a log, by a developer, and translating them
would make searching for one harder rather than easier.

### What is missing

| Missing | Situation |
|---|---|
| Embedded CLDR plural rules | Would need `ext-intl` or a copy of the data. `pluralizer()` is the hook |
| CLDR-accurate formatting without `ext-intl` | `Time::localised()` and `Time::number()` use the extension when it is there and degrade when it is not |
| Route translation (`/products` ↔ `/produtos`) | Does not exist |
| String extraction into catalogs | No command scans the code |
| Text direction (RTL) | A template decision, not the translator's |

---

## Time and time zones

Everything the framework stores, computes and logs is **UTC**.

```php
now();                                   // the current instant, in UTC
Time::now();                             // the same thing
Time::parse('2026-09-21 23:00:00');      // a stored value, read as UTC
Time::in($order->created_at, 'Asia/Tokyo');   // the same instant, seen there
Time::display($order->created_at);       // rendered in APP_TIMEZONE
Time::toDatabase($instant);              // the UTC value a column holds
```

### Why this one is strict

A naive `2026-09-21 23:00:00` in a database column is only an instant if
something says which zone wrote it. When that answer is "whatever the server
was set to", moving the server — or adding a second one — silently changes what
every existing row means.

The damage is **retroactive**, and that is what makes this different from a
missing feature. A feature can be added later. A year of timestamps written in
an unknown zone cannot be repaired later, because the information needed to
repair them was never written down.

So the runtime zone is UTC and **is not configurable**. A setting that changes
how stored timestamps are interpreted is a setting that can rewrite the meaning
of existing data, which is not a knob worth offering.

### Showing a time to a person

That is a separate decision, made where the value is rendered rather than where
it is stored:

```php
Time::display($order->created_at);                 // APP_TIMEZONE
Time::display($order->created_at, 'd/m/Y H:i');
Time::in($order->created_at, $user->timezone);     // per user
```

```ini
APP_TIMEZONE=America/Sao_Paulo
```

`APP_TIMEZONE` decides how times are **shown**. It does not decide how they are
stored, and changing it does not change a single row.

### In the visitor's language

`Time::display()` takes a `date()` format, which is fixed text: `d/m/Y` is
wrong for an American reader and `F` prints "September" to someone reading
Portuguese. For anything a visitor reads, ask for a style instead of a format
and let the locale decide the order and the words:

```php
Time::localised($order->created_at);                        // Sep 21, 2026, 10:00 AM
Time::localised($order->created_at, 'full', 'none');        // Monday, September 21, 2026
Time::localised($order->created_at, 'short', 'short', 'pt-BR');  // 21/09/2026, 10:00
Time::number(1234.56, 2);                                   // 1,234.56 — or 1.234,56 in pt-BR
```

Both read the active locale when none is given, so a page already running under
`SetLocale` needs no argument. The styles are `none`, `short`, `medium`, `long`
and `full`, for the date and for the time independently.

`Time::number()` is here rather than in the translator because the separators
swap: 1.234,56 in Portuguese against 1,234.56 in English. Printing one for the
other is not a cosmetic difference — it reads as a different number.

> **With `ext-intl` these are correct; without it they degrade.** The extension
> is what carries the CLDR data, so the framework uses it when it is there and
> falls back to an ISO-ish date and a separator guessed from the language when
> it is not — the same arrangement `Str` has with mbstring. The fallback gets
> the long tail wrong, but it gets it wrong as a readable number in the wrong
> convention, never as a wrong number.

### Reading values in

`Time::parse()` takes what a database, a form or an API gives it:

| Given | Read as |
|---|---|
| A string with an offset or zone (`2026-09-21T10:00:00+02:00`) | That instant, converted to UTC |
| A naive string (`2026-09-21 23:00:00`) | UTC, because that is what the framework wrote |
| A naive string with a zone named in the second argument | That zone, converted to UTC |
| A Unix timestamp | Already an instant; no zone to guess |
| A `DateTimeInterface` in any zone | Converted to UTC |
| Anything that is not a date as written | `null`, rather than an exception |

A string is read only in the shapes a database, a date input and ISO 8601
produce — a date, optionally a time, optionally an offset or a zone. PHP's own
parser takes far more: `next monday` and `1 week ago` were dates, and
`2026-02-30` quietly became the 2nd of March. Those are `null` now.

### Model attributes

The `datetime` and `date` casts go through the same rules, in both directions:

```php
$article->published_at;              // DateTimeImmutable, always UTC
$article->toArray()['published_at']; // "2026-09-21T23:00:00+00:00"

// 08:00 in Tokyo is stored as the instant it names, not as the wall clock
$article->published_at = new DateTimeImmutable('2026-09-22 08:00', new DateTimeZone('Asia/Tokyo'));
// stored: 2026-09-21 23:00:00
```

The JSON form carries the offset, so a consumer cannot guess the zone wrongly
— which is the same reason the value is UTC in the first place.

### The database has a clock too

PHP being on UTC is only half of it. `CURRENT_TIMESTAMP` reads the database
server's clock, so a `useCurrent()` default or an `ON UPDATE` trigger writes in
whatever zone **that** machine is set to. Leave the two disagreeing and one
column ends up holding two different meanings, with nothing in the data saying
which row is which.

The connection therefore puts its own session on UTC:

| Driver | Statement |
|---|---|
| MySQL | `SET time_zone = '+00:00'` |
| PostgreSQL | `SET TIME ZONE 'UTC'` |
| Oracle | `ALTER SESSION SET TIME_ZONE = '+00:00'` |
| Others | Left alone — set the session zone yourself, or keep the server on UTC |

Only the session is changed, never the server: a connection stating what it
expects is correct, and a library reconfiguring a shared database for every
other client on it is not. A driver that refuses the statement is logged as a
warning rather than refused, because a timestamp inconsistency should not become
an outage.

### Testing

A test that asserts on "now" races the clock. The clock can be held still:

```php
Time::freeze('2026-01-01T12:00:00+00:00');
// ... now() returns that instant
Time::unfreeze();
```

**For tests only.** The frozen value is static, so under a persistent runtime it
would outlive the request that set it and every later request would be told the
wrong time.

### What is missing

| Missing | Situation |
|---|---|
| CLDR data of its own | `Time::localised()` reads it from `ext-intl`; without the extension the fallback is ISO-ish rather than wrong |
| Relative times ("3 hours ago") | Not provided; the phrasing is per language and belongs to the application |
| A per-user zone column | `Time::in()` takes one; where the user's zone is stored is the application's decision |

---

## UTF-8 strings

`Str` provides the string operations plain PHP only does by byte.

```php
use SfphpProject\src\Str;

Str::length('日本語');                  // 3, not 9
Str::substr('日本語', 1, 1);            // 本
Str::truncate('日本語テキスト', 5);      // 日本...
Str::reverse('日本語');                 // 語本日
Str::isAlpha('José');                   // true
Str::isAlpha('Владимир');               // true
Str::isAlphanumeric('José99');          // true
Str::isNumeric('123');                  // true
Str::isNumeric('١٢٣');                  // false — would not survive (int)
Str::isUtf8($value);
Str::upper('ação');  Str::lower('AÇÃO');  Str::ucfirst('ação');
```

Built on **PCRE with `/u`**, which is always compiled into PHP, for length,
validation and matching. Case conversion needs the Unicode tables PCRE does not
expose, so it uses `mbstring` — a required extension. The ASCII folding that
remains in the code is for a runtime that lacks the extension anyway: it
degrades a display detail rather than corrupting data.

---

## Authentication

Three pieces, separate on purpose:

- a **provider** says where users are looked up;
- a **guard** says how a request proves who it is;
- **`Auth`** ties the two together and holds the resolved user.

Separating provider from guard is what lets the same login flow work over a
table, an LDAP directory or an in-memory list in a test.

### The user contract

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

Three methods, because that is all the framework needs to know: how the key is
named, what the key is, and what to compare a password against. Name, e-mail
and roles belong to your application, and the framework never reads them.

### Configuring

```php
use SfphpProject\src\Auth\{Auth, ModelUserProvider, SessionGuard, TokenGuard};

Auth::provider(new ModelUserProvider(User::class));
Auth::guard('web', new SessionGuard(Auth::provider()));
Auth::guard('api', new TokenGuard(Auth::provider()));
Auth::setDefaultGuard('web');
```

### Signing in and out

```php
if (Auth::attempt(['email' => $email, 'password' => $password])) {
    return Response::redirect('/dashboard');
}

return Response::sfht('login', ['error' => __('auth.failed')]);
```

```php
Auth::user();        // Authenticatable|null
Auth::check();       // bool
Auth::guest();       // bool
Auth::id();          // the key, or null
Auth::login($user);  // without checking a password
Auth::logout();
```

Inside a controller the user also arrives on the request:

```php
public function dashboard(Request $request): Response
{
    return Response::sfht('dashboard', ['user' => $request->user()]);
}
```

### Passwords

```php
use SfphpProject\src\Auth\Hash;

Hash::make($password);                // to store
Hash::check($password, $storedHash);  // to verify
Hash::needsRehash($storedHash);       // to upgrade
```

A thin wrapper over PHP's `password_hash()`, on purpose: it already picks a
sound algorithm, generates the salt and encodes the parameters into the result.
Writing anything cleverer here would be a step backwards.

It uses `PASSWORD_DEFAULT` rather than naming an algorithm, so a PHP upgrade
that adopts a better default is picked up automatically for new passwords. Old
ones catch up with `needsRehash()`, right after a successful login — the only
moment a password's algorithm can be upgraded without asking the user to type
it again:

```php
if (Auth::attempt($credentials)) {
    $user = Auth::user();

    if (Hash::needsRehash($user->getAuthPassword())) {
        $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
    }
}
```

### The middleware

```php
// Global: identifies whoever it can, lets anonymous requests carry on
$router->middleware(new Authenticate('web'));

// Per route: refuses an anonymous request
Router::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(new Authenticate('web', required: true));

// An API uses the token guard
Router::group('/api', function (): void {
    Router::get('/me', [ProfileController::class, 'me']);
}, 'api.', [new Authenticate('api', required: true)]);
```

When refusing, it answers **401** to a client expecting JSON and **redirects to
the login page** for a browser — `/login` unless `AUTH_LOGIN_PATH` says
otherwise, or `new Authenticate('web', required: true, loginPath: '/signin')`
for one route. The redirect is deliberate: a 401 with no
`WWW-Authenticate` header makes some browsers open their own credentials
prompt, which is not your application's form.

> **Under a persistent runtime this middleware is mandatory.** `Auth` holds the
> resolved user in a `static` so that asking twice does not query twice. In a
> worker serving many requests, that same `static` would carry one visitor's
> identity into the next request. The middleware calls `Auth::forgetUser()` at
> the start of every request and is the explicit owner of that reset — exactly
> as `SetLocale` is for the active language.

### Two guards, two natures

| | `SessionGuard` | `TokenGuard` |
|---|---|---|
| Proof | session cookie | `Authorization: Bearer` |
| State | on the server | none |
| Suits | pages | APIs, workers, another process |
| Revoke before expiry | yes, delete the session | yes, through the denylist |
| `Auth::login()` | yes | no — throws |

`SessionGuard` stores **only the identifier** in the session, never the user.
Serialising the model would freeze a copy of the row: someone whose permissions
were revoked would keep them until the session expired, and renaming a column
would break deserialisation of every live session.

It also **regenerates the session id** on login and on logout. On login that is
what stops session fixation: an attacker who planted a known id beforehand
cannot use it afterwards, because the id the victim ends up with is new. The
CSRF token is replaced at the same moment, since the one issued to the
anonymous session may have been seen by whoever planted it. Logging out empties
the session — not only the user id, but anything else the application kept for
that user — so the next person at the same browser starts from nothing.

`TokenGuard` stores nothing on the server, which is what makes it usable
outside a web request. That is also what makes revocation something you have to
decide about: a token is accepted because its signature is valid, so nothing
about the token itself can take it back.

### Revoking a token

```php
use SfphpProject\src\Auth\TokenDenylist;

TokenDenylist::revoke($token);          // this one token
TokenDenylist::revokeUser($user->id);   // every token issued before now
```

The only way to revoke a stateless token is to stop being stateless about the
ones you have revoked, and the trade is worth seeing plainly: the guard now asks
the cache on every request, so a token is no longer free to verify.

What keeps that cheap is that a revoked token only has to be remembered until it
would have expired anyway. A list of everything ever revoked would grow forever;
this one is entries with a lifetime, so it stays the size of "revoked recently".

`revokeUser()` is "log out everywhere", keyed by the claim the token guard
identifies users by — `id` unless the guard was built with another. It cannot list the user's tokens —
nothing ever recorded them — so it records the moment instead, and a token whose
`iat` is older than that moment is refused. A login *after* it keeps working,
which is what stops logging out everywhere from locking someone out of logging
back in.

Tokens are stored hashed, never whole: a cache someone can read — a shared
Redis, a dump taken while debugging — would otherwise hand out working
credentials for every token that has not expired yet.

> **The denylist must be shared between instances.** It lives in the cache, so
> with the default `CACHE_DRIVER=file` it is local to one machine and a token
> revoked on one instance still works on another. `CACHE_DRIVER=redis` is the
> whole fix. Elsewhere a per-instance cache is a performance choice; here it is
> a hole.

Checking can be turned off per guard, for a service where tokens are short
enough that the extra read is not worth it:

```php
$guard = new TokenGuard($provider, 'id', checkRevocation: false);
```

### Remembering a login

```php
use SfphpProject\src\Auth\RememberToken;

$token = RememberToken::issue();
// ['cookie' => 'selector:verifier', 'selector' => ..., 'hash' => ..., 'expires' => ...]
```

A remember cookie is a password that never expires and that the user does not
know they have, so its shape matters. The cookie carries a **selector** in the
clear, which is the lookup key, and a **verifier**, which is stored only as a
sha256 hash. A database someone reads therefore does not hand them working
cookies, and finding the row still costs one indexed lookup rather than a scan.

```php
$parts = RememberToken::parse($_COOKIE['remember'] ?? '');

if ($parts !== null && RememberToken::matches($parts['verifier'], $row->remember_token)) {
    // Log the user in, then issue a new token: a cookie works exactly once.
}
```

Rotating on every use is what limits the damage. If a stolen cookie is used, the
real user's next request fails and the theft becomes visible, instead of two
people sharing an account quietly for a month.

### Authorization

```php
use SfphpProject\src\Auth\Gate;

Gate::policy(Post::class, PostPolicy::class);
Gate::define('access-admin', fn (?Authenticatable $u): bool
    => $u !== null && $u->role === 'admin');
```

```php
Gate::allows('update', $post);     // calls PostPolicy::update($user, $post)
Gate::denies('update', $post);
Gate::authorize('update', $post);  // throws AuthorizationException — answered with 403
Gate::forUser($other, 'update', $post);
```

```bash
./sfphp make:policy Post       # writes PostPolicy, every ability denying until you write it
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

Two decisions worth knowing:

**An ability nobody declared is denied.** Allowing by default would let a typo
in an ability name silently open a door.

**A policy receives `null` when the request is anonymous**, rather than being
refused beforehand. That is what lets a public rule — reading a published post,
say — live alongside the others in the same place.

`AuthorizationException` is distinct from being unauthenticated: it means the
framework knows who you are and the answer is still no. One is **403**, the
other **401**. Left uncaught, `Gate::authorize()` becomes that 403 by itself —
see [Error handling](#error-handling).

### Account enumeration

`Auth::attempt()` verifies a password **even when no user matched**, against a
throwaway hash. Without it, a login attempt for a non-existent account would
return faster than one for an existing account with the wrong password — and
that difference is enough to discover which accounts exist.

The throwaway hash must have been generated with the same parameters
`password_hash()` uses today. PHP 8.4 raised bcrypt's default cost from 10 to
12, and a hash left at 10 verifies about four times faster than a real one —
which would reopen exactly the difference it exists to hide. A test asserts the
constant does not need rehashing, so a future change to PHP's default is caught
by CI.

### Messages

`auth.failed`, `auth.unauthenticated`, `auth.unauthorized` and
`auth.logged_out` ship in the three languages the framework carries. See
[Internationalisation](#internationalisation).

### What is missing

| Missing | Situation |
|---|---|
| The "remember me" flow | `RememberToken` issues and verifies the cookie; reading it on a request and reissuing it is the application's |
| Password recovery | No token table, no e-mail flow |
| E-mail verification | The `email_verified_at` column exists; the flow does not |
| Two-factor | Does not exist |
| Roles and permissions | `Gate` decides; storing roles is your application's job |

Rate limiting on the login form **does** exist — see [Security](#security).

---

## Security

What the framework does by default, what needs configuring, and what it
deliberately does not do.

### Mass assignment

A model can only be filled from an array after declaring **which columns** it
accepts:

```php
final class User extends Model
{
    protected static array $fillable = ['name', 'email'];
}
```

Without the list, filling throws `MassAssignmentException`. That is deliberate,
and the reason is the most natural line anyone writes:

```php
User::create($request->all());
```

With no list, that stores **every column the attacker chose to send**. A
registration form that never showed an `is_admin` field writes one anyway if
the request carries it:

```php
// submitted: name, email, is_admin=1, balance=999999
$user = new User($request->all());
$user->is_admin;   // null — dropped
```

Keys outside the list are **dropped**, not an error, so a form carrying an
extra field a browser added still works. A model that declares nothing, on the
other hand, **fails loudly** the first time it is used — long before it reaches
production.

Allowing by default would protect only the developers who already knew to
declare the list, which is exactly the wrong set of people.

For values the application itself chose:

```php
$user->forceFill(['email_verified_at' => now()]);
```

### Trusted proxies

`X-Forwarded-*` headers are client-controlled: anyone can send them. They mean
something only when the connection comes from a machine known to rewrite them,
so **nothing is trusted** until the deployment says what:

```php
Request::setTrustedProxies(['10.0.0.0/8', '172.16.0.5']);
```

```ini
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.5
```

`public/index.php` reads `TRUSTED_PROXIES` through `Env::get()`, so it is found
whether the variable came from `.env` or from the container's environment — it
used to read `$_ENV` alone, which is empty under the `variables_order` many
images use.

> **Behind a TLS-terminating load balancer this is not a detail.** The PHP
> process sees plain HTTP, so `isSecure()` answers false and **the session
> cookie loses its `secure` flag** — it then travels in the clear as soon as a
> visitor reaches the site over HTTP. Configuring the proxies is what fixes it.

With proxies declared:

```php
$request->ip();         // the client's real address, not the balancer's
$request->isSecure();   // true, reading X-Forwarded-Proto
```

Without them, or coming from outside the trusted range, the headers are
ignored — a visitor cannot forge their own address. That matters the moment
anything rate limits or logs by IP.

With them, `X-Forwarded-For` is read **from the right**. Each proxy appends the
address it received the connection from, so only the entries the trusted proxies
added are facts; the address is the first one, walking back from the end, that
is not a trusted proxy. The left-most entry — what this used to read — is the
one the client wrote itself. `X-Forwarded-Proto` is read the same way: the value
the nearest proxy set.

### Rate limiting

```php
Router::post('/login', [AuthController::class, 'login'])
    ->middleware(new RateLimit(maxAttempts: 5, decaySeconds: 60));
```

Answers **429** with `Retry-After` once the limit is reached, and adds
`X-RateLimit-Limit` and `X-RateLimit-Remaining` to normal responses.

This is what makes the login form's other defences worth having. Equalising the
time a failed attempt takes stops an attacker **discovering which accounts
exist**; it does nothing about simply trying passwords. Without a limit, the
attacker never needs to enumerate anything.

Counters live in the cache, so the limit holds across processes when a shared
driver is configured. An authenticated request counts **per user**, so several
people behind the same office address do not consume each other's allowance.

A limit counts **the route**, not the path that reached it. On
`/reset/code:alphanum` every code used to get a counter of its own, so trying
codes was not limited at all; now `/reset/a`, `/reset/b` and `/reset/c` are the
same bucket for the same client.

The count is an **atomic** `Cache::increment()`, not a read followed by a
write. That distinction is the whole middleware: requests counted with `get()`
and `put()` overwrite one another, so the limit leaks under exactly the
parallel traffic it exists to refuse — an attacker trying passwords opens
several connections at once rather than waiting for each answer. See
[Counters](#counters).

The window is **not** renewed on each attempt: renewing would let a client that
keeps knocking hold its own window open indefinitely, and the counter would
never forgive. `Retry-After` reports the counter's remaining lifetime, so it
counts down towards the window's close instead of restarting on every refusal.

> The client's address is only as trustworthy as the proxy configuration.
> Behind a balancer with no proxies declared, **the whole site shares one
> bucket**.

### Response headers

```php
$router->middleware(new SecurityHeaders());
```

Sent by default:

| Header | Closes |
|---|---|
| `X-Content-Type-Options: nosniff` | An uploaded file served as `text/plain` being executed as JavaScript because its first bytes look like a script |
| `X-Frame-Options: DENY` | Clickjacking — the site being framed invisibly over something the visitor means to click |
| `Referrer-Policy: strict-origin-when-cross-origin` | The full URL, including anything in the query string, leaking to every site a visitor follows a link to |

Two are **off** until asked for:

```php
new SecurityHeaders(
    contentSecurityPolicy: "default-src 'self'; style-src 'self' 'unsafe-inline'",
    hstsMaxAge: 31536000,
    hstsIncludeSubdomains: true,
);
```

**Content-Security-Policy** is the strongest and the easiest to get wrong: a
policy that does not match your own assets breaks the page **with no error the
developer sees**, and the framework cannot know what those assets are.

> Note the `'unsafe-inline'` in `style-src` in the example: the framework's own
> error pages use inline CSS, precisely so they need no network. A policy
> without it leaves the 404 page unstyled.

**Strict-Transport-Security** is off because turning it on is hard to undo — a
browser that has seen it refuses plain HTTP for the whole `max-age`, including
for a site that later has to serve HTTP for some reason. And it is only sent
over HTTPS: a browser ignores HSTS on an insecure connection, so sending it
there would look like protection without being any.

### Session and CSRF

- Cookie with `httponly`, `samesite=Lax` and `secure` when the connection is
  HTTPS — decided by the request, respecting the trusted proxies
- Session id **regenerated on login and on logout**, against session fixation;
  the CSRF token is replaced on login, and logout empties the session
- `session.use_strict_mode` on, so an id PHP never issued is refused rather
  than adopted
- An **idle** and an **absolute** deadline, both enforced in the pipeline — see
  [Sessions](#sessions)
- A store that can be shared between instances, so sessions are not tied to one
  machine
- A 32-byte CSRF token, compared with `hash_equals`
- `VerifyCsrfToken` applies the check **by default** to every state-changing
  request; safe methods pass, and a bearer-token request only when it carries
  no session cookie

### Passwords and login

- `password_hash` with `PASSWORD_DEFAULT`, and `Hash::needsRehash()` to upgrade
  without asking for the password again
- `Auth::attempt()` verifies a password **even with no matching user**, against
  a throwaway hash, so a non-existent account does not answer faster than a
  wrong password
- The session stores **only the identifier**, never the serialised user

### Database

- Every value is bound; none is concatenated
- Every identifier — table, column, alias — is validated against a whitelist
  and quoted for the driver; an invalid one throws rather than reaching the SQL
- `EMULATE_PREPARES => false`, so the driver really prepares
- A failed connection logs the driver's message and throws one naming only the
  missing extension or the driver and host; the password and the driver's own
  text never reach the visitor

### Uploads

- A file is refused unless `is_uploaded_file()` agrees it is one, so a forged
  `$_FILES` cannot make the framework read an arbitrary path
- The media type is read from the file's own bytes, never from the header the
  client sent
- The stored name is generated, with an extension taken from the content and
  never one a server would run; the client's name is stripped of path segments
  and null bytes and used only for display

See [File uploads](#file-uploads), including why the stored file still belongs
outside the document root.

### Output

- SFHT's `{{ }}` escapes by default; raw output takes `{!! !!}`
- `e()` for raw PHP templates
- Exception detail appears only with `APP_ENV=development`
- A model's `$hidden` columns never reach its JSON
- The expressions in SFJS's `@state` cannot reach the prototype chain
- Compiled templates and the file cache live in a private directory of the
  project, never a shared temporary one

### What is missing

| Missing | Situation |
|---|---|
| Password recovery, e-mail verification, 2FA | The flows belong to the application; [Mail](#mail) is the piece the framework owes it |
| A safe default for more than one instance | `CACHE_DRIVER` defaults to `file`, which is right for one machine and wrong for several. The framework cannot tell which you are running, so it says so rather than guessing. See [Choosing the driver](#choosing-the-driver) |
| Storage abstraction for uploads | Files are validated and stored locally; S3 or a shared volume is the application's to arrange. See [File uploads](#file-uploads) |
| Audit logging | Records are structured and carry a request id, but nothing writes a deliberate "who changed what" trail. See [Logging](#logging) |

---

## Sessions

```php
use SfphpProject\src\Session\Session;

Session::put('cart_id', 42);
Session::get('cart_id');
Session::get('absent', 'fallback');
Session::has('cart_id');
Session::forget('cart_id');
Session::all();
Session::regenerate();     // new id, same data
Session::invalidate();     // new id, no data
Session::id();
```

`$_SESSION` still exists and still works, but nothing in the framework touches
it any more. Going through `Session` is what makes the two deadlines below
unavoidable: code that started a session some other way would have skipped
them.

### Two deadlines

```ini
SESSION_LIFETIME=7200             # idle: seconds without a request
SESSION_ABSOLUTE_LIFETIME=43200   # absolute: seconds since the session began
```

Before this existed a session lasted whatever `php.ini` said, which on a shared
host is a number nobody in the application chose.

PHP's own garbage collector deletes a session file older than
`session.gc_maxlifetime` — 1440 seconds by default, so a two-hour idle
deadline used to end after 24 minutes. Starting the session raises that setting
to the longer of the two deadlines. Debian and Ubuntu also clean sessions from a
cron job that reads `php.ini` rather than this setting; there, raise
`session.gc_maxlifetime` in `php.ini` too, or keep sessions in the cache or the
database.

The **idle** timeout closes a session left open on a machine somebody walked
away from. The **absolute** one closes a session that has been alive too long
however busy it has been, and it is the one an audit asks about: it is what
limits how long a stolen cookie is worth having. Either can be turned off with
`0`, and both are enforced by `StartSession`, which is the only place they can
be applied once and cover every route.

When a deadline passes, the data goes **and the id changes with it**. Emptying
the data while keeping the id would leave the visitor holding a cookie that
still names a live session, which is most of what expiring one was meant to
prevent.

```php
if (Session::expiredReason() === 'idle') {
    // show "you were signed out after a period of inactivity"
}
```

That reads once and forgets, so the notice appears on the request after the
expiry rather than on every request from then on.

### Where sessions are stored

```ini
SESSION_DRIVER=native      # native, database, or cache
```

| Driver | Stores in | Use it when |
|---|---|---|
| `native` | PHP's own files | One machine. The default |
| `cache` | The cache, through `CacheManager` | Several instances, with Redis configured |
| `database` | A `sessions` table | Several instances, and you already have a database |

Native files are local to one machine, so two application instances cannot see
each other's sessions. That is what forces sticky sessions on a load balancer,
and it is why a deploy that adds a second machine logs everybody out. A shared
handler removes that, and it is the one change that makes the framework usable
behind more than one process.

`cache` is faster and not durable — a flushed cache is everybody logged out.
`database` costs a read and a write per request on the connection the
application is already using, and survives a restart. With the default file
cache driver, `cache` behaves exactly like `native`: the driver decides that,
not the handler.

The database driver needs a `sessions` table, and the framework does not ship
its migration. Write one — named `create_sessions_table`, which `./sfphp reset`
keeps:

```bash
./sfphp make:migration create_sessions_table
```

```php
$schema->create('sessions', function (Blueprint $table): void {
    $table->string('id', 128)->primary();
    $table->longText('payload');
    $table->unsignedBigInteger('expires_at')->index();   // a Unix timestamp, UTC
});
```

The handler stores the payload base64-encoded: PHP's session format writes NUL
bytes for private and protected properties, and a PostgreSQL text column
refuses them — the write failed and the visitor was signed out. Rows written
before are still read.

```bash
./sfphp migrate
```

A handler can also be passed directly, which is how a deployment plugs in one
of its own:

```php
$router->middleware(new StartSession(new CacheHandler(), 1800, 28800));
```

Any class implementing PHP's own `SessionHandlerInterface` works. Implementing
`SessionUpdateTimestampHandlerInterface` as well — both shipped handlers do —
is what makes the next section work.

### Session fixation

Two defences, and they close different halves of the same attack.

The id is **regenerated on login and on logout**, so an id an attacker planted
beforehand is not the id the victim ends up with.

And `session.use_strict_mode` is now on. Without it PHP adopts whatever id the
cookie carries, including one it never issued — which is the door the attacker
knocks on in the first place. With it, an unknown id is refused and a fresh one
issued:

```
GET / with Cookie: PHPSESSID=an-id-nobody-issued
→ Set-Cookie: PHPSESSID=636dbac9b2f1bc09c3d335c16115bc89
```

This is why a handler should implement `validateId()`: it is how PHP asks the
store whether an id names a session that exists.

### What is missing

| Missing | Situation |
|---|---|
| Listing or revoking another device's session | The `database` driver's table (your own migration) makes it possible to build; nothing ships |
| Periodic id rotation | The id changes on login, on logout and on expiry, not on a timer |
| Encryption at rest | The payload is stored as PHP serialises it (base64 in the database); a database or cache with its own encryption is the answer |
| Starting the session only when it is used | Every request starts one, so every response carries the cookie and `Cache-Control: no-store`. A page a CDN should cache is served from a route group without `StartSession` |
| Flash data | No "keep this for exactly one more request" helper |

---

## CSRF

```php
csrf_token();     // the session token
csrf_field();     // <input type="hidden" name="_token" value="...">
csrf_meta();      // <meta name="csrf-token" content="...">
csrf_verify();    // validates the current request's token
```

```sfht
<form method="post" action="/posts">
    {!! csrf_field() !!}
    <input name="title">
</form>
```

```php
public function store(Request $request): Response
{
    if (!Csrf::validate($request->body('_token'))) {
        return Response::json(['message' => __('http.csrf_message')], HTTP_FORBIDDEN);
    }

    // ...
}
```

The token is 32 bytes from `random_bytes`, compared with `hash_equals` in
constant time, and the session uses `httponly`, `samesite=Lax` and `secure`
over HTTPS. The token is accepted from the `_token` field of a form, from
`_token` in a JSON body, or from the `X-CSRF-Token` / `X-XSRF-Token` headers.

SFJS sends a form as JSON and adds the `X-CSRF-Token` header from
`<meta name="csrf-token">`, so a page that uses SFJS puts `{!! csrf_meta() !!}`
in its `<head>`; a form with `csrf_field()` works either way. The example
application's layouts have both.

`csrf_verify()` is the legacy helper and reads the superglobals itself; inside
an action, check the request you were given, as above.

In practice you rarely call `csrf_verify()` yourself: the `VerifyCsrfToken`
middleware applies the check by default. See [Middleware](#middleware).

---

## JWT

```php
use SfphpProject\src\JWT;

$token = JWT::generate(['id' => 1]);
$token = JWT::generate(['id' => 1, 'role' => 'editor']);   // any other claim travels as given

if (JWT::validate($token)) {
    // the token is intact and unexpired
}

$claims = JWT::claims($token);   // validates and returns the payload, or null
```

What the signature enforces:

- `generate()` **requires** the `id` claim — what `TokenGuard` looks the user up
  by — and throws `InvalidArgumentException` without it. Anything else is
  optional: an e-mail used to be required, which put personal data in every
  token, and a token is signed, not encrypted. `iat` and `exp` are the
  framework's own and cannot be overridden
- `validate()` returns a **`bool`**, not the claims, and does not throw for an
  invalid token
- `claims()` validates and returns the payload in one pass, which is what a
  guard needs — checking the signature separately would mean verifying twice,
  or reading a payload that was never verified
- It validates the signature, `alg` (only `HS256`), `typ` and `exp`.
  `alg: none` is rejected
- Expiry is fixed at one hour
- `JWT_KEY` must be at least 32 bytes; the placeholder in `.env-example` is
  refused on purpose

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

---

## Debugging

```php
dump($order);              // show it and carry on — in the page the action returns
dump($a, $b, $c);          // several at once
dd($request->all());       // show it and stop
```

`dd()` **replaces the response** with a page showing only what was dumped. That
is the difference from printing a value into the page you were already
rendering: you asked to stop and look, so what you are looking at is not mixed
in with a half-finished layout.

The screen is built from SFCSS — the same stylesheet an application writes its
own pages with — and the stylesheet is inlined rather than linked, because a
screen the framework renders has to render when the application around it is
what is broken.

What it shows, and why each part is there:

| | |
|---|---|
| The line that called it | A dump you cannot locate is a riddle. `app/controllers/OrderController.php:42` |
| Property visibility | `private $token` read as public sends you looking in the wrong place |
| String length | A value that looks right and is 11 characters when you expected 10 is the bug |
| `already shown above` | A value that points at itself is reported rather than followed |
| `uninitialised` | A typed property that was never assigned. Reading it throws; that state is usually the answer being looked for |
| `only the first 200 shown` | What was cut is stated. A truncated dump that admits it beats a browser that stops responding |

Branches collapse. They use `<details>`, so collapsing works with no script at
all — including behind a Content-Security-Policy that blocks inline scripts.

`dump()` in a request waits for the response. It used to print on the spot,
ahead of the headers, and the page it was meant to sit in was lost: the browser
got the dump and nothing else. Now an HTML page gets the dumps just before
`</body>`; a JSON body, a file or any other response is left untouched and the
dump goes to the log at `debug` level. Inside a stream's producer, where the
response has already started, it is written where it happens.

### In a terminal

```bash
./sfphp queue:work
```

A queue worker, a console command and a test run have no browser. There the
same dump is written to standard output as indented text, coloured with ANSI
when the output is a terminal and plain when it is redirected or piped — escape
codes in a file you are about to `grep` are noise.

### In production

```php
dd($user);   // APP_ENV=production
```

The dump is **written to the log** and the visitor gets the ordinary error page.
`dd()` still stops, by throwing.

A dump handed to a visitor shows whatever was passed to it: a user record, the
request headers, a configuration array. Working the same way in every
environment would mean a forgotten `dd()` is a data leak; this way it is an
entry in your log and a 500 for them. The log goes through `LogManager`, so
passwords and tokens are redacted on the way.

`dump()` in production writes to the log too, and does not stop.

---

## Async

Two things share one word, and keeping them apart is the whole of understanding
this:

- **Async scheduling** — an operation is a value the runtime can hold, pass
  around, combine and await. Everything here gets this.
- **Non-blocking I/O** — while the operation waits, the process does other work.
  **HTTP requests and timers get this. Database queries do not.**

The framework will not pretend otherwise, because an API that says `await()` and
blocks anyway teaches you something false about your own program.

### Starting work, and waiting for it

```php
use function SfphpProject\src\Async\async;
use function SfphpProject\src\Async\await;
use function SfphpProject\src\Async\delay;

$a = Http::getAsync('https://billing.internal/invoices/7');
$b = Http::getAsync('https://catalog.internal/products/42');

[$invoice, $product] = [await($a), await($b)];
```

Each request is on the wire as soon as it is created, so the two overlap and
this costs about as long as the slower one. `async()` does the same for your own
code: it runs as a task, alongside whatever else is running.

```php
$task = async(fn () => await(Http::getAsync($url))->json());

$data = await($task);
```

`await()` never polls. Inside a task it parks the Fiber and the scheduler
resumes it once the result is there; outside one it drives the event loop, so
everything else pending keeps progressing. The process waits in a single
`select()` over every outstanding transfer and the nearest timer.

### Several at once

```php
use SfphpProject\src\Async\CompositeFuture;

[$first, $second, $third] = await(CompositeFuture::all(
    Http::getAsync($one),
    Http::getAsync($two),
    Http::getAsync($three),
));

$fastest = await(CompositeFuture::race($primary, $mirror));
```

`all()` settles when every part has, with the values in the order given, and
rejects with the first failure. `race()` settles with the first to finish.

Whether the parts overlap is decided by the parts: they listen, they do not
start anything. Three `Http::getAsync()` overlap because each was already in
flight.

### Deadlines

```php
$response = await(Http::getAsync($url), timeout: 5000);
```

The deadline is a timer in the event loop. When it passes, the transfer is
cancelled — the socket is released, not left to finish into an answer nobody
will read — and `TimeoutException` is raised.

### Delays

```php
await(delay(250));
```

A deadline the loop wakes for, not a `usleep()`. Three delays of 250 ms awaited
together take 250 ms, and every request in flight keeps progressing through
them.

### What a Future is

| | |
|---|---|
| `isPending()` | has not settled |
| `isResolved()` / `getValue()` | settled with a value |
| `isRejected()` / `getException()` | settled with an exception |
| `isCancelled()` | called off |
| `onResolve()` | run something when it settles |

Settled is final. Reading a value that has not arrived throws rather than
returning `null`.

### Queries: scheduled, not overlapped

```php
$users = await(User::query()->where('active', true)->getAsync());
```

This works, and it blocks. PDO has no asynchronous API: `execute()` waits for
the server and no Fiber changes that. Awaiting three queries together takes as
long as three queries — measured at 609 ms against 603 ms for the plain
synchronous version.

The shape is worth keeping anyway. `ext-mysqli` on mysqlnd (`MYSQLI_ASYNC`,
`mysqli_poll()`) and `ext-pgsql` (`pg_send_query()`, `pg_socket()`) both hand
out a socket the event loop can already watch, so a backend written against
either would settle these Futures from the loop and this line would not change.

### Components can await

```php
function UserPanel(string $url): Sfht
{
    $data = await(Http::getAsync($url))->json();

    return Sfht(
        <div class="card"><p>{{ $data['name'] }}</p></div>
    );
}
```

A `.phpx` component is a function, so it suspends and resumes like anything
else. Suspending is not overlapping, though: a template renders its components
one after another, so two components each awaiting a 300 ms request take about
600 ms (measured: 604 ms). Rendered inside `async()` tasks and awaited together,
the same two take about 300 ms (measured: 301 ms).

> The full guide — every Future type, the scheduler, `EnableAsync`, what blocks
> and what does not — is [ASYNC.md](./ASYNC.md).

---

## Error handling

An exception thrown inside an action is caught by the router, at a boundary
that sits **outside** the pipeline. `ErrorHandler::toResponse()` is the shared
renderer.

Outside matters, and this page used to claim the opposite. A middleware's
outgoing half never runs for a failed request: the exception unwinds past it,
so a header it would have added is not added. That is why the router attaches
`X-Request-Id` to the error response itself — see [Logging](#logging) — and it
is worth knowing before writing a middleware that assumes it always gets its
turn on the way out.

The boundary is deliberately not a middleware. A middleware can be registered
in the wrong order and quietly stop catching anything; a `try/catch` around the
pipeline structurally cannot.

The global registration (`ErrorHandler::register()`) stays, because it covers
what a `try/catch` cannot reach: a warning during bootstrap, and a fatal
reported at shutdown — out of memory, exceeded execution time, a parse error in
an included file. Without it, those become blank pages.

On both paths the response is:

- **The exception's own status** when it has one, and **500** otherwise
- A negotiated `Content-Type` — JSON if the request asked for or sent JSON,
  HTML otherwise
- The real message **only** with `APP_ENV=development`; in production, the
  translated message for the status — except for an `HttpException` below 500,
  whose message describes what the client did and is shown
- The detail always goes to the logger, with the request id attached — see
  [Logging](#logging). A 4xx an exception asked for is recorded as information,
  not as an error

An exception names its status by implementing `HttpStatus`. The framework's own
do:

| Thrown | Answered with |
|---|---|
| `ModelNotFoundException` — from `findOrFail()` | 404 |
| `AuthorizationException` — from `Gate::authorize()` | 403 |
| `InvalidJsonException` — from `$request->json()` on a malformed body | 400 |
| `HttpException(409, 'That slug is taken.')` | whatever it was given |

```php
use SfphpProject\src\Http\HttpException;

throw new HttpException(404);
throw new HttpException(409, 'That slug is taken.');
```

Every error the framework answers — 400, 401, 403, 404, 405, 429, 500, 503 —
goes through one page, `ErrorPage`: the status, the message, a link home, in
the visitor's language, or the same as JSON. It inlines only the part of SFCSS
it uses, about 11 KB rather than the whole stylesheet, makes no external request
and respects `prefers-color-scheme`. The CSRF and rate-limit refusals used to be
bare unstyled pages of their own.

A PHP deprecation is logged as a warning and the request carries on. It used to
become an exception, so upgrading PHP or a library turned working pages into
500s.

---

## Logging

One JSON object per line, timestamped in UTC.

```php
logger()->info('order placed', ['order_id' => $order->id]);
logger()->warning('payment retried', ['attempt' => 3]);
logger()->error('gateway refused', ['code' => $code]);
logger()->exception($throwable);
```

```json
{"timestamp":"2026-09-21T23:34:46.472Z","level":"info","message":"request handled","context":{"request_id":"cc13917b45e763edf3476b9e02818b09","method":"GET","path":"/","ip":"127.0.0.1","status":200,"duration_ms":3.488}}
```

JSON rather than a sentence, because a log line is read by a program before a
person reads it: any collector parses this, and a field can be filtered on
without a regular expression that breaks the first time a message contains a
colon. UTC, because lines carrying local time cannot be put in order, and once
there are two machines that ordering is the only thing that makes the logs
worth keeping.

### Levels

The eight of RFC 5424, which is what PSR-3 uses as well — `debug`, `info`,
`notice`, `warning`, `error`, `critical`, `alert`, `emergency`. Matching those
names matters even without depending on the package: every collector already
sorts records by them.

Anything below `LOG_LEVEL` is dropped before it reaches the driver, so a
`debug()` call in a hot path costs a comparison in production rather than a
write.

### Configuration

```ini
LOG_CHANNEL=stream          # stream (the default), error_log, or null
LOG_PATH=php://stderr       # a stream or a file, for the stream channel
LOG_LEVEL=info              # debug in development, info otherwise
```

`stderr` is the default because it needs no directory to exist and no
permission to be granted, and it is where a container expects to find an
application's logs. A path works too, and its directory is created if missing.
A path that cannot be opened — a directory the web server may not write — does
not take the application down: records go to PHP's own error log instead, with
a note the first time saying why. It used to throw, and since every request
logs, every request was a 500.

`error_log` writes through PHP's own error log, for a deployment where
something already collects that. `null` discards, which is what the test suite
uses so that deliberate failures do not bury the output in stack traces.

### The request id

This is the point of the whole section. A production failure is never one line:
it is the request that came in, the query that was slow and the exception that
came out, written at different moments and interleaved with every other request
the server was handling. Without something joining them, reading the log is
guesswork.

```php
$router->middleware(new LogRequests());
```

That middleware gives every request an id and puts it in four places: the
shared log context, so every line written afterwards carries it; the request
itself, as the `request_id` attribute; the response, as `X-Request-Id`; and the
record it writes when the request finishes, with the status and the duration.

Because it reaches the response, the id is on the visitor's screen when
something breaks — a support ticket can carry the one string that finds
everything.

An inbound `X-Request-Id` is honoured, which is how a trace follows a request
from one service into the next. It is also client-controlled input going
straight into the logs, so it must match `[A-Za-z0-9._-]{1,128}`: unbounded
length turns a log into a disk bill, and control characters turn a log viewer
into something that no longer shows what it says it shows. An id that does not
match is replaced rather than refused, because the request itself is not the
problem.

**Register it first**, or as close to first as the pipeline allows. Only what
runs after it is covered, and a request that matches no route never reaches a
controller — a 404 is worth having logs for.

> **Under a persistent runtime this middleware is mandatory.** The shared
> context lives in an object that outlives a request in a Swoole or FrankenPHP
> worker, so one visitor's id would follow the next visitor's logs. It calls
> `forgetContext()` at the start of every request and is the explicit owner of
> that reset — exactly as `SetLocale` is for the active language and
> `Authenticate` for the user.

### Failures

A failing request is reported **once**, by the router's own boundary rather
than by the middleware. The boundary is guaranteed to run and a middleware can
be registered in the wrong order, so logging in both would mean a duplicate
whenever both were present and nothing whenever neither was.

The record still carries the request id, because an exception unwinding the
pipeline does not touch the shared context. It carries the exception class, the
file, the line and the trace as separate fields, so a collector can group by
class without parsing a message.

An exception skips the rest of the pipeline, so the middleware never gets its
turn to add the response header. The router attaches it instead: the visitor
who sees a 500 is the person most in need of the id.

### Secrets

Structured logging invites passing whole arrays through, and the body of a
login form is the first array anyone reaches for. Values under these keys are
replaced with `[redacted]`, at any depth:

`password` `password_confirmation` `current_password` `new_password` `passwd`
`secret` `client_secret` `private_key` `token` `_token` `access_token`
`refresh_token` `remember_token` `api_key` `apikey` `api-key` `x-api-key`
`x-csrf-token` `x-xsrf-token` `authorization` `auth` `cookie` `set-cookie`
`credit_card` `card_number` `cvv` `ssn` `cpf`

An object in the context is written as its fields and scrubbed the same way, so
a model or a DTO passed whole does not carry its password past the check.

```php
logger()->redact('pin', 'account_number');
```

Redacting by key is crude, and it is the difference between a password reaching
a log aggregator and not.

### What is missing

| Missing | Situation |
|---|---|
| Tracing | A request id ties one request's records together; following a call across services needs a trace id propagated between them |
| Sampling | Every record that passes the level is written; there is no "one in a hundred" |
| Several destinations at once | One driver at a time — no fan-out to a file and a collector together |
| Log rotation | The file grows; rotation belongs to `logrotate` or the platform |

---

## Health and metrics

### Health

A load balancer needs somewhere to ask whether sending traffic here will work,
and "the process is running" is the wrong question: an instance whose database
is unreachable still accepts connections and still serves errors to everyone
routed to it.

```php
use SfphpProject\src\Health;

Health::registerDefaults(['database', 'cache']);
Health::register('payments', fn (): bool => $gateway->ping());

$report = Health::check();
// ['healthy' => true, 'checks' => ['database' => ['ok' => true, 'ms' => 1.4], ...]]
```

The framework ships the checks and not the route, because where it lives and who
may see it are the application's to decide:

```php
Router::get('/health', [HealthController::class, 'show']);

public function show(Request $request): Response
{
    $report = Health::check();

    return Response::json($report, $report['healthy'] ? HTTP_OK : 503);
}
```

Each check is timed, because "the database answered" and "the database answered
in four seconds" are different states and only one of them is visible in a
boolean. A check that throws counts as a failure and its **message** is
reported — not its trace, which names paths and classes that are nobody else's
business.

> **A health endpoint describes your infrastructure.** Left public, it tells
> anyone which dependencies you have and which are currently down, which is the
> first thing worth knowing before attacking something. Put it behind the
> balancer's network, or behind a token.

Nothing is registered by default: a health endpoint reporting on a database the
application does not use would be answering the wrong question.
`Health::check(['databse'])` — a name nothing registered — throws, rather than
reporting healthy with nothing checked.

### Metrics

```php
use SfphpProject\src\Log\Metrics;

Metrics::count('orders.placed');
Metrics::count('payments.failed', ['gateway' => 'stripe']);

$report = Metrics::time('report.build', fn () => $builder->run());
```

A log line carries a duration, which answers "how long did this request take".
It does not answer "how long do requests take", and the difference is the reason
metrics exist: one is an anecdote, the other is the shape of the system.

`time()` records the call that **threw**, as well as the one that returned —
something that only gets slow when it is failing is exactly the thing worth
seeing.

The collector is in-process. `snapshot()` reads it as an array, and
`prometheus()` renders the text format a scraper understands, assembled here
rather than through a client library:

```
# TYPE orders_placed counter
orders_placed 2
# TYPE payments_failed counter
payments_failed{gateway="stripe"} 1
# TYPE report_build_ms_count counter
report_build_ms_count 2
# TYPE report_build_ms_sum counter
report_build_ms_sum 41.882
# TYPE report_build_ms_min gauge
report_build_ms_min 18.204
# TYPE report_build_ms_max gauge
report_build_ms_max 23.678
```

Each family is declared once with `# TYPE`, values carry at most three
decimals, and a name that would start with a digit gets a leading underscore,
since the format does not allow one.

### What is missing

| Missing | Situation |
|---|---|
| Aggregation across instances | Each process holds its own counts; a scraper or a push gateway does the joining |
| Histograms and percentiles | Count, sum, min and max are recorded; a p99 needs buckets this does not keep |
| Persistence | Counts are lost when the process ends, which under php-fpm is every request. Scrape a persistent runtime, or push |

---

## CLI

`./sfphp` exposes **41 commands**. `./sfphp list` prints every one with its
usage, `./sfphp help` groups them, and `./sfphp help <command>` — or
`<command> --help` — explains one; all three are printed from the same table,
which a test holds against the dispatcher. An option takes its value after `=`
or after a space: `--port=8080` and `--port 8080` are the same.

### Generation (14 generators)

```bash
./sfphp make:controller Post   # PostController, and the view its action renders
./sfphp make:model Post
./sfphp make:repository Post
./sfphp make:service Post
./sfphp make:request StorePost
./sfphp make:test Post         # tests/PostTest.php, for ./sfphp test
./sfphp make:middleware CheckAdmin
./sfphp make:event UserCreated
./sfphp make:listener SendWelcome
./sfphp make:policy Post       # PostPolicy, denying until you write each ability
./sfphp make:seeder User       # UserSeeder
./sfphp make:factory User
./sfphp make:pwa --name="My App" --logo=path/to/logo.png

./sfphp make:scaffold Post     # controller + view + model + repository + service
```

Fourteen generators here and two migration commands under
[Database](#database-2) make the sixteen `make:*` commands.

**A generator never overwrites.** A file that is already there is refused, with
its path, and `--force` replaces it; `make:scaffold` keeps the parts that exist
and writes the rest. The suffix is added once — `make:test PostTest` and
`make:test Post` both write `PostTest` — and the first letter is upper-cased, so
`make:controller product` writes `ProductController.php`.

`make:pwa` reads `app/pwa/config.php` when it exists — `--name` is then
optional, and `--short=`, `--description=`, `--color=`, `--background=`,
`--enable-push` and `--enable-sync` override the file. See
[PWA_GUIDE.md](./PWA_GUIDE.md).

> Every generator now produces code against something that exists and runs —
> `make:event` and `make:listener` included, since `Dispatcher` arrived. What a
> generated file still owes you is its registration: a listener has to be handed
> to `Dispatcher::listen()` where the application boots. See [Events](#events).

### Database

```bash
./sfphp make:migration create_posts title:string timestamps
./sfphp make:migration:create posts       # deprecated: the same as make:migration create_posts
./sfphp migrate [--step=N] [--path=dir]
./sfphp rollback [--step=N] [--path=dir]
./sfphp status [--path=dir]
./sfphp db:fresh [--force]
./sfphp db:seed [--class=UserSeeder]
```

A database command that cannot connect says which PDO extension is missing, or
which driver and host did not answer, instead of only "connection failed".

### Cache and queue

```bash
./sfphp cache:clear          # what has expired
./sfphp cache:flush          # everything
./sfphp queue:table          # the database queue's tables
./sfphp queue:work [--timeout=3600]
./sfphp queue:failed
```

All of them follow `CACHE_DRIVER` and `QUEUE_DRIVER`. A `cache:clear` that emptied
a file cache while the application used Redis would report success and change
nothing.

### Assets

```bash
./sfphp assets:publish                     # into public/assets
./sfphp assets:publish --path=web/static   # somewhere else
./sfphp assets:publish --force             # overwrite what is there
./sfphp assets:publish --symlink           # link instead of copying
```

Copies SFCSS and SFJS out of the package and into a directory the project
serves. `composer create-project` and `./sfphp serve` both run it (as does
`composer run assets`), and `css:build` and `js:build` publish what they
build, so this is for an upgrade or an unusual layout; a run that finds the
same files copies nothing and says so.

A published file is replaced when it is still the one the framework last
published — a hash is kept beside the files, in `.sfphp-published.json` — and
kept, with its name printed, when it was changed by hand. "Different from the
package" used to count as "yours", so a rebuilt stylesheet was never published
and the new colours never reached the browser. `--force` replaces either.

> **Why the files exist twice.** The package keeps them where they are
> version-controlled and where an upgrade replaces them; the browser can only
> read what is under the document root, and no package can write into your
> `public/` at install time. So one is the source and the other is a published
> copy — `public/assets/css` and `public/assets/js` belong in `.gitignore`,
> like `vendor/`.
>
> `--symlink` makes it one file where symbolic links work. It is not the
> default because a link is a deployment decision: it breaks when a deploy
> copies rather than moves, it needs care on Windows, and an upgrade then
> changes what a running site serves instead of waiting for you to publish.

### Server and utilities

```bash
./sfphp serve          # http://127.0.0.1:8000; --host= --port=
./sfphp routes         # a table of the registered routes; --path= for an unusual layout
./sfphp env:example    # creates .env from .env-example
./sfphp init           # finishes a new project: .env, assets, .gitignore, composer scripts
./sfphp css:build      # builds SFCSS from the config and publishes it; --config= --output=
./sfphp js:build       # joins core.js, stream.js and ui.js into sfjs.js, minifies and publishes it
./sfphp build --phpx   # compiles the .phpx components; --from= --to=
./sfphp test [filter]  # runs tests/*Test.php
./sfphp reset          # removes the example application; --force skips the question
./sfphp upgrade        # replaces the framework, keeps the application
./sfphp tinker         # REPL — local development only
./sfphp list
./sfphp version
./sfphp help [command]
```

`serve` tries the port before announcing anything, and exits with an error when
it is taken; the project path may contain spaces. Every command that starts PHP
again uses the binary running the console, not whichever `php` is first on the
`PATH`.

`test` runs the classes under `tests/` that extend
`SfphpProject\src\Testing\TestCase` — every public method whose name starts with
`test`, each on a fresh instance, with `assertSame()`, `assertTrue()`,
`assertThrows()` and a few more. `make:test` writes one. A project that wants
PHPUnit adds it to `require-dev` and uses it instead.

`tinker` keeps the variables of a session from one line to the next, prints the
value of an expression and runs a statement — `echo`, a loop — as written; an
error is printed and the session carries on. It evaluates input with `eval()`: a
local development tool, never to be exposed to untrusted input.

`version` is the framework's own version, written into `src/`, so after an
upgrade it names the release the project now runs.

### Upgrading

**`composer update` does not upgrade SFPHP, and cannot.** A project created with
`composer create-project` does not have the framework as a dependency — the
framework's files *are* the project, and its `require` names only PHP and a
couple of extensions. There is nothing in `vendor/` for Composer to replace.

So upgrading means replacing those files, and knowing which ones they are:

```bash
./sfphp upgrade --dry-run          # what it would do, changing nothing
./sfphp upgrade                    # the latest release
./sfphp upgrade --to=v0.32.0       # fetches that tag with git
./sfphp upgrade --from=../sfphp    # a copy you already have
```

Without `--to` it fetches the newest release tag — it used to fetch `master`,
code nobody had released. What the package leaves out of an install — the
framework's tests, its tooling, its documentation — is removed from the fetched
copy too, so the upgrade brings what an install would have.

| | |
|---|---|
| Replaced whole | `src/`, `sfphp`, `server.php` — entirely the framework |
| Merged in | `resources/`, `lang/`, `tools/` — the framework's files land on top, yours stay |
| Written beside | `public/index.php`, `composer.json` and `tools/css-builder/sfcss.config.json` become `<file>.new` for you to read |
| Never touched | `app/`, `database/`, the rest of `public/`, `.env`, `vendor/` |

It lists all of that — **including, by name, every file of yours a merge would
replace** — waits for you to type `upgrade`, and refuses when there is no
terminal to answer at. `--force` is for a script.

"Merged" sounds safer than it is, which is why those names are printed: a file
of yours that shares a name with one of the framework's is replaced. Edit a
shipped language file and you will see it on that list before anything happens.

**Commit first.** A change you made under `src/` is lost — it was going to be
lost at the next release anyway, and silently. `public/index.php` and
`composer.json` are the two files releases change that are also yours, which is
why they are never overwritten: compare the `.new` and take what you want.

Afterwards:

```bash
composer dump-autoload
./sfphp assets:publish --force
./sfphp build --phpx        # if the project has components
```

> **The command lives in the version you are upgrading to, not the one you have.**
> Coming from a release before this one, do that first upgrade by hand — replace
> `src/`, the binary and `server.php`, merge `resources/`, `lang/` and `tools/`,
> and diff `public/index.php`. From then on this command does it.

### Starting from zero

The package ships an application: a home page, controllers, components, a model,
a seeder. It is there to be read and run, and it is in the way the moment you
start writing your own.

```bash
./sfphp reset            # asks first
./sfphp reset --force    # for a script
```

It empties `app/components`, `app/controllers`, `app/models`, `app/Jobs`,
`app/resources/views`, `database/migrations`, `database/seeders` and
`database/factories`, and rewrites the routes file with no routes — otherwise
the application would boot into a controller that is no longer there. The
directories stay, because they are where the next thing goes.

**The migration the framework ships is kept**: the users table, which the
authentication guard is written against, and which a project that dropped it
would miss at its first login rather than here. A `create_sessions_table`
migration, if you wrote one for the database session driver, is kept too. Any
other migration you wrote is yours, and goes with the rest of what you wrote. A `.gitkeep` stays too — it exists to hold an empty
directory, which is what this leaves behind.

Before deleting anything it prints what it is about to delete, with a count per
directory, and waits for you to type the word `reset`. With no terminal to
answer at — a pipe, a CI job — it refuses instead of proceeding on silence.
There is no undo and nothing goes to a trash bin.

---

## SFCSS

A CSS framework of components and utilities. **It arrives built** — the
package carries the stylesheet, and `composer create-project` and `sfphp
serve` each copy it into `public/assets`, so using it is one line of HTML:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nothing has to be generated to use SFCSS. The generator is there for changing
it, which is [further down](#changing-sfcss).

It ships the components a page is built from — buttons, forms with validation
states, cards, alerts, badges, tables, navs and tabs, a navbar, breadcrumb,
pagination, list groups, progress bars, spinners, an accordion, and the styles
of the dropdowns, tooltips, modals, offcanvas panels and toasts that
SFJS drives — and the utilities to adjust them, with breakpoint,
`hover:` and `print:` variants.

| | |
|---|---|
| Classes in total | **3,836** |
| — base utilities and components | 2,091 |
| — `hover:` variants | 628 |
| — breakpoint variants (`sm` `md` `lg` `xl`) | 1,096 |
| — `print:` variants | 21 |
| Colour classes | 600 palette (20 families × 10 shades × `bg`/`text`/`border`), plus every role colour's variants |
| Size | 237KB raw · 195KB minified · **33.1KB gzipped** |
| Dependencies | none |

Forms and tables are styled **by class** — `form-control`, `form-select`,
`form-check-input`, `table` — so a bare `<input>` or `<table>` is left alone.

### Changing SFCSS

The design lives in a config: colours, the spacing and sizing scales, type,
radii, breakpoints and options. A project keeps its own `sfcss.config.json` next
to `composer.json`, and it is **merged over the defaults**, so it holds only
what it changes:

```json
{
  "colors": { "primary": "#7c3aed", "brand": "#0f766e" },
  "options": { "darkMode": true, "hoverVariants": false }
}
```

```bash
./sfphp css:build
./sfphp css:build --config=design/sfcss.json --output=web/css
```

`css:build` writes into `resources/assets/css` in a project created with
`create-project` or cloned — run `./sfphp assets:publish` afterwards, as it
reminds you — and straight into `public/assets/css`, which is what the browser
reads, when the framework is installed under `vendor/`. `--output=` overrides
both. The builder's warnings are printed on standard error even when the build
succeeds.

Everything that follows from a colour is computed by the builder rather than
written down: the text that stays readable on it (checked against WCAG AA,
4.5:1, with a warning at build time for a colour nothing reads on), its hover
and active shades, a pale background with its border and text, its form as text
on the page, and the same set for the dark theme. Each colour — including one a
project adds — gets `btn-`, `btn-outline-`, `badge-`, `alert-`, `text-`,
`bg-`, `border-` and the rest. `text-{colour}` uses the colour's readable form,
so `.text-warning` on white passes AA rather than being the raw amber at 2.2:1.

Every utility comes from a map a project can extend or trim from the same
config, and options switch features off: components, dark mode, `hover:` or
breakpoint variants, rounding, shadows, a prefix for the CSS variables.

> **A stylesheet you changed by hand is not overwritten.** `css:build` publishes
> what it builds, and `create-project` and `serve` publish the framework's
> assets; a file under `public/assets` that differs from what was last
> published there is kept, and named. `assets:publish --force` takes the
> framework's version back.

For a colour on one section of a page, overriding the variables is enough:

```css
.checkout { --primary: #047857; --primary-rgb: 4 120 87; }
```

That does not recompute the derived values (`--primary-contrast`,
`--primary-subtle`…); for the whole site, change the config and rebuild.

### Themes and accessibility

A page opts into a theme with `data-theme` on its root element — `light` (the
default), `dark`, or `auto` to follow the reader's system setting. Surfaces,
body text and each colour's pale, border, emphasis and text forms change;
alerts, tables, forms, cards and toasts follow. Palette classes such as
`bg-blue-50` keep their value, because they are colours, not roles.

Keyboard focus is always visible (`:focus-visible`), the reader's
reduced-motion setting is honoured, `.visually-hidden` and `.skip-link` are
there for screen-reader text, components mirror under `dir="rtl"`, and the
current tab, page or invalid field is styled from its ARIA attribute — markup
that tells a screen reader the truth is markup that looks right.

The error page and the dump screen are built from SFCSS and inline it — a
framework with its own stylesheet should not have its own screens written in a
second one.

Full reference: [SFCSS](SFCSS.md) and
[utilities reference](SFCSS_UTILITIES.md).

---

## SFJS

A dependency-free JavaScript library — 113KB raw, 58KB minified, **15.4KB
gzipped**. Exposed as `window.sf`. It is **one file**, with everything in it:
requests and swaps, validation, state, streams (`@stream`, `@sse`) and the
interface components (modals, menus, tooltips, tabs, toasts).

```html
<script src="/assets/js/sfjs.min.js"></script>
<!-- or sfjs.js, readable, for debugging — never both: each is the whole bundle -->
```

Up to 0.27, streams and the interface components were `sfjs-stream.js` and
`sfjs-ui.js`, two extra files that only worked when loaded after this one. They
are part of `sfjs.js` now and are no longer published: a page that includes
them should drop those two tags.

The source is three files under `resources/assets/js/src/` — `core.js`,
`stream.js`, `ui.js` — that the builder joins, in that order, into the bundle.

```bash
./sfphp js:build         # joins the parts, writes sfjs.js and sfjs.min.js, and publishes them
```

The minifier removes comments and collapses whitespace, and deliberately does
not rewrite tokens — no shortened names, no dropped semicolons, no statements
joined onto one line. Those are where a minifier changes what a program means,
and the extra kilobyte is not worth owning a JavaScript parser in a framework
that has no dependencies. A test checks that both builds expose the same API and
that the minified one still parses, and the build refuses a minified file that
kept more than two thirds of the bundle's size — the sign that the minifier lost
its place, which a quote inside a regex literal does.

### Programmatic API

```js
sf.ajax.get('/api/posts');
sf.ajax.post('/api/posts', { title: 'Hello' });
sf.ajax.put('/api/posts/1', { title: 'Edited' });
sf.ajax.delete('/api/posts/1');
sf.ajax.patch('/api/posts/1', { title: 'X' });

sf.form.serialize(formEl);
sf.form.submit(formEl);
sf.form.validate(inputEl);

sf.dom.addClass(el, 'active');   sf.dom.removeClass(el, 'active');
sf.dom.toggleClass(el, 'active'); sf.dom.hasClass(el, 'active');
sf.dom.show(el); sf.dom.hide(el); sf.dom.toggle(el);   // the hidden attribute, like @show
sf.dom.on(el, 'click', fn);     sf.dom.off(el, 'click', fn);
sf.dom.ready(fn);

sf.validate.email(v);  sf.validate.required(v);  sf.validate.number(v);
sf.validate.url(v);    sf.validate.minLength(v, 5);  sf.validate.maxLength(v, 50);
sf.validate.pattern(v, '^[a-z]+$');

sf.storage.set('k', {a: 1});  sf.storage.get('k');
sf.storage.remove('k');       sf.storage.clear();

sf.util.debounce(fn, 300);  sf.util.throttle(fn, 300);  sf.util.wait(500);
sf.util.id(el, 'prefix');   // el's id, giving it a unique one first if it has none

sf.form.check(inputEl);     // validates, and shows or clears the message
sf.messages = { required: 'Campo obrigatório.' };   // see "What SFJS says, in any language"
sf.config({ swapStrategy: 'innerHTML', messages: { close: 'Fechar' } });
sf.t('minLength', { min: 3 });          // a message by key, placeholders filled in
sf.emit(el, 'app:saved', { id: 7 });    // a bubbling CustomEvent
sf.onBind((root) => { /* runs on the page and on every fragment a swap brings in */ });
sf.bind(el);                            // wire up markup you inserted yourself
sf.morph(el, html);                     // the default swap, without a request

sf.toast('Saved.', { variant: 'success' });   // an interface component
sf.modal.open(dialogEl);  sf.modal.close(dialogEl);
```

### Declarative attributes

```html
<button @get="/api/data" @target="#content">Load</button>
<button @delete="/api/item/1" @target="#item" @swap="outerHTML">Delete</button>

<form @post="/users" @target="#list">
  <input name="email" @validate="email">
  <button type="submit">Create</button>
</form>

<button @toggle="#menu">Menu</button>
<div id="menu" hidden>...</div>
```

The five verbs are `@get`, `@post`, `@put`, `@patch` and `@delete`, with
`@target` (a CSS selector) and `@swap` beside them. Without `@target` the answer
goes into the element that asked — it used to go nowhere. A form sends its
fields: `@get` and `@delete` as a query string, the rest as the body. A named
input sends its own value the same way.

Every request that changes something carries the CSRF token as `X-CSRF-Token`,
read from `<meta name="csrf-token">` — put `{!! csrf_meta() !!}` in the page's
`<head>`. A form's `_token` field reaches the server too, inside the JSON body.

What comes back is swapped in as markup, so what answers one of these is a
fragment — rendered by the same component that renders it inside the full page,
rather than JSON that JavaScript has to rebuild into HTML.

> The older spellings `@hxGet`, `@hxTarget` and `@hxSwap` still work and mean
> the same thing. They shipped, so they are read as aliases rather than
> removed; they are deprecated and go in a later release.

### Saying when it fires

Without `@trigger`, a click fires an element and a submit fires a form. With it,
the element says for itself:

```html
<div @get="/dashboard/sales" @trigger="load, every:10s"></div>

<input name="q" @get="/search" @trigger="input delay:300ms" @target="#results">
```

**The grammar is one rule.** A trigger is a word, or a word and a value joined
by a colon. Several words separated by spaces belong to the same trigger; a
comma starts another one.

```
@trigger="load, every:10s"        two triggers: fire now, then every ten seconds
@trigger="input delay:300ms"      one trigger, with a modifier
@trigger="every:5s delay:2s"      one trigger: start in two seconds, then every five
```

That is why the first example has a comma and the second does not — the second
is a single trigger carrying a modifier, not two triggers.

**What a trigger can be**, and this is the whole list:

| | |
|---|---|
| `load` | as soon as the element is in the page |
| `every:10s` | on a period. `ms`, `s` and `m` are understood; a bare number is seconds |
| any DOM event | `click`, `submit`, `input`, `change`, `focus`, `blur`, `keyup`, `mouseenter` — whatever the browser fires |

There is no list of supported events, because there is no list in the code: the
name is handed to `addEventListener`, so anything the browser knows about works.
A name the browser does not know simply never fires.

**Modifiers**, added to any trigger:

| | |
|---|---|
| `delay:300ms` | on an event, waits for the typing to stop before sending — one request, not one per keystroke. On `load` it postpones the first run; on `every` it offsets the first run, so ten panels do not all fire in the same instant |

A fragment that arrives through a swap is wired up too, so a panel that
refreshes itself keeps refreshing after the first time.

> `every 10s` with a space is read as `every:10s`. It shipped in 0.15.0, so it
> is kept as a deprecated spelling and goes in a later release.

### State in the page

Two different things get called state, and keeping them apart is most of the
design:

- **Application state** is what the server owns: the cart, the record, the
  list. The page shows a copy of it, and `@get` with `@trigger` and `morph` is
  how that copy is kept current.
- **Interface state** is what only this page cares about: whether a menu is
  open, which tab is selected, what has been typed and not sent yet. Keep it in
  the browser. Opening a menu takes no time at all, and asking the server about
  it would add a round trip to a question the page can already answer.

`@state` is for the second one.

```html
<div @state="{ open: false, name: '' }">
  <button @on:click="open = !open">Toggle</button>

  <div @show="open">
    <input @model="name">
    <p>Hello, <span @text="name"></span></p>
  </div>
</div>
```

An element carrying `@state` opens a scope. Everything under it reads and
writes that state until another `@state` starts a scope of its own, and a write
updates only the bindings that mention it.

**A scope survives a refresh from the server.** When a panel with `@state` is
updated through `@trigger` and `morph`, the state it already had is kept and its
bindings are collected again against the new markup — so a section the visitor
closed stays closed while the numbers inside it change.

| | |
|---|---|
| `@text` | the element's text becomes the expression's value |
| `@show` | shown while the expression is true, through the `hidden` attribute |
| `@class` | adds classes to the ones the element was written with |
| `@model` | two-way on an input, a textarea, a select, a checkbox (its `checked`) or a group of radios (the one whose value matches is checked; choosing one writes its value) |
| `@on:click`, `@on:input`, … | runs an expression when that event fires |

### The first values come from PHP

```php
function CartPanel(array $items): Sfht
{
    return Sfht(
        <div @state="{{ state(['open' => false, 'items' => $items]) }}">
            <button @on:click="open = !open">
                Cart: <span @text="items.length"></span>
            </button>
        </div>
    );
}
```

`state()` returns JSON as a plain string so that `{{ }}` escapes it: the quotes
become entities inside the attribute and the browser hands them back intact.

### Fetching into the state

```html
<button @get="/api/users/7" @into="user" @loading="busy">Load</button>

<span @text="busy ? 'Loading…' : user.name"></span>
```

`@into` puts the decoded JSON into that key instead of swapping markup;
`@loading` holds a boolean for as long as the request is in the air. Between
them, that is `useState` and `await` without a line of JavaScript.

### What the expressions can do, and what they cannot

Paths (`user.name`), string, number, boolean and null literals, `!` and unary
`-`, `+ - * / %`, `== != === !== < > <= >=`, `&&` and `||` with short-circuit,
the ternary, object and array literals, and assignment.

**Not** function calls, arrow functions or indexing by expression, and not the
prototype chain: a path through `__proto__`, `prototype` or `constructor` reads
`undefined` and writes nothing, so `constructor.prototype.isAdmin = true` cannot
reach every object in the page. So this does not work:

```html
<span @text="items.filter(i => i.active).length"></span>
```

Compute it in PHP, before the markup, where the data already is — and pass the
number in.

> **Why the limit exists.** Alpine and Vue hand attribute values to
> `new Function()`, which accepts all of JavaScript and, in exchange, requires
> `unsafe-eval` in the Content-Security-Policy of every page that uses them.
> This parses the expressions instead, so a strict policy stays strict. An
> expression it cannot read is reported in the console by name rather than
> failing silently.

### Swap strategies

`@swap` accepts `morph` (the default), `innerHTML`, `outerHTML`, `beforebegin`,
`afterbegin`, `beforeend` and `afterend`.

**`morph` is the default because the alternative is destructive.** Replacing
markup throws away the focus, the caret and anything typed into a field that has
not been sent yet — so a panel refreshing every ten seconds makes a form inside
it unusable, and nothing warns you. `morph` walks the old tree and the new one
together and changes only what differs: the same node stays the same node, and a
field the visitor is typing into is left alone.

```html
<div id="cart" @get="/cart" @trigger="every:5s" @target="#cart"></div>
```

It costs about four times what `innerHTML` costs. Measured in Chrome on this
machine, replacing a table twenty times: a 200-row table is 1.4 ms against 6 ms,
and a 1,000-row one is 4 ms against 16 ms. Both are a fragment's worth of work,
and a fragment with a thousand rows in it is a pagination problem rather than a
swap problem.

Ask for `innerHTML` when the answer has nothing in common with what is there —
a list replaced by an empty state, for instance — and the comparison would be
work for nothing:

```html
<div @get="/results" @target="#results" @swap="innerHTML"></div>
```

> `morph` became the default in 0.17.0. Before that it was `innerHTML`, and a
> page that relied on the subtree being rebuilt — a third-party widget
> reinitialising itself, say — should now say `@swap="innerHTML"` for that
> target.

**Children are matched by key before position.** A child with `@key`, or with
an `id`, is found wherever it now sits in the new markup, so a row inserted at
the top of a list is inserted — it does not shift every row below it by one and
morph each into its neighbour. The field being typed into stays the same field,
with its focus, caret and text:

```html
<ul id="messages" @get="/messages" @trigger="every:5s" @target="#messages">
  <li @key="msg-41">…</li>
  <li @key="msg-40">…</li>
</ul>
```

Children without a key are still matched by position. A checkbox's `checked`
and an option's `selected` follow what the server sent, as a field's value
does, unless the visitor is on that control right now. A field the server sends
back without a value is emptied — the text typed before used to stay in the box
and go out again with the next submit.

With `innerHTML` and `outerHTML` the nodes are rebuilt, but when the focused
element has an `id` that is also in the new markup, the focus and the caret are
put back on it — a keyboard user is not thrown to the top of the page.

### Answering with a fragment

One action serves both the swap and a browser with no JavaScript running:

```php
public function sales(Request $request): Response
{
    $panel = SalesPanel(await(Http::getAsync($url))->json());

    return Response::fragment($request, $panel, page: fn (Sfht $inner) => Dashboard($inner));
}
```

SFJS sends `X-Requested-With`, so it gets the piece that changed; a form
submitted without JavaScript gets the whole page with the fragment already in
place. `$request->isFragment()` is the same question if you need it directly.

### The life of a request

Every request an element sends goes through the same steps, and each step is an
event dispatched on that element. The events bubble, so one listener on
`document` hears the whole page:

| | |
|---|---|
| `sf:before` | before sending. Cancelable: `preventDefault()` stops the request. `detail`: `{url, method, target}` |
| `sf:after` | after a successful answer was swapped in. `detail`: `{response, target}` |
| `sf:error` | on a non-2xx answer or a network failure. `detail`: `{error, response, target}` — `response` is `null` when nothing came back, and `target` is the `@error-target` element when the body was shown there |

```js
document.addEventListener('sf:before', (e) => {
  if (e.detail.method === 'DELETE' && !confirm(sf.t('confirmDelete'))) e.preventDefault();
});

document.addEventListener('sf:error', (e) => {
  console.warn('Request failed', e.detail.error, e.detail.response?.status);
});
```

`confirmDelete` is not a built-in key: `sf.t()` returns the key itself when
there is no message, so an application adds its own keys to `sf.messages` and
reads them the same way. An element that a swap has already removed from the
page cannot bubble, so its events go to `document` instead, and so do the
events of `sf.ajax.*` calls made without an element.

While a request is in the air:

- the target carries `aria-busy="true"`, so a screen reader waits for the new
  content instead of reading half of it, and CSS can show it —
  `[aria-busy="true"] { opacity: .6 }`;
- the element that sent it — a button, a link, or a form's submit buttons — is
  disabled and marked `aria-disabled="true"`, so a second click cannot send a
  second order. A text field that sends is neither disabled nor marked —
  disabling it would take the focus from somebody who is still typing — so its
  target's `aria-busy` is what says a request is running. When the answer
  arrives the button is enabled again and gets its focus back.

**A newer request cancels the older one.** When an element sends again before
its previous answer arrived — a search box while somebody types — the previous
request is aborted, so a slow answer to "ab" can never overwrite the answer to
"abc".

**Ctrl, Cmd, Shift and middle clicks on a link are left to the browser.** They
are the visitor asking for a new tab or window, and a link with `@get` opens
there as any other link would.

**`every:` and `load delay:` stop with their element.** A polling panel that a
swap took out of the page stops at its next tick, instead of polling for an
element nobody can see for as long as the tab is open.

### When the server says no

A non-2xx answer is still an answer. A 422 carrying the form back with its
messages is the most useful thing the server can send, so `@error-target` says
where that body goes:

```html
<form id="signup" @post="/signup" @target="#welcome" @error-target="#signup" @swap="outerHTML">
  …
</form>
```

With `@error-target`, the body is swapped there with the element's `@swap`
strategy, and `sf:error` still fires. Without it, nothing on the page changes
and `sf:error` is how you hear about it. The same option exists in code:
`sf.ajax.post(url, data, { target: '#welcome', errorTarget: '#signup' })`.

### What a request sends

| | |
|---|---|
| `GET` and `DELETE` | the fields as a query string, with no body and no `Content-Type` |
| a form with an `<input type="file">`, or with `enctype="multipart/form-data"` | `multipart/form-data`, exactly as the browser would send it without JavaScript — which is how a file reaches the server |
| anything else | JSON |

A field that appears more than once — three ticked checkboxes called `tags` —
arrives as a list, and a name ending in `[]` is a list even when only one value
was sent, so the server never receives a string one day and an array the next.
Names are read the way PHP reads them, so the JSON means what the same form
means without JavaScript: `tags[]` is the list `tags`, and `address[city]` is
`city` inside `address` — the brackets used to travel as part of the key. The
button that submitted the form is included with its `name` and `value`, as in a
submit without JavaScript.

### Validation in the browser

`@validate` runs on `blur` and takes the same rules the server validates with —
see [Validation](#validation) for the list. Several separated by `|`:
`@validate="required|number|min:18"`. As on the server, `required` is judged
first: an empty field shows only "required" when the rule is there, and nothing
when it is not; the other rules check a field only once it has a value. The
rules decide the same way on both sides — `url` wants `http` or `https` and a
host, `email` accepts any script, characters are counted as a reader sees them,
and rule names are read regardless of case.

```html
<form @post="/users" @target="#list">
  <label for="email">Email</label>
  <input id="email" name="email" @validate="required|email">

  <label for="nick">Nickname</label>
  <input id="nick" name="nick" @validate="required|minLength:3"
         data-msg-minlength="Pick a nickname of {min} letters or more.">

  <button type="submit">Create</button>
</form>
```

When a field breaks a rule:

- it gets `aria-invalid="true"` and the class `is-invalid`;
- a message is inserted right after it —
  `<div class="invalid-feedback" id="sf-error-3" aria-live="polite">` — and
  linked to it through `aria-describedby`, so a screen reader reads the message
  when the field is focused. The id is generated, so two fields without an id
  never share a message, and ids the page had already put in
  `aria-describedby` are kept;
- from then on the field is checked again as the visitor types, and the message
  goes away as soon as the value is right.

**An invalid form is not sent.** On submit, every `@validate` field in the form
is checked; if any fails, the submit is cancelled — whether the form is sent by
SFJS or by a plain `action` — and the focus moves to the first field at fault,
which is where a screen reader reads its message.

The look belongs to the stylesheet: SFCSS styles `is-invalid` and
`invalid-feedback`, and SFJS adds no utility classes of its own. In code,
`sf.form.check(field)` validates and updates the page, returning whether the
field passed; `sf.form.validate(field)` only answers.

### What SFJS says, in any language

Everything SFJS shows to a visitor is a key in `sf.messages`, and the defaults
are English:

| Key | Default |
|---|---|
| `required` | This field is required. |
| `email` | Enter a valid email address. |
| `number` | Use digits only. |
| `alpha` | Use letters only. |
| `alphanum` | Use letters and numbers only. |
| `min`, `max` | Enter a value of at least {min}. — Enter a value of at most {max}. |
| `minLength`, `maxLength` | Use at least {min} characters. — Use at most {max} characters. |
| `pattern` | Use the format requested. |
| `url` | Enter a valid URL. |
| `invalid` | This value is not valid. — used for a rule that has no message of its own |
| `close` | Close — the accessible name of a toast's close button |
| `streamClosed` | [Connection closed] |
| `streamError` | Error: {error} |

`min` and `max` on a value that is not a number count characters, as they do on
the server, so they are worded with `minLength` and `maxLength`. `{min}`, `{max}`
and `{error}` are replaced where they appear.

There are four ways to change them, from the widest to the narrowest:

```html
{{-- 1. From the server's catalogs, in a meta element: no inline script, so it
     works under a strict Content-Security-Policy, and {{ }} escapes it. --}}
<meta name="sf-messages" content="{{ state([
    'required' => __('app.form.required'),
    'email' => __('app.form.email'),
    'minLength' => __('app.form.min_length'),
    'close' => __('app.close'),
]) }}">
```

```js
// 2. Assigning: only the keys given change, the rest stay in English.
sf.messages = { required: 'Campo obrigatório.', minLength: 'Use ao menos {min} caracteres.' };

// 3. Together with the other defaults.
sf.config({ swapStrategy: 'morph', messages: { close: 'Fechar' } });
```

```html
<!-- 4. One field, one rule: data-msg- followed by the rule name in lower case. -->
<input name="age" @validate="number|min:18" data-msg-min="You must be {min} or older.">
```

A catalog entry that SFJS will read writes its placeholders the SFJS way —
`{min}`, not `:min` — since it is filled in by the browser, not by `__()`.

### @toggle

`@toggle` shows and hides another element. It takes a CSS selector; a bare id,
which is what it took before, keeps working:

```html
<button @toggle="#filters">Filters</button>
<div id="filters" hidden>…</div>
```

The panel is shown and hidden with the `hidden` attribute rather than an inline
`display`, so it keeps the display its own CSS gives it. Every trigger of the
panel gets `aria-controls` and an `aria-expanded` that follows the panel, which
is what a screen reader announces as "collapsed" and "expanded" — two buttons
opening the same panel both say the same thing. The panel receives `sf:show` or
`sf:hide` before it changes, and both are cancelable. A panel hidden with an
inline `display: none`, as the older version required, still works on the first
click.

`@show` in a state scope uses the `hidden` attribute too. SFCSS makes `[hidden]`
win over any display utility; a stylesheet of your own should do the same.

### Streams

The streaming part of SFJS adds `@stream`, and is documented in the
[streaming guide](STREAMING.md). How it behaves:

- `sfjs.js` can be loaded in the `<head>`: it binds when the document is ready;
- an element added to the page later is bound itself, not only its
  descendants, and a stream whose element is removed from the page is stopped;
- the stream's target gets `aria-live="polite"` (unless it has its own) and
  `aria-busy="true"` while data is arriving, so a screen reader announces the
  result once instead of every chunk;
- `@trigger` reads the same list the core reads — `@trigger="load, every:10s"`,
  `@trigger="load delay:1s"`;
- form fields that repeat are sent as lists, not only the last value, and a form
  with a file input is sent as `multipart/form-data`, so the file arrives;
- an `EventSource` keeps the browser's own reconnection. The stream ends when
  the server sends a final event — `done` or `complete`, or the names given in
  `@done="finished"` — or answers a reconnection with 204;
- `[Connection closed]` and `Error:` come from `sf.messages`
  (`streamClosed`, `streamError`).

### Interface components

The interface part of SFJS adds modals,
offcanvas panels, dropdown menus, tooltips, tabs and toasts, built on what the
browser already does: `<dialog>` supplies the focus trap, the top layer and
Escape; the `popover` attribute supplies light dismiss. The script adds the
keyboard and ARIA work those elements leave to the page, and SFCSS supplies the
look — the script writes no CSS except the coordinates of a floating element.
It is in `sfjs.min.js`; there is nothing else to load.

Everything is delegated from the document, and markup that arrives through a
swap is wired up like the rest.

### Modal and offcanvas

```html
<button @modal="#confirm">Delete account</button>

<dialog id="confirm" class="modal" aria-labelledby="confirm-title">
  <div class="modal-header">
    <h2 class="modal-title" id="confirm-title">Delete your account?</h2>
    <button class="btn-close" @dismiss aria-label="Close"></button>
  </div>
  <div class="modal-body">This cannot be undone.</div>
  <div class="modal-footer">
    <button class="btn" @dismiss>Cancel</button>
    <button class="btn btn-danger" @delete="/account">Delete</button>
  </div>
</dialog>
```

`@modal="#confirm"` opens the dialog with `showModal()`: the rest of the page
becomes inert and the focus stays inside. It closes with Escape, with a
`@dismiss` inside it, or with a click on the backdrop — unless the dialog has
`data-static`, for a form that must not be lost to a stray click. When it
closes, the focus goes back to the button that opened it. The trigger gets
`aria-haspopup="dialog"` and `aria-controls`.

An offcanvas panel is the same script on a dialog with another class; SFCSS
slides it in from the side it names:

```html
<button @modal="#cart">Cart</button>
<dialog id="cart" class="offcanvas offcanvas-end" aria-label="Cart">…</dialog>
```

`offcanvas-start`, `offcanvas-end`, `offcanvas-top` and `offcanvas-bottom` are
the four sides. Both fire the same events on the dialog:

| | |
|---|---|
| `sf:show` | before opening; cancelable. `detail.relatedTarget` is the trigger |
| `sf:shown` | after opening |
| `sf:hide` | before closing, however it closes; cancelable — a form with unsaved changes can say no |
| `sf:hidden` | after closing |

In code: `sf.modal.open(dialog, trigger)` and `sf.modal.close(dialog)`.

### Dismiss

`@dismiss` on a button closes what it is inside: a `dialog` is closed, an
`.alert` or a `.toast` is removed from the page after `sf:dismissed` is fired on
it.

```html
<div class="alert alert-warning" role="alert">
  Your trial ends tomorrow.
  <button class="btn-close" @dismiss aria-label="Close"></button>
</div>
```

### Dropdown menus

A menu is a native popover: the browser opens it from its button, closes it on
Escape and on a click outside, and puts it above everything else.

```html
<button popovertarget="account-menu" class="dropdown-toggle">Account</button>

<div id="account-menu" popover class="dropdown-menu" role="menu">
  <a class="dropdown-item" href="/profile">Profile</a>
  <a class="dropdown-item" href="/settings">Settings</a>
  <hr class="dropdown-divider">
  <button class="dropdown-item" @post="/logout">Sign out</button>
</div>
```

What SFJS adds:

- `aria-haspopup` and an `aria-expanded` that follows the menu on the button;
  `role="menuitem"` on `.dropdown-item` and `role="separator"` on
  `.dropdown-divider` when the markup did not say;
- the focus moves to the first item when the menu opens; ArrowDown and ArrowUp
  move between items, wrapping at the ends, and Home and End go to the first and
  the last; ArrowDown on the closed menu's button opens it;
- choosing an item closes the menu (unless it has `data-keep-open`), and so does
  tabbing out of it; on Escape the focus goes back to the button;
- positioning. Where the browser has CSS anchor positioning, the button gets an
  `anchor-name` and the menu a `--sf-anchor` custom property naming it, and
  SFCSS places the menu with `position-anchor: var(--sf-anchor)`. Where it does
  not, SFJS places the menu under its button with fixed coordinates — above it
  when there is no room below, aligned to the right in a right-to-left page — and
  follows the button on scroll and resize while the menu is open.

### Tooltips

```html
<button @tooltip="Copies the address of this page">Copy link</button>
<a href="/help" @tooltip="Opens in this tab" @tooltip-placement="bottom">Help</a>
```

`@tooltip` shows its text in a single shared `<div class="tooltip"
role="tooltip" popover="manual">` — on hover after 300 ms, at once on keyboard
focus — and hides it on leave, on blur and on Escape. The pointer can move from
the element onto the tooltip without closing it — the hide waits a moment, so
crossing the gap between them does not count as leaving — and the text can be
read and selected, as WCAG 1.4.13 asks of content shown on hover. While it is shown, the element's `aria-describedby` points at it. The
text is set as text, never as markup.

`@tooltip-placement` is `top` (the default), `bottom`, `left` or `right`. The
tooltip moves to the opposite side when there is no room, and its class says
where it ended up — `tooltip-top`, `tooltip-bottom`, `tooltip-left`,
`tooltip-right` — so SFCSS can point its arrow the right way.

A tooltip adds a description. An icon-only button still needs an
`aria-label` of its own.

### Tabs

```html
<div @tabs role="tablist" class="nav nav-tabs" aria-label="Account">
  <button role="tab" class="nav-link" id="tab-profile" aria-controls="panel-profile" aria-selected="true">Profile</button>
  <button role="tab" class="nav-link" id="tab-billing" aria-controls="panel-billing">Billing</button>
</div>

<div id="panel-profile">…</div>
<div id="panel-billing" hidden>…</div>
```

`@tabs` on the tab list makes it follow the ARIA tabs pattern:

- only the selected tab is in the Tab order (roving `tabindex`); ArrowLeft and
  ArrowRight move to the previous and next tab and select it, wrapping at the
  ends — reversed in a right-to-left page — and Home and End go to the first and
  the last. With `aria-orientation="vertical"` on the list it is ArrowUp and
  ArrowDown;
- the selected tab has `aria-selected="true"`, the others `"false"`;
- each panel gets `role="tabpanel"`, `aria-labelledby` pointing at its tab, and
  `tabindex="0"` when it has none; the panels of the other tabs get `hidden`;
- `sf:tab` is fired on the list with `detail: {tab, panel}`.

Write `hidden` on the panels that start closed, as above, so the page does not
flash them before the script runs. SFCSS styles the `.nav-link` of `.nav-tabs`
and `.nav-pills` through `[aria-selected="true"]` — a tab without `nav-link`
is left looking like a plain button. A tab marked `disabled` or
`aria-disabled="true"` is skipped.

### Toasts

```js
sf.toast('Saved.', { variant: 'success' });
sf.toast('The payment was refused.', { variant: 'danger', timeout: 0 });
```

| Option | Default | |
|---|---|---|
| `variant` | `info` | becomes the class `toast-{variant}`: `success`, `danger`, `warning`, `info`, or any name SFCSS styles |
| `timeout` | `5000` | milliseconds until it goes away; `0` keeps it until it is dismissed |
| `dismissible` | `true` | adds a close button, `<button class="btn-close" @dismiss>`, labelled with `sf.messages.close` |

The toasts go into a `<div class="toast-stack" aria-live="polite">` created when
the page loads — a screen reader only announces changes to a live region it
already knew about. A toast is `role="status"`; a `danger` toast is
`role="alert"`, which interrupts, because an error the visitor never heard is
worse than an interruption. The timer pauses while the pointer or the focus is
on the toast. The message is set as text; pass a DOM node for anything richer.
`sf.toast()` returns the toast element.

**The server can raise a toast.** When the answer to an SFJS request carries an
`SF-Toast` header, it is shown — on success and on error:

```php
return Response::fragment($request, $row)
    ->withHeader('SF-Toast', json_encode(['message' => __('app.saved'), 'variant' => 'success']));
```

The header is JSON with `message` and, optionally, `variant` and `timeout`, or
plain text. Header values are Latin-1, so a message in any other script has to
travel as JSON with `\u` escapes — which is exactly what `json_encode` writes by
default. Do not pass `JSON_UNESCAPED_UNICODE` here.

### Accordions and collapse

An accordion needs no script: `<details>` opens and closes natively, and giving
several the same `name` makes opening one close the others.

```html
<div class="accordion">
  <details name="faq" open>
    <summary>How long does delivery take?</summary>
    <p>Two to five working days.</p>
  </details>
  <details name="faq">
    <summary>Can I return an item?</summary>
    <p>Within thirty days.</p>
  </details>
</div>
```

SFCSS styles `.accordion` on `details` and `summary`. For a collapsible region
that is not a disclosure — a filter panel opened from a toolbar — use
[`@toggle`](#toggle).

---

## Tests

A bespoke runner, no PHPUnit — consistent with zero dependencies.

In a project created with `composer create-project`, `composer test` runs
`./sfphp test`: your own tests under `tests/`, written against
`SfphpProject\src\Testing\TestCase` — see [CLI](#cli). What follows is the
framework's own suite, which a clone of the repository carries.

```bash
composer run lint        # php -l across the project
composer run test        # the unit suite (php tests/run.php); prints passed and failed
composer run test:db     # integration against real MySQL/PostgreSQL
composer run test:all
composer run docs        # the three languages agree, and every link resolves
```

`tests/db.php` needs DSNs in the environment and skips with a notice when there
are none:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
SFPHP_TEST_REDIS_HOST=127.0.0.1 \
  composer run test:db
```

It covers the schema builder against both dialects, the database queue, the
migration lock and — when a Redis host is given — the cache, the session handler
over it and the Redis queue driver. Those three had no test that ran against a
server until this version, and the queue driver was losing every job id because
of it.

Anything whose work happens **on the server** belongs here rather than in the
unit suite, because that code reads correctly and still does nothing: the
migration lock is `GET_LOCK` and `pg_try_advisory_lock`, so only a second real
connection being refused shows that it holds.

CI runs two jobs: `unit` on a PHP 8.1–8.4 matrix, and `integration` with
MySQL 8, PostgreSQL 16 and Redis 7 as services.

`composer run docs` runs in the unit job too. The documentation exists in three
languages, and prose cannot be compared mechanically — but structure can. It
asserts the three versions have the same sections, subsections, tables and code
blocks, in the same order and with the same fence languages, and that every
relative link and in-page anchor resolves — and that each in-page link of a
translation reaches the same heading as the English link it translates, since
two headings translated alike can make a link land on the wrong one. That
catches the things that actually go wrong when three files are edited by hand:
a section added to one language and forgotten in the others, a link left
pointing at a file that moved, and a link that resolves to the wrong place.

---

## Known limitations

These are the real absences. They are not bugs — they are things the framework
does not do, and you should know before choosing it.

| Missing | Impact |
|---|---|
| **Password recovery and two-factor** | Login exists; these flows do not, and they are the application's to write. See [Authentication](#authentication) and [Mail](#mail) |
| **An event bus between processes** | `Dispatcher` delivers in the same process, synchronously. Telling another service something happened is a queue job or a message broker, not this |
| **A full ORM** | There is a [Models](#models) layer with hydration, attribute types, relations (including many-to-many) and `with()`. There is no identity map, unit of work, lazy-loading proxy, polymorphic relation or schema derived from the class — and [ORM or query builder?](#orm-or-query-builder) explains the reason for each |
| **Relative dates** | "3 hours ago" is not provided: the phrasing is per language and belongs to the application. Localised dates and numbers are, through `Time::localised()` and `Time::number()`. See [Time and time zones](#time-and-time-zones) |
| **A metrics backend** | `Metrics` counts and times in the process and prints Prometheus text; shipping it to a collector, and keeping it across requests, is the deployment's. See [Health and metrics](#health-and-metrics) |
| **Route caching to disk** | A static path is matched by comparison rather than by `preg_match`, but a parameterised route still costs one match, and nothing is compiled ahead of time. Fine for hundreds, not thousands |
| **A language server for `.phpx`** | The editor gets highlighting, Emmet and completion through the configuration the package ships, but a `.phpx` is not valid PHP, so diagnostics are turned off — and turned off for every `.php` alongside it. `./sfphp build --phpx` and `composer run lint` are what catch a real error. See [Components and .phpx](#components-and-phpx) |
| **Session revocation from elsewhere** | Ending another device's session is buildable on the `database` driver's table (a migration of your own); nothing ships. See [Sessions](#sessions) |

SFHT also has no automatic loop variables (`$loop`) and no partial block
inheritance (`@parent`).

Smaller things worth knowing before they surprise you:

| | |
|---|---|
| Unix timestamps in `INTEGER` columns | The database queue and the sessions recipe store times as integers; a signed 32-bit column ends in 2038. The recipe uses `unsignedBigInteger`; check your own |
| `decimal:N` casts to a float | Fine for display, wrong for money arithmetic — keep money in integer cents, or read the column raw with `getAttribute()` |
| `LIKE` patterns | A `%` or `_` a visitor typed is a wildcard; escape it yourself when it should be literal |
| `update()` and `delete()` without `where()` | Affect every row, as the SQL would. Nothing asks first |
| Response size in the HTTP client | Nothing caps it; a service that answers gigabytes is read into memory. Stream it instead |
| Default timeouts | 5 s to connect and 15 s in all for the synchronous client; the async one waits 10 and 30 |

---

## Related Guides

Beyond this framework reference, explore our specialized guides:

- **[Async guide](./ASYNC.md)** — Futures, the scheduler and `EnableAsync`, what blocks and what does not, event broadcasting, streams and reactive state
- **[Streaming](./STREAMING.md)** — `Response::stream()`, Server-Sent Events, `@stream` in the browser, streaming an HTTP client response, and server configuration
- **[PWA guide](./PWA_GUIDE.md)** — `make:pwa`, `app/pwa/config.php`, the manifest, the service worker, offline support, push and background sync
- **[.phpx components](./PHPX_COMPONENTS.md)** — writing, compiling and loading components in depth
- **[SFCSS framework](./SFCSS.md)** — the built-in CSS framework, with components, utilities and variants generated from a config
- **[SFCSS utilities](./SFCSS_UTILITIES.md)** — every utility class, by group

---

*Documentation reviewed on 2026-09-25 against the running code.*

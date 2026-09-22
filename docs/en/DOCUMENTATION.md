# SFPHP — Documentation

A full-stack PHP framework with **zero runtime dependencies** and Unicode
correctness across the whole surface. This documentation describes what the
code does today. Where something does not exist, it says so — see
[Known limitations](#known-limitations).

> Verified against PHP 8.4 · suite: 140 tests, 0 failures
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
- [Container and dependency injection](#container-and-dependency-injection)
- [Database](#database)
- [Models](#models)
- [ORM or query builder?](#orm-or-query-builder)
- [Migrations and schema builder](#migrations-and-schema-builder)
- [Seeders and factories](#seeders-and-factories)
- [Cache](#cache)
- [Queue](#queue)
- [Events](#events)
- [Mail](#mail)
- [Validation](#validation)
- [File uploads](#file-uploads)
- [Internationalisation](#internationalisation)
- [Time and time zones](#time-and-time-zones)
- [UTF-8 strings](#utf-8-strings)
- [Authentication](#authentication)
- [Security](#security)
- [Sessions](#sessions)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Debugging](#debugging)
- [Error handling](#error-handling)
- [Logging](#logging)
- [Health and metrics](#health-and-metrics)
- [CLI](#cli)
- [SFCSS](#sfcss)
- [SFJS](#sfjs)
- [Tests](#tests)
- [Known limitations](#known-limitations)

---

## What it is, what it is not

**It is** a lean framework for web applications and APIs, with routing,
Request/Response objects, a middleware pipeline, a DI container, a query
builder, a schema builder with MySQL/PostgreSQL parity, a template engine,
cache, queues, and a CLI with 35 commands.

**It is not** a replacement for Laravel or Symfony. There is no full ORM and no
event system, and authentication covers login, guards and authorization but not
password recovery or two-factor. What exists is small enough to read end to
end.

### Zero dependencies, literally

`composer.json` requires only `php ^8.1`, `ext-json` and `ext-pdo`. The
`vendor/` directory contains **nothing but Composer's autoloader**.

The same holds for the browser runtime: no page the framework serves —
including the 404 and 500 error pages — loads CSS, fonts or JavaScript from a
CDN.

Optional extensions, declared under `suggest`:

| Extension | Enables |
|---|---|
| `ext-mbstring` | More accurate Unicode case conversion. Without it `upper`/`lower` fall back to ASCII; the rest of the UTF-8 handling does not depend on it |
| `ext-redis` | The Redis cache and queue drivers |
| `ext-pcntl` | Graceful shutdown of the queue worker |

---

## Requirements and installation

- PHP 8.1 or later
- Composer 2
- PDO with your database's driver (optional — only if you use a database)

### As a dependency

```bash
composer require fabioaacarneiro/sfphp
```

The package carries the framework and nothing else: no example application, no
test suite, no `app/` directory appearing inside your `vendor/`. What arrives is
`src/`, the console, the licence, the readme and `resources/assets/` — SFCSS and
SFJS, which travel with the framework rather than being left behind in a public
directory you never receive. A test asserts that list, because what a consumer
gets is the archive rather than the repository and the two drift silently.

```bash
./vendor/bin/sfphp init
```

`init` writes what a project needs before it can answer anything: a front
controller, a route file, a controller, a view, the router script the built-in
server uses, and SFCSS and SFJS copied into `public/assets`. It prints the
`autoload` line to paste into your `composer.json`, and then `./vendor/bin/sfphp
serve` answers on <http://localhost:8000> with a page saying where everything
is.

A file that is already there is kept: running `init` twice reports what it left
alone rather than overwriting your front controller. `--force` overrides that,
and `--namespace=Acme\Shop` changes the namespace the generated classes use.

Everything it writes is **yours**. Nothing there is updated by a later
`composer update`, and deleting the welcome controller and its view is the
expected next step.

Doing it by hand is fine too — the front controller is the only part the
framework has an opinion about. One call wires your project to it, at the top of
that file and of any console entry point:

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
]);
```

The console arrives as `vendor/bin/sfphp`, and generates files into **your**
project rather than into the package.

### As a starting point

To begin from the example application instead — routes, controllers, views and
migrations already in place:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env

# Generate the JWT key (required to issue or validate tokens)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalent
```

In production, point the `DocumentRoot` at `public/`.

### What belongs to whom

The line runs between the framework and the application, and it is worth
knowing because everything above depends on it.

| | |
|---|---|
| The package autoloads | `src/` only, plus four files inside it |
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
database/       The application's migrations/, seeders/, factories/
tools/          The SFCSS builder
tests/          A bespoke suite, no PHPUnit
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

The first mapping is the package's; the other three are this repository's own,
declared under `autoload-dev` so they never reach a project that installs the
framework.

Four files are always loaded (`autoload.files`), and all four live in `src/`:
`runtime.php`, `utils.php`, `http.php` and `helpers.php`. The example
application's `app/config/config.php` is loaded too, through `autoload-dev`,
and all it does is call `Bootstrap::load()`.

---

## Request lifecycle

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ runtime.php→ date_default_timezone_set('UTC')
 │      utils.php  → global helpers: e(), asset(), csrf_*()
 │      http.php   → HTTP_OK, GET, POST, ... constants
 │      helpers.php→ cache(), logger(), mailer(), now(), dispatch(), __(), ...
 │      config.php → Bootstrap::load(): .env, constants, view and lang paths
 ├─ ErrorHandler::register()  safety net for fatals and bootstrap failures
 ├─ require src/routes.php    fills the static route registry
 ├─ new Container()
 │   └─ set(PDO::class, closure)   lazy connection
 ├─ Request::setTrustedProxies()   nothing is trusted until declared
 ├─ Request::fromGlobals()    the only place that reads superglobals
 ├─ Router->dispatch($request)
 │   └─ global → group → route middleware → action → Response
 └─ Emitter->emit($response)  the only place that writes output
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

Routes live in `src/routes.php`. The API is **static**.

```php
use SfphpProject\src\Router;

Router::get('/', 'MainController', 'index')->name('home');
Router::post('/users', 'UserController', 'store')->name('users.store');
```

The signature is always `(string $url, string $controller, string $action)` —
three separate arguments, not `'Controller@action'`.

The controller is resolved under `SfphpProject\app\controllers\{Controller}`,
and that namespace is a constructor parameter, so an application can put its
controllers elsewhere.

### Methods

```php
Router::get($url, $controller, $action);
Router::post(...);
Router::put(...);
Router::patch(...);
Router::delete(...);
Router::head(...);
Router::options(...);
```

An unmatched route answers **404**. A path that matches with the wrong method
answers **405** with an `Allow` header. `OPTIONS` answers **204** automatically
when a path has routes.

### Parameters

The syntax is `name:type`, **without braces**:

```php
Router::get('/posts/id:number', 'PostController', 'show');
Router::get('/users/username:alpha', 'UserController', 'profile');
Router::get('/codes/code:alphanum', 'CodeController', 'show');
```

| Type | Matches | Note |
|---|---|---|
| `number` | `[0-9]+` | ASCII on purpose: the value exists to survive an `(int)` cast, and PHP's cast does not understand Eastern Arabic or Devanagari digits |
| `alpha` | `\p{L}+` | Any script: `café`, `北京`, `Владимир` |
| `alphanum` | `[\p{L}\p{N}]+` | Letters and digits from any script |

Values reach the action **positionally**, in the order they appear in the URL,
after the request:

```php
Router::get('/tenant/tenantId:number/posts/postId:number', 'PostController', 'show');

public function show(Request $request, string $tenantId, string $postId): Response
{
    // ...
}
```

The request path is decoded segment by segment before matching, so
`/products/caf%C3%A9` matches `/products/name:alpha`. Encoded separators
(`%2F`, `%5C`) are **not** turned into real ones: `/a%2Fb` never reaches the
route `/a/b`.

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
    Router::get('/posts', 'ApiPostController', 'index')->name('posts.index');
    Router::post('/posts', 'ApiPostController', 'store')->name('posts.store');
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
`InvalidArgumentException` for a missing, invalid or unknown one. Duplicate
names are rejected at registration.

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
$request->json();                    // decodes the body, throws JsonException
$request->rawBody;

$request->cookie('session');
$request->file('avatar');
$request->ip();
$request->isSecure();
$request->expectsJson();

$request->user();                    // set by the Authenticate middleware
$request->route('id');               // route parameter
$request->attribute('locale');       // attached by a middleware
$withUser = $request->withAttribute('user', $user);   // clones
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
Response::view('posts/index', ['posts' => $posts]);
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

### BaseController

```php
$this->view('posts/index', ['posts' => $posts]);   // an HTML Response
$this->redirect('/posts');                          // a redirect Response
```

> **A controller does not need a base class.** Both methods are one line each,
> forwarding to `Response::view()` and `Response::redirect()`, and those are
> what `make:controller` generates against. `BaseController` belongs to this
> repository's example application and is **not** in the package, so a project
> that installed the framework calls the `Response` methods directly:
>
> ```php
> return Response::view('posts/index', ['posts' => $posts]);
> return Response::redirect('/posts');
> ```
>
> Both forms are current and produce the same response. Extending a base class
> is a convenience when several controllers share helpers of your own, not a
> requirement of the framework.

### BaseAPIController

```php
$this->json(['ok' => true], HTTP_CREATED);

// Decodes the body, or hands back the error response ready to return
$data = $this->payload($request);
if ($data instanceof Response) {
    return $data;
}
```

`payload()` answers **415** when the `Content-Type` is not `application/json`
and **400** when the body does not decode.

### Global helpers

Loaded on every request by `src/utils.php`:

```php
e($value);                    // escapes for HTML: <script> → &lt;script&gt;
asset('css/app.css');         // → /assets/css/app.css
asset('js/sfjs.js');          // → /assets/js/sfjs.js
csrf_token();  csrf_field();  csrf_meta();  csrf_verify();
```

`asset()` prefixes `/assets/` and **validates the path**: directory traversal
and characters outside `[A-Za-z0-9._-]` throw `InvalidArgumentException`.

Static files live in `public/assets/{css,js,images}/`.

And by `src/helpers.php`:

```php
cache();                      // a CacheManager with the file driver
logger();                     // a LogManager, configured from LOG_*
mailer();                     // a MailManager, configured from MAIL_*
now();                        // the current instant, in UTC
dispatch(new MyJob());        // queues a job
__('app.welcome', ['name' => 'Ana']);
trans_choice('app.items', 3);
locale();
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
    SecurityHeaders::class,
    StartSession::class,
    VerifyCsrfToken::class
);

// Per group, in src/routes.php
Router::group('/admin', function (): void {
    Router::get('/panel', 'AdminController', 'index');
}, 'admin.', [RequireTokenMiddleware::class]);

// Per route
Router::get('/report', 'ReportController', 'show')
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

`VerifyCsrfToken` lets safe methods and bearer-token requests through — a
browser never attaches a bearer token by itself, so there is no cross-site
request to forge. Path prefixes can be exempted:

```php
new VerifyCsrfToken(['/api'])
```

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
Response::view('posts/index', ['posts' => $posts]);
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
| `escape` | Escapes HTML explicitly |
| `json` | JSON with `UNESCAPED_UNICODE` |
| `format(fmt)` | `sprintf` |
| `trim` | Strips surrounding whitespace |
| `abs` / `round(n)` | Numeric |
| `default(v)` | Replaces `null` and the empty string |

The string filters count **characters, not bytes**: `truncate(5)` over
`日本語テキスト` returns `日本...`, never a byte cut in half.

`||` is not mistaken for a filter — `{{ $a || $b ? 'y' : 'n' }}` works.

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
@media (min-width: 40rem) { ... }            {{-- preserved --}}
```

### Global variables

```php
$engine->setGlobal('siteName', 'My Site');
$engine->setGlobals(['version' => '1.0.0', 'year' => date('Y')]);
```

### Compilation cache

Templates compile to PHP on disk and are executed with `include`, so **OPcache
works** and runtime errors report a real file and line. The write is atomic and
invalidates OPcache for that exact path. The cache revalidates by timestamp.

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
Filter not registered: nosuchfilter
```

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
final class PostController extends BaseController
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
statements** (`EMULATE_PREPARES => false`). A failed connection logs the detail
and throws a generic exception — the host, database and user never reach the
visitor.

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
->insert(['name' => 'John'])      // returns the generated id (string)
->update(['name' => 'Smith'])     // returns affected rows
->delete()                        // returns affected rows
->toSql()      // inspect the SQL without running it
->bindings()   // the bound values
```

**Security.** Every value is bound with the right PDO type. Every identifier —
table, column, alias — is validated against `^[A-Za-z_][A-Za-z0-9_]*$` and
quoted for the driver; an invalid identifier throws
`InvalidArgumentException` rather than reaching the SQL.

Pagination is translated per dialect: `LIMIT/OFFSET` on MySQL, PostgreSQL and
SQLite, `TOP` on SQL Server, `FIRST` on Firebird, `OFFSET … FETCH NEXT` on
Oracle. A driver without support fails explicitly.

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
knowing: a failure inside the inner callback rolls back the outer work too.
Savepoints would avoid that, but their syntax differs between drivers, and
degrading silently on the ones that lack them would be worse than being
explicit.

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
Post::findOrFail(1);               // Post, or RuntimeException
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

### Writing

```php
$post = new Post(['title' => 'Hello']);   // only columns listed in $fillable
$post->save();                     // INSERT, and the key comes back filled

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
dirty issues no query.

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

`with()` takes several relations: `->with('author', 'comments')`, and it works
for many-to-many too:

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
- **Query builder** when you work with sets — reports, aggregates, `GROUP BY`,
  bulk updates. Hydrating into objects does not help, and sometimes gets in the
  way.
- **Raw SQL** (`Database::query()`) when the query is the product: a CTE, a
  window function, something specific to the dialect.

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
./sfphp make:migration create_users_table
./sfphp make:migration:create users        # pre-filled with id + timestamps

./sfphp migrate
./sfphp migrate --step=2
./sfphp rollback
./sfphp rollback --step=3
./sfphp status
./sfphp db:fresh                           # drops everything and rebuilds
```

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
$schema->hasTable('posts');
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
$table->string('name')->collation('en_US.utf8')->charset('utf8mb4');
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
$table->check('price >= 0');

$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->foreign('user_id')->references('id')->table('users')->nullOnDelete();

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

An unknown name lists the available seeders and exits with code 1.

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
            'name' => 'User ' . mt_rand(1000, 9999),
            'email' => 'user' . mt_rand(1000, 9999) . '@example.com',
            'password' => password_hash('password', PASSWORD_BCRYPT),
        ];
    }
}
```

```php
$data  = (new UserFactory())->make();                      // an array, unsaved
$user  = (new UserFactory())->create();                    // saved
$many  = (new UserFactory())->count(50)->create();
$admin = (new UserFactory())->create(['role' => 'admin']);  // overrides
```

`make()` and `create()` return **arrays**, not objects. To work with objects,
see [Models](#models).

---

## Cache

```php
$cache = cache();                    // global helper, file driver

$cache->put('key', $value, 300);     // TTL in seconds; null never expires
$cache->get('key');
$cache->get('key', 'default');
$cache->has('key');
$cache->forget('key');
$cache->flush();
$cache->pull('key');                            // reads and removes
$cache->remember('users', 600, fn () => /* ... */);   // computes when missing

$cache->increment('hits');           // atomic; returns the new value
$cache->increment('hits', 5);        // add more than one
$cache->decrement('slots');

$cache->increment('window', 1, 60);  // a counter that expires in 60 seconds
$cache->ttl('window');               // seconds left, or null
```

### Counters

`increment()` is not `get()` plus `put()`, and the difference is the point.
Two requests that arrive together both read 4 and both write 5 — one hit is
lost. That is harmless for a cached page and not harmless for a rate limiter,
which counts precisely when several requests arrive at once.

The addition happens where the data lives: inside an exclusive lock for the
file driver, and as `INCRBY` for Redis, so the driver adds rather than PHP.

The lifetime is applied **only when the counter is created**. A counter that
already exists keeps the expiry it had, so a client that keeps knocking cannot
push its own window forward and sit inside a limit forever.

The corollary is worth knowing: a counter first created **without** a lifetime
never gets one. `increment('hits')` followed by `increment('hits', 1, 60)`
leaves a counter that never expires, and `ttl()` answers `null`. Pass the
lifetime on the call that creates the counter, or on every call — the rate
limiter does the latter.

> Adding `increment()` and `ttl()` to the `Cache` interface is a **breaking
> change** for an application that ships its own driver: a class implementing
> `Cache` must now implement both.

### Choosing the driver

```ini
CACHE_DRIVER=file          # the default
CACHE_DRIVER=redis         # needs ext-redis
CACHE_DRIVER=array         # memory, gone at the end of the request

CACHE_PATH=storage/cache   # where the file driver writes
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

Selecting `redis` without `ext-redis` **fails at startup** rather than falling
back to the file driver. A silent fallback would leave an operator believing
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

```bash
./sfphp cache:clear
./sfphp cache:flush
```

---

## Queue

```php
use SfphpProject\src\Queue\Job;

final class SendEmailJob extends Job
{
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

(new SendEmailJob('a@b.com'))->tries(5)->timeout(120);
```

```bash
./sfphp queue:work                 # default: 3600s
./sfphp queue:work --timeout=7200
./sfphp queue:failed
```

The worker processes until the timeout, increments attempts on failure, and
moves a job to `failed_jobs` once its tries run out. With `ext-pcntl`, `SIGTERM`
and `SIGINT` shut it down gracefully.

The `jobs` and `failed_jobs` tables are created on demand, on the first
operation that needs them — instantiating the driver opens no connection.

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

### What is missing

| Missing | Situation |
|---|---|
| Several named queues | Everything goes to `default`; the column exists and nothing reads it |
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
| `assertImage()` | Decodes the header, so a fake image is refused |

Type and extension are both worth checking, because they are different lies:
what a file contains decides how a library reads it, and what its name ends in
decides how a web server treats it. A real PNG called `avatar.php` is still a
problem if it lands somewhere PHP is executed.

```php
try {
    $file->assertType(['application/pdf'])->assertSmallerThan(5 * 1024 * 1024);
} catch (UploadException $e) {
    $errors['invoice'] = $e->getMessage();
}
```

`Validator` is deliberately not involved. It works on scalars from a form, and
an upload's real type is something only the file itself can answer.

### Storing

```php
$path = $file->store('/var/app/storage/invoices');
// /var/app/storage/invoices/9f2c…a41.pdf

$path = $file->store($directory, 'report.csv');   // still sanitised
```

The stored name is **random**, and that is the point rather than a convenience:
the client's name is the client's input. The extension is carried over only when
it is plain alphanumeric, so nothing in it can be a path or a second extension.
A name you pass yourself is reduced to something that cannot be a path, and
refused outright when nothing usable is left.

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
file whose every property is a list. `files()` turns it the right way round, and
`file()` answers `null` for such a field rather than handing back something
unusable. `hasFile()` asks whether a **usable** file arrived, not whether the
field was present.

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
reach a real person by accident.

### One driver, every provider

`smtp` is the only transport the framework needs, and that is not a compromise.
Every service anyone contracts — SES, Postmark, SendGrid, Mailgun, Resend,
Brevo — accepts SMTP, so changing provider is four values in the environment
rather than a new driver. An HTTP client per vendor would be more code reaching
fewer of them.

Both routes to TLS work, because providers are split between them:

| `MAIL_ENCRYPTION` | Port, usually | What happens |
|---|---|---|
| `tls` | 587 | Plain connection, upgraded with `STARTTLS` |
| `ssl` | 465 | Encrypted from the first byte |
| `none` | 25, 1025 | Neither — a local server only |

`AUTH PLAIN` and `AUTH LOGIN` are both supported; the server's own announcement
decides which is used. The certificate is verified by default.

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
message readable.

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
intended recipient in an `X-Intended-For` header. It is for a staging
environment working from a copy of production data, where the addresses in the
database belong to real people.

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

Dispatcher::listen(OrderPlaced::class, SendReceipt::class);
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
./sfphp make:listener SendReceipt
```

Registering against a parent class or an interface catches its children, which
is what makes "record every domain event" expressible without naming each one:

```php
Dispatcher::listen(DomainEvent::class, AuditTrail::class);
```

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

Rules are a **pipe-separated string**, not an array. Arguments follow a colon.

| Rule | Checks |
|---|---|
| `required` | Not null and not empty |
| `email` | `FILTER_VALIDATE_EMAIL` |
| `min:N` | At least N **characters** |
| `max:N` | At most N **characters** |
| `alpha` | Letters only, in **any script** (`\p{L}`) |
| `alphanum` | Letters and digits from any script |
| `number` | ASCII digits only (safe for `(int)`) |

An unknown rule throws `InvalidArgumentException` — a typo fails early rather
than silently passing validation.

`ValidationResult`: `passes()`, `fails()`, `errors()`, `validated()`.

Custom messages:

```php
Validator::validate($data, ['name' => 'required|min:3'], [
    'name' => [
        'required' => 'Please enter your name.',
        'min' => 'The name needs at least 3 letters.',
    ],
]);
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
__('http.not_found_title');                    // 404 - Page Not Found
__('app.welcome', ['name' => 'Ana']);          // Welcome, Ana!
__('app.welcome', ['name' => 'Ana'], 'pt_BR'); // in a specific language
locale();                                      // 'en'
```

The key is `group.entry`, and it may nest deeper (`app.form.title`). **A key
with no translation comes back as it is** — the gap shows up where it is,
rather than rendering an empty page.

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
second for everything else.

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

It also adds the `Content-Language` header, and the framework's error pages now
declare the right `lang` on the document — before, they said `lang="en"`
regardless of content:

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
preference. With no configuration, both default to English.

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
| Anything unparsable | `null`, rather than an exception |

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

Built on **PCRE with `/u`**, not on `mbstring`. PCRE is always compiled into
PHP; `mbstring` is optional, and requiring it would put a hard dependency in
front of every install. The exception is case conversion, which needs
per-locale tables PCRE does not expose: there `mbstring` is used when present
and ASCII folding is the fallback when it is not — degrading a display detail
rather than corrupting data.

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
    return $this->redirect('/dashboard');
}

return $this->view('login', ['error' => __('auth.failed')]);
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
    return $this->view('dashboard', ['user' => $request->user()]);
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
if (Auth::attempt($credentials) && Hash::needsRehash($user->password)) {
    $user->password = Hash::make($credentials['password']);
    $user->save();
}
```

### The middleware

```php
// Global: identifies whoever it can, lets anonymous requests carry on
$router->middleware(new Authenticate('web'));

// Per route: refuses an anonymous request
Router::get('/dashboard', 'DashboardController', 'index')
    ->middleware(new Authenticate('web', required: true));

// An API uses the token guard
Router::group('/api', function (): void {
    Router::get('/me', 'ApiController', 'me');
}, 'api.', [new Authenticate('api', required: true)]);
```

When refusing, it answers **401** to a client expecting JSON and **redirects to
`/login`** for a browser. The redirect is deliberate: a 401 with no
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
cannot use it afterwards, because the id the victim ends up with is new.

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

`revokeUser()` is "log out everywhere". It cannot list the user's tokens —
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
Gate::authorize('update', $post);  // throws AuthorizationException
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

Two decisions worth knowing:

**An ability nobody declared is denied.** Allowing by default would let a typo
in an ability name silently open a door.

**A policy receives `null` when the request is anonymous**, rather than being
refused beforehand. That is what lets a public rule — reading a published post,
say — live alongside the others in the same place.

`AuthorizationException` is distinct from being unauthenticated: it means the
framework knows who you are and the answer is still no. One is **403**, the
other **401**.

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

### Rate limiting

```php
Router::post('/login', 'AuthController', 'login')
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
- Session id **regenerated on login and on logout**, against session fixation
- `session.use_strict_mode` on, so an id PHP never issued is refused rather
  than adopted
- An **idle** and an **absolute** deadline, both enforced in the pipeline — see
  [Sessions](#sessions)
- A store that can be shared between instances, so sessions are not tied to one
  machine
- A 32-byte CSRF token, compared with `hash_equals`
- `VerifyCsrfToken` applies the check **by default** to every state-changing
  request; safe methods and bearer-token requests pass

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
- A failed connection logs the detail and throws a generic exception: the host,
  database and user never reach the visitor

### Uploads

- A file is refused unless `is_uploaded_file()` agrees it is one, so a forged
  `$_FILES` cannot make the framework read an arbitrary path
- The media type is read from the file's own bytes, never from the header the
  client sent
- The stored name is generated; the client's name is stripped of path segments
  and null bytes and used only for display

See [File uploads](#file-uploads), including why the stored file still belongs
outside the document root.

### Output

- SFHT's `{{ }}` escapes by default; raw output takes `{!! !!}`
- `e()` for raw PHP templates
- Exception detail appears only with `APP_ENV=development`

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

The database driver needs its table:

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
| Listing or revoking another device's session | The `database` driver's table makes it possible to build; nothing ships |
| Periodic id rotation | The id changes on login, on logout and on expiry, not on a timer |
| Encryption at rest | The payload is stored as PHP serialises it; a database or cache with its own encryption is the answer |
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
if (!csrf_verify()) {
    http_response_code(HTTP_FORBIDDEN);
    return;
}
```

The token is 32 bytes from `random_bytes`, compared with `hash_equals` in
constant time, and the session uses `httponly`, `samesite=Lax` and `secure`
over HTTPS. The token is accepted from the `_token` field or from the
`X-CSRF-Token` / `X-XSRF-Token` headers.

In practice you rarely call `csrf_verify()` yourself: the `VerifyCsrfToken`
middleware applies the check by default. See [Middleware](#middleware).

---

## JWT

```php
use SfphpProject\src\JWT;

$token = JWT::generate(['id' => 1, 'email' => 'john@example.com']);

if (JWT::validate($token)) {
    // the token is intact and unexpired
}

$claims = JWT::claims($token);   // validates and returns the payload, or null
```

What the signature enforces:

- `generate()` **requires** the `id` and `email` claims; without them it throws
  `InvalidArgumentException`
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
dump($order);              // show it and carry on
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

- **500** with a negotiated `Content-Type` — JSON if the request asked for or
  sent JSON, HTML otherwise
- The real message **only** with `APP_ENV=development`; in production, the
  translated `http.server_error_message`
- The detail always goes to the logger, with the request id attached — see
  [Logging](#logging)

The 404, 405 and 500 pages use inline CSS, make no external request, and
respect `prefers-color-scheme`. All three are rendered in the visitor's
language; until this version the 500 page alone was not, shipping a Portuguese
title and `lang="pt-br"` whatever the request asked for.

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

`password` `password_confirmation` `current_password` `new_password` `secret`
`token` `_token` `access_token` `refresh_token` `api_key` `apikey`
`authorization` `auth` `cookie` `set-cookie` `credit_card` `card_number` `cvv`
`ssn` `cpf`

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
Router::get('/health', 'HealthController', 'show');

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
orders_placed 2
payments_failed{gateway="stripe"} 1
report_build_ms_count 2
report_build_ms_sum 41.882
report_build_ms_min 18.204
report_build_ms_max 23.678
```

### What is missing

| Missing | Situation |
|---|---|
| Aggregation across instances | Each process holds its own counts; a scraper or a push gateway does the joining |
| Histograms and percentiles | Count, sum, min and max are recorded; a p99 needs buckets this does not keep |
| Persistence | Counts are lost when the process ends, which under php-fpm is every request. Scrape a persistent runtime, or push |

---

## CLI

`./sfphp` exposes **35 commands**.

### Generation (12 generators)

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

./sfphp make:scaffold Post     # controller + model + repository + service
```

> Every generator now produces code against something that exists and runs —
> `make:event` and `make:listener` included, since `Dispatcher` arrived. What a
> generated file still owes you is its registration: a listener has to be handed
> to `Dispatcher::listen()` where the application boots. See [Events](#events).

### Database

```bash
./sfphp make:migration create_users_table
./sfphp make:migration:create users
./sfphp migrate [--step=N] [--path=dir]
./sfphp rollback [--step=N]
./sfphp status
./sfphp db:fresh
./sfphp db:seed [--class=UserSeeder]
```

### Cache and queue

```bash
./sfphp cache:clear
./sfphp cache:flush
./sfphp queue:work [--timeout=3600]
./sfphp queue:failed
```

All four follow `CACHE_DRIVER` and `QUEUE_DRIVER`. A `cache:clear` that emptied
a file cache while the application used Redis would report success and change
nothing.

### Scaffolding a project

```bash
./vendor/bin/sfphp init
./vendor/bin/sfphp init --namespace=Acme\Shop
./vendor/bin/sfphp init --force            # overwrite what is there
```

For a project that installed the framework and has nothing to run yet. See
[As a dependency](#as-a-dependency).

### Assets

```bash
./sfphp assets:publish                     # into public/assets
./sfphp assets:publish --path=web/static   # somewhere else
./sfphp assets:publish --force             # overwrite what is there
./sfphp assets:publish --symlink           # link instead of copying
```

Copies SFCSS and SFJS out of the package and into a directory the project
serves. `composer install` and `./sfphp serve` both run it, so this is for an
upgrade or an unusual layout; a run that finds the same files copies nothing and
says so.

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
./sfphp serve          # http://localhost:8000
./sfphp routes         # a table of the registered routes
./sfphp env:example    # creates .env from .env-example
./sfphp css:build      # builds SFCSS from the config; --config= --output=
./sfphp js:build       # minifies SFJS
./sfphp tinker         # REPL — local development only
./sfphp list
./sfphp version
./sfphp help [command]
```

`tinker` evaluates input with `eval()`. It is a local development tool; never
expose the CLI to untrusted input.

---

## SFCSS

A utility CSS framework. **It arrives built** — `composer require` delivers the
stylesheet, and `composer install`, `sfphp init` and `sfphp serve` each copy it
into `public/assets`, so using it is one line of HTML:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nothing has to be generated to use SFCSS. The generator is there for changing
it, which is [further down](#changing-sfcss).

| | |
|---|---|
| Classes in total | **2,339** |
| — base utilities | 1,211 |
| — `hover:` variants | 600 |
| — responsive variants (`sm` `md` `lg` `xl`) | 528 |
| Colour classes | 600 palette (20 families × 10 shades × `bg`/`text`/`border`) + 25 theme |
| Size | 112KB raw · 94KB minified · **16.4KB gzipped** |
| Dependencies | none |

### Changing SFCSS

The colours, the spacing scale, the type scale and the breakpoints come from a
config, and the generator ships with the package — a stylesheet described as
"generated from a config" is of no use to somebody who has no generator.

```bash
cp vendor/fabioaacarneiro/sfphp/tools/css-builder/sfcss.config.json .
# edit it: palettes, spacing, breakpoints, fonts
./vendor/bin/sfphp css:build
```

`css:build` uses **your** config when there is one next to `composer.json` and
writes into your `public/assets/css`. Editing the copy inside `vendor/` would
work until the next `composer update` threw it away, which is why yours wins and
why the build never writes into the package.

```bash
./vendor/bin/sfphp css:build --config=design/sfcss.json --output=web/css
```

> **A stylesheet you built is not overwritten.** `composer install` and `serve`
> publish the framework's assets, and when one of yours differs they say they
> kept it rather than replacing it. `assets:publish --force` takes the
> framework's version back.

For a change of colour alone, editing the config is more than you need: the
theme reads CSS variables, so overriding them in your own stylesheet is enough.

```css
:root { --primary: #ff6600; }
```

### What the framework's own screens use

`code`, `pre` and `kbd` are styled, `font-mono` and `font-sans` set the family,
and the neutral surfaces are variables rather than fixed hex values:

```css
--surface  --surface-raised  --surface-sunken
--surface-border  --surface-border-strong
--body-color  --body-color-muted  --code-color
```

A page opts into a theme with `data-theme` on its root element — `light` (the
default), `dark`, or `auto` to follow the reader's system setting. Only those
eight change. Brand and palette colours keep their meaning in both themes; what
has to change is the paper they sit on, and a page that says nothing stays
light.

That set exists because the error page and the dump screen are built from SFCSS
and inline it — a framework with its own stylesheet should not have its own
screens written in a second one.

Full reference: [SFCSS](SFCSS.md) and
[utilities reference](SFCSS_UTILITIES.md).

---

## SFJS

A dependency-free JavaScript library — 11KB raw, 8KB minified, **2.4KB
gzipped**. Exposed as `window.sf`.

```html
<script src="/assets/js/sfjs.min.js"></script>
<script src="/assets/js/sfjs.js"></script>     <!-- readable, for debugging -->
```

```bash
./sfphp js:build         # rebuilds sfjs.min.js from sfjs.js
./sfphp assets:publish   # copies both into public/assets
```

The minifier removes comments and collapses whitespace, and deliberately does
not rewrite tokens — no shortened names, no dropped semicolons, no statements
joined onto one line. Those are where a minifier changes what a program means,
and the extra kilobyte is not worth owning a JavaScript parser in a framework
that has no dependencies. A test checks that both builds expose the same API and
that the minified one still parses.

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

### Declarative attributes

```html
<button @hxGet="/api/data" @hxTarget="#content">Load</button>
<button @hxDelete="/api/item/1" @hxTarget="#item" @hxSwap="outerHTML">Delete</button>

<form @hxPost="/users" @hxTarget="#list">
  <input name="email" @validate="email">
  <button type="submit">Create</button>
</form>

<button @toggle="menu">Menu</button>
<div id="menu">...</div>
```

`@hxSwap` accepts `innerHTML` (the default), `outerHTML`, `beforebegin`,
`afterbegin`, `beforeend` and `afterend`.

`@validate` runs on `blur` and accepts `required`, `email`, `number`, `url`,
`minLength:N`, `maxLength:N` and `pattern:regex`.

---

## Tests

A bespoke runner, no PHPUnit — consistent with zero dependencies.

```bash
composer run lint        # php -l across the project
composer run test        # 140 unit cases
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

CI runs two jobs: `unit` on a PHP 8.1–8.4 matrix **without `mbstring`**, which
is what keeps the UTF-8 handling from depending on the extension; and
`integration` with MySQL 8, PostgreSQL 16 and Redis 7 as services.

`composer run docs` runs in the unit job too. The documentation exists in three
languages, and prose cannot be compared mechanically — but structure can. It
asserts the three versions have the same sections, subsections, tables and code
blocks, in the same order and with the same fence languages, and that every
relative link and in-page anchor resolves. That catches the two things that
actually go wrong when three files are edited by hand: a section added to one
language and forgotten in the others, and a link left pointing at a file that
moved.

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
| **Session revocation from elsewhere** | Ending another device's session is buildable on the `database` driver's table; nothing ships. See [Sessions](#sessions) |

SFHT also has no automatic loop variables (`$loop`) and no partial block
inheritance (`@parent`).

---

*Documentation reviewed on 2026-09-22 against the running code.*

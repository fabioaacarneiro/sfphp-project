# SFPHP — Documentation

A full-stack PHP framework with **zero runtime dependencies** and Unicode
correctness across the whole surface. This documentation describes what the
code does today. Where something does not exist, it says so — see
[Known limitations](#known-limitations).

> Verified against PHP 8.4 · suite: 78 tests, 0 failures
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
- [Validation](#validation)
- [Internationalisation](#internationalisation)
- [UTF-8 strings](#utf-8-strings)
- [Authentication](#authentication)
- [Security](#security)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Error handling](#error-handling)
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
cache, queues, and a CLI with 32 commands.

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

And four files always loaded (`autoload.files`): `app/config/config.php`,
`src/utils.php`, `src/http.php`, `src/helpers.php`.

---

## Request lifecycle

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ config.php → loads .env (optional), defines APP_NAME/VERSION/ENV/LOCALE
 │      utils.php  → global helpers: e(), asset(), csrf_*()
 │      http.php   → HTTP_OK, GET, POST, ... constants
 │      helpers.php→ cache(), dispatch(), __(), trans_choice(), locale()
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
| `SecurityHeaders` | Adds `nosniff`, `X-Frame-Options`, `Referrer-Policy`; CSP and HSTS on request |
| `SetLocale` | Negotiates the language from `Accept-Language` |
| `StartSession` | Starts the session with `httponly` + `samesite=Lax` + `secure` over HTTPS |
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
```

Changing the driver:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Cache\RedisDriver;

$cache = new CacheManager(new MemoryDriver());   // for this request only
$cache = new CacheManager(new RedisDriver());    // needs ext-redis
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
        mail($this->to, 'Hello', 'Body');
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

The application's `lang/` path is registered in `app/config/config.php`, which
runs from the autoloader — so the CLI, a queue worker and the test suite all
see the same messages a web request would.

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
| Per-locale date and number formatting | `ext-intl` does that well; the framework does not attempt it |
| Route translation (`/products` ↔ `/produtos`) | Does not exist |
| String extraction into catalogs | No command scans the code |
| Text direction (RTL) | A template decision, not the translator's |

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
| Revoke before expiry | yes, delete the session | **no** |
| `Auth::login()` | yes | no — throws |

`SessionGuard` stores **only the identifier** in the session, never the user.
Serialising the model would freeze a copy of the row: someone whose permissions
were revoked would keep them until the session expired, and renaming a column
would break deserialisation of every live session.

It also **regenerates the session id** on login and on logout. On login that is
what stops session fixation: an attacker who planted a known id beforehand
cannot use it afterwards, because the id the victim ends up with is new.

`TokenGuard` stores nothing on the server, which is what makes it usable
outside a web request — and also what means **a token cannot be revoked before
it expires**. If you need revocation, you need a list of invalidated tokens,
which the framework does not provide.

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
| "Remember me" | The migration brings the `remember_token` column; nothing uses it |
| Password recovery | No token table, no e-mail flow |
| E-mail verification | The `email_verified_at` column exists; the flow does not |
| Token revocation | A JWT is valid until it expires; there is no revocation list |
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

The window is **not** renewed on each attempt: renewing would let a client that
keeps knocking hold its own window open indefinitely, and the counter would
never forgive.

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

### Output

- SFHT's `{{ }}` escapes by default; raw output takes `{!! !!}`
- `e()` for raw PHP templates
- Exception detail appears only with `APP_ENV=development`

### What is missing

| Missing | Situation |
|---|---|
| Token revocation | A JWT is valid until it expires; there is no revocation list |
| Password recovery, e-mail verification, 2FA | Out of scope |
| "Remember me" | The `remember_token` column exists; nothing uses it |
| Idle or absolute session timeout | Whatever `php.ini` says |
| Malicious upload protection | `$_FILES` is exposed raw; validating type and destination is the application's job |
| Audit / security logging | Only `error_log()` |

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

## Error handling

An exception thrown inside an action is caught by the router, which returns the
error response through the same pipeline — so outgoing middleware still runs.
`ErrorHandler::toResponse()` is the shared renderer.

The global registration (`ErrorHandler::register()`) stays, because it covers
what a `try/catch` cannot reach: a warning during bootstrap, and a fatal
reported at shutdown — out of memory, exceeded execution time, a parse error in
an included file. Without it, those become blank pages.

On both paths the response is:

- **500** with a negotiated `Content-Type` — JSON if the request asked for or
  sent JSON, HTML otherwise
- The real message **only** with `APP_ENV=development`; in production, just
  `Internal Server Error`
- The detail always goes to `error_log`

The 404, 405 and 500 pages use inline CSS, make no external request, and
respect `prefers-color-scheme`.

---

## CLI

`./sfphp` exposes **32 commands**.

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

> `make:middleware` and `make:policy` now generate against contracts that exist
> and run. `make:event` and `make:listener` still produce code for missing
> infrastructure: there is no event dispatcher. See
> [Known limitations](#known-limitations).

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

### Server and utilities

```bash
./sfphp serve          # http://localhost:8000
./sfphp routes         # a table of the registered routes
./sfphp env:example    # creates .env from .env-example
./sfphp css:build      # builds SFCSS from the config
./sfphp tinker         # REPL — local development only
./sfphp list
./sfphp version
./sfphp help [command]
```

`tinker` evaluates input with `eval()`. It is a local development tool; never
expose the CLI to untrusted input.

---

## SFCSS

A utility CSS framework generated from `tools/css-builder/sfcss.config.json`.
The `hover:` variants and the `sm`/`md`/`lg`/`xl` breakpoints are generated
from that config.

| | |
|---|---|
| Classes in total | **2,337** |
| — base utilities | 1,209 |
| — `hover:` variants | 600 |
| — responsive variants (`sm` `md` `lg` `xl`) | 528 |
| Colour classes | 600 palette (20 families × 10 shades × `bg`/`text`/`border`) + 25 theme |
| Size | 110KB raw · 92KB minified · **16.1KB gzipped** |
| Dependencies | none |

```bash
./sfphp css:build     # builds public/assets/css/sfcss.css and .min.css
```

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

Full reference: [SFCSS](SFCSS.md) and
[utilities reference](SFCSS_UTILITIES.md).

---

## SFJS

A dependency-free JavaScript library — 12KB raw, **3.0KB gzipped**. Exposed as
`window.sf`.

```html
<script src="/assets/js/sfjs.js"></script>
```

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
composer run test        # 78 unit cases
composer run test:db     # integration against real MySQL/PostgreSQL
composer run test:all
composer run docs        # the three languages agree, and every link resolves
```

`tests/db.php` needs DSNs in the environment and skips with a notice when there
are none:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
  composer run test:db
```

CI runs two jobs: `unit` on a PHP 8.1–8.4 matrix **without `mbstring`**, which
is what keeps the UTF-8 handling from depending on the extension; and
`integration` with MySQL 8 and PostgreSQL 16 as services.

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
| **Session timeout** | Idle or absolute: whatever `php.ini` says. See [Security](#security) |
| **Password recovery and two-factor** | Login exists; these flows do not. See [Authentication](#authentication) |
| **Event system** | `make:event` and `make:listener` generate classes with no dispatcher |
| **A full ORM** | There is a [Models](#models) layer with hydration, attribute types, relations (including many-to-many) and `with()`. There is no identity map, unit of work, lazy-loading proxy, polymorphic relation or schema derived from the class — and [ORM or query builder?](#orm-or-query-builder) explains the reason for each |
| **Per-locale formatting** | Dates and numbers are not formatted per language; `ext-intl` does that well and the framework does not attempt it. See [Internationalisation](#internationalisation) |
| **Time zones** | No dedicated handling |
| **Structured logging** | Only `error_log()` — plain text |
| **Route caching** | Dispatch is O(n), one `preg_match` per route. Fine for dozens, not hundreds |
| **Pluggable session** | Native `$_SESSION`. Multiple instances need sticky sessions |
| **Distribution as a package** | The controller namespace is already a Router parameter, but `composer.json` still describes an application rather than a library |

SFHT also has no automatic loop variables (`$loop`) and no partial block
inheritance (`@parent`).

---

*Documentation reviewed on 2026-09-21 against the running code.*

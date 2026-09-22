# SFPHP — Simple Framework PHP

> 🌍 **Read this in:** [English](README.md) ·
> [Português](README.pt-BR.md) · [Español](README.es.md)

A full-stack PHP framework with **zero runtime dependencies**, built to be used
in any language and any script.

`composer.json` requires only `php ^8.1`, `ext-json` and `ext-pdo`. The
`vendor/` directory holds nothing but Composer's autoloader. No page the
framework serves — not even its error pages — loads CSS, fonts or JavaScript
from a CDN.

## Requirements

- PHP 8.1 or later
- Composer 2
- PDO with your database's driver (optional — only if you use a database)

## Installing

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

That is the whole setup: <http://localhost:8000> answers, and what you have is
a working application to edit — a controller, its views, the routes, migrations
for users and sessions, and the console at `./sfphp` in the project root. `.env`
is written for you with a real `JWT_KEY`, and SFCSS and SFJS are published into
`public/assets`.

Everything there is yours; the framework is `src/` and does not mind what you
delete.

Nothing you edit lives in `vendor/`. It holds the autoloader and nothing else,
because the framework has no dependencies:

```
my-app/
  public/      what the web server serves
  app/         your code and your templates
  src/         the framework, and what configures it: routes, middleware
  database/    migrations, seeders, factories
  lang/        your message catalogs
  sfphp        the console
  vendor/      the autoloader. Nothing to open
```

To work on the framework itself, clone it — a clone adds the test suite, the
documentation in three languages and the CI definition:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project && composer install && cp .env-example .env && ./sfphp serve
```

## Debugging

```php
dump($order);          // show it and carry on
dd($request->all());   // show it and stop
```

`dd()` replaces the response with a page showing only what was dumped — built
from SFCSS, with the calling line, property visibility, string lengths, and
collapsible branches. In a terminal the same dump is printed as indented text.
In production it goes to the log instead, so a forgotten `dd()` is an entry you
can read rather than a page of internals handed to a visitor.

## Running

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalent
```

In production, point `DocumentRoot` at `public/`.

## What is in it

| | |
|---|---|
| **Routing** | Typed parameters that work in any script, groups, named routes, URL generation |
| **HTTP** | Request/Response objects and a middleware pipeline — global, per group and per route |
| **i18n** | Per-language catalogs (en, pt_BR, es), `Accept-Language` negotiation, plurals by range or per-language rule |
| **Authentication** | Session and token guards, hashing over `password_hash`, policies through `Gate` |
| **DI container** | Autowiring by reflection, lazy factories, cycle detection |
| **Query builder** | Whitelisted identifiers, every value bound, pagination per dialect, transactions |
| **Models** | Hydration into objects, attribute types, relations (including many-to-many) and `with()` against N+1 |
| **Schema builder** | 30+ column types with real MySQL 8 ↔ PostgreSQL 12 parity |
| **SFHT** | Template engine with automatic escaping, layout inheritance and an OPcache-friendly cache |
| **Logging** | JSON lines in UTC, a request id joining every line of one request, secrets redacted |
| **Time** | UTC everywhere, including the database session; zones are a display decision |
| **Sessions** | Idle and absolute deadlines, strict id validation, and a store shareable between instances |
| **Uploads** | Type read from the file's bytes, generated storage names, forged `$_FILES` refused |
| **Mail** | SMTP with STARTTLS and implicit TLS, so any contracted provider is four values in `.env` |
| **Cache** | File, memory and Redis drivers |
| **Queue** | Workers with retries, database and Redis drivers |
| **SFCSS** | 2,337 utility classes with `hover:` and responsive variants, 16.1KB gzipped |
| **SFJS** | AJAX, DOM, validation and declarative attributes — 3.0KB gzipped |
| **CLI** | 32 commands, 12 code generators |

## Built for any language

UTF-8 handling is built on **PCRE with `/u`**, not on `mbstring` — PCRE is
always compiled into PHP, `mbstring` is optional.

```php
Str::length('日本語');            // 3, not 9
Str::truncate('日本語テキスト', 5); // 日本... never a byte split in half
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/products/name:alpha', 'ProductController', 'show');   // matches /products/café
```

And the framework's own messages come out in the visitor's language — including
on a 404, which never reaches a controller:

```php
__('http.not_found_title');     // follows the request's Accept-Language
trans_choice('app.items', 5);   // plural forms by range or per-language rule
```

## Security

- **CSRF** — a 32-byte token, constant-time comparison with `hash_equals`,
  cookie with `httponly` + `samesite=Lax` + `secure` over HTTPS. Helpers
  `csrf_field()`, `csrf_meta()`, `csrf_verify()`
- **JWT** — HS256, validates signature, `alg`, `typ` and `exp`; rejects
  `alg: none` and requires a 32-byte key
- **SQL** — every value is bound, every identifier validated against a whitelist
- **XSS** — SFHT's `{{ }}` escapes by default; raw output takes `{!! !!}`
- **Mass assignment** — a model must declare `$fillable`; without it, filling
  from an array throws
- **Headers** — `nosniff`, `X-Frame-Options` and `Referrer-Policy` by default;
  CSP and HSTS available and off, because both are easy to get wrong
- **Rate limiting** — the `RateLimit` middleware, with counters in the cache
- **Proxies** — `X-Forwarded-*` is read only from declared proxies
- **Passwords** — `password_hash` with `PASSWORD_DEFAULT`, and `Auth::attempt()`
  equalises response time so a non-existent account cannot be told apart from a
  wrong password
- **Session** — the id is regenerated on login and logout, `use_strict_mode`
  refuses an id PHP never issued, and idle and absolute deadlines are enforced
- **Token revocation** — `TokenDenylist` refuses one token or every token issued
  to a user before a moment, remembering each only until it would have expired
- **"Remember me"** — a selector and a verifier, the verifier stored hashed and
  rotated on every use, so a stolen cookie works once and becomes visible
- **Middleware** — `VerifyCsrfToken` applies the CSRF check by default to every
  state-changing request

> **Running more than one instance? Set `CACHE_DRIVER=redis`.** Rate limit
> counters, the token denylist and cache-backed sessions all live in the cache,
> and the default file driver keeps a separate copy per machine — so a revoked
> token still works on the other instances and a limit of 60 is really 60 each.

The framework follows **validate on the way in, escape on the way out**.
Request values arrive unmodified on purpose: escaping on input would corrupt the
data in the database without protecting its real destination.

## Quality

```bash
composer run lint        # php -l across the project
composer run test        # 149 unit cases
composer run test:db     # integration against real MySQL/PostgreSQL
composer run docs        # the three documentation languages agree
```

CI runs the suite on a PHP 8.1–8.4 matrix **without `mbstring`**, which is what
guarantees the Unicode handling does not depend on the extension, and the schema
tests against real MySQL 8 and PostgreSQL 16.

## What is not in it

Stated up front, so you can decide with the facts: authentication covers login,
guards, authorization, token revocation and the "remember me" cookie, but not
password recovery or two-factor — those flows belong to an application, and
[Mail](docs/en/DOCUMENTATION.md#mail) is the piece the framework owed them.
Events are dispatched in-process and synchronously; there is no message broker.
The Models layer is **not a full ORM** — no
identity map, unit of work, lazy-loading proxy, polymorphic relation or schema
derived from the class. The reason for each absence is in
[ORM or query builder?](docs/en/DOCUMENTATION.md#orm-or-query-builder),
including one case where the missing piece would be a security risk under a
persistent runtime rather than merely a cost. The complete list, with the impact
of each absence, is in
[Security](docs/en/DOCUMENTATION.md#security) and
[Known limitations](docs/en/DOCUMENTATION.md#known-limitations).

## Documentation

Complete in three languages — none of them a summary of another:

| Language | Framework | SFCSS | SFCSS utilities |
|---|---|---|---|
| 🇬🇧 **English** *(primary)* | [Documentation](docs/en/DOCUMENTATION.md) | [SFCSS](docs/en/SFCSS.md) | [Utilities](docs/en/SFCSS_UTILITIES.md) |
| 🇧🇷 **Português** | [Documentação](docs/pt-BR/DOCUMENTATION.md) | [SFCSS](docs/pt-BR/SFCSS.md) | [Utilitários](docs/pt-BR/SFCSS_UTILITIES.md) |
| 🇪🇸 **Español** | [Documentación](docs/es/DOCUMENTATION.md) | [SFCSS](docs/es/SFCSS.md) | [Utilidades](docs/es/SFCSS_UTILITIES.md) |

Start at [docs/](docs/README.md) to pick a language.

## Licence

MIT — see [LICENSE](LICENSE). Created by Fabio Carneiro.

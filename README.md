# 🚀 SFPHP — Simple Framework PHP

> **Read this in:** [English](README.md) · [Português](README.pt-BR.md) · [Español](README.es.md)

**The PHP framework for developers who care about performance, security, and simplicity.**

A full-stack, production-ready PHP framework with **zero runtime dependencies**, built for speed and designed to ship. Just PHP 8.1+, your database, and your code—nothing else needed.

---

## Why Choose SFPHP?

### ⚡ **Lightning-Fast Performance**
- **No dependencies bloat** — only the PHP standard library and your database driver
- **Built-in async/await system** — 3-5x faster for I/O with native PHP Fibers
- **Optimized queries** — automatic N+1 detection, efficient relations, smart caching
- **Minimal framework overhead** — your code runs immediately, not buried in layers

### 🔒 **Security Built In**
- **Zero-trust by default** — CSRF protection, SQL binding, XSS escaping everywhere
- **Battle-tested authentication** — sessions, JWT tokens, policies, remember-me
- **Request validation** — type-safe routing parameters, input filtering
- **No security theater** — we implement what matters, skip what cargo-culting created

### 🎯 **Developer Experience**
- **Type-safe everywhere** — PHP 8.1 attributes, typed parameters, IDE autocompletion
- **Generators for speed** — 12 code generators for models, migrations, controllers, etc.
- **Comprehensive CLI** — 35 commands to manage your application
- **Intuitive API** — learn it once, it works the same everywhere

### 🌍 **Truly Multilingual**
- **UTF-8 first** — works perfectly with any language, no `mbstring` required
- **Built-in i18n** — language catalogs, pluralization rules, Accept-Language negotiation
- **Framework messages in visitor's language** — even 404 errors respect locale

### 📦 **Everything You Need, Nothing You Don't**
- 150+ built-in security features
- Database migrations & seeders
- Email with SMTP/TLS
- Redis & file caching
- Background job queues
- Session management
- File upload handling
- Logging & debugging

---

## What SFPHP Delivers

### Backend & Core

| Feature | What You Get |
|---------|-------------|
| **Routing** | Typed parameters, groups, named routes, middleware per-route |
| **HTTP** | Request/Response objects, middleware pipeline, status codes |
| **Database** | Query builder with relations, migrations, seeders, transactions |
| **ORM (Models)** | Object hydration, attribute types, with() for eager loading |
| **Schema Builder** | 30+ column types, MySQL 8 ↔ PostgreSQL 12 perfect parity |
| **Authentication** | Sessions, JWT tokens, password hashing, policies, rememberme |
| **Authorization** | Gate-based access control, policy classes |
| **Validation** | Form validation, custom rules, error messages |
| **Middleware** | Global, per-group, per-route, automatic CSRF verification |

### Frontend & Views

| Feature | What You Get |
|---------|-------------|
| **SFHT Templates** | Auto-escaping, layout inheritance, component composition |
| **.phpx Components** | Markup inside PHP functions, compiled on build |
| **SFCSS Framework** | 2,337+ utility classes, 16.1KB gzipped, Tailwind-compatible |
| **SFJS Library** | AJAX, DOM utilities, form validation, 3KB gzipped |
| **Built-in Assets** | Published to `public/` with zero config |

### Advanced Features

| Feature | What You Get |
|---------|-------------|
| **Async/Await System** | Native PHP Fibers, parallel queries, real-time WebSocket, stream processing |
| **Event Broadcasting** | Pub/sub with wildcards, event history, async dispatch |
| **Caching** | File, memory, Redis drivers with smart invalidation |
| **Job Queues** | Background workers with retries, database or Redis drivers |
| **Email** | SMTP with TLS, plaintext + HTML, attachments |
| **File Uploads** | Type detection from bytes, secure storage, forged file rejection |
| **Logging** | JSON lines in UTC, request tracing, secret redaction |
| **Time & Dates** | UTC everywhere, timezone display only |
| **Debugging** | Beautiful error pages, `dump()` and `dd()`, in-terminal output |

---

## Perfect For These Scenarios

### 📱 **High-Traffic APIs**
Why SFPHP wins: Built-in async/await for parallel requests, intelligent caching, optimized query builder, zero-dependency footprint means minimal memory per request.

**Example:** Your API needs to fetch user data, posts, and stats in parallel. SFPHP does this 3x faster with native Fibers.

### 🌐 **Multilingual Platforms**
Why SFPHP wins: First-class i18n support with language negotiation, UTF-8 handling that doesn't need `mbstring`, framework messages in visitor's language.

**Example:** A marketplace serving 10+ languages—pluralization rules, content localization, and Accept-Language negotiation built-in.

### 🛡️ **Security-Critical Applications**
Why SFPHP wins: Security-first design—CSRF by default, SQL binding always, XSS escaping automatic, JWT validation strict, session regeneration on login.

**Example:** Financial dashboards, health records systems, and admin panels that can't afford compromises.

### ⚡ **Real-Time Applications**
Why SFPHP wins: Built-in WebSocket support, async event broadcasting, reactive state management with automatic cache invalidation.

**Example:** Live dashboards, chat applications, collaborative tools where updates need to propagate instantly.

### 📊 **Data-Intensive Systems**
Why SFPHP wins: Stream processing with map/filter/reduce, bulk operations, async batch processing, efficient pagination for large datasets.

**Example:** CSV imports, report generation, data pipeline tools that process millions of records without memory explosion.

### 🔄 **Monolithic Applications**
Why SFPHP wins: Batteries included—authentication, authorization, validation, logging, email, queues. No jumping between 20 packages.

**Example:** Content management systems, SaaS platforms, business applications where you want everything in one framework.

### 💼 **Enterprise Integrations**
Why SFPHP wins: Zero dependencies means minimal CVEs, static analysis friendly, no version hell, audit trail for security compliance.

**Example:** Systems that must integrate with legacy code, bank APIs, or corporate infrastructure without dragging in dependency trees.

### 🚀 **Startup MVP**
Why SFPHP wins: Fast to code, slow to break, nothing to configure, 35 CLI generators, migrations built-in, deployment is just PHP files.

**Example:** Launch a SaaS, marketplace, or service without weeks of infrastructure decisions.

---

## Getting Started

### Install

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

That's it. Open `http://localhost:8000` and you have:
- ✅ Working application with example code
- ✅ Database migrations for users & sessions
- ✅ JWT configured with real secret key
- ✅ CSS & JavaScript published and ready
- ✅ Full CLI available at `./sfphp`

### Generate Your First Model

```bash
./sfphp make:model Product --migration
./sfphp migrate
```

### Create a Controller

```bash
./sfphp make:controller ProductController
```

### Build a Route

```php
Router::get('/products', 'ProductController', 'index');
Router::get('/products/:id', 'ProductController', 'show');
```

### Write Queries (with async!)

```php
// Sequential (normal)
$products = Product::query()->getAsync();

// Parallel (3x faster!)
[$products, $categories, $tags] = await(CompositeFuture::all(
    async(fn() => Product::query()->getAsync()),
    async(fn() => Category::query()->getAsync()),
    async(fn() => Tag::query()->getAsync())
));
```

---

## The Numbers

| Metric | SFPHP |
|--------|-------|
| **Dependencies** | 0 (just PHP + PDO) |
| **Framework size** | ~2MB (src/) |
| **Vendor directory** | Autoloader only |
| **Setup time** | <1 minute |
| **Built-in commands** | 35 |
| **Code generators** | 12 |
| **Security features** | 150+ |
| **Test coverage** | 154+ unit tests |
| **Async operations/sec** | 286,000+ |
| **Parallel speedup** | 3-5x for I/O operations |
| **Documentation** | 3 languages |

---

## Built For Every Language

UTF-8 isn't an afterthought—it's how the framework works:

```php
Str::length('日本語');           // 3 (not 9 bytes)
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/products/:name:alpha', ...);  // matches /produtos/café
__('http.not_found');  // Message in visitor's language
```

---

## The SFPHP Philosophy

**Simple** — Doing one thing well beats doing many things partially.

**Secure** — Security is not added, it's default. No bypasses. No "just this once."

**Fast** — With async/await, native database queries, and zero bloat, your app is fast without trying.

**Yours** — The framework is `/src/` — delete what you don't need. Everything else is your code.

**Transparent** — No magic. No hidden layers. Read the code when curious; it's all in one place.

---

## What's NOT Included (And Why)

- **Password recovery** — Your app needs to email it anyway, we handle the infrastructure
- **Two-factor** — Not one-size-fits-all; Mail handles the piece framework owned
- **Message brokers** — Built-in queues are usually enough; plug in RabbitMQ when you need it
- **Full ORM** — Query builder with relations is better for most apps; keeps you in control
- **Polymorphic relations** — Edge case; doesn't justify 200 lines of code for most systems

**Principle:** Never add complexity until you prove you need it. SFPHP gives you the pieces to build what's right for your app.

---

## Documentation

Complete in three languages—not translations, but full documentation in each:

| Language | Framework | Async System | Styling | Components |
|----------|-----------|-------------|---------|-----------|
| 🇬🇧 **English** | [Docs](docs/en/DOCUMENTATION.md) | [Async/Await](docs/ASYNC_COMPLETE_GUIDE.md) | [SFCSS](docs/en/SFCSS.md) | [SFHT](docs/en/DOCUMENTATION.md) |
| 🇧🇷 **Português** | [Docs](docs/pt-BR/DOCUMENTATION.md) | [Async/Await](docs/ASYNC_COMPLETE_GUIDE.md) | [SFCSS](docs/pt-BR/SFCSS.md) | [SFHT](docs/pt-BR/DOCUMENTATION.md) |
| 🇪🇸 **Español** | [Docs](docs/es/DOCUMENTATION.md) | [Async/Await](docs/ASYNC_COMPLETE_GUIDE.md) | [SFCSS](docs/es/SFCSS.md) | [SFHT](docs/es/DOCUMENTATION.md) |

**Quick start?** → [5-minute async guide](ASYNC_QUICK_START.md)

---

## System Requirements

- **PHP** 8.1 or later
- **Composer** 2.0+
- **Database** (optional) — any PDO-compatible database (MySQL 8+, PostgreSQL 12+, SQLite)
- **Cache** (optional) — File, Memory, or Redis
- **Queue** (optional) — Database or Redis

No extensions required except what your database driver needs. No `mbstring`. No dependencies.

---

## Testing

```bash
composer run test       # 154 unit tests
composer run test:db    # Integration tests on real MySQL & PostgreSQL
composer run lint       # PHP syntax check
composer run docs       # Verify documentation consistency
```

All tests pass on **PHP 8.1–8.4** without `mbstring` installed.

---

## Security

We take security seriously. See [SECURITY.md](SECURITY.md) for:
- How to report vulnerabilities
- Security practices used in SFPHP
- Where to find detailed security documentation

---

## License

MIT — See [LICENSE](LICENSE)

**Created by** Fabio Carneiro  
**Contributors** The community, with special thanks to Claude Haiku 4.5 for the async/await system

---

## Ready to Build?

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

Your next great app starts now. 🚀

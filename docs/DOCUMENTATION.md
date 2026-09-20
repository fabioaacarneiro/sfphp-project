# SFPHP Documentação

## Índice

- [CLI e Geração de Código](#cli-e-geração-de-código)
- [SFHT Template Engine](#sfht-template-engine)
- [SFCSS Framework](#sfcss-framework)
- [SFJS Library](#sfjs-library)
- [Migrations e Schema Builder](#migrations-e-schema-builder)
- [Roteamento](#roteamento)
- [Controllers e Views](#controllers-e-views)
- [Modelos e Repositórios](#modelos-e-repositórios)
- [Query Builder](#query-builder)
- [Validação](#validação)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Container e DI](#container-e-di)

---

## CLI e Geração de Código

O binário `./sfphp` fornece 20+ comandos para gerar código, gerenciar migrations, testar, e desenvolver.

### Geração de Código (10 Generators)

#### Principais — Scaffold

```bash
./sfphp make:controller UserController
./sfphp make:model User
./sfphp make:repository UserRepository
./sfphp make:service UserService
./sfphp make:request StoreUserRequest

# Gerar tudo de uma vez (Rails-style)
./sfphp make:scaffold Post
```

#### Complementares

```bash
./sfphp make:test PostTest              # Test class
./sfphp make:middleware CheckAdmin      # Middleware
./sfphp make:event UserCreated          # Event
./sfphp make:listener SendWelcomeEmail  # Event listener
./sfphp make:policy PostPolicy          # Authorization
```

### Migrations e Banco de Dados

```bash
# Criar migration vazia
./sfphp make:migration create_users_table

# Criar migration com schema pré-preenchida
./sfphp make:migration:create users

# Aplicar migrations
./sfphp migrate
./sfphp migrate --step=2                 # Apenas 2

# Reverter migrations
./sfphp rollback
./sfphp rollback --step=3                # Reverter 3

# Status
./sfphp status

# Reset (apaga e recria do zero)
./sfphp db:fresh

# Seeders
./sfphp db:seed
```

### Servidor e Utilitários

```bash
./sfphp serve                           # Dev server (localhost:8000)
./sfphp routes                          # Listar rotas
./sfphp tinker                          # REPL interativo
./sfphp list                            # Listar comandos
./sfphp version                         # Versão
./sfphp help [command]                  # Ajuda
```

---

## SFHT Template Engine

**SFHT** (Simple Framework HTML Template) é um motor de templates poderoso e produtivo com sintaxe clara e recursos completos para construir UIs.

### Extensão
Arquivo de template: `.sfht`

### Sintaxe Básica

#### Variáveis e Output
```sfpt
<!-- Echo simples -->
{{ $name }}

<!-- Com escape HTML -->
{{ $user->email }}

<!-- Com filtros -->
{{ $title | upper }}
{{ $text | truncate(50) }}
{{ $price | format('%.2f') }}
```

#### Controle de Fluxo

```sfpt
@if($user->isAdmin())
  <p>Welcome Admin!</p>
@elseif($user->isPremium())
  <p>Welcome Premium User!</p>
@else
  <p>Welcome!</p>
@endif
```

#### Loops

```sfpt
<!-- Foreach -->
@foreach($posts as $post)
  <article>
    <h2>{{ $post->title }}</h2>
    @if($loop->first)
      <strong>Featured Post</strong>
    @endif
    @if($loop->last)
      <p>End of posts</p>
    @endif
  </article>
@endforeach

<!-- For -->
@for($i = 0; $i < 10; $i++)
  <p>Item {{ $i }}</p>
@endfor

<!-- While -->
@while($count < 100)
  {{ $count }}
@endwhile
```

#### Herança e Componentização

```sfpt
<!-- layouts/base.sfpt -->
<!DOCTYPE html>
<html>
  <head>
    @block('head')
      <title>Default Title</title>
    @endblock
  </head>
  <body>
    @block('content')
    @endblock
  </body>
</html>

<!-- pages/home.sfpt -->
@extends('layouts.base')

@block('head')
  <title>Home Page</title>
@endblock

@block('content')
  <h1>Welcome!</h1>
@endblock
```

#### Includes e Components

```sfpt
<!-- Incluir um partial -->
@include('partials.header')

<!-- Incluir condicionalmente -->
@includeWhen($showForm, 'partials.form')

<!-- Componente com dados -->
@component('components.button', [
  'label' => 'Click me',
  'variant' => 'primary',
  'disabled' => false
])
```

#### Use Statements (Ativar Features)

```sfpt
@use(RequestsFunctions)      <!-- Ativa atributos SFJS -->
@use(SFPHPStyleFramework)   <!-- Ativa classes SFCSS -->

<button @hxGet="/api/data" @hxTarget="#content" class="btn btn-primary">
  Load Data
</button>
```

### Filters (Filtros)

```sfpt
{{ $text | upper }}               <!-- Maiúsculas -->
{{ $text | lower }}               <!-- Minúsculas -->
{{ $text | capitalize }}          <!-- Primeira letra maiúscula -->
{{ $text | truncate(50) }}        <!-- Truncar com reticências -->
{{ $email | escape }}             <!-- Escapar HTML -->
{{ $data | json }}                <!-- Converter para JSON -->
{{ $price | format('%.2f') }}     <!-- Formato sprintf -->
{{ $text | trim }}                <!-- Remover espaços -->
{{ $string | reverse }}           <!-- Reverter string -->
{{ $number | abs }}               <!-- Valor absoluto -->
{{ $float | round(2) }}           <!-- Arredondar -->
```

### Variáveis Automáticas em Loops

```sfpt
@foreach($items as $item)
  {{ $loop->iteration }}    <!-- 1, 2, 3, ... -->
  {{ $loop->index }}        <!-- 0, 1, 2, ... -->
  {{ $loop->count }}        <!-- Total de itens -->
  {{ $loop->first }}        <!-- true no primeiro -->
  {{ $loop->last }}         <!-- true no último -->
  {{ $loop->even }}         <!-- true em índices pares -->
  {{ $loop->odd }}          <!-- true em índices ímpares -->
@endforeach
```

### Uso no Controller

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\View\SfhtEngine;

final class HomeController extends BaseController
{
    public function index(): string
    {
        $engine = new SfhtEngine([__DIR__ . '/../resources/views']);
        
        return $engine->render('home', [
            'title' => 'Welcome',
            'posts' => Post::all(),
            'user' => auth()->user(),
        ]);
    }
}
```

### Estrutura de Diretórios Recomendada

```
resources/
├── views/
│   ├── layouts/
│   │   ├── base.sfpt
│   │   └── app.sfpt
│   ├── pages/
│   │   ├── home.sfpt
│   │   ├── about.sfpt
│   │   └── contact.sfpt
│   ├── components/
│   │   ├── button.sfpt
│   │   ├── card.sfpt
│   │   └── form-field.sfpt
│   └── partials/
│       ├── header.sfpt
│       ├── footer.sfpt
│       └── navigation.sfpt
```

### Performance e Caching

O SFHT compila templates para PHP e cacheia o resultado automaticamente:

```php
$engine = new SfhtEngine(
    [__DIR__ . '/views'],
    '/tmp/sfht-cache'  // Cache directory
);

// Cache é validado automaticamente por timestamp
// Limpar cache quando necessário:
$engine->clearCache();
```

### Variáveis Globais

```php
$engine->setGlobal('siteName', 'My Site');
$engine->setGlobal('user', auth()->user());

// Ou múltiplas ao mesmo tempo
$engine->setGlobals([
    'siteName' => 'My Site',
    'user' => auth()->user(),
    'version' => '1.0.0',
]);

// Agora acessíveis em todas as templates
{{ $siteName }}
{{ $user->name }}
>>>>>>> origin/master
```

---

## SFCSS Framework

**SFCSS** (Simple Framework CSS) é um framework CSS minimalista e customizável, combinando a simplicidade do Pico CSS com a flexibilidade do Tailwind.

### Características

- **Minimalista:** ~8KB gzipped, sem bloat
- **Customizável:** Sistema de tema via CSS variables
- **Responsivo:** Mobile-first com breakpoints sm/md/lg/xl
- **Componentes:** Buttons, forms, cards, tables, alerts, badges
- **Utilitários:** Spacing, text, display, color classes
- **Sem lock-in:** Fácil customizar cores e valores

### Instalação

```html
<!-- No seu HTML -->
<link rel="stylesheet" href="/css/sfcss.css">
```

### Customização de Cores

Edite `public/css/sfcss.config.json`:

```json
{
  "colors": {
    "primary": "#0066cc",
    "secondary": "#6c757d",
    "success": "#28a745",
    "danger": "#dc3545",
    "warning": "#ffc107",
    "info": "#17a2b8"
  }
}
```

Depois regenere o CSS:

```bash
php public/css/sfcss-builder.php > public/css/sfcss.css
```

### Componentes

#### Buttons com SFJS

```sfpt
<!-- Botões com interações AJAX -->
<button class="btn">Default</button>
<button class="btn btn-primary" @hxGet="/api/data" @hxTarget="#content">Load Data</button>
<button class="btn btn-success" @hxPost="/posts" @hxTarget="#posts-list">Create Post</button>
<button class="btn btn-danger" @hxDelete="/item/1">Delete</button>

<!-- Botões com tamanhos -->
<button class="btn btn-sm">Small</button>
<button class="btn">Regular</button>
<button class="btn btn-lg">Large</button>

<!-- Botão desabilitado -->
<button class="btn" disabled>Disabled</button>

<!-- Botão com toggle -->
<button class="btn btn-primary" @toggle="modal">Open Modal</button>
```

#### Forms com SFHT

```sfpt
<!-- Form com validação SFJS integrada -->
<form @hxPost="/users" @hxTarget="#users-list" class="card p-4 mb-4">
  <div class="form-group">
    <label class="form-label">Name</label>
    <input type="text" name="name" @validate="required" placeholder="John Doe">
  </div>

  <div class="form-group">
    <label class="form-label">Email</label>
    <input type="email" name="email" @validate="email" placeholder="user@example.com">
  </div>

  <div class="form-group">
    <label class="form-label">Country</label>
    <select name="country">
      <option value="">Select...</option>
      @foreach($countries as $code => $name)
        <option value="{{ $code }}">{{ $name }}</option>
      @endforeach
    </select>
  </div>

  <button type="submit" class="btn btn-primary">Submit</button>
</form>
```

#### Cards com SFHT

```sfpt
<!-- Cards dinâmicas com dados -->
@foreach($posts as $post)
  <div class="card mb-3">
    <div class="card-header">
      <h3>{{ $post->title | truncate(50) }}</h3>
    </div>
    <div class="card-body">
      <p>{{ $post->excerpt }}</p>
      <small class="text-muted">By {{ $post->author->name }}</small>
    </div>
    <div class="card-footer">
      <a href="/posts/{{ $post->id }}" class="btn btn-primary btn-sm">Read More</a>
      <button @hxDelete="/posts/{{ $post->id }}" class="btn btn-danger btn-sm">Delete</button>
    </div>
  </div>
@endforeach
```

#### Grid com SFHT

```sfpt
<!-- Grid responsiva com dados dinâmicos -->
<div class="grid grid-cols-3">
  @foreach($products as $product)
    <div class="card">
      <img src="{{ $product->image }}" alt="{{ $product->name }}" style="width:100%; height:200px; object-fit:cover;">
      <div class="card-body">
        <h4>{{ $product->name }}</h4>
        <p class="text-sm text-muted">{{ $product->description | truncate(80) }}</p>
        <strong class="text-lg text-primary">${{ $product->price }}</strong>
      </div>
      <div class="card-footer">
        <button @hxPost="/cart" @hxVals="{ product_id: {{ $product->id }} }" 
                class="btn btn-primary w-100">Add to Cart</button>
      </div>
    </div>
  @endforeach
</div>
```

#### Tables com SFHT

```sfpt
<!-- Tabela dinâmica com dados do controller -->
<table>
  <thead>
    <tr>
      <th>Name</th>
      <th>Email</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    @foreach($users as $user)
      <tr>
        <td>{{ $user->name }}</td>
        <td>{{ $user->email }}</td>
        <td>
          @if($user->is_active)
            <span class="badge badge-success">Active</span>
          @else
            <span class="badge">Inactive</span>
          @endif
        </td>
        <td>
          <button @hxDelete="/users/{{ $user->id }}" @hxConfirm="Delete user?" 
                  class="btn btn-sm btn-danger">Delete</button>
        </td>
      </tr>
    @endforeach
  </tbody>
</table>
```

#### Alerts

```html
<div class="alert alert-primary">Primary alert</div>
<div class="alert alert-success">Success alert</div>
<div class="alert alert-danger">Danger alert</div>
<div class="alert alert-warning">Warning alert</div>
```

### Exemplo Completo — SFHT + SFCSS + SFJS

```sfpt
<!DOCTYPE html>
<html>
<head>
  <title>Users Dashboard</title>
  <link rel="stylesheet" href="/css/sfcss.css">
</head>
<body>
  <div class="container p-4">
    <h1>Users Management</h1>

    <!-- Form criar usuário com validação SFJS -->
    <form @hxPost="/users" @hxTarget="#users-table" class="card p-4 mb-4">
      <h2 class="mb-3">New User</h2>
      <div class="form-group">
        <label class="form-label">Name</label>
        <input type="text" name="name" @validate="required" placeholder="Full Name">
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input type="email" name="email" @validate="email" placeholder="user@example.com">
      </div>
      <button type="submit" class="btn btn-primary">Create User</button>
    </form>

    <!-- Tabela com dados dinâmicos e AJAX actions -->
    <div class="card">
      <div class="card-header">
        <h2>Users List</h2>
      </div>
      <div class="card-body">
        <table id="users-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            @foreach($users as $user)
              <tr>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td>
                  @if($user->is_active)
                    <span class="badge badge-success">Active</span>
                  @else
                    <span class="badge badge-warning">Inactive</span>
                  @endif
                </td>
                <td>
                  <button @hxPut="/users/{{ $user->id }}" 
                          @hxTarget="closest tr" @hxSwap="outerHTML"
                          class="btn btn-sm">Edit</button>
                  <button @hxDelete="/users/{{ $user->id }}" 
                          @hxTarget="closest tr" @hxSwap="outerHTML"
                          class="btn btn-sm btn-danger">Delete</button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>

    <!-- Grid de estatísticas -->
    <div class="grid grid-cols-3 mt-4">
      <div class="card">
        <div class="card-body text-center">
          <h3 class="text-2xl font-bold text-primary">{{ $total_users }}</h3>
          <p class="text-sm text-muted">Total Users</p>
        </div>
      </div>
      <div class="card">
        <div class="card-body text-center">
          <h3 class="text-2xl font-bold text-success">{{ $active_users }}</h3>
          <p class="text-sm text-muted">Active</p>
        </div>
      </div>
      <div class="card">
        <div class="card-body text-center">
          <h3 class="text-2xl font-bold text-warning">{{ $inactive_users }}</h3>
          <p class="text-sm text-muted">Inactive</p>
        </div>
      </div>
    </div>
  </div>

  <script src="/js/sfjs.js"></script>
</body>
</html>
```

**O que está acontecendo aqui:**
- **SFHT:** `@foreach`, `@if/@else`, variáveis com `{{ }}`
- **SFCSS:** Classes `.btn`, `.card`, `.grid`, `.badge`, `.form-group`
- **SFJS:** `@hxPost`, `@hxDelete`, `@hxPut` com `@hxTarget` e `@hxSwap`, `@validate` no input

Tudo integrado, sem JavaScript customizado!

### Utilidades

#### Spacing

```html
<!-- Margins -->
<div class="mt-3 mb-4">Content</div>

<!-- Padding -->
<div class="p-3">Padded content</div>
<div class="px-4 py-2">Custom padding</div>
```

#### Typography

```html
<h1 class="text-3xl font-bold text-primary">Title</h1>
<p class="text-lg font-semibold">Subtitle</p>
<small class="text-sm text-muted">Small text</small>
```

#### Colors

```html
<div class="text-primary">Primary color</div>
<div class="text-success">Success color</div>
<div class="bg-light p-3">Light background</div>
```

### Sistema de Tema

Variáveis CSS padrão (customizáveis em sfcss.config.json):

```
Colors: --primary, --secondary, --success, --danger, --warning, --info
Spacing: --xs, --sm, --md, --lg, --xl
Typography: --font-family, --font-size-*, --font-weight-*
Border: --border-radius, --border-color
Shadows: --shadow-sm, --shadow, --shadow-lg
```

### Responsividade

Breakpoints automáticos:
- **sm:** 480px (mobile)
- **md:** 768px (tablet)
- **lg:** 1024px (desktop)
- **xl:** 1280px (wide)

```html
<div class="grid grid-cols-3">
  <!-- 3 columns on desktop, 1 on mobile -->
</div>
```

### Configuração Completa

Arquivo `sfcss.config.json`:

```json
{
  "colors": { /* 10+ color variants */ },
  "spacing": { /* xs, sm, md, lg, xl */ },
  "typography": {
    "fontFamily": "system fonts",
    "sizes": { /* sm, base, lg, xl, 2xl, 3xl */ },
    "lineHeight": "1.6",
    "weights": { /* normal, semibold, bold */ }
  },
  "border": { /* radius, color, width */ },
  "shadows": { /* sm, base, lg */ },
  "breakpoints": { /* sm, md, lg, xl */ },
  "transition": "all 0.3s ease"
}
```

---

## SFJS Library

**SFJS** (Simple Framework JavaScript) é uma biblioteca JavaScript minimalista tipo HTMX para AJAX, validação, e manipulação DOM sem dependências (~8KB).

### Instalação

```html
<!-- No seu HTML -->
<script src="/js/sfjs.js"></script>
```

### AJAX Declarativo

Sem JavaScript customizado — use atributos HTML:

```html
<!-- GET request -->
<button @hxGet="/api/data" @hxTarget="#content">
  Load Data
</button>

<!-- POST request -->
<form @hxPost="/submit" @hxTarget="#result" @hxSwap="outerHTML">
  <input name="title" type="text">
  <button type="submit">Save</button>
</form>

<!-- PUT/DELETE/PATCH -->
<button @hxPut="/api/item/1" @hxTarget="#item">Update</button>
<button @hxDelete="/api/item/1" @hxTarget="#item">Delete</button>
```

### Swap Strategies

```html
<!-- innerHTML (padrão) - substitui conteúdo -->
<div @hxGet="/new" @hxTarget="#container" @hxSwap="innerHTML"></div>

<!-- outerHTML - substitui elemento inteiro -->
<div @hxGet="/new" @hxTarget="#container" @hxSwap="outerHTML"></div>

<!-- beforebegin/afterbegin/beforeend/afterend -->
<div @hxGet="/item" @hxSwap="beforeend"></div>
```

### Form Handling

```html
<!-- Form submit com AJAX -->
<form @hxPost="/users" @hxTarget="#users-list">
  <input name="name" type="text" @validate="required">
  <input name="email" type="email" @validate="email">
  <button type="submit">Create</button>
</form>
```

### Validação Client-Side

```html
<!-- Validação automática em blur -->
<input name="email" @validate="email">
<input name="age" @validate="number">
<input name="url" @validate="url">
<input name="text" @validate="minLength:5">
```

**Regras disponíveis:**
- `required` — Campo obrigatório
- `email` — Email válido
- `number` — Apenas dígitos
- `url` — URL válida
- `minLength:N` — Comprimento mínimo
- `maxLength:N` — Comprimento máximo
- `pattern:regex` — Expressão regular customizada

### JavaScript API

```javascript
// AJAX manual
sf.ajax.get('/api/data', {
  target: '#content',
  swap: 'innerHTML'
});

sf.ajax.post('/submit', { name: 'John' }, {
  onSuccess: (html) => console.log('Done'),
  onError: (error) => console.error(error)
});

// Form utilities
const data = sf.form.serialize(document.querySelector('form'));
sf.form.submit(formElement);

// DOM manipulation
sf.dom.addClass('element', 'active');
sf.dom.removeClass('element', 'disabled');
sf.dom.toggleClass('element', 'hidden');
sf.dom.show('modal');
sf.dom.hide('modal');

// Event binding
sf.dom.on('button', 'click', (e) => {
  console.log('Clicked!');
});

// Storage
sf.storage.set('user-id', 123);
const userId = sf.storage.get('user-id');
sf.storage.remove('user-id');
sf.storage.clear();

// Utilities
const debounced = sf.util.debounce((value) => {
  console.log('Search:', value);
}, 300);

const throttled = sf.util.throttle(() => {
  console.log('Resized!');
}, 500);

// Wait for async
await sf.util.wait(1000);
console.log('Done waiting');
```

### Toggle/Show/Hide

```html
<!-- @toggle attribute -->
<button @toggle="modal-id">
  Open Modal
</button>

<div id="modal-id" style="display:none;">
  Modal content
</div>
```

### Exemplo Completo

```html
<!-- SfPHP SFJS Example -->
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="/css/sfcss.css">
</head>
<body>
  <div class="container p-4">
    <h1>Users</h1>
    
    <!-- Form create com validação -->
    <form @hxPost="/users" @hxTarget="#users-list" class="card p-3 mb-4">
      <div class="form-group">
        <input name="name" @validate="required" placeholder="Name">
      </div>
      <div class="form-group">
        <input name="email" @validate="email" placeholder="Email">
      </div>
      <button type="submit" class="btn btn-primary">Create</button>
    </form>

    <!-- List with AJAX refresh -->
    <div id="users-list">
      <div class="grid grid-cols-3">
        <!-- Loaded via AJAX -->
      </div>
    </div>

    <!-- Refresh button -->
    <button @hxGet="/users" @hxTarget="#users-list" class="btn mt-3">
      Refresh
    </button>
  </div>

  <script src="/js/sfjs.js"></script>
</body>
</html>
```

### Auto-Initialization

SFJS **não requer inicialização manual**:
- Event listeners instalados automaticamente no DOMContentLoaded
- Validação funciona sem setup
- AJAX declarativa pronta para usar

Basta incluir o script e usar os atributos `@hx*`.

---

## Migrations e Schema Builder

O sistema de migrations permite versionamento do schema de banco de dados sem SQL cru.

### Estrutura de uma Migration

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
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

### Tipos de Coluna

**Numéricas:**
- `id()`, `increments()`, `smallIncrements()`, `mediumIncrements()`, `bigIncrements()`
- `tinyInteger()`, `smallInteger()`, `mediumInteger()`, `integer()`, `bigInteger()`
- `unsignedTinyInteger()`, `unsignedSmallInteger()`, `unsignedMediumInteger()`, `unsignedInteger()`, `unsignedBigInteger()`
- `decimal($precision, $scale)`, `unsignedDecimal($precision, $scale)`
- `float()`, `double()`

**Strings:**
- `string($length = 255)`, `char($length = 255)`
- `text()`, `mediumText()`, `longText()`
- `binary()` (BLOB/BYTEA)

**Booleanos e Data/Hora:**
- `boolean()`
- `date()`, `time($precision = null)`, `timeTz($precision = null)`
- `dateTime($precision = null)`, `dateTimeTz($precision = null)`
- `timestamp($precision = null)`, `timestampTz($precision = null)`

**Especiais:**
- `enum($values)` — ENUM em MySQL, VARCHAR com CHECK em PostgreSQL
- `set($values)` — SET em MySQL (erro em PostgreSQL)
- `json()`, `jsonb()` — JSON/JSONB (JSONB em PostgreSQL)
- `uuid()` — UUID em PostgreSQL, CHAR(36) em MySQL
- `ulid()` — CHAR(26)
- `ipAddress()` — INET em PostgreSQL, VARCHAR(45) em MySQL
- `macAddress()` — MACADDR em PostgreSQL, VARCHAR(17) em MySQL
- `year()` — YEAR em MySQL, SMALLINT em PostgreSQL
- `rawColumn($definition)` — Tipo customizado (POINT, INTEGER[], etc)

**Helpers:**
- `timestamps()` — created_at e updated_at com default CURRENT_TIMESTAMP
- `timestampsTz()` — Idem com time zone
- `softDeletes($column = 'deleted_at')` — deleted_at nullable timestamp
- `softDeletesTz($column = 'deleted_at')` — Idem com time zone
- `rememberToken()` — remember_token VARCHAR(100) nullable
- `morphs($name)` — {name}_id e {name}_type com index
- `nullableMorphs($name)` — Idem, mas nullable
- `uuidMorphs($name)` — Morph com UUID em vez de BIGINT
- `ulidMorphs($name)` — Morph com ULID

### Modificadores de Coluna

```php
$table->string('email')
    ->nullable()           // NULL allowed
    ->default('none')      // DEFAULT 'none'
    ->unique()             // UNIQUE constraint
    ->index()              // INDEX
    ->comment('Email do usuário')
    ->collation('utf8mb4_unicode_ci')  // MySQL only
    ->charset('utf8mb4')   // MySQL only
    ->after('name')        // MySQL only: posição
    ->first()              // MySQL only: primeira coluna
    ->unsigned()           // Numéricos apenas
    ->autoIncrement()      // Auto-increment
    ->useCurrent()         // DEFAULT CURRENT_TIMESTAMP
    ->useCurrentOnUpdate() // ON UPDATE CURRENT_TIMESTAMP (MySQL) ou TRIGGER (PostgreSQL)
    ->change();            // ALTER em vez de ADD
```

### Operações na Tabela

```php
$schema->create('posts', function (Blueprint $table): void {
    // Criar tabelas e colunas...
});

$schema->table('posts', function (Blueprint $table): void {
    // Adicionar coluna
    $table->string('slug')->unique();
    
    // Alterar coluna
    $table->string('title', 100)->change();
    
    // Renomear coluna
    $table->renameColumn('author_id', 'user_id');
    
    // Dropar coluna(s)
    $table->dropColumn(['obsolete_field', 'legacy_data']);
    
    // Indexes
    $table->index(['first_name', 'last_name']);
    $table->unique('email');
    $table->primary(['tenant_id', 'id']);
    $table->fullText('body');
    $table->index('slug')->where('deleted_at IS NULL');  // PostgreSQL: partial index
    
    // Foreign keys
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->foreign(['tenant_id', 'parent_id'])->references('users')->on('organizations');
    
    // Constraints
    $table->check('age >= 18', 'min_age');
    
    // Drop constraints
    $table->dropIndex(['first_name', 'last_name']);
    $table->dropUnique('email');
    $table->dropForeign('user_id');
    $table->dropPrimary();
    $table->dropCheck('min_age');
    
    // Table options (MySQL)
    $table->engine('InnoDB');
    $table->tableCharset('utf8mb4');
    $table->tableCollation('utf8mb4_unicode_ci');
    $table->tableComment('Posts table');
});

$schema->rename('old_table', 'new_table');
$schema->drop('posts');
$schema->dropIfExists('posts');
```

### Introspection

```php
$schema->hasTable('users');
$schema->hasColumn('users', 'email');
$schema->hasIndex('users', 'users_email_unique');
```

### Foreign Key Actions

```php
$table->foreignId('user_id')
    ->constrained('users')
    ->cascadeOnDelete()      // DELETE children
    ->cascadeOnUpdate()      // UPDATE children
    ->nullOnDelete()         // SET NULL on delete
    ->nullOnUpdate()         // SET NULL on update
    ->restrictOnDelete()     // RESTRICT delete
    ->restrictOnUpdate()     // RESTRICT update
    ->noActionOnDelete()     // NO ACTION on delete
    ->noActionOnUpdate()     // NO ACTION on update
    ->deferrable(true);      // PostgreSQL: DEFERRABLE INITIALLY DEFERRED
```

### Generated Columns

```php
$table->integer('a');
$table->integer('b');
$table->integer('sum')->storedAs('a + b');     // STORED (persistido)
$table->integer('half')->virtualAs('a / 2');   // VIRTUAL (MySQL only)
```

### Table Qualify

```php
// Schema.table com suporte a schema:
$schema->create('public.users', function (Blueprint $table): void {
    // ...
});

$schema->hasTable('my_schema.users');
```

---

## Roteamento

### Registrar Rotas

No arquivo `src/routes.php`:

```php
use SfphpProject\src\Router;

$router = new Router();

// Rotas simples
$router->get('/', 'Home@index')->name('home');
$router->post('/users', 'User@store')->name('users.store');

// Parâmetros
$router->get('/posts/{id:number}', 'Post@show')->name('posts.show');
$router->get('/users/{username:alpha}', 'User@profile')->name('users.profile');

// Grupos com prefixo
$router->group('/api', function (Router $api): void {
    $api->get('/posts', 'Api/Post@index')->name('api.posts.index');
    $api->post('/posts', 'Api/Post@store')->name('api.posts.store');
});

// Múltiplos parâmetros
$router->get('/tenant/{tenantId:number}/posts/{postId:number}', 'Post@show');

// Gerar URLs
echo Router::url('posts.show', ['id' => 1]);  // /posts/1
echo Router::url('home');  // /

return $router;
```

### Métodos HTTP

```php
$router->get($path, $action);
$router->post($path, $action);
$router->put($path, $action);
$router->patch($path, $action);
$router->delete($path, $action);
$router->head($path, $action);
$router->options($path, $action);
```

### Padrões de Parâmetro

```php
{id:number}     // Apenas dígitos
{slug:alpha}    // Apenas letras
{code:alphanum} // Letras e dígitos
{id}            // Qualquer coisa (greedy)
```

---

## Controllers e Views

### Structure

Controllers herdam de `BaseController` (web) ou `BaseAPIController` (API).

```php
<?php

namespace SfphpProject\app\Controllers;

use SfphpProject\app\controllers\BaseController;

final class PostController extends BaseController
{
    public function index(): string
    {
        $posts = User::all();
        return $this->view('posts/index', compact('posts'));
    }

    public function show(int $id): string
    {
        $post = Post::find($id);
        return $this->view('posts/show', compact('post'));
    }
}
```

### Views e Partials

```php
// Renderizar view
return $this->view('posts/show', ['post' => $post]);

// Renderizar partial (reutilizável)
echo View::partial('header', ['title' => 'Meu Site']);

// Escapar output (previne XSS)
echo e($user->name);

// Asset URLs
echo asset('css/style.css');  // /css/style.css
echo asset('js/app.js');      // /js/app.js
```

---

## Modelos e Repositórios

### Models (Static)

Gerado por `./sfphp make:model User`:

```php
final class User
{
    public static function all(): array { ... }
    public static function find(int $id): ?array { ... }
    public static function create(array $data): int { ... }
    public static function update(int $id, array $data): int { ... }
    public static function delete(int $id): int { ... }
}
```

### Repositories (Instância)

Gerado por `./sfphp make:repository Post`:

```php
$repo = new PostRepository();
$repo->all();
$repo->find(1);
$repo->create(['title' => 'Olá']);
$repo->update(1, ['title' => 'Modificado']);
$repo->delete(1);
```

---

## Query Builder

```php
use SfphpProject\src\Database;

// SELECT
Database::table('users')->get();
Database::table('users')->where('age', '>', 18)->get();
Database::table('users')->whereIn('role', ['admin', 'moderator'])->get();
Database::table('users')->first();
Database::table('users')->count();

// WHERE
->where('age', '>', 18)
->where('email', 'like', '%@example.com')
->whereIn('id', [1, 2, 3])
->whereNull('deleted_at')
->whereNotNull('verified_at')

// ORDER BY, LIMIT
->orderBy('created_at', 'desc')
->limit(10)
->offset(5)

// INSERT
Database::table('users')->insert(['name' => 'João', 'email' => 'joao@example.com']);

// UPDATE
Database::table('users')->where('id', 1)->update(['name' => 'João Silva']);

// DELETE
Database::table('users')->where('id', 1)->delete();

// Raw SQL
Database::query('SELECT * FROM users WHERE age > ?', [18])->fetch();
Database::query('DELETE FROM users WHERE id = ?', [1]);
```

---

## Validação

```php
use SfphpProject\src\Validator;

$result = Validator::validate($data, [
    'name' => ['required', 'string', 'min:3', 'max:255'],
    'email' => ['required', 'email'],
    'age' => ['required', 'integer', 'min:18'],
]);

if (!$result->isValid()) {
    foreach ($result->errors as $field => $messages) {
        echo "$field: " . implode(', ', $messages);
    }
}
```

### Regras

- `required` — Campo obrigatório
- `string` — Deve ser string
- `integer` — Deve ser inteiro
- `email` — Email válido
- `min:N` — Comprimento mínimo
- `max:N` — Comprimento máximo
- `url` — URL válida
- `regex:pattern` — Expressão regular

---

## CSRF

Proteção contra CSRF em formulários:

```php
// Gerar token (automático em cada sessão)
$token = csrf_token();

// Campo em formulários HTML
<?php echo csrf_field(); ?>
// <input type="hidden" name="_token" value="...">

// Meta tag para AJAX
<?php echo csrf_meta(); ?>
// <meta name="csrf-token" content="...">

// Validar em controller
if (!Csrf::validateRequest()) {
    // Token inválido
}
```

---

## JWT

Autenticação com tokens HS256:

```php
use SfphpProject\src\JWT;

// Gerar token (válido por 1 hora)
$token = JWT::generate(['user_id' => 1, 'role' => 'admin']);

// Validar token
try {
    $claims = JWT::validate($token);
    echo $claims['user_id'];  // 1
} catch (Exception $e) {
    echo "Token inválido";
}
```

---

## Container e DI

O container resolve automaticamente as dependências via reflection:

```php
use SfphpProject\src\Container;

$container = new Container();

// Registrar serviço
$container->bind(UserRepository::class, fn () => new UserRepository());

// Resolver
$repo = $container->resolve(UserRepository::class);

// Auto-wiring de controllers
class PostController
{
    public function __construct(private PostRepository $posts) {}
    
    public function index(): string
    {
        $posts = $this->posts->all();
        return $this->view('posts/index', compact('posts'));
    }
}
// O container injeta PostRepository automaticamente
```

---

## Cobertura de Features

| Recurso | MySQL 8.0+ | PostgreSQL 12+ |
|---------|:----------:|:--------------:|
| Tipos de coluna (30+) | ✓ | ✓ |
| Constraints (FK, unique, check, primary) | ✓ | ✓ |
| Indexes (normal, unique, full-text, partial) | ✓ | ✓ |
| Generated columns (STORED/VIRTUAL) | ✓ | ✓ (STORED apenas) |
| ON UPDATE CURRENT_TIMESTAMP | ✓ | ✓ (via trigger) |
| ENUM/SET | ✓ | ENUM emulado |
| Schema.table qualify | ✓ | ✓ |
| Transações em migrations | ✓ (implícitas) | ✓ |
| Deferrable FK | ✗ | ✓ |

---

**Documentação Gerada:** 2026-09-20

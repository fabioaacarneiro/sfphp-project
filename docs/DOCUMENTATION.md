# SFPHP Documentação

## Índice

- [CLI e Geração de Código](#cli-e-geração-de-código)
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

O binário `./sfphp` fornece 15+ comandos para gerar código, gerenciar migrations, e desenvolver.

### Comandos de Geração

Gerar arquivos esqueleto com o padrão do framework:

```bash
# Controller
./sfphp make:controller User

# Model
./sfphp make:model Post

# Repository
./sfphp make:repository Post

# Service
./sfphp make:service PostService

# Form Request (Validação)
./sfphp make:request StoreUserRequest

# Scaffold: Gerar tudo de uma vez (controller, model, repository, service)
./sfphp make:scaffold Article
```

Os arquivos são gerados nos diretórios apropriados com namespace correto e estrutura inicial.

**Scaffold** é a forma recomendada para gerar um CRUD completo rapidamente, assim como Rails faz.

#### Diretórios Gerados

- Controllers: `app/controllers/{Name}Controller.php`
- Models: `app/models/{Name}.php`
- Repositories: `app/repositories/{Name}Repository.php`
- Services: `app/services/{Name}Service.php`
- Form Requests: `app/requests/{Name}Request.php`

### Comandos de Migration

```bash
# Criar nova migration vazia
./sfphp make:migration create_users_table

# Criar migration com schema pré-preenchida (id, timestamps)
./sfphp make:migration:create users

# Aplicar todas as migrations pendentes
./sfphp migrate

# Aplicar apenas 2 migrations
./sfphp migrate --step=2

# Reverter a última migration
./sfphp rollback

# Reverter 3 migrations
./sfphp rollback --step=3

# Ver status das migrations
./sfphp status

# Usar diretório customizado
./sfphp migrate --path=db/migrations
./sfphp make:migration:create users --path=db/migrations
```

### Comandos do Servidor

```bash
# Iniciar servidor de desenvolvimento (localhost:8000)
./sfphp serve

# Criar arquivo .env a partir de .env-example
./sfphp env:example

# Listar todas as rotas registradas
./sfphp routes
```

### Comandos de Utilidade

```bash
# Mostrar versão do SFPHP
./sfphp version
# ou
./sfphp --version
./sfphp -v

# Listar todos os comandos disponíveis
./sfphp list
# ou
./sfphp --list

# Mostrar ajuda geral
./sfphp help

# Mostrar ajuda de um comando específico
./sfphp help make:scaffold
./sfphp help migrate
./sfphp help serve
```

### Exemplos de Uso Completo

```bash
# Criar uma feature completa com um comando
./sfphp make:scaffold Post

# Criar a tabela no banco com schema básico
./sfphp make:migration:create posts

# Aplicar a migration
./sfphp migrate

# Ver as rotas que você registrou
./sfphp routes

# Iniciar servidor para testar
./sfphp serve
```

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

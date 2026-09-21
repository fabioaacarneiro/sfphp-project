# SFPHP — Documentação

Framework PHP full-stack com **zero dependências de runtime** e correção
Unicode em toda a superfície. Esta documentação descreve o que o código faz
hoje. Onde algo não existe, está dito que não existe — veja
[Limitações conhecidas](#limitações-conhecidas).

> Verificado contra PHP 8.4 · suíte: 37 testes, 0 falhas

---

## Índice

- [O que é, o que não é](#o-que-é-o-que-não-é)
- [Requisitos e instalação](#requisitos-e-instalação)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Ciclo de vida da requisição](#ciclo-de-vida-da-requisição)
- [Roteamento](#roteamento)
- [Controllers](#controllers)
- [Views e SFHT](#views-e-sfht)
- [Container e injeção de dependências](#container-e-injeção-de-dependências)
- [Banco de dados](#banco-de-dados)
- [Migrations e Schema Builder](#migrations-e-schema-builder)
- [Seeders e Factories](#seeders-e-factories)
- [Cache](#cache)
- [Queue](#queue)
- [Validação](#validação)
- [Strings UTF-8](#strings-utf-8)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Tratamento de erros](#tratamento-de-erros)
- [CLI](#cli)
- [SFCSS](#sfcss)
- [SFJS](#sfjs)
- [Testes](#testes)
- [Limitações conhecidas](#limitações-conhecidas)

---

## O que é, o que não é

**É** um framework enxuto para aplicações web e APIs, com roteamento,
container de DI, query builder, schema builder com paridade MySQL/PostgreSQL,
template engine, cache, filas, e um CLI com 32 comandos.

**Não é** um substituto de Laravel ou Symfony. Não há ORM, camada de
autenticação, pipeline de middleware, sistema de eventos ou i18n. O que existe
é pequeno o suficiente para ser lido inteiro.

### Zero dependências, literalmente

`composer.json` exige apenas `php ^8.1`, `ext-json` e `ext-pdo`. O diretório
`vendor/` contém **só o autoloader do Composer**.

Isso vale também para o runtime do navegador: nenhuma página servida pelo
framework — incluindo as páginas de erro 404 e 500 — carrega CSS, fontes ou
JavaScript de um CDN.

Extensões opcionais, declaradas em `suggest`:

| Extensão | Habilita |
|---|---|
| `ext-mbstring` | Conversão de caixa Unicode mais precisa. Sem ela, `upper`/`lower` caem para ASCII; o resto do tratamento UTF-8 não depende dela |
| `ext-redis` | Drivers Redis de cache e fila |
| `ext-pcntl` | Encerramento gracioso do worker de fila |

---

## Requisitos e instalação

- PHP 8.1 ou superior
- Composer 2
- PDO com o driver do seu banco (opcional — só se usar banco)

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env

# Gere a chave JWT (obrigatória para emitir ou validar tokens)
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalente
```

Em produção, aponte o `DocumentRoot` para `public/`.

---

## Estrutura do projeto

```
src/            O framework (namespace SfphpProject\src)
app/            Código de EXEMPLO da aplicação — ilustra o uso, não é prescritivo
public/         Document root: index.php e assets/ (css, js, images)
database/       migrations/, seeders/, factories/ da aplicação
tools/          Gerador do SFCSS
tests/          Suíte própria, sem PHPUnit
docs/           Esta documentação
sfphp           Entrypoint do CLI
server.php      Router script do servidor embutido
```

Autoload PSR-4 configurado:

| Prefixo | Diretório |
|---|---|
| `SfphpProject\src\` | `src/` |
| `SfphpProject\app\` | `app/` |
| `Database\Seeders\` | `database/seeders/` |
| `Database\Factories\` | `database/factories/` |

E três arquivos carregados sempre (`autoload.files`): `app/config/config.php`,
`src/utils.php`, `src/http.php`, `src/helpers.php`.

---

## Ciclo de vida da requisição

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ config.php → carrega .env (opcional) e define APP_NAME/VERSION/ENV
 │      utils.php  → helpers globais: e(), asset(), csrf_*()
 │      http.php   → constantes HTTP_OK, GET, POST, ...
 │      helpers.php→ cache(), dispatch()
 ├─ Csrf::startSession()      sessão com httponly + samesite=Lax + secure sob HTTPS
 ├─ ErrorHandler::register()  erros, exceções e fatais viram resposta HTTP
 ├─ require src/routes.php    popula o registro estático de rotas
 ├─ new Container()
 │   └─ set(PDO::class, closure)   conexão preguiçosa
 └─ new Router($container)->dispatch()
```

O `.env` é **opcional**. Um clone novo sobe sem configuração; quem precisa de
valor (banco, JWT) falha por conta própria, com mensagem específica.

---

## Roteamento

Rotas ficam em `src/routes.php`. A API é **estática**.

```php
use SfphpProject\src\Router;

Router::get('/', 'MainController', 'index')->name('home');
Router::post('/users', 'UserController', 'store')->name('users.store');
```

A assinatura é sempre `(string $url, string $controller, string $action)` — três
argumentos separados, não `'Controller@action'`.

O controller é resolvido como `SfphpProject\app\controllers\{Controller}`.

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

Uma rota não casada devolve **404**. Um caminho que casa mas com método errado
devolve **405** com header `Allow`. `OPTIONS` devolve **204** automaticamente
quando há rotas no caminho.

### Parâmetros

A sintaxe é `nome:tipo`, **sem chaves**:

```php
Router::get('/posts/id:number', 'PostController', 'show');
Router::get('/users/username:alpha', 'UserController', 'profile');
Router::get('/codes/code:alphanum', 'CodeController', 'show');
```

| Tipo | Casa | Observação |
|---|---|---|
| `number` | `[0-9]+` | ASCII de propósito: o valor existe para sobreviver a um `(int)`, e o cast do PHP não entende algarismos indo-arábicos ou devanágari |
| `alpha` | `\p{L}+` | Qualquer alfabeto: `café`, `北京`, `Владимир` |
| `alphanum` | `[\p{L}\p{N}]+` | Letras e dígitos de qualquer escrita |

Os valores chegam à action **posicionalmente**, na ordem em que aparecem na URL:

```php
Router::get('/tenant/tenantId:number/posts/postId:number', 'PostController', 'show');

public function show(string $tenantId, string $postId): void { /* ... */ }
```

O caminho da requisição é decodificado por segmento antes do casamento, então
`/produtos/caf%C3%A9` casa `/produtos/nome:alpha`. Separadores codificados
(`%2F`, `%5C`) **não** são transformados em separadores reais: `/a%2Fb` nunca
alcança a rota `/a/b`.

### Grupos

O callback não recebe argumentos — as rotas registradas dentro dele herdam o
prefixo:

```php
Router::group('/api', function (): void {
    Router::get('/posts', 'ApiPostController', 'index')->name('posts.index');
    Router::post('/posts', 'ApiPostController', 'store')->name('posts.store');
}, 'api.');
```

O terceiro argumento é o prefixo de **nome**. As rotas acima ficam
`api.posts.index` e `api.posts.store`. Grupos aninham.

### Rotas nomeadas e geração de URL

```php
Router::url('posts.show', ['id' => 42]);                   // /posts/42
Router::url('posts.index', [], ['page' => 2]);             // /posts?page=2
Router::url('users.profile', ['username' => 'café']);      // /users/caf%C3%A9
```

`url()` valida os valores contra o tipo do parâmetro e lança
`InvalidArgumentException` para valor ausente, inválido ou desconhecido. Nomes
duplicados são rejeitados no registro.

---

## Controllers

Controllers para HTML estendem `BaseController`; para JSON, `BaseAPIController`.
Actions **escrevem a resposta** (via `View::render()` ou `echo`) e retornam
`void` — não há objeto Response.

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\View;

final class PostController extends BaseController
{
    public function show(string $id): void
    {
        View::render('posts/show', ['id' => (int) $id]);
    }
}
```

### BaseController

```php
$this->query('page');            // $_GET['page'], sem modificação
$this->input('title');           // $_POST['title'], sem modificação
$this->input('title', 'padrão'); // com valor padrão
$this->all();                    // todo o $_POST
$this->filled('title');          // presente e não vazio
$this->redirect('/posts', HTTP_FOUND);
```

Os valores voltam **inalterados**, por decisão de projeto. Escape é
propriedade do destino, não do valor: escapar na entrada corrompe o dado
(`O'Brien` virava `O&#39;Brien` no banco; uma senha `a<b` era hasheada como
`a&lt;b`) e não protege nada, porque um valor escapado para HTML continua
inseguro em SQL ou num shell.

A regra do framework é: **validar na entrada, escapar na saída.**

- Validar com `Validator`, que verifica sem modificar
- Vincular, nunca concatenar, ao falar com o banco — `QueryBuilder` e
  `RawQuery` fazem bind de tudo
- Escapar no ponto de saída — `{{ }}` do SFHT escapa sozinho; `e()` existe
  para templates PHP crus

### Helpers globais

Carregados em toda requisição por `src/utils.php`:

```php
e($valor);                    // escapa para HTML: <script> → &lt;script&gt;
asset('css/app.css');         // → /assets/css/app.css
asset('js/sfjs.js');          // → /assets/js/sfjs.js
csrf_token();  csrf_field();  csrf_meta();  csrf_verify();
```

`asset()` prefixa `/assets/` e **valida o caminho**: travessia de diretório e
caracteres fora de `[A-Za-z0-9._-]` lançam `InvalidArgumentException`.

Arquivos estáticos ficam em `public/assets/{css,js,images}/`.

E por `src/helpers.php`:

```php
cache();                      // CacheManager com driver de arquivo
dispatch(new MeuJob());       // enfileira um job
```

### BaseAPIController

```php
$body   = $this->getRequest();      // corpo cru
$data   = $this->getJsonRequest();  // decodifica JSON, valida Content-Type
$header = $this->getHeader('Authorization');
$this->responseJSON(['ok' => true], HTTP_CREATED);
```

`getJsonRequest()` responde 415 se o `Content-Type` não for
`application/json` e 400 se o corpo não decodificar.

---

## Views e SFHT

### Renderizando

```php
use SfphpProject\src\View;

View::render('posts/index', ['posts' => $posts]);  // ecoa a saída
View::partial('header', ['title' => 'Meu Site']);  // resolve em partials/header
```

Nomes de view são validados contra travessia de diretório. Templates vivem em
`app/resources/views/` com extensão **`.sfht`**.

Para controlar caminhos e cache diretamente:

```php
use SfphpProject\src\View\SfhtEngine;

$engine = new SfhtEngine([__DIR__ . '/views'], '/tmp/sfht-cache');
echo $engine->render('home', ['title' => 'Olá']);
```

### Saída

```sfht
{{ $name }}              escapa HTML — use este
{!! $html !!}            saída crua — só para HTML que você produziu
{{-- comentário --}}     removido na compilação, não vai para o HTML
```

**`{{ }}` escapa por padrão** (`ENT_QUOTES | ENT_SUBSTITUTE`, UTF-8). A forma
segura é a curta; contorná-la exige escrever mais.

A expressão é PHP real — chamadas de função, operadores e índices funcionam:

```sfht
{{ count($items) }}
{{ $user['name'] }}
{{ $total > 0 ? 'sim' : 'não' }}
```

### Condicionais

```sfht
@if($user->isAdmin())
  <p>Admin</p>
@elseif($user->isPremium())
  <p>Premium</p>
@else
  <p>Visitante</p>
@endif

@unless($autorizado)
  <p>Acesso negado</p>
@endunless
```

### Laços

```sfht
@foreach($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@endforeach

@forelse($posts as $post)
  <h2>{{ $post['title'] }}</h2>
@empty
  <p>Nenhum post ainda.</p>
@endforelse

@for($i = 0; $i < 10; $i++)
  <p>{{ $i }}</p>
@endfor

@while($fila->temItens())
  {{ $fila->proximo() }}
@endwhile
```

### Herança de layout

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

@block('title')Página inicial@endblock

@block('content')
  <h1>Bem-vindo</h1>
@endblock
```

O filho renderiza primeiro e seus blocos vencem. Um bloco que o filho não
define usa o conteúdo padrão do layout. O layout também renderiza sozinho.
Ciclos de `@extends` são detectados (limite de 16 níveis).

### Partials e componentes

```sfht
@include('partials/header')
@include('partials/card', ['title' => 'Olá'])
@includeWhen($mostrarForm, 'partials/form')
@component('components/button', ['label' => 'Enviar'])
```

O partial herda as variáveis em escopo no ponto da inclusão; o array explícito
tem precedência. `@component` é sinônimo de `@include`.

### PHP embutido

```sfht
@php
    $total = array_sum($valores);
@endphp

<p>Total: {{ $total }}</p>
```

### Filtros

Encadeáveis com `|`:

```sfht
{{ $texto | upper }}
{{ $texto | truncate(50) }}
{{ $texto | upper | truncate(20, '…') }}
{{ $preco | format('%.2f') }}
{{ $nome | default('Anônimo') }}
```

| Filtro | Efeito |
|---|---|
| `upper` / `lower` | Caixa alta/baixa |
| `capitalize` | Primeira letra maiúscula |
| `truncate(n, sufixo)` | Encurta para `n` **caracteres**; o sufixo conta no limite |
| `length` | Caracteres de uma string, ou itens de um array |
| `reverse` | Inverte respeitando multibyte |
| `escape` | Escapa HTML explicitamente |
| `json` | JSON com `UNESCAPED_UNICODE` |
| `format(fmt)` | `sprintf` |
| `trim` | Remove espaços nas pontas |
| `abs` / `round(n)` | Numéricos |
| `default(v)` | Substitui `null` e string vazia |

Os filtros de string contam **caracteres, não bytes**: `truncate(5)` sobre
`日本語テキスト` devolve `日本...`, nunca um byte partido ao meio.

`||` não é confundido com filtro — `{{ $a || $b ? 's' : 'n' }}` funciona.

Registrar um filtro próprio:

```php
$engine->addFilter('slug', fn (string $v): string
    => strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '-', $v)));
```

### `@` que não é diretiva

Só nomes de diretiva conhecidos viram sintaxe. Tudo mais é texto:

```sfht
<link href="...family=Inter:wght@300;400">   {{-- preservado --}}
Escreva para suporte@exemplo.com             {{-- preservado --}}
@media (min-width: 40rem) { ... }            {{-- preservado --}}
```

### Variáveis globais

```php
$engine->setGlobal('siteName', 'Meu Site');
$engine->setGlobals(['versao' => '1.0.0', 'ano' => date('Y')]);
```

### Cache de compilação

Templates compilam para PHP em disco e são executados com `include`, de modo
que o **OPcache funciona** e erros de runtime apontam arquivo e linha reais. A
gravação é atômica e invalida o OPcache no caminho exato. O cache revalida por
timestamp.

```php
$engine->clearCache();
```

### Erros de template

Diretivas desbalanceadas falham na compilação, com a linha:

```
Unclosed @if opened on line 12.
@endforeach on line 20 closes @if opened on line 12.
@empty on line 8 must appear inside @forelse.
Unclosed "{{" expression on line 3.
Filter not registered: naoexiste
```

---

## Container e injeção de dependências

```php
use SfphpProject\src\Container;

$container = new Container();

// Instância pronta
$container->set(Mailer::class, new Mailer());

// Fábrica preguiçosa — só executa quando alguém pedir
$container->set(PDO::class, fn (): PDO => Database::connect());

$mailer = $container->get(Mailer::class);
$container->has(Mailer::class);
```

Chaves são o **nome totalmente qualificado** da classe (`PDO::class`, não
`'pdo'`), porque é assim que o resolvedor procura ao preencher um parâmetro de
construtor.

Autowiring por reflexão resolve controllers e suas dependências:

```php
final class PostController extends BaseController
{
    public function __construct(private PDO $pdo) {}
}
```

O container resolve tipos de união, usa valores padrão quando disponíveis,
aceita `null` em parâmetros nuláveis, e detecta dependência circular com
`RuntimeException`.

---

## Banco de dados

### Conexão

Configure no `.env`. Drivers suportados: `mysql`, `pgsql`, `sqlite`, `sqlsrv`,
`oci`, `firebird`, `dblib`. Para qualquer outro, informe `DB_DSN` direto.

```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=app
DB_USER=root
DB_PASS=secret
DB_CHARSET=utf8mb4
```

A conexão usa `ERRMODE_EXCEPTION`, `FETCH_ASSOC` e **prepares reais**
(`EMULATE_PREPARES => false`). Falha de conexão registra o detalhe no log e
lança uma exceção genérica — host, banco e usuário não chegam ao visitante.

### Query Builder

```php
use SfphpProject\src\Database;

Database::table('users')->get();
Database::table('users')->where('age', '>', 18)->get();
Database::table('users')->where('email', 'joao@exemplo.com')->first();
Database::table('users')->count();
```

Métodos disponíveis:

```php
->select('id', 'name')            // ou ->select(['id', 'name'])
->select('name AS nome')
->where('age', '>', 18)           // = ! = <> > >= < <= LIKE "NOT LIKE"
->where('status', 'ativo')        // dois argumentos: igualdade
->orWhere('role', 'admin')
->whereNull('deleted_at')
->whereNotNull('verified_at')
->whereIn('id', [1, 2, 3])        // array vazio → nenhuma linha
->join('posts', 'users.id', '=', 'posts.user_id')
->join('posts', 'users.id', '=', 'posts.user_id', 'LEFT')
->orderBy('created_at', 'desc')
->limit(10)->offset(20)

->get()        // array de linhas
->first()      // primeira linha ou null
->count()      // int
->insert(['name' => 'João'])      // devolve o id gerado (string)
->update(['name' => 'Silva'])     // devolve linhas afetadas
->delete()                        // devolve linhas afetadas
->toSql()      // inspeciona o SQL sem executar
->bindings()   // valores vinculados
```

**Segurança.** Todo valor é vinculado com tipo PDO correto. Todo identificador
(tabela, coluna, alias) é validado contra `^[A-Za-z_][A-Za-z0-9_]*$` e citado
conforme o driver — um identificador inválido lança
`InvalidArgumentException` em vez de ir para o SQL.

Paginação é traduzida por dialeto: `LIMIT/OFFSET` em MySQL, PostgreSQL e
SQLite, `TOP` em SQL Server, `FIRST` em Firebird, `OFFSET … FETCH NEXT` em
Oracle. Driver sem suporte falha explicitamente.

### SQL cru

```php
use SfphpProject\src\Database;

Database::query('SELECT * FROM users WHERE age > ?', [18])->get();
Database::query('SELECT name FROM users WHERE id = :id', ['id' => 1])->first();
Database::query('SELECT COUNT(*) FROM users')->scalar();
Database::query('DELETE FROM users WHERE id = ?', [1])->rowCount();
Database::query('INSERT INTO logs (msg) VALUES (?)', ['oi'])->lastInsertId();
```

Aceita placeholders posicionais e nomeados. Os métodos são `get()`, `first()`,
`scalar()`, `execute()`, `rowCount()` e `lastInsertId()`.

---

## Migrations e Schema Builder

O subsistema mais completo do framework: `Blueprint` cobre MySQL 8+ e
PostgreSQL 12+ com paridade real, e **falha explicitamente** quando um dialeto
não consegue honrar a semântica pedida, em vez de mudá-la em silêncio.

### Criar e executar

```bash
./sfphp make:migration create_users_table
./sfphp make:migration:create users        # já preenchida com id + timestamps

./sfphp migrate
./sfphp migrate --step=2
./sfphp rollback
./sfphp rollback --step=3
./sfphp status
./sfphp db:fresh                           # derruba tudo e recria
```

Migrations são classes anônimas retornadas pelo arquivo:

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
$schema->table('posts', fn (Blueprint $t) => /* altera */);
$schema->drop('posts');
$schema->dropIfExists('posts');
$schema->rename('posts', 'articles');
$schema->hasTable('posts');
$schema->hasColumn('posts', 'title');
$schema->hasIndex('posts', 'posts_title_index');
$schema->statement('SET ...', $bindings);
$schema->driver();
```

### Tipos de coluna

```php
// Chaves
$table->id();                    $table->increments('id');
$table->bigIncrements('id');     $table->smallIncrements('id');
$table->mediumIncrements('id');  $table->uuid('uuid');    $table->ulid('ulid');

// Inteiros
$table->integer('n');            $table->bigInteger('n');
$table->mediumInteger('n');      $table->smallInteger('n');
$table->tinyInteger('n');        $table->unsignedInteger('n');
$table->unsignedBigInteger('n'); $table->unsignedDecimal('v', 8, 2);

// Decimais
$table->decimal('preco', 8, 2);  $table->float('f');      $table->double('d');

// Texto
$table->string('nome', 255);     $table->char('uf', 2);
$table->text('corpo');           $table->mediumText('c');  $table->longText('c');

// Data e hora
$table->date('d');               $table->dateTime('dt');   $table->dateTimeTz('dt');
$table->time('t');               $table->timeTz('t');      $table->year('y');
$table->timestamp('ts');         $table->timestampTz('ts');
$table->timestamps();            $table->timestampsTz();
$table->softDeletes();           $table->softDeletesTz();

// Outros
$table->boolean('ativo');        $table->json('meta');     $table->jsonb('meta');
$table->binary('blob');          $table->enum('st', ['a','b']);  $table->set('tags', [...]);
$table->ipAddress('ip');         $table->macAddress('mac');
$table->rememberToken();         $table->rawColumn('tags', 'TEXT[]');
```

### Modificadores

```php
$table->string('slug')->nullable()->default('')->comment('URL amigável');
$table->integer('views')->unsigned()->default(0);
$table->string('email')->unique();
$table->string('nome')->collation('pt_BR.utf8')->charset('utf8mb4');
$table->timestamp('atualizado')->useCurrent()->useCurrentOnUpdate();
$table->string('extra')->after('nome');     // MySQL
$table->string('primeiro')->first();        // MySQL
$table->integer('total')->storedAs('a + b');
$table->integer('calc')->virtualAs('a * 2');
```

### Índices e chaves

```php
$table->primary('id');
$table->unique(['email', 'tenant_id']);
$table->index('created_at');
$table->fullText('corpo');
$table->index('nome')->algorithm('btree');
$table->check('preco >= 0');

$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
$table->foreign('user_id')->references('id')->table('users')->nullOnDelete();

$table->morphs('owner');            // owner_id + owner_type + índice
$table->nullableMorphs('owner');
$table->uuidMorphs('owner');        $table->ulidMorphs('owner');
```

`onDelete`/`onUpdate` aceitam `cascadeOnDelete()`, `restrictOnDelete()`,
`nullOnDelete()`, `noActionOnDelete()` e equivalentes para update.

### Alterações e remoções

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->string('titulo', 500)->change();
    $table->renameColumn('corpo', 'conteudo');
    $table->renameIndex('idx_velho', 'idx_novo');
    $table->dropColumn('obsoleto');
    $table->dropIndex('posts_slug_index');
    $table->dropUnique('posts_email_unique');
    $table->dropForeign('posts_user_id_foreign');
    $table->dropPrimary();
    $table->dropCheck('posts_preco_check');
    $table->dropTimestamps();
    $table->dropSoftDeletes();
    $table->dropRememberToken();
    $table->dropMorphs('owner');
});
```

Nomes gerados respeitam o limite de identificador do driver (63 em PostgreSQL,
64 em MySQL) e são **determinísticos**: o nome que `create` gera é o mesmo que
`drop` procura.

### Paridade entre dialetos

| Recurso | MySQL 8+ | PostgreSQL 12+ |
|---|:--:|:--:|
| Tipos de coluna | ✓ | ✓ |
| Constraints (FK, unique, check, primary) | ✓ | ✓ |
| Índices (normal, unique, full-text) | ✓ | ✓ |
| Colunas geradas | ✓ (STORED/VIRTUAL) | ✓ (STORED) |
| `ON UPDATE CURRENT_TIMESTAMP` | ✓ nativo | ✓ via trigger |
| `ENUM` | ✓ nativo | ✓ emulado com CHECK |
| `SET` | ✓ | ✗ falha explicitamente |
| `COMMENT` | ✓ inline | ✓ via `COMMENT ON` |
| Qualificação `schema.tabela` | ✓ | ✓ |
| FK deferrable | ✗ | ✓ |

---

## Seeders e Factories

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
            'name' => 'João',
            'email' => 'joao@exemplo.com',
        ]);
    }
}
```

Encadeie a partir do `DatabaseSeeder`:

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
./sfphp db:seed                       # roda DatabaseSeeder
./sfphp db:seed --class=UserSeeder    # roda um específico
```

Um nome inexistente lista os seeders disponíveis e sai com código 1.

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
            'email' => 'user' . mt_rand(1000, 9999) . '@exemplo.com',
            'password' => password_hash('password', PASSWORD_BCRYPT),
        ];
    }
}
```

```php
$dados  = (new UserFactory())->make();                      // array, sem salvar
$user   = (new UserFactory())->create();                    // salva
$muitos = (new UserFactory())->count(50)->create();
$admin  = (new UserFactory())->create(['role' => 'admin']);  // sobrescreve
```

`make()` e `create()` devolvem **arrays**, não objetos — não há ORM.

---

## Cache

```php
$cache = cache();                    // helper global, driver de arquivo

$cache->put('chave', $valor, 300);   // TTL em segundos; null = sem expirar
$cache->get('chave');
$cache->get('chave', 'padrão');
$cache->has('chave');
$cache->forget('chave');
$cache->flush();
$cache->pull('chave');                          // lê e remove
$cache->remember('users', 600, fn () => /* ... */);   // calcula se faltar
```

Trocar o driver:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;
use SfphpProject\src\Cache\RedisDriver;

$cache = new CacheManager(new MemoryDriver());   // só durante a requisição
$cache = new CacheManager(new RedisDriver());    // exige ext-redis
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
    public function __construct(private string $para) {}

    public function handle(): void
    {
        mail($this->para, 'Olá', 'Corpo');
    }
}
```

```php
dispatch(new SendEmailJob('a@b.com'));         // helper global
dispatch(new SendEmailJob('a@b.com'), 300);    // com atraso em segundos

(new SendEmailJob('a@b.com'))->tries(5)->timeout(120);
```

```bash
./sfphp queue:work                 # padrão: 3600s
./sfphp queue:work --timeout=7200
./sfphp queue:failed
```

O worker processa até o timeout, incrementa tentativas em caso de erro e move
para `failed_jobs` quando as tentativas se esgotam. Com `ext-pcntl`, `SIGTERM`
e `SIGINT` encerram graciosamente.

As tabelas `jobs` e `failed_jobs` são criadas sob demanda, na primeira
operação que precisa delas — instanciar o driver não abre conexão.

---

## Validação

```php
use SfphpProject\src\Validator;

$resultado = Validator::validate($_POST, [
    'name'  => 'required|min:3|max:255',
    'email' => 'required|email',
    'idade' => 'required|number',
]);

if ($resultado->fails()) {
    foreach ($resultado->errors() as $campo => $mensagens) {
        echo $campo . ': ' . implode(', ', $mensagens);
    }
}

$limpos = $resultado->validated();
```

As regras são uma **string separada por `|`**, não um array. Argumentos vêm
depois de `:`.

| Regra | Verifica |
|---|---|
| `required` | Não nulo e não vazio |
| `email` | `FILTER_VALIDATE_EMAIL` |
| `min:N` | Pelo menos N **caracteres** |
| `max:N` | No máximo N **caracteres** |
| `alpha` | Só letras, **qualquer alfabeto** (`\p{L}`) |
| `alphanum` | Letras e dígitos de qualquer escrita |
| `number` | Só dígitos ASCII (seguro para `(int)`) |

Uma regra desconhecida lança `InvalidArgumentException` — erro de digitação
falha cedo, em vez de passar validação em silêncio.

`ValidationResult`: `passes()`, `fails()`, `errors()`, `validated()`.

Mensagens customizadas:

```php
Validator::validate($dados, ['name' => 'required|min:3'], [
    'name' => [
        'required' => 'Informe seu nome.',
        'min' => 'O nome precisa de ao menos 3 letras.',
    ],
]);
```

---

## Strings UTF-8

`Str` dá as operações de string que o PHP padrão só faz por byte.

```php
use SfphpProject\src\Str;

Str::length('日本語');                  // 3, não 9
Str::substr('日本語', 1, 1);            // 本
Str::truncate('日本語テキスト', 5);      // 日本...
Str::reverse('日本語');                 // 語本日
Str::isAlpha('José');                   // true
Str::isAlpha('Владимир');               // true
Str::isAlphanumeric('José99');          // true
Str::isNumeric('123');                  // true
Str::isNumeric('١٢٣');                  // false — não sobrevive a (int)
Str::isUtf8($valor);
Str::upper('ação');  Str::lower('AÇÃO');  Str::ucfirst('ação');
```

Construído sobre **PCRE com `/u`**, não sobre `mbstring`. PCRE está sempre
compilado no PHP; `mbstring` é opcional e exigi-la colocaria uma dependência
rígida na frente de cada instalação. A exceção é a conversão de caixa, que
precisa de tabelas por locale que o PCRE não expõe: ali `mbstring` é usada
quando existe e há queda para ASCII quando não existe — degrada um detalhe de
exibição em vez de corromper dado.

---

## CSRF

```php
csrf_token();     // token da sessão
csrf_field();     // <input type="hidden" name="_token" value="...">
csrf_meta();      // <meta name="csrf-token" content="...">
csrf_verify();    // valida o token da requisição atual
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

O token tem 32 bytes de `random_bytes`, é comparado com `hash_equals` (tempo
constante), e a sessão usa `httponly`, `samesite=Lax` e `secure` sob HTTPS. O
token é aceito por campo `_token` ou pelos headers `X-CSRF-Token` /
`X-XSRF-Token`.

---

## JWT

```php
use SfphpProject\src\JWT;

$token = JWT::generate(['id' => 1, 'email' => 'joao@exemplo.com']);

if (JWT::validate($token)) {
    // token íntegro e não expirado
}
```

Pontos que a assinatura impõe:

- `generate()` **exige** as claims `id` e `email`; sem elas lança
  `InvalidArgumentException`
- `validate()` devolve **`bool`**, não as claims, e não lança para token
  inválido
- Valida assinatura, `alg` (só `HS256`), `typ` e `exp`. `alg: none` é rejeitado
- Expiração fixa em 1 hora
- `JWT_KEY` precisa ter ao menos 32 bytes; o placeholder do `.env-example` é
  recusado de propósito

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

---

## Tratamento de erros

`ErrorHandler::register()` converte erros do PHP em `ErrorException`, captura
exceções não tratadas e erros fatais no shutdown, e responde:

- **500** com `Content-Type` negociado — JSON se a requisição pediu ou enviou
  JSON, HTML caso contrário
- Mensagem real **apenas** com `APP_ENV=development`; em produção, só
  `Internal Server Error`
- O detalhe sempre vai para o `error_log`

As páginas 404, 405 e 500 usam CSS inline, sem nenhuma requisição externa, e
respeitam `prefers-color-scheme`.

---

## CLI

`./sfphp` expõe **32 comandos**.

### Geração (12 geradores)

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

> Alguns geradores produzem código para infraestrutura que **ainda não
> existe**: middleware não tem pipeline que o execute, events/listeners não
> têm dispatcher, policies não têm camada de autorização. Veja
> [Limitações conhecidas](#limitações-conhecidas).

### Banco

```bash
./sfphp make:migration create_users_table
./sfphp make:migration:create users
./sfphp migrate [--step=N] [--path=dir]
./sfphp rollback [--step=N]
./sfphp status
./sfphp db:fresh
./sfphp db:seed [--class=UserSeeder]
```

### Cache e fila

```bash
./sfphp cache:clear
./sfphp cache:flush
./sfphp queue:work [--timeout=3600]
./sfphp queue:failed
```

### Servidor e utilitários

```bash
./sfphp serve          # http://localhost:8000
./sfphp routes         # tabela de rotas registradas
./sfphp env:example    # cria .env a partir de .env-example
./sfphp css:build      # gera o SFCSS a partir do config
./sfphp tinker         # REPL — só para desenvolvimento local
./sfphp list
./sfphp version
./sfphp help [comando]
```

`tinker` avalia entrada com `eval()`. É uma ferramenta de desenvolvimento
local; nunca exponha o CLI a entrada não confiável.

---

## SFCSS

Framework CSS utilitário gerado a partir de
`tools/css-builder/sfcss.config.json`. Variantes `hover:` e os breakpoints
`sm`/`md`/`lg`/`xl` são gerados a partir do próprio config.

| | |
|---|---|
| Classes no total | **2.337** |
| — utilitárias base | 1.209 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Classes de cor | 620 (20 famílias × 10 tons × `bg`/`text`/`border`) |
| Tamanho | 112KB cru · 96KB minificado · **16,1KB gzipped** |
| Dependências | nenhuma |

```bash
./sfphp css:build     # gera public/assets/css/sfcss.css e .min.css
```

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

Referência completa: [SFCSS_DOCUMENTATION.md](SFCSS_DOCUMENTATION.md) e
[SFCSS_UTILITIES_REFERENCE.md](SFCSS_UTILITIES_REFERENCE.md).

---

## SFJS

Biblioteca JavaScript sem dependências — 12KB crus, **3,0KB gzipped**.
Exposta como `window.sf`.

```html
<script src="/assets/js/sfjs.js"></script>
```

### API programática

```js
sf.ajax.get('/api/posts');
sf.ajax.post('/api/posts', { title: 'Olá' });
sf.ajax.put('/api/posts/1', { title: 'Editado' });
sf.ajax.delete('/api/posts/1');
sf.ajax.patch('/api/posts/1', { title: 'X' });

sf.form.serialize(formEl);
sf.form.submit(formEl);
sf.form.validate(inputEl);

sf.dom.addClass(el, 'ativo');   sf.dom.removeClass(el, 'ativo');
sf.dom.toggleClass(el, 'ativo'); sf.dom.hasClass(el, 'ativo');
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
<button @hxGet="/api/data" @hxTarget="#conteudo">Carregar</button>
<button @hxDelete="/api/item/1" @hxTarget="#item" @hxSwap="outerHTML">Excluir</button>

<form @hxPost="/users" @hxTarget="#lista">
  <input name="email" @validate="email">
  <button type="submit">Criar</button>
</form>

<button @toggle="menu">Menu</button>
<div id="menu">...</div>
```

`@hxSwap` aceita `innerHTML` (padrão), `outerHTML`, `beforebegin`,
`afterbegin`, `beforeend` e `afterend`.

`@validate` roda no `blur` e aceita `required`, `email`, `number`, `url`,
`minLength:N`, `maxLength:N` e `pattern:regex`.

---

## Testes

Runner próprio, sem PHPUnit — coerente com zero dependências.

```bash
composer run lint        # php -l em todo o projeto
composer run test        # 37 casos unitários
composer run test:db     # integração contra MySQL/PostgreSQL reais
composer run test:all
```

`tests/db.php` precisa de DSN nas variáveis de ambiente e pula com aviso
quando não há:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
  composer run test:db
```

O CI roda dois jobs: `unit` numa matriz PHP 8.1–8.4 **sem `mbstring`**, o que
garante que o tratamento UTF-8 não depende da extensão; e `integration` com
MySQL 8 e PostgreSQL 16 como serviços.

---

## Limitações conhecidas

Aqui estão as ausências reais. Elas não são bugs — são coisas que o framework
não faz, e que você deve saber antes de escolhê-lo.

| Ausência | Impacto |
|---|---|
| **Objetos Request/Response** | Controllers leem superglobais e escrevem com `echo`. Não dá para testá-los unitariamente nem rodar em runtime persistente (Swoole, FrankenPHP) |
| **Pipeline de middleware** | `make:middleware` gera a classe, mas nada a executa. CORS, rate limiting e autenticação não têm onde morar |
| **Rate limiting** | Não existe |
| **Autenticação / autorização** | Não existe. `make:policy` gera um esqueleto sem camada que o use |
| **Sistema de eventos** | `make:event` e `make:listener` geram classes sem dispatcher |
| **ORM** | Models e repositories geram métodos estáticos sobre o Query Builder; retornam arrays, não objetos. Sem relacionamentos, sem lazy loading |
| **i18n / l10n** | Não existe. Mensagens de erro são fixas |
| **Fusos horários** | Sem tratamento dedicado |
| **Log estruturado** | Só `error_log()` — texto plano |
| **Cache de rotas** | O despacho é O(n), com uma `preg_match` por rota. Adequado a dezenas, não a centenas |
| **Sessão plugável** | `$_SESSION` nativa. Múltiplas instâncias exigem sticky sessions |
| **Framework separado da aplicação** | O Router codifica `SfphpProject\app\controllers\`. Ainda não distribuível como pacote |

O SFHT também não tem variáveis automáticas de laço (`$loop`) nem herança
parcial de bloco (`@parent`).

---

*Documentação revisada em 2026-09-21 contra o código em execução.*

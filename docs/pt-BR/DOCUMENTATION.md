# SFPHP — Documentação

Framework PHP full-stack com **zero dependências de runtime** e correção
Unicode em toda a superfície. Esta documentação descreve o que o código faz
hoje. Onde algo não existe, está dito que não existe — veja
[Limitações conhecidas](#limitações-conhecidas).

> Verificado contra PHP 8.4 · suíte: 152 testes, 0 falhas
>
> 🌍 Disponível também em [English](../en/DOCUMENTATION.md) e
> [Español](../es/DOCUMENTATION.md).

---

## Índice

- [O que é, o que não é](#o-que-é-o-que-não-é)
- [Requisitos e instalação](#requisitos-e-instalação)
- [Estrutura do projeto](#estrutura-do-projeto)
- [Ciclo de vida da requisição](#ciclo-de-vida-da-requisição)
- [Roteamento](#roteamento)
- [Controllers](#controllers)
- [Middleware](#middleware)
- [Views e SFHT](#views-e-sfht)
- [Componentes e .phpx](#componentes-e-phpx)
- [Container e injeção de dependências](#container-e-injeção-de-dependências)
- [Banco de dados](#banco-de-dados)
- [Models](#models)
- [ORM ou Query Builder?](#orm-ou-query-builder)
- [Migrations e Schema Builder](#migrations-e-schema-builder)
- [Seeders e Factories](#seeders-e-factories)
- [Cache](#cache)
- [Queue](#queue)
- [Eventos](#eventos)
- [Cliente HTTP](#cliente-http)
- [E-mail](#e-mail)
- [Validação](#validação)
- [Upload de arquivos](#upload-de-arquivos)
- [Internacionalização](#internacionalização)
- [Tempo e fusos horários](#tempo-e-fusos-horários)
- [Strings UTF-8](#strings-utf-8)
- [Autenticação](#autenticação)
- [Segurança](#segurança)
- [Sessões](#sessões)
- [CSRF](#csrf)
- [JWT](#jwt)
- [Depuração](#depuração)
- [Tratamento de erros](#tratamento-de-erros)
- [Log](#log)
- [Health check e métricas](#health-check-e-métricas)
- [CLI](#cli)
- [SFCSS](#sfcss)
- [SFJS](#sfjs)
- [Testes](#testes)
- [Limitações conhecidas](#limitações-conhecidas)

---

## O que é, o que não é

**É** um framework enxuto para aplicações web e APIs, com roteamento,
objetos Request/Response, pipeline de middleware, container de DI, query
builder, schema builder com paridade MySQL/PostgreSQL, template engine,
componentes em `.phpx`, cliente HTTP, eventos, cache, filas, e um CLI com 35
comandos.

**Não é** um substituto de Laravel ou Symfony. Não há ORM completo, os eventos
são despachados no processo e de forma síncrona, sem broker de mensagens, e a
autenticação cobre login, guards e autorização, mas não recuperação de senha nem
dois fatores. O que existe é pequeno o suficiente para ser lido inteiro.

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

### Começar um projeto

```bash
composer create-project fabioaacarneiro/sfphp-framework meu-app
cd meu-app
./sfphp serve
```

É toda a configuração. O <http://localhost:8000> responde, o console fica em
`./sfphp` na raiz do projeto em vez de enterrado no `vendor/bin`, e o que você
tem na frente é uma aplicação funcionando, para editar:

```
meu-app/
  app/controllers/        um controller, respondendo a home
  app/models/             um model
  app/resources/views/    os templates de que essa página é feita
  src/routes.php          as rotas
  src/                    o framework
  database/migrations/    users e sessions, prontas para rodar
  public/index.php        o front controller
  resources/assets/       o SFCSS e o SFJS
  sfphp                   o console
  .env                    escrito para você, com a chave JWT gerada
```

Criar o projeto também copia o `.env-example` para `.env` — **com um `JWT_KEY`
de verdade**, porque o placeholder é recusado de propósito e gerar chave não
deveria ser a primeira coisa sobre a qual você precisa ler — e publica o SFCSS e
o SFJS em `public/assets`.

Tudo ali é **seu**. Apague o controller de exemplo e as views dele; o framework
é o `src/` e não se importa.

### Onde cada coisa fica

Nada do que você edita está dentro do `vendor/`, e essa é a regra sobre a qual o
layout é construído: o `vendor/` guarda o autoloader e mais nada, porque o
framework não tem dependências e um projeto criado carrega a própria cópia dele.

| | |
|---|---|
| `public/` | O que o servidor web serve — o front controller e os assets publicados |
| `app/` | O seu código e os seus templates: controllers, models, services, views |
| `src/` | O framework, e o que o configura: rotas, middleware, migrations, camada de configuração |
| `database/` | Migrations, seeders e factories |
| `lang/` | Os seus catálogos de mensagem |
| `.env` | Configuração, nunca versionada |
| `vendor/` | O autoloader. Nada para abrir, nada para editar |

A única chamada que o framework pede já está no front controller que veio
pronto, e vale conhecer porque é o que amarra as duas metades:

```php
require __DIR__ . '/../vendor/autoload.php';

use SfphpProject\src\Bootstrap;

Bootstrap::load(dirname(__DIR__));
```

Ela carrega o seu `.env` se existir, define as configurações que o framework lê
a menos que você já as tenha definido, e registra onde ficam as suas views e
catálogos. Um projeto com layout fora do comum diz isso:

```php
Bootstrap::load(dirname(__DIR__), [
    'views' => 'resources/views',
    'lang' => 'resources/lang',
    'env' => null,               // a configuração vem do ambiente
]);
```

### A partir de um clone

Para trabalhar **no** framework, e não com ele:

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
./sfphp serve
```

Um clone acrescenta o que um projeto criado deixa para trás: a suíte de testes,
a documentação nos três idiomas e a definição do CI.

Em produção, aponte o `DocumentRoot` para `public/`.

### O que é de quem

A linha passa entre o framework e a aplicação, e vale conhecê-la porque tudo
acima depende dela.

| | |
|---|---|
| O pacote autoloada | Só `src/`, mais quatro arquivos dentro dele |
| A aplicação é dona de | `.env`, suas constantes, suas views, seus catálogos, suas rotas |
| O `Bootstrap::load()` | É como a segunda conta de si para o primeiro |

Toda configuração que o framework lê passa por `defined()`, então um projeto que
nunca chame `Bootstrap::load()` ainda sobe nos padrões — e um teste afirma que
nenhum arquivo do framework lê uma sem esse guarda.

O fuso do runtime é a exceção: é posto em UTC quando o pacote carrega, antes de
qualquer coisa poder perguntar, porque é regra de corretude e não configuração.
Ver [Tempo e fusos horários](#tempo-e-fusos-horários).

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

O primeiro mapeamento é do pacote; os outros três são deste repositório,
declarados em `autoload-dev` para nunca chegarem a um projeto que instala o
framework.

Quatro arquivos são carregados sempre (`autoload.files`), e os quatro ficam em
`src/`: `runtime.php`, `utils.php`, `http.php` e `helpers.php`. O
`app/config/config.php` da aplicação de exemplo também é carregado, por
`autoload-dev`, e tudo o que ele faz é chamar o `Bootstrap::load()`.

---

## Ciclo de vida da requisição

```
public/index.php
 ├─ vendor/autoload.php
 │   └─ runtime.php→ date_default_timezone_set('UTC')
 │      utils.php  → helpers globais: e(), asset(), csrf_*()
 │      http.php   → constantes HTTP_OK, GET, POST, ...
 │      helpers.php→ cache(), logger(), mailer(), now(), dispatch(), __(), ...
 │      config.php → Bootstrap::load(): .env, constantes, caminhos de view e lang
 ├─ ErrorHandler::register()  rede de segurança para fatais e bootstrap
 ├─ require src/routes.php    popula o registro estático de rotas
 ├─ new Container()
 │   └─ set(PDO::class, closure)   conexão preguiçosa
 ├─ Request::fromGlobals()    único ponto que lê superglobais
 ├─ Router->dispatch($request)
 │   └─ middleware global → grupo → rota → action → Response
 └─ Emitter->emit($response)  único ponto que escreve saída
```

O `.env` é **opcional**. Um clone novo sobe sem configuração; quem precisa de
valor (banco, JWT) falha por conta própria, com mensagem específica.

### Ler uma configuração

O `Bootstrap` transforma o `.env` em constantes, e o framework as lê pelo
`Config` em vez de chamar `constant()` direto:

```php
use SfphpProject\src\Config;

Config::get('APP_ENV', 'production');
Config::int('SESSION_LIFETIME', 7200);
Config::string('MAIL_FROM_ADDRESS');
Config::has('JWT_KEY');
```

Um `set()` explícito ganha, depois a constante, depois o default que quem chamou
passou. Nada que lê `APP_ENV` direto mudou — as constantes continuam definidas e
continuam funcionando.

O motivo da indireção é que uma constante não pode ser removida. Isso está certo
para uma aplicação, que decide suas configurações uma vez no boot, e é
incômodo para um teste, que quer saber o que acontece com outro tempo de sessão
sem subir um processo separado para descobrir:

```php
Config::set('SESSION_LIFETIME', 60);
// ...
Config::forget('SESSION_LIFETIME');   // volta para a constante
```

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

Os valores chegam à action **posicionalmente**, na ordem em que aparecem na
URL, depois da requisição:

```php
Router::get('/tenant/tenantId:number/posts/postId:number', 'PostController', 'show');

public function show(Request $request, string $tenantId, string $postId): Response
{
    // ...
}
```

O caminho da requisição é decodificado por segmento antes do casamento, então
`/produtos/caf%C3%A9` casa `/produtos/nome:alpha`. Separadores codificados
(`%2F`, `%5C`) **não** são transformados em separadores reais: `/a%2Fb` nunca
alcança a rota `/a/b`.

Uma rota **sem parâmetros** é casada comparando duas strings, nunca rodando uma
expressão regular, e uma rota com parâmetros compila o padrão dela uma vez e o
guarda. A maioria das aplicações é feita majoritariamente de caminhos estáticos,
então a maior parte do laço de despacho custa uma comparação. O que continua
linear é o laço em si: o router percorre a tabela até algo casar, e nada é
compilado de antemão para um arquivo.

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

Uma action recebe o `Request` como **primeiro argumento** e devolve um
`Response`. Os parâmetros de rota vêm depois, na ordem em que aparecem na URL.
É uma regra só, sem exceção e sem reflexão.

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

O `Response` devolvido é o que o framework envia. Nada de `echo`, nada de
`header()`, nada de `exit` — foi justamente o `exit` que impedia qualquer
middleware de rodar depois do controller.

### O que a action pode devolver

`Response::from()` coage o retorno, então os casos comuns ficam curtos:

| Retorno | Vira |
|---|---|
| `Response` | ele mesmo |
| `string` | `Response::html(...)` |
| `array` ou `JsonSerializable` | `Response::json(...)` |
| **nada** | **erro**, nomeando `Classe::action()` |

Devolver nada é erro de propósito. É como uma action que esqueceu o `return`
se anuncia; um 200 vazio esconderia o problema.

### Request

```php
$request->method;                    // 'POST'
$request->path;                      // '/produtos/café', já decodificado
$request->isMethod('post');

$request->query('page');             // query string
$request->body('title');             // corpo parseado
$request->input('title', 'padrão');  // corpo → JSON → query string
$request->all();                     // tudo, mesclado
$request->filled('title');

$request->header('Authorization');   // busca sem diferenciar maiúsculas
$request->bearerToken();
$request->json();                    // decodifica o corpo, lança JsonException
$request->rawBody;

$request->cookie('sessao');
$request->file('avatar');
$request->ip();
$request->isSecure();
$request->expectsJson();

$request->user();                    // definido pelo middleware Authenticate
$request->route('id');               // parâmetro de rota
$request->attribute('locale');       // anexado por um middleware
$withUser = $request->withAttribute('user', $user);   // clona
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

O `Request` nunca lê uma superglobal por conta própria: o construtor recebe
arrays, e `Request::fromGlobals()` é o único ponto do framework que toca
`$_SERVER`, `$_GET`, `$_POST` e companhia. É isso que torna o roteamento
testável e o que um runtime persistente precisa.

### Response

```php
Response::html('<h1>Olá</h1>');
Response::text('ok');
Response::json(['id' => 1], HTTP_CREATED);
Response::view('posts/index', ['posts' => $posts]);
Response::redirect('/posts');
Response::noContent();

$response->withStatus(HTTP_NOT_FOUND);
$response->withHeader('X-Request-Id', $id);   // substitui sem duplicar
$response->withBody('outro corpo');

$response->status();  $response->body();  $response->header('Content-Type');
```

`Response` é um value object: não chama `header()`, não ecoa, não mexe em
buffer. Transformar em bytes é trabalho do `Emitter`, e é essa separação que
permite testar todo o caminho sem output buffering.

### Sem classe base

Um controller não herda nada. O framework pede uma action que devolva um
`Response`, e o `Response` é uma fábrica — então todo tipo de resposta é
alcançável de qualquer classe:

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
| `Response::view($view, $data, $status)` | Uma página HTML |
| `Response::json($data, $status)` | JSON |
| `Response::html($html, $status)` · `Response::text()` | Um corpo que você montou |
| `Response::redirect($url, $status)` | Um redirect |
| `Response::route($nome, $parametros, $query)` | Um redirect para rota nomeada |
| `Response::back($request, $fallback)` | Um redirect para onde o visitante veio |
| `Response::noContent()` | 204 |

> **O `back()` não sai do seu site.** O referer é um header, então quem escolhe
> é o visitante, o que faz dele um destino de redirect que um atacante pode
> ditar. Seguir para outra origem é redirect aberto — o jeito clássico de um
> link de phishing pegar emprestado o bom nome do seu domínio. Referer que
> nomeia outro host cai no fallback, e o que não for caminho também.

Havia aqui um `BaseController` oferecendo `$this->view()` e `$this->redirect()`.
Ele pedia que você herdasse uma classe para encurtar duas chamadas que já
existiam, o que é herança sem pagar nada, e morava na aplicação de exemplo, onde
um `composer require` nunca chegava — então a linha que ele ensinava lançava
fatal num projeto instalado. O `route()` e o `back()` eram as únicas coisas dele
que ainda não existiam em outro lugar, e agora estão no `Response`.

### Endpoints JSON

Um endpoint que responde JSON também não precisa de classe base. O que ele
precisa é que um corpo que não seja JSON seja recusado **antes** da action
rodar, e é para isso que existe o pipeline:

```php
use SfphpProject\src\Http\Middleware\RequireJson;

Router::group('/api', function (): void {
    Router::post('/posts', 'PostController', 'store');
}, 'api.', [new RequireJson()]);
```

```php
public function store(Request $request): Response
{
    $data = $request->attribute('json');   // já decodificado, já válido

    return Response::json(['id' => 1], HTTP_CREATED);
}
```

O `RequireJson` responde **415** quando o `Content-Type` não é
`application/json` e **400** quando o corpo não decodifica, e deixa o que
decodificou na requisição. Os dois códigos merecem ser distinguidos: quem depura
"não falo esse formato" procura num lugar bem diferente de quem depura "isso não
era JSON válido".

`GET`, `HEAD`, `OPTIONS` e `DELETE` passam direto, porque não carregam corpo —
senão o middleware seria inutilizável num grupo que lê e escreve, que é a
maioria. Passe `new RequireJson(required: true)` para recusar uma escrita que
chegue sem `Content-Type` nenhum.

Isso substituiu um `ApiController` que você tinha de estender. A verificação
rodava só onde alguém lembrava de chamá-la, e punha uma classe entre o framework
e cada endpoint para fazer um trabalho que o pipeline já fazia.

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
logger();                     // LogManager, configurado por LOG_*
mailer();                     // MailManager, configurado por MAIL_*
now();                        // o instante atual, em UTC
dispatch(new MeuJob());       // enfileira um job
__('app.welcome', ['name' => 'Ana']);
trans_choice('app.items', 3);
locale();
```

---

## Middleware

Um middleware recebe a requisição, pode inspecioná-la ou substituí-la, e chama
`$next` para passar adiante. O que vem antes do `$next` roda na entrada; o que
vem depois roda na saída, com a resposta em mãos. Devolver sem chamar `$next`
interrompe tudo abaixo.

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

### Registrando

Três níveis, executados nesta ordem: **global → grupo → rota → action.**

```php
// Global, em public/index.php — vale inclusive para 404 e 405
$router = (new Router($container))->middleware(
    StartSession::class,
    VerifyCsrfToken::class
);

// Por grupo, em src/routes.php
Router::group('/admin', function (): void {
    Router::get('/painel', 'AdminController', 'index');
}, 'admin.', [RequireTokenMiddleware::class]);

// Por rota
Router::get('/relatorio', 'ReportController', 'show')
    ->middleware(RequireTokenMiddleware::class)
    ->name('relatorio');
```

O middleware global envolve o despacho inteiro, **incluindo requisições que não
casam com rota nenhuma**. É deliberado: header de CORS e log de requisição que
pulam 404 são bug, não otimização.

Um middleware pode ser um nome de classe, uma instância ou um callable. Nome de
classe é resolvido pelo **Container**, então o middleware pode declarar
dependências no construtor e recebê-las por autowiring.

### Middlewares que acompanham o framework

| Middleware | Faz |
|---|---|
| `LogRequests` | Dá um id à requisição e registra o desfecho dela |
| `SecurityHeaders` | Acrescenta `nosniff`, `X-Frame-Options`, `Referrer-Policy`; CSP e HSTS sob demanda |
| `SetLocale` | Negocia o idioma a partir do `Accept-Language` |
| `StartSession` | Inicia a sessão e aplica os prazos ocioso e absoluto |
| `VerifyCsrfToken` | Recusa requisição que altera estado sem token válido |
| `Authenticate` | Identifica o usuário, e recusa anônimos quando exigido |
| `RateLimit` | Limita quantas vezes o mesmo cliente bate numa rota |
| `RequireJson` | Recusa com 415 um corpo que não é JSON, e com 400 um que não decodifica |

`VerifyCsrfToken` deixa passar métodos seguros e requisições com Bearer token —
um navegador nunca anexa Bearer sozinho, então não há requisição cross-site a
forjar. Prefixos podem ser isentados:

```php
new VerifyCsrfToken(['/api'])
```

> Até esta versão, a verificação de CSRF existia mas **nada no framework a
> chamava**: cada aplicação tinha de lembrar de verificar em cada action, e
> esquecer não produzia erro algum. Agora ela vale por padrão.

---

## Views e SFHT

### Renderizando

```php
use SfphpProject\src\View;

View::make('posts/index', ['posts' => $posts]);    // devolve string
View::makePartial('header', ['title' => 'Meu Site']);

// Ou, direto para uma resposta:
Response::view('posts/index', ['posts' => $posts]);
```

`View::render()` e `View::partial()` ainda existem e ecoam, mas estão
**deprecadas**: um `Response` precisa de um corpo que ele possa carregar, não
de saída que já escapou para o cliente.

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

Há exatamente uma exceção, e ela é carregada por um tipo, não por uma sintaxe:
um valor que seja `Sfht` é impresso como está, porque `Sfht` quer dizer markup
que este framework produziu. É isso que permite compor um componente com
`{{ }}` enquanto uma string na mesma posição continua escapada — veja
[Componentes e .phpx](#componentes-e-phpx). Tudo que não for `Sfht` é escapado,
inclusive uma string sobre a qual você tem certeza.

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

## Componentes e .phpx

Há duas formas de escrever uma página, as duas distribuídas, e cada uma é melhor
em alguma coisa. A aplicação de exemplo usa uma nas páginas dela e a outra na
demonstração em `/phpx`.

| | `.sfht` | `.phpx` |
|---|---|---|
| O que é | Um arquivo de markup | Uma função PHP cujo markup mora dentro dela |
| Composição | `@include`, `@extends`, `@block` | Chamar a função |
| O que recebe | O que estiver em escopo, mais o que for passado | Os parâmetros dela, e nada além |
| Editado por | Quem sabe HTML | Quem lê PHP |
| Melhor para | Páginas e layouts | Pedaços reaproveitáveis |
| Passo de build | Nenhum — compila sob demanda | `./sfphp build --phpx` |

### Escrever um componente

```php
<?php

namespace App\Components;

use SfphpProject\src\View\Sfht;

function Card(string $titulo, string $corpo, string $cor = 'blue'): Sfht
{
    return sfht(
        <div class="card border-{{ $cor }}-500">
            <div class="card-header"><h3 class="m-0">{{ $titulo }}</h3></div>
            <div class="card-body"><p>{{ $corpo }}</p></div>
        </div>
    );
}
```

O `sfht(` abre uma região de markup e o `)` correspondente fecha. Entre os dois é
SFHT, então `{{ }}`, `{!! !!}`, `@if` e `@foreach` funcionam e o escape é o mesmo
do resto do framework.

```bash
./sfphp build --phpx                       # todo .phpx sob app/components
./sfphp build --phpx --from=src/ui         # a partir de outra pasta
./sfphp build --phpx --to=build/components # saída em outra pasta
```

O build escreve o PHP ao lado do fonte e roda `php -l` em cada resultado, então
erro de sintaxe aparece no build com o número da linha do `.phpx` — o compilador
preenche a saída para manter esse alinhamento.

### Um componente por arquivo

Um arquivo tem um componente e leva o nome dele, e os componentes de uma mesma
página ficam em uma pasta própria. O build percorre a árvore inteira e a
espelha, então o que é compilado se parece com o que foi escrito:

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

Componentes na mesma pasta compartilham o namespace, então se compõem pelo nome
— sem import e sem prefixo. Atravessar uma pasta, ou alcançar um componente de
um controller, é um `use function`, como para qualquer outra função em PHP:

```php
use function SfphpProject\app\components\postcode\lookup\Address;
```

### Por que um componente devolve Sfht

```php
{{ Card('Olá', $corpo) }}    o card renderiza
{{ $corpo }}                  o texto é escapado
```

Os dois na mesma posição, com a coisa certa acontecendo a cada um, porque o
**tipo** diz qual é qual. `Sfht` quer dizer "markup que este framework produziu";
qualquer outra coisa é texto de origem desconhecida.

A alternativa — devolver string e escrever `{!! Card(...) !!}` — pede que o autor
lembre quais valores são confiáveis, e é aí que um dia alguém escreve
`{!! $comentario !!}` e publica um buraco de cross-site scripting.

> **Embrulhar uma string em `Sfht` contorna o escape**, que é para isso que ele
> serve e por isso `new Sfht($qualquerCoisa)` merece um segundo olhar. O
> compilador monta esses objetos a partir de markup que um autor escreveu; um
> montado a partir de uma requisição é uma decisão de confiar nela.

### Qual escolher

Use `.sfht` quando a coisa é uma **página**: layout, bloco, algo que um designer
possa abrir. Use `.phpx` quando a coisa é um **pedaço**: um card, um campo, uma
linha de tabela — qualquer coisa que receba argumentos e apareça mais de uma vez.

A diferença prática é o contrato. Um parcial vê o que por acaso estava em escopo
onde ele foi incluído, então o que ele precisa se descobre lendo o arquivo. Os
parâmetros de um componente são as props dele, então o que ele precisa é a
assinatura.

### Carregar os componentes

O PHP autoloada classe, não função, então um componente compilado não pode ser
encontrado sob demanda. O front controller o inclui uma vez:

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

### Suporte do editor

Um `.phpx` é PHP com markup onde o PHP não espera, então o editor precisa ser
avisado de três coisas separadas. O projeto já vem com a configuração, e um
projeto criado a recebe pronta:

| Arquivo | Cobre |
|---|---|
| `.editorconfig` | Espaço em branco e codificação, em todo editor |
| `.vscode/settings.json` | Associação de linguagem, Emmet e a lista de arquivos do Intelephense |
| `.zed/settings.json` | O mesmo, no formato do Zed |

O Intelephense mantém uma lista de arquivos a indexar que é **separada** da
associação de linguagem do editor, e é por isso que o autocomplete parece
impossível até o `intelephense.files.associations` citar `*.phpx`.

O custo de mapear `.phpx` para a linguagem `php` é que o markup dele é lido como
erro de sintaxe, então o diagnóstico fica desligado para todo `.php` também. O
`./sfphp build --phpx` e o `composer run lint` continuam pegando os de verdade.

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
final class PostController
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

### Transações

```php
use SfphpProject\src\Database;

Database::transaction(function (): void {
    $pedido = Pedido::create(['cliente_id' => 7]);

    foreach ($itens as $item) {
        ItemPedido::create(['pedido_id' => $pedido->id] + $item);
    }
});
```

Faz commit quando o callback retorna e desfaz quando ele lança, **relançando**
o erro em seguida. O valor de retorno do callback é repassado.

```php
$id = Database::transaction(fn (): int => Pedido::create([...])->id);
Database::inTransaction();   // true enquanto dentro
```

Uma chamada aninhada **entra** na transação já aberta em vez de começar uma
segunda, porque o PDO não tem transações aninhadas. A consequência vale
conhecer: uma falha no callback interno desfaz o trabalho externo também.
Savepoints evitariam isso, mas a sintaxe deles varia entre drivers, e degradar
em silêncio naqueles que não os têm seria pior do que ser explícito.

O helper também resolve um detalhe fácil de errar à mão: um statement que falha
pode deixar o driver **sem** transação ativa, e um `rollBack()` cru nesse
estado lança `There is no active transaction` de dentro do `catch` —
substituindo o erro que realmente causou a falha. Aqui o rollback só acontece
se houver transação ativa, então o erro original sobrevive.

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

## Models

Uma camada fina sobre o Query Builder: linhas chegam como objetos tipados,
relacionamentos são declarados uma vez em vez de virarem JOIN escrito à mão em
cada chamada, e `with()` carrega esses relacionamentos em **uma** query em vez
de uma por linha.

**Não é um ORM completo.** Não há identity map, unit of work, proxy de lazy
loading nem schema derivado da classe — e isso é deliberado, porque cada um
deles é a diferença entre algo que se lê de uma sentada e algo que não.

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

Sem `$table`, o nome é inferido da classe: `Post` → `posts`, `Category` →
`categories`, `Box` → `boxes`. A inferência é simples de propósito — um nome
irregular deve declarar `$table`.

### Lendo

```php
Post::all();                       // array<Post>
Post::find(1);                     // Post|null
Post::findOrFail(1);               // Post, ou RuntimeException
Post::query()->where('publicado', 1)->orderBy('criado_em', 'desc')->limit(10)->get();
Post::query()->count();

$post->titulo;                     // atributo
$post->author;                     // relação, resolvida ao ler
$post->toArray();
```

`Model` implementa `JsonSerializable`, então um modelo vai direto para uma
resposta:

```php
return Response::json(Post::findOrFail($id));
```

### Escrevendo

```php
$post = new Post(['titulo' => 'Olá']);   // só colunas em $fillable
$post->save();                     // INSERT, e a chave volta preenchida

$post = Post::find(1);
$post->titulo = 'Outro título';
$post->save();                     // UPDATE só do que mudou

Post::create(['titulo' => 'Direto']);
$post->forceFill(['publicado_em' => now()]);   // ignora $fillable
$post->delete();
```

`$fillable` é **obrigatório**: um modelo que não o declara lança ao ser
preenchido. Ver [Segurança](#segurança) para o porquê.

Um `save()` sobre modelo existente escreve **apenas os atributos alterados** —
tocar um campo não reescreve a linha inteira. Um `save()` sem alteração não
emite query.

### Tipos de atributo

PDO devolve o que o driver entrega: uma coluna `DATETIME` chega como string, e
uma coluna JSON também. Declarar o tipo faz a conversão acontecer uma vez, em
vez de em cada ponto de uso:

```php
final class Artigo extends Model
{
    protected static array $casts = [
        'publicado' => 'bool',
        'meta' => 'json',
        'publicado_em' => 'datetime',
        'preco' => 'decimal:2',
        'views' => 'int',
    ];
}
```

```php
$artigo->publicado;      // true, não '1'
$artigo->meta;           // ['cor' => 'azul'], não '{"cor":"azul"}'
$artigo->publicado_em;   // DateTimeImmutable
$artigo->views;          // 42, não '42'
```

Disponíveis: `int`, `float`, `bool`, `string`, `json`, `array`, `datetime`,
`date` e `decimal:N`. Uma coluna nula continua nula — não vira valor zero.

A conversão vale nos dois sentidos: `$artigo->meta = ['cor' => 'verde']` é
gravado como JSON, e um `DateTimeImmutable` é gravado no formato do banco.

Um atributo de data é **sempre UTC**, nos dois sentidos — ver
[Tempo e fusos horários](#tempo-e-fusos-horários) para o motivo de a regra ser
rígida.

Em `toArray()` e no JSON, uma data sai como **ISO 8601** em vez do objeto
`DateTimeImmutable` — que `json_encode` renderizaria como uma estrutura de
campos internos, inútil para quem consome a API.

Duas exceções que vale conhecer:

```php
$artigo->getAttribute('publicado');   // '1' — o valor cru, sem cast
$artigo->cast('publicado');           // true — com cast
```

`getAttribute()` é cru **de propósito**: relacionamentos casam por esses
valores, e um cast mudaria o que eles comparam — uma chave lida como `int` de
um lado e como `string` do outro pararia de casar em silêncio.

### Relacionamentos

```php
$this->hasMany(Comment::class, 'post_id');        // um para muitos
$this->hasOne(Profile::class, 'user_id');         // um para um
$this->belongsTo(User::class, 'user_id');         // o inverso

// muitos para muitos, através de uma tabela pivô
$this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
```

Ler a propriedade resolve a relação na hora. Dentro de um laço, isso é o
problema N+1:

```php
// 1 query dos posts + 1 por post = 101 queries para 100 posts
foreach (Post::all() as $post) {
    echo $post->author->nome;
}

// 1 query dos posts + 1 de todos os autores = 2 queries
foreach (Post::query()->with('author')->get() as $post) {
    echo $post->author->nome;
}
```

`with()` aceita várias relações: `->with('author', 'comments')`, e funciona
também para muitos-para-muitos:

```php
final class Post extends Model
{
    public function tags(): Relation
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}

foreach (Post::query()->with('tags')->get() as $post) {
    foreach ($post->tags as $tag) { echo $tag->nome; }
}
```

Duas queries, independente de quantos posts existam. A coluna da pivô é
selecionada sob um alias, e é assim que as linhas da junção são reagrupadas
por post — sem isso, carregar em lote através de uma pivô voltaria a ser uma
query por linha.

### A saída de emergência

Tudo que o `Model` não faz continua a uma chamada de distância, e volta a ser
array:

```php
Post::query()->builder();          // o QueryBuilder por baixo
Database::table('posts');          // sem passar pelo Model
Database::query('SELECT ...');     // SQL cru
```

### O que não tem, e por quê

Os motivos estão detalhados em [ORM ou Query Builder?](#orm-ou-query-builder).

| Ausente | Por quê |
|---|---|
| Identity map | Buscar a mesma linha duas vezes devolve dois objetos. Rastrear identidade exige um unit of work |
| Lazy loading por proxy | A relação resolve ao ler a propriedade; não há proxy simulando o objeto ausente |
| Relações polimórficas | `hasMany`, `hasOne`, `belongsTo` e `belongsToMany` existem |
| Migrations derivadas da classe | O schema vem das migrations, não do modelo |

---

## ORM ou Query Builder?

Resposta curta: **um Query Builder, com objetos por cima.** Nem um Query
Builder puro, nem um ORM — e a fronteira é deliberada, não inacabamento.
Esta seção existe porque um meio-ORM que se confunde com um completo é pior
que qualquer um dos dois: você passa a contar com transação implícita que não
existe, ou com identidade de objeto que não é garantida.

### Os dois extremos

Um **Query Builder** monta SQL para você. Você continua pensando em tabelas,
colunas e junções; ele cuida de citar identificadores, vincular valores e
traduzir paginação entre dialetos. O resultado são linhas — arrays.

```php
Database::table('posts')
    ->join('users', 'posts.user_id', '=', 'users.id')
    ->where('posts.publicado', 1)
    ->get();                                  // array de arrays
```

Um **ORM** (mapeador objeto-relacional) inverte isso. Você pensa em objetos e
em relações entre eles; o mapeador decide o SQL. Para isso ele precisa manter
**identidade** (a mesma linha é o mesmo objeto), **ciclo de vida** (rastrear o
que mudou e gravar na ordem certa) e frequentemente **transação implícita**.

```php
$post->author->nome = 'Ana';
$entityManager->flush();      // o ORM descobre o UPDATE, a ordem e a transação
```

### Onde o SFPHP fica

| | Query Builder puro | **SFPHP** | ORM completo |
|---|:--:|:--:|:--:|
| SQL seguro, com bind e citação | ✓ | ✓ | ✓ |
| Linhas como objetos tipados | ✗ | **✓** | ✓ |
| Tipos declarados (data, JSON, bool) | ✗ | **✓** | ✓ |
| Relações declaradas uma vez | ✗ | **✓** | ✓ |
| Carga em lote contra N+1 | ✗ | **✓** | ✓ |
| Transação explícita | ✗ | **✓** | ✓ |
| Identity map | ✗ | ✗ | ✓ |
| Unit of work / `flush()` | ✗ | ✗ | ✓ |
| Proxy de lazy loading | ✗ | ✗ | ✓ |
| Relações polimórficas | ✗ | ✗ | ✓ |
| Schema derivado da classe | ✗ | ✗ | ✓ |

A linha divisória tem uma lógica: **o SFPHP mapeia leitura e escrita de linhas,
mas não gerencia o ciclo de vida dos objetos.** Tudo acima da divisória é
tradução de dados; tudo abaixo exige que o framework mantenha estado sobre os
seus objetos entre uma chamada e outra.

### Por que paramos exatamente aí

O que está abaixo da linha não foi omitido por falta de tempo. Cada item cobra
um preço concreto, e em um deles o preço é risco de segurança.

#### Identity map — não, e aqui o motivo é risco

A ideia: `Post::find(1)` duas vezes devolve o **mesmo** objeto, então editar num
lugar aparece no outro.

O problema: um identity map é um cache, com todos os problemas de cache —
invalidação, consumo de memória, e a surpresa de `find()` não ir ao banco
quando você esperava dado fresco.

E o motivo decisivo: **runtime persistente é objetivo declarado deste
framework** (Swoole, FrankenPHP). Num processo que atende várias requisições,
um identity map que não seja rigorosamente reiniciado a cada requisição vira
vazamento de dados **entre usuários** — alguém enxergando a linha que outra
pessoa carregou. É a única peça da lista em que o objetivo do projeto
argumenta *contra*, não apenas de forma neutra.

#### Unit of work — não, porque `transaction()` entrega o que importa

A ideia: você altera objetos à vontade, chama `flush()` uma vez, e o mapeador
calcula o conjunto mínimo de INSERT/UPDATE/DELETE, na ordem correta das
dependências de chave estrangeira, dentro de uma transação.

O preço: é a maior peça de um ORM como o Doctrine. Depende do identity map,
de cálculo de *changeset*, de grafo de dependências e de regras de cascata. E
torna **não óbvio quando a sua query roda** — a causa nº 1 de "por que minha
alteração não salvou?".

O que fazemos em vez disso: `save()` por objeto, que grava só o que mudou, e
uma transação **explícita** quando você precisa de atomicidade:

```php
Database::transaction(function (): void {
    $pedido = Pedido::create(['cliente_id' => 7]);

    foreach ($itens as $item) {
        ItemPedido::create(['pedido_id' => $pedido->id, ...]);
    }
});
```

Isso entrega a atomicidade sem a ambiguidade. Você vê onde a transação começa
e termina.

#### Proxy de lazy loading — não, porque já temos o valor

A ideia: `$post->author` devolve um objeto que *parece* um `User` e só consulta
o banco quando alguém toca nele de verdade.

Mas ler a propriedade **já** resolve a relação sob demanda — isso *é* lazy
loading, e é o que esta camada faz. O proxy só acrescenta o caso em que você
precisa de um objeto tipado `User` em mãos antes da consulta, e cobra caro por
ele: quebra `get_class()`, torna `instanceof` sutil, complica serialização, e
`var_dump` passa a mostrar um proxy em vez do objeto que você quer inspecionar.

#### Relações polimórficas — não, por causa de onde o dado mora

A ideia: `$comentario->comentavel` aponta para um `Post` ou para um `Video`,
conforme uma coluna `comentavel_type`.

O problema é o que essa coluna guarda: **nome de classe PHP dentro do banco**.
Isso acopla o schema ao seu namespace — renomear uma classe passa a exigir
migration — e, se algum dia esse valor for instanciado a partir de entrada não
confiável, deixa de ser questão de design e passa a ser de segurança.

Muitos-para-muitos, que é o caso comum e não tem esse problema, **existe**:
`belongsToMany()`.

#### Schema derivado da classe — não, e este seria um mau negócio mesmo se fosse barato

A ideia: atributos na classe geram as migrations, então a forma da tabela vive
num lugar só.

O problema é que inverte a fonte da verdade. E o Schema Builder é o
**subsistema mais forte deste framework**: cobre MySQL e PostgreSQL com
paridade real, emula ENUM e `ON UPDATE` no PostgreSQL via constraint e
trigger, e **falha explicitamente** quando um dialeto não consegue honrar a
semântica pedida, em vez de alterá-la em silêncio. Subordinar isso a
anotações numa classe trocaria a peça mais confiável do projeto por
conveniência.

### Como saber de qual lado escrever

Uma regra prática:

- **Model** quando você trabalha com entidades e relações — CRUD, formulários,
  API de recursos. É onde objetos e `with()` pagam.
- **Query Builder** quando você trabalha com conjuntos — relatórios,
  agregações, `GROUP BY`, atualizações em massa. Hidratação em objeto não
  ajuda, e às vezes atrapalha.
- **SQL cru** (`Database::query()`) quando a query é o produto: CTE, função de
  janela, algo específico do dialeto.

Os três coexistem, e sair do Model custa uma chamada:

```php
Post::query()->builder();   // devolve o QueryBuilder por baixo
```

Se um dia você precisar de identity map ou unit of work, o caminho honesto não
é esperar que o SFPHP cresça até lá — é usar o Doctrine, que faz isso bem, e
aceitar as dependências que vêm com ele.

---

## Migrations e Schema Builder

O subsistema mais completo do framework: `Blueprint` cobre MySQL 8+ e
PostgreSQL 12+ com paridade real, e **falha explicitamente** quando um dialeto
não consegue honrar a semântica pedida, em vez de mudá-la em silêncio.

### Criar e executar

As migrations tomam uma trava antes de ler a lista de pendentes, então duas
instâncias migrando no deploy não podem ambas decidir que o mesmo arquivo está
pendente e ambas rodá-lo. MySQL e PostgreSQL têm cada um uma trava consultiva —
uma trava nomeada, presa à conexão, liberada quando a conexão vai embora, então
um deploy morto no meio da migration não deixa nada travado. Um driver sem ela
não é recusado: ele registra que está rodando sem trava, porque fazer migrations
falharem no SQLite seria pior que abrir mão de uma guarda onde um escritor único
é a norma de qualquer jeito.

Rodar migrations como um passo do pipeline continua sendo a forma melhor. A
trava existe porque o framework não deveria depender de todo mundo ter isso.

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

Os nomes gerados respeitam o limite de identificador do driver (63 no
PostgreSQL, 64 no MySQL) e são **determinísticos**: o nome que o `create` gera é
o que o `drop` procura.

#### As instruções rodam na ordem em que você escreveu

Isso importa assim que há um rename, porque um rename muda como toda instrução
seguinte precisa chamar a coluna:

```php
$schema->table('posts', function (Blueprint $table): void {
    $table->renameColumn('code', 'sku');
    $table->string('sku', 10)->change();     // o nome novo, e funciona
});
```

O builder emitia todas as instruções de coluna antes de todas as operações, o
que punha essa modificação antes do rename que criou o nome usado por ela.
Nenhum agrupamento fixo pode estar certo — pôr renames primeiro quebra a ordem
oposta do mesmo jeito — então as instruções saem na ordem em que o blueprint as
declara.

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

`make()` e `create()` devolvem **arrays**, não objetos. Para trabalhar com
objetos, veja [Models](#models).

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

$cache->increment('hits');           // atômico; devolve o novo valor
$cache->increment('hits', 5);        // soma mais de um
$cache->decrement('slots');

$cache->increment('window', 1, 60);  // um contador que expira em 60 segundos
$cache->ttl('window');               // segundos restantes, ou null
```

### Contadores

`increment()` não é `get()` mais `put()`, e a diferença é justamente o ponto.
Duas requisições que chegam juntas leem 4 as duas e gravam 5 as duas — um
acesso se perde. Isso é inofensivo numa página em cache e não é inofensivo num
limitador de requisições, que conta precisamente quando várias chegam ao mesmo
tempo.

A soma acontece onde o dado está: dentro de um lock exclusivo no driver de
arquivo, e como `INCRBY` no Redis, de modo que quem soma é o driver, não o PHP.

O tempo de vida é aplicado **só quando o contador é criado**. Um contador que
já existe mantém a expiração que tinha, então um cliente que continua batendo
não consegue empurrar a própria janela para a frente e ficar dentro do limite
para sempre.

O corolário vale conhecer: um contador criado **sem** tempo de vida nunca ganha
um. `increment('hits')` seguido de `increment('hits', 1, 60)` deixa um contador
que não expira nunca, e `ttl()` responde `null`. Passe o tempo de vida na
chamada que cria o contador, ou em todas — o limitador de requisições faz a
segunda coisa.

> Acrescentar `increment()` e `ttl()` à interface `Cache` é uma **quebra de
> compatibilidade** para uma aplicação que traga o próprio driver: uma classe
> que implementa `Cache` passa a precisar implementar os dois.

### Escolher o driver

```ini
CACHE_DRIVER=file          # o padrão
CACHE_DRIVER=redis         # exige ext-redis
CACHE_DRIVER=array         # memória, some no fim da requisição

CACHE_PATH=storage/cache   # onde o driver de arquivo escreve
CACHE_PREFIX=sfphp:cache:  # para duas aplicações dividirem um Redis

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DB=0
```

> **Essa configuração decide mais do que cache.** Os contadores de rate limit, a
> lista de tokens revogados e — com `SESSION_DRIVER=cache` — as sessões moram
> todos aqui. No driver de arquivo cada máquina guarda a sua cópia, então atrás
> de um balanceador um token revogado continua funcionando nas outras instâncias
> e um limite de 60 requisições é, na verdade, 60 *por instância*. **Mais de uma
> instância significa `redis`.**

Escolher `redis` sem o `ext-redis` **falha na subida**, em vez de cair para o
driver de arquivo. Uma queda silenciosa deixaria quem opera acreditando que
essas três coisas são compartilhadas enquanto cada máquina guarda a sua — um
buraco que aparece meses depois e nunca como erro.

Uma conexão é aberta por processo e compartilhada pelo cache, pela fila e pelo
handler de sessão, em vez de um socket para cada. Uma aplicação que monta a
própria — socket TLS, cliente de cluster — entrega a dela:

```php
use SfphpProject\src\RedisConnection;

RedisConnection::use($meuRedis);
```

Montar um manager na mão continua valendo, e é assim que se tem um segundo cache
diferente do configurado:

```php
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$rascunho = new CacheManager(new MemoryDriver());   // só durante a requisição
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
        mailer()->send(
            (new Message())->to($this->para)->subject('Olá')->text('Corpo')
        );
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

O worker processa até o timeout, incrementa as tentativas ao falhar e move o job
para `failed_jobs` quando os tries acabam. Com `ext-pcntl`, `SIGTERM` e `SIGINT`
desligam de forma ordenada.

As tabelas `jobs` e `failed_jobs` são criadas sob demanda, na primeira operação
que precisa delas — instanciar o driver não abre conexão.

### Escolher o driver

```ini
QUEUE_DRIVER=database          # o padrão
QUEUE_DRIVER=redis             # exige ext-redis

QUEUE_RESERVATION_SECONDS=900  # maior que o seu job mais lento
QUEUE_TABLE=jobs
QUEUE_FAILED_TABLE=failed_jobs
```

O `dispatch()` e o `./sfphp queue:work` leem a mesma configuração, e é por isso
que ela existe em vez de ser argumento de construtor: um worker que montasse o
próprio driver esvaziaria o banco enquanto as requisições empilhariam no Redis,
e nenhum dos dois lados acusaria nada errado.

O driver de banco não precisa de serviço extra e sobrevive a um restart, então é
o padrão. O Redis é mais rápido e tira a tabela de jobs do banco; os dois
entregam um job a exatamente um worker.

### Mais de um worker

Um job é entregue a exatamente um worker. Vale dizer isso porque não era verdade
até esta versão, e porque a falha era invisível com um worker só: o `pop()`
selecionava uma linha e depois a atualizava, então dois workers liam o mesmo job,
os dois marcavam como reservado, e **os dois executavam**. Para uma fila isso não
é lentidão, é efeito colateral duplicado — o mesmo e-mail duas vezes, o mesmo
cartão cobrado duas vezes.

A reserva agora é uma reivindicação. O `UPDATE` carrega a condição de o job
ainda estar sem reserva, e só o worker cuja instrução afeta uma linha o tem;
quem perde procura o próximo em vez de executar o de outro. Um update
condicional em vez de `SELECT … FOR UPDATE SKIP LOCKED`, porque o framework
suporta sete drivers e não todos têm isso.

```php
new DatabaseDriver(reservationSeconds: 900);
```

**Um job reservado por um worker que morreu volta.** Um worker morto entre
reservar e terminar deixa o job marcado como tomado sem ninguém trabalhando
nele. Todo job reservado por mais de `reservationSeconds` — quinze minutos por
padrão — é liberado para outro worker reivindicar. Defina acima do maior tempo
que um job pode legitimamente levar, ou um job lento será pego duas vezes.

O driver Redis tinha a mesma falha pelo mesmo motivo: o `zRem` informa quantos
membros removeu e ninguém conferia a resposta. Agora confere.

### O que falta

| Ausência | Situação |
|---|---|
| Várias filas nomeadas | Tudo vai para `default`; a coluna existe e nada a lê |
| Reprocessar um job falho | O `failed_jobs` registra; recolocar é manual |
| Backoff entre tentativas | Uma retentativa espera 60 segundos fixos |
| Supervisor | Manter o worker vivo é tarefa do `systemd`, do `supervisor` ou da plataforma |

---

## Upload de arquivos

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

O `$_FILES` era exposto cru antes disso existir, o que deixava cada aplicação
escrever o mesmo código crítico de segurança do zero. Upload é um caminho
clássico para dentro de um servidor, e os erros são específicos e repetíveis —
então vale nomeá-los em vez de resumir.

### Três mentiras que um navegador conta

**O tipo informado é uma alegação.** O `$_FILES['x']['type']` é um cabeçalho que
o cliente mandou, então um script PHP anunciado como `image/png` chega como
`image/png`. Conferir isso não prova nada. O `mimeType()` lê os próprios bytes
do arquivo com `ext-fileinfo`, e o `assertType()` recusa em vez de adivinhar
quando essa extensão falta.

**O nome informado também é uma alegação.** Usá-lo para montar um caminho é como
`../../public/shell.php` é gravado. O `clientName()` remove tudo que parece
caminho, inclusive o byte nulo que faz `shell.php\0.png` passar por uma
verificação de extensão e cair como `shell.php` — e o `store()` não o usa.

**Um arquivo que não foi enviado não é um arquivo.** O `$_FILES` pode ser
forjado quando um script fica alcançável de um jeito que o autor não previu,
apontando `tmp_name` para `/etc/passwd`. O `is_uploaded_file()` distingue os
dois, e é checado antes de qualquer leitura ou movimentação; o `store()` então
usa `move_uploaded_file()`, que aplica a mesma guarda no momento que importa.

### Verificações

Cada uma lança `UploadException` com uma mensagem nomeando o que recusou, então
o controller decide se aquilo é erro de formulário ou falha:

| | |
|---|---|
| `assertType(['image/png'])` | O que o arquivo **contém**, pelos bytes |
| `assertExtension(['png'])` | Como o arquivo se **chama** |
| `assertSmallerThan($bytes)` | Por campo, diferente do `upload_max_filesize` |
| `assertImage()` | Decodifica o cabeçalho, então imagem falsa é recusada |

Tipo e extensão valem os dois, porque são mentiras diferentes: o que um arquivo
contém decide como uma biblioteca o lê, e no que o nome dele termina decide como
um servidor web o trata. Um PNG de verdade chamado `avatar.php` continua sendo
problema se cair onde PHP é executado.

```php
try {
    $file->assertType(['application/pdf'])->assertSmallerThan(5 * 1024 * 1024);
} catch (UploadException $e) {
    $errors['nota'] = $e->getMessage();
}
```

O `Validator` deliberadamente não entra nisso. Ele trabalha com escalares de um
formulário, e o tipo real de um upload é algo que só o próprio arquivo responde.

### Armazenando

```php
$path = $file->store('/var/app/storage/notas');
// /var/app/storage/notas/9f2c…a41.pdf

$path = $file->store($diretorio, 'relatorio.csv');   // ainda sanitizado
```

O nome guardado é **aleatório**, e isso é o ponto, não uma conveniência: o nome
do cliente é entrada do cliente. A extensão é levada adiante só quando é
alfanumérica simples, então nada nela pode ser um caminho ou uma segunda
extensão. Um nome que você mesmo passe é reduzido a algo que não pode ser
caminho, e recusado de vez quando não sobra nada usável.

> **Guarde uploads fora do document root.** Nada disso impede um arquivo de ser
> executado se for gravado onde o servidor web o roda. O `public/` é o único
> lugar em que um upload nunca deveria ir.

### Vários arquivos

```php
foreach ($request->files('fotos') as $foto) {
    $foto->assertImage()->store($diretorio);
}
```

O `$_FILES['fotos']` para `name="fotos[]"` não é uma lista de arquivos — é um
arquivo cujas propriedades são todas listas. O `files()` inverte isso, e o
`file()` responde `null` para um campo assim em vez de devolver algo inutilizável.
O `hasFile()` pergunta se chegou um arquivo **usável**, não se o campo veio.

### Por que um upload falhou

O PHP reporta falhas como inteiros `UPLOAD_ERR_*`, e a diferença importa para
quem preenche o formulário: "o arquivo é grande demais" é algo sobre o que a
pessoa pode agir e "o servidor não tem diretório temporário" não é.
O `errorMessage()` devolve a mensagem certa, traduzida, do catálogo `upload.*`
que o framework traz nos três idiomas.

### O que falta

| Ausência | Situação |
|---|---|
| Abstração de armazenamento | O `store()` grava num caminho local. S3 ou volume compartilhado é da aplicação, e disco local não é compartilhado entre instâncias |
| Processamento de imagem | Sem redimensionar nem recodificar. O `ext-gd` faz isso e o framework não o embrulha |
| Remoção de metadados | EXIF, inclusive onde uma foto foi tirada, é mantido como chegou |
| Antivírus | Fora de escopo; é trabalho do ClamAV, sobre o arquivo já guardado |
| Upload em partes ou retomável | Uma requisição, um arquivo |

---

## Cliente HTTP

Chamar outro serviço significava `curl_setopt_array` com uma dúzia de
constantes, decodificar o corpo na mão e lembrar — ou, muito mais frequentemente,
esquecer — de definir um timeout.

```php
use SfphpProject\src\Http\Http;

$resposta = Http::get('https://api.exemplo.com/users', ['page' => 2]);
$resposta = Http::post('https://api.exemplo.com/users', ['name' => 'Ana']);

$resposta->ok();       // true para 2xx
$resposta->status();   // 200
$resposta->json();     // o corpo decodificado
```

Um corpo passado como array vai como JSON, com os headers `Content-Type` e
`Accept` que isso implica. O `->asForm()` manda como formulário, e uma string vai
como está — quem codificou o corpo é dono do tipo dele.

Todo verbo existe na fachada e no cliente, com a mesma assinatura:

```php
Http::get($url, $query);       // valores de query, anexados à URL
Http::post($url, $corpo);
Http::put($url, $corpo);
Http::patch($url, $corpo);
Http::delete($url, $corpo);    // corpo é permitido, e frequentemente ignorado

Http::client();                // um cliente sem nada configurado
```

Para um método que esses não cobrem — `OPTIONS`, `HEAD`, ou algo que um serviço
inventou — o `send()` aceita:

```php
Http::client()->send('OPTIONS', 'https://api.exemplo.com/users');
Http::client()->send('REPORT', $url, $corpo, ['page' => 2]);
```

### Um cliente para um serviço que você chama sempre

```php
$billing = Http::base('https://billing.interno')
    ->token($jwt)
    ->timeout(5);

$fatura = $billing->get('/invoices/7')->throw()->json();
```

Um cliente é um **valor**: cada método devolve um novo, então um cliente
configurado para um serviço pode circular sem que nada consiga alterá-lo.

| Na fachada | No cliente | Faz |
|---|---|---|
| `Http::base($url)` | `->base($url)` | Os caminhos relativos penduram aqui |
| `Http::withToken($jwt)` | `->token($jwt)` | Um bearer token |
| `Http::withBasic($usuario, $senha)` | `->basic($usuario, $senha)` | Credenciais HTTP basic |
| `Http::withHeaders([...])` | `->headers([...])` | Qualquer outro header |
| `Http::timeout($segundos, $conexao)` | `->timeout($segundos, $conexao)` | Quanto esperar |
| — | `->asForm()` | Mandar corpos como formulário em vez de JSON |
| — | `->insecure()` | Parar de verificar certificados |

Cada uma na fachada equivale a `Http::client()` seguido do método de instância, e
todas devolvem um cliente, então encadeiam em qualquer ordem.

### Ler a resposta

Um erro **é** uma resposta: o servidor foi alcançado, entendeu e disse não. Então
404 e 500 voltam para serem inspecionados, não lançados.

| | |
|---|---|
| `status()` | O código |
| `ok()` | 2xx |
| `failed()` · `clientError()` · `serverError()` | 4xx ou 5xx, 4xx, 5xx |
| `body()` · `json()` | O corpo, cru ou decodificado |
| `header($nome)` · `headers()` | Sem diferenciar maiúsculas |
| `url()` | A URL que respondeu, **depois** dos redirects |
| `throw()` | Lança em 4xx e 5xx, e devolve `$this` nos outros casos |

O `json()` responde `null` quando o corpo não é JSON, porque um serviço devolver
página de erro no lugar é coisa que acontece; o `json(strict: true)` lança.

Uma requisição que **não** produziu resposta — conexão recusada, nome que não
resolve, timeout, certificado que não verificou — lança `ClientException`. Não há
o que devolver.

### O que ele não faz

**Retry.** Quantas vezes tentar, quanto esperar entre tentativas e quais falhas
merecem outra são decisões sobre o serviço chamado, não sobre HTTP — uma
requisição que cobra um cartão não é para repetir porque a resposta demorou. Isso
pertence à integração, ao lado do conhecimento que consegue responder.

### O que ele protege

Um timeout é definido quer você peça ou não: 5 segundos para conectar e 15 para a
troca inteira. Uma chamada sem timeout segura um worker até o limite do próprio
PHP, então um serviço lento leva a aplicação inteira junto.

Certificados são verificados. O `->insecure()` desliga isso e tem nome
desconfortável de propósito, porque `CURLOPT_SSL_VERIFYPEER => false` copiado de
resposta de fórum está entre os buracos mais comuns em PHP.

Redirects são seguidos, limitados a cinco, e **nunca** de `https://` para
`http://` — um rebaixamento que o servidor pede e o cliente deve recusar, já que
tudo depois dele viaja em claro, inclusive o header `Authorization` que a
requisição possa estar carregando.

> **Uma URL que veio de um visitante é uma requisição que um atacante escolheu.**
> Apontada para `169.254.169.254`, ou para algo que só a sua rede alcança, isto
> busca e devolve a resposta — o ataque chamado SSRF. Nada aqui distingue uma URL
> que você montou de uma que alguém digitou, então verifique as que não foram
> você que montou.

---

## E-mail

```php
use SfphpProject\src\Mail\Message;

mailer()->send(
    (new Message())
        ->to('ana@exemplo.com', 'Ana')
        ->subject('Seu pedido')
        ->text('Obrigado pela compra.')
        ->html('<p>Obrigado pela compra.</p>')
);
```

O framework sabe pôr bytes num servidor de e-mail. Ele não sabe por que você
está mandando: não há e-mail de boas-vindas aqui nem recuperação de senha,
porque isso é decisão sobre para que serve uma aplicação. O que há é o
transporte, na mesma forma do cache e da fila — um contrato, um manager e
drivers.

### Configuração

```ini
MAIL_DRIVER=smtp
MAIL_HOST=smtp.provedor.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls              # tls para STARTTLS, ssl para TLS implícito
MAIL_FROM_ADDRESS=nao-responda@seudominio.com
MAIL_FROM_NAME="Seu Produto"
```

| Driver | Envia por | Use para |
|---|---|---|
| `smtp` | Um servidor de e-mail | Produção, com um serviço contratado |
| `mail` | O `mail()` do PHP | Máquina de desenvolvimento, e nada além |
| `log` | O logger | O padrão; mostra o que teria saído |
| `array` | Memória | Testes, via `ArrayDriver::messages()` |

O padrão é `log`, não `mail`. Um framework cujo comportamento de fábrica é
entregar mensagens a um MTA local não configurado não envia nada e não avisa
nada; escrever no log pelo menos diz o que teria saído, e não alcança uma pessoa
real por acidente.

### Um driver, todos os provedores

O `smtp` é o único transporte de que o framework precisa, e isso não é
concessão. Todo serviço que alguém contrata — SES, Postmark, SendGrid, Mailgun,
Resend, Brevo — aceita SMTP, então trocar de fornecedor é mudar quatro valores
no ambiente, não escrever driver. Um cliente HTTP por fornecedor seria mais
código alcançando menos deles.

Os dois caminhos até o TLS funcionam, porque os provedores se dividem entre
eles:

| `MAIL_ENCRYPTION` | Porta, em geral | O que acontece |
|---|---|---|
| `tls` | 587 | Conexão limpa, elevada com `STARTTLS` |
| `ssl` | 465 | Criptografada desde o primeiro byte |
| `none` | 25, 1025 | Nenhum dos dois — só servidor local |

`AUTH PLAIN` e `AUTH LOGIN` são os dois suportados; o que o servidor anuncia
decide qual é usado. O certificado é verificado por padrão.

### Enviar não é chegar

Configure as credenciais e as mensagens saem certas. Se elas chegam à caixa de
entrada depende de três coisas que são DNS e painel do fornecedor, não código:

- **Registros SPF, DKIM e DMARC** no seu domínio de envio. O provedor te dá os
  valores. Sem eles a mensagem é pontuada como spam ou recusada de saída.
- **Remetente verificado.** Quase todo serviço recusa um `From` que você não
  provou ser seu.
- **Bounces e reclamações**, que o provedor reporta por webhook. Nada aqui os
  consome, e ignorá-los queima sua reputação de envio.

Nenhum framework faz isso pela aplicação. É configurado uma vez por projeto.

### Escrevendo uma mensagem

```php
(new Message())
    ->from('nao-responda@seudominio.com', 'Seu Produto')   // em geral fica com MAIL_FROM_*
    ->to('ana@exemplo.com', 'Ana')
    ->cc('registros@seudominio.com')
    ->bcc('auditoria@seudominio.com')
    ->replyTo('suporte@seudominio.com', 'Suporte')
    ->subject('Seu pedido')
    ->text('A versão em texto.')
    ->html('<p>A versão em HTML.</p>')
    ->attach('nota.pdf', $bytes, 'application/pdf')
    ->attachFile('/tmp/relatorio.csv', 'relatorio.csv', 'text/csv')
    ->header('X-Campanha', 'outubro');
```

Definir `text()` e `html()` envia um `multipart/alternative` e deixa o cliente
de quem lê escolher. HTML sem alternativa em texto é uma das coisas que fazem
uma mensagem ser pontuada como spam, então vale preencher.

Um **endereço em Bcc chega ao servidor e nunca chega a um cabeçalho**. Escrever
um mostraria cada destinatário oculto para todos os outros, que é justamente o
que o Bcc promete não fazer.

### Duas coisas que não são conveniência

**Uma quebra de linha num cabeçalho é recusada.** Um newline num nome, num
endereço ou num assunto permite a quem o forneceu acrescentar cabeçalhos
próprios — `Bcc:` para um endereço que você nunca quis é o clássico, e o valor
costuma vir de um formulário. A `Message` lança em vez de remover, porque enviar
em silêncio uma mensagem diferente da pedida é a resposta errada tanto para um
ataque quanto para um engano.

**Tudo é UTF-8 até o fim.** Um assunto com acento é codificado conforme a RFC
2047 e um corpo conforme a RFC 2045, então "Confirmação de inscrição" chega como
ele mesmo e não como mojibake. ASCII puro fica intocado, o que mantém uma
mensagem crua legível.

### Enviando em segundo plano

A fila já existe, e uma requisição não deveria esperar um servidor de e-mail:

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

### Testando

```php
$sent = new ArrayDriver();
mailer()->driver($sent);

// ... exercite o código sob teste

$sent->last()->recipients();      // ['ana@exemplo.com']
$sent->last()->subjectLine();
```

O `MAIL_ALWAYS_TO` redireciona toda mensagem para um endereço, mantendo o
destinatário pretendido num cabeçalho `X-Intended-For`. É para um ambiente de
homologação trabalhando sobre cópia de dados de produção, onde os endereços no
banco pertencem a pessoas reais.

### O que falta

| Ausência | Situação |
|---|---|
| Retorno de entrega | Bounces e reclamações chegam por webhook no provedor; nada os consome |
| Imagens embutidas (`cid:`) | Anexos são enviados como anexos, sem referência a partir do HTML |
| Templates | Renderize uma view e passe o resultado para `html()`; o mailer recebe string |
| Assinatura DKIM no cliente | Feita pelo provedor, a partir dos registros DNS que você publica |
| Conexão reaproveitada | Uma conexão por mensagem. Envio em massa pertence à fila |

---

## Eventos

```php
use SfphpProject\src\Events\Dispatcher;

Dispatcher::listen(OrderPlaced::class, SendReceipt::class);
Dispatcher::listen(OrderPlaced::class, fn (OrderPlaced $e) => Metrics::count('orders.placed'));

Dispatcher::dispatch(new OrderPlaced($order));
```

O `make:event` e o `make:listener` geraram classes por quatro versões sem nada
que as despachasse. Um gerador que produz código para uma infraestrutura que não
existe é pior que nenhum gerador, porque parece uma feature.

Um evento é **qualquer objeto**. Não há classe base para estender nem interface
para implementar, porque nenhuma das duas carregaria informação: o que faz algo
ser um evento é alguém escutá-lo.

### Listeners

Um listener é um callable, ou o nome de uma classe com método `handle()`. A
forma com nome de classe é resolvida pelo container **quando o evento dispara**,
então um listener que precisa de conexão com banco não abre uma no boot por
causa de um evento que pode nunca acontecer.

```bash
./sfphp make:listener SendReceipt
```

Registrar contra uma classe pai ou uma interface pega os filhos, que é o que
torna "registrar todo evento de domínio" exprimível sem nomear cada um:

```php
Dispatcher::listen(DomainEvent::class, AuditTrail::class);
```

### Um listener que lança

É registrado no log, com o evento e o listener nomeados, e os outros continuam
rodando. Despachar é contar, não perguntar: um evento cujo terceiro listener
falhou aconteceu do mesmo jeito, e fazer a ação que o disparou falhar colocaria
o bug de um listener no caminho de quem chamou.

```php
Dispatcher::dispatchOrFail($event);   // quando quem chama depende deles
```

É um método separado e não uma flag, porque o padrão importa mais que a exceção:
uma flag convida a passar `true` sem decidir.

### Sob runtime persistente

Os listeners vivem num estático e são registrados uma vez, no boot, como as
rotas. É a forma certa para algo que a aplicação declara. O que não pode ir num
listener é estado por requisição capturado numa closure — ele sobreviveria à
requisição que o criou e seria visto pela seguinte.

### O que falta

| Ausência | Situação |
|---|---|
| Listener em fila | Um listener roda na requisição que disparou o evento; despache um job a partir dele para mover o trabalho |
| Interromper a propagação | Todo listener roda; não existe "tratado, pare" |
| Nomes com curinga | O registro é por classe, e uma classe pai já generaliza |

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

## Internacionalização

Mensagens ficam em catálogos por idioma; o idioma sai do `Accept-Language` da
requisição. O framework traz os próprios catálogos, e a aplicação sobrescreve
o que quiser sem editá-los.

**O padrão é inglês.** Até esta versão, as páginas 404 e 405 do framework eram
fixas em português — um desenvolvedor alemão que adotasse o SFPHP entregaria
uma página de erro em português aos usuários dele. Um framework de uso global
não pode fazer isso.

### Onde as mensagens moram

```
src/I18n/lang/            catálogos do framework (menor prioridade)
  en/http.php
  en/validation.php
  pt_BR/…

lang/                     catálogos da sua aplicação (vencem)
  en/app.php
  pt_BR/app.php
```

Um catálogo é um arquivo PHP que devolve um array:

```php
<?php   // lang/pt_BR/app.php

return [
    'welcome' => 'Bem-vindo, :name!',
    'items' => '{0} Nenhum item|{1} Um item|[2,*] :count itens',
];
```

Nada é compilado nem parseado: o catálogo custa um `require` e entra no
OPcache como qualquer outro arquivo.

A sobrescrita é **chave a chave**. Para mudar só a mensagem do 404, crie
`lang/pt_BR/http.php` com apenas `not_found_message` — o resto continua vindo
do framework.

### Traduzindo

```php
__('http.not_found_title');                    // 404 - Página Não Encontrada
__('app.welcome', ['name' => 'Ana']);          // Bem-vindo, Ana!
__('app.welcome', ['name' => 'Ana'], 'en');    // num idioma específico
locale();                                      // 'pt_BR'
```

A chave é `grupo.entrada`, e pode aninhar mais fundo (`app.form.titulo`).
**Uma chave sem tradução volta como está** — a falta aparece onde ela é, em
vez de virar uma página vazia.

### Plural

Formas separadas por `|`. Uma forma pode vir com condição explícita — `{0}`
para um número exato, `[2,4]` para faixa, `[5,*]` para faixa aberta:

```php
'items' => '{0} Nenhum item|{1} Um item|[2,*] :count itens',
```

```php
trans_choice('app.items', 0);   // Nenhum item
trans_choice('app.items', 1);   // Um item
trans_choice('app.items', 5);   // 5 itens
```

Sem condição, a regra do idioma escolhe: a primeira forma para um, a segunda
para o resto.

#### Quando faixas não bastam

Faixas cobrem a maioria dos idiomas, **mas não todos**. O polonês escolhe a
forma pelos últimos dígitos, não por faixa: 22 e 12 usam formas diferentes,
embora ambos passem de cinco. O árabe tem seis formas; o russo, três.

Fazer isso direito exige os dados de pluralização do CLDR, que é o que a
extensão `intl` carrega. Como a `intl` é opcional e o framework é
zero-dependências, embarcar uma cópia incompleta dessas regras significaria
estar **silenciosamente errado** para esses idiomas. Em vez disso, a regra é
um gancho:

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

Quem conhece o idioma escreve a regra dele. É a troca honesta: o framework não
finge saber o que não sabe.

### Escolhendo o idioma da requisição

O middleware `SetLocale` resolve o idioma uma vez, na borda:

```php
$router = (new Router($container))->middleware(
    new SetLocale(APP_LOCALES, APP_LOCALE),
    // ...
);
```

Ele lê `Accept-Language`, respeitando as qualidades (`pt-BR,pt;q=0.9,en;q=0.8`),
descarta o que vier com `q=0`, e escolhe o melhor par entre o que o cliente
pediu e o que a aplicação oferece. Pedir `pt` e receber `pt_BR` é melhor do que
receber inglês, então isso acontece.

Também acrescenta o header `Content-Language` à resposta, e as páginas de erro
do framework passam a declarar o `lang` correto no documento — antes elas
diziam `lang="en"` independentemente do conteúdo:

```html
<html lang="pt-BR">   <!-- segue o idioma negociado -->
```

Isso não é cosmético: leitores de tela escolhem a pronúncia pelo `lang`, e o
navegador usa o atributo para decidir se oferece tradução da página.

Registrá-lo **globalmente** importa por dois motivos.

O primeiro: uma requisição que não casa com rota nenhuma jamais chega a um
controller, e é só por isso que um 404 consegue sair no idioma do visitante.

O segundo aparece sob runtime persistente (Swoole, FrankenPHP). O tradutor
guarda o idioma ativo num `static`, então um worker que atendeu uma requisição
em português **responderia a próxima em português** se nada redefinisse o
idioma. Esse middleware é o que redefine. Se você montar a pipeline sem ele e
chamar `Translator::setLocale()` de dentro de um controller, o idioma vaza da
requisição de um visitante para a do seguinte.

É a mesma classe de cuidado que manteve o *identity map* fora da camada de
Models: estado estático num processo que atende várias requisições precisa de
um dono explícito que o reinicie.

Direto da requisição, quando você precisa:

```php
$request->acceptedLanguages();                      // ['pt-BR', 'pt', 'en']
$request->preferredLanguage(['en', 'pt_BR'], 'en'); // 'pt_BR'
$request->attribute('locale');                      // definido pelo SetLocale
```

### Configuração

```ini
APP_LOCALE=pt_BR
APP_LOCALES=pt_BR,en
```

`APP_LOCALE` é o idioma usado quando o cliente não pede nenhum dos que a
aplicação oferece; `APP_LOCALES` são os oferecidos, em ordem de preferência.
Sem configuração, ambos assumem inglês.

O caminho `lang/` da aplicação é registrado pelo `Bootstrap::load()`, que todo
ponto de entrada chama — então CLI, worker de fila e suíte de testes enxergam as
mesmas mensagens que uma requisição web.

### Validação

As mensagens do `Validator` saem do catálogo, e uma mensagem passada pelo
chamador continua vencendo intocada:

```php
Validator::validate($dados, ['nome' => 'required|min:5']);
// pt_BR: "nome é obrigatório." / "nome deve ter ao menos 5 caracteres."
// en:    "nome is required."   / "nome must be at least 5 characters long."

Validator::validate($dados, ['nome' => 'required'], [
    'nome' => ['required' => 'Informe seu nome.'],   // vence
]);
```

As regras de comprimento flexionam pelo número, então `min:1` diz "ao menos um
caractere" em vez de "ao menos 1 caracteres".

### O que fica em inglês de propósito

Só o texto que chega ao **usuário final** passa pelo tradutor. As exceções
dirigidas a quem escreve o código — CLI, Query Builder, Schema Builder,
Container — continuam em inglês:

```
Unknown validation rule "inexistente" for field "nome".
Cannot resolve parameter $foo in App\Service. Bind a service or provide a default value.
```

Elas são lidas num stack trace ou num log, por um desenvolvedor, e traduzi-las
tornaria mais difícil pesquisar por uma delas, não mais fácil.

### O que não tem

| Ausente | Situação |
|---|---|
| Regras CLDR de plural embutidas | Exigiriam `ext-intl` ou uma cópia dos dados. `pluralizer()` é o gancho |
| Formatação fiel ao CLDR sem o `ext-intl` | O `Time::localised()` e o `Time::number()` usam a extensão quando ela existe e degradam quando não |
| Tradução de rotas (`/products` ↔ `/produtos`) | Não existe |
| Extração de strings para catálogo | Sem comando que varra o código |
| Direção do texto (RTL) | É decisão de template, não do tradutor |

---

## Tempo e fusos horários

Tudo o que o framework armazena, calcula e registra é **UTC**.

```php
now();                                   // o instante atual, em UTC
Time::now();                             // a mesma coisa
Time::parse('2026-09-21 23:00:00');      // um valor gravado, lido como UTC
Time::in($order->created_at, 'Asia/Tokyo');   // o mesmo instante, visto de lá
Time::display($order->created_at);       // renderizado em APP_TIMEZONE
Time::toDatabase($instant);              // o valor UTC que a coluna guarda
```

### Por que aqui a regra é rígida

Um `2026-09-21 23:00:00` ingênuo numa coluna de banco só é um instante se algo
disser em que fuso ele foi escrito. Quando essa resposta é "o que o servidor
estivesse configurado", mudar o servidor — ou acrescentar um segundo —
silenciosamente muda o que toda linha existente significa.

O dano é **retroativo**, e é isso que diferencia esse caso de uma feature que
falta. Uma feature pode ser acrescentada depois. Um ano de timestamps escritos
num fuso desconhecido não pode ser consertado depois, porque a informação
necessária para consertá-los nunca foi registrada.

Por isso o fuso do runtime é UTC e **não é configurável**. Uma configuração que
muda como timestamps gravados são interpretados é uma configuração capaz de
reescrever o significado de dados existentes, e isso não é um botão que valha a
pena oferecer.

### Mostrar um horário para uma pessoa

Isso é uma decisão separada, tomada onde o valor é renderizado e não onde ele é
guardado:

```php
Time::display($order->created_at);                 // APP_TIMEZONE
Time::display($order->created_at, 'd/m/Y H:i');
Time::in($order->created_at, $user->timezone);     // por usuário
```

```ini
APP_TIMEZONE=America/Sao_Paulo
```

O `APP_TIMEZONE` decide como os horários são **mostrados**. Ele não decide como
são guardados, e mudá-lo não muda uma linha sequer.

### No idioma de quem lê

O `Time::display()` recebe um formato de `date()`, que é texto fixo: `d/m/Y`
está errado para um leitor americano, e `F` imprime "September" para quem lê em
português. Para qualquer coisa que um visitante leia, peça um estilo em vez de
um formato e deixe o idioma decidir a ordem e as palavras:

```php
Time::localised($order->created_at);                        // 21 de set. de 2026, 10:00
Time::localised($order->created_at, 'full', 'none');        // segunda-feira, 21 de setembro de 2026
Time::localised($order->created_at, 'short', 'short', 'en'); // 9/21/26, 10:00 AM
Time::number(1234.56, 2);                                   // 1.234,56 — ou 1,234.56 em inglês
```

Os dois leem o idioma ativo quando nenhum é passado, então uma página que já
roda sob o `SetLocale` não precisa de argumento. Os estilos são `none`, `short`,
`medium`, `long` e `full`, para a data e para a hora de forma independente.

O `Time::number()` está aqui e não no tradutor porque os separadores trocam de
lugar: 1.234,56 em português contra 1,234.56 em inglês. Imprimir um pelo outro
não é uma diferença cosmética — lê-se como outro número.

> **Com o `ext-intl` isso fica correto; sem ele, degrada.** A extensão é quem
> carrega os dados do CLDR, então o framework a usa quando ela está presente e
> cai para uma data no estilo ISO e um separador adivinhado pelo idioma quando
> não está — o mesmo arranjo que o `Str` tem com a mbstring. O fallback erra a
> cauda longa, mas erra como um número legível na convenção errada, nunca como
> um número errado.

### Leitura de valores

O `Time::parse()` aceita o que um banco, um formulário ou uma API entregam:

| Recebe | Lê como |
|---|---|
| Uma string com offset ou fuso (`2026-09-21T10:00:00+02:00`) | Aquele instante, convertido para UTC |
| Uma string ingênua (`2026-09-21 23:00:00`) | UTC, porque foi o que o framework escreveu |
| Uma string ingênua com um fuso nomeado no segundo argumento | Aquele fuso, convertido para UTC |
| Um timestamp Unix | Já é um instante; não há fuso a adivinhar |
| Um `DateTimeInterface` em qualquer fuso | Convertido para UTC |
| Qualquer coisa impossível de interpretar | `null`, em vez de uma exceção |

### Atributos de modelo

Os casts `datetime` e `date` seguem as mesmas regras, nos dois sentidos:

```php
$article->published_at;              // DateTimeImmutable, sempre UTC
$article->toArray()['published_at']; // "2026-09-21T23:00:00+00:00"

// 08:00 em Tóquio é gravado como o instante que ele nomeia, não como o relógio
$article->published_at = new DateTimeImmutable('2026-09-22 08:00', new DateTimeZone('Asia/Tokyo'));
// gravado: 2026-09-21 23:00:00
```

A forma JSON carrega o offset, então quem consome não pode adivinhar o fuso
errado — que é a mesma razão de o valor ser UTC em primeiro lugar.

### O banco também tem um relógio

O PHP estar em UTC é só metade. O `CURRENT_TIMESTAMP` lê o relógio do servidor
de banco, então um default de `useCurrent()` ou um trigger de `ON UPDATE`
escreve no fuso em que **aquela** máquina estiver. Deixe os dois discordando e
uma coluna acaba guardando dois significados diferentes, sem nada nos dados
dizendo qual linha é qual.

Por isso a conexão coloca a própria sessão em UTC:

| Driver | Comando |
|---|---|
| MySQL | `SET time_zone = '+00:00'` |
| PostgreSQL | `SET TIME ZONE 'UTC'` |
| Oracle | `ALTER SESSION SET TIME_ZONE = '+00:00'` |
| Outros | Intocados — configure o fuso da sessão você mesmo, ou mantenha o servidor em UTC |

Só a sessão é alterada, nunca o servidor: uma conexão declarando o que espera
está certa, e uma biblioteca reconfigurando um banco compartilhado para todos os
outros clientes dele não está. Um driver que recuse o comando é registrado como
aviso em vez de recusado, porque uma inconsistência de timestamp não deve virar
uma indisponibilidade.

### Testes

Um teste que afirma sobre "agora" corre contra o relógio. Dá para segurá-lo:

```php
Time::freeze('2026-01-01T12:00:00+00:00');
// ... now() devolve aquele instante
Time::unfreeze();
```

**Só para testes.** O valor congelado é estático, então sob runtime persistente
ele sobreviveria à requisição que o definiu e toda requisição seguinte receberia
a hora errada.

### O que falta

| Ausência | Situação |
|---|---|
| Dados do CLDR próprios | O `Time::localised()` os lê do `ext-intl`; sem a extensão o fallback é no estilo ISO, e não errado |
| Tempo relativo ("3 horas atrás") | Não existe; a frase é por idioma e pertence à aplicação |
| Coluna de fuso por usuário | O `Time::in()` aceita um; onde o fuso do usuário é guardado é decisão da aplicação |

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

## Autenticação

Três peças, separadas de propósito:

- um **provider** diz onde os usuários são procurados;
- um **guard** diz como uma requisição prova quem é;
- o **`Auth`** amarra os dois e guarda o usuário resolvido.

Separar provider de guard é o que permite o mesmo fluxo de login funcionar
sobre uma tabela, um LDAP ou uma lista em memória num teste.

### O contrato de usuário

```php
use SfphpProject\src\Auth\Authenticatable;
use SfphpProject\src\Database\Model;

final class User extends Model implements Authenticatable
{
    protected static string $table = 'users';

    public function getAuthIdentifierName(): string { return 'id'; }
    public function getAuthIdentifier(): mixed { return $this->id; }
    public function getAuthPassword(): string { return (string) $this->password; }
}
```

Três métodos, porque é tudo que o framework precisa saber: como se chama a
chave, qual é a chave, e contra o que comparar a senha. Nome, e-mail e papéis
pertencem à sua aplicação, e o framework nunca os lê.

### Configurando

```php
use SfphpProject\src\Auth\{Auth, ModelUserProvider, SessionGuard, TokenGuard};

Auth::provider(new ModelUserProvider(User::class));
Auth::guard('web', new SessionGuard(Auth::provider()));
Auth::guard('api', new TokenGuard(Auth::provider()));
Auth::setDefaultGuard('web');
```

### Entrando e saindo

```php
if (Auth::attempt(['email' => $email, 'password' => $senha])) {
    return Response::redirect('/painel');
}

return Response::view('login', ['erro' => __('auth.failed')]);
```

```php
Auth::user();        // Authenticatable|null
Auth::check();       // bool
Auth::guest();       // bool
Auth::id();          // a chave, ou null
Auth::login($user);  // sem checar senha
Auth::logout();
```

Dentro de um controller, o usuário também vem pela requisição:

```php
public function painel(Request $request): Response
{
    return Response::view('painel', ['usuario' => $request->user()]);
}
```

### Senhas

```php
use SfphpProject\src\Auth\Hash;

Hash::make($senha);                 // para gravar
Hash::check($senha, $hashGravado);  // para conferir
Hash::needsRehash($hashGravado);    // para atualizar
```

É um invólucro fino sobre o `password_hash()` do PHP, de propósito: ele já
escolhe um algoritmo sólido, gera o sal e codifica os parâmetros no resultado.
Escrever algo mais esperto aqui seria um retrocesso.

Usa `PASSWORD_DEFAULT` em vez de nomear um algoritmo, então uma atualização do
PHP que adote um padrão melhor é aproveitada automaticamente para senhas
novas. As antigas alcançam com `needsRehash()`, logo após um login bem
sucedido — é o único momento em que dá para atualizar o algoritmo de uma senha
sem pedir que o usuário a digite de novo:

```php
if (Auth::attempt($credenciais) && Hash::needsRehash($usuario->password)) {
    $usuario->password = Hash::make($credenciais['password']);
    $usuario->save();
}
```

### O middleware

```php
// Global: identifica quem puder, deixa anônimo seguir
$router->middleware(new Authenticate('web'));

// Por rota: recusa requisição anônima
Router::get('/painel', 'PainelController', 'index')
    ->middleware(new Authenticate('web', required: true));

// Uma API usa o guard de token
Router::group('/api', function (): void {
    Router::get('/eu', 'ApiController', 'eu');
}, 'api.', [new Authenticate('api', required: true)]);
```

Recusando, ele responde **401** para quem espera JSON e **redireciona para
`/login`** para um navegador. O redirecionamento é deliberado: um 401 sem
header `WWW-Authenticate` faz alguns navegadores abrirem o próprio prompt de
credenciais, que não é o formulário da sua aplicação.

> **Sob runtime persistente, este middleware é obrigatório.** O `Auth` guarda
> o usuário resolvido num `static`, para que perguntar duas vezes não consulte
> duas vezes. Num worker que atende várias requisições, esse mesmo `static`
> levaria a identidade de um visitante para a requisição do seguinte. O
> middleware chama `Auth::forgetUser()` no início de cada requisição, e é o
> dono explícito desse reset — exatamente como o `SetLocale` é do idioma
> ativo.

### Dois guards, duas naturezas

| | `SessionGuard` | `TokenGuard` |
|---|---|---|
| Prova | cookie de sessão | `Authorization: Bearer` |
| Estado | no servidor | nenhum |
| Serve para | páginas | API, workers, outro processo |
| Revogar antes de expirar | sim, é só apagar a sessão | sim, pela lista de revogados |
| `Auth::login()` | sim | não — lança |

O `SessionGuard` guarda **apenas o identificador** na sessão, nunca o usuário.
Serializar o modelo congelaria uma cópia da linha: alguém com permissões
revogadas as manteria até a sessão expirar, e renomear uma coluna quebraria a
desserialização de todas as sessões vivas.

Ele também **regenera o id da sessão** no login e no logout. No login isso é o
que impede *session fixation*: um atacante que tenha plantado um id conhecido
antes não consegue usá-lo depois, porque o id com que a vítima termina é novo.

O `TokenGuard` não guarda nada no servidor, e é isso que o torna usável fora de
uma requisição web. É também o que faz da revogação uma decisão em vez de algo
dado: um token é aceito porque a assinatura dele é válida, então nada no próprio
token consegue voltar atrás.

### Revogar um token

```php
use SfphpProject\src\Auth\TokenDenylist;

TokenDenylist::revoke($token);          // este token
TokenDenylist::revokeUser($usuario->id); // todo token emitido antes de agora
```

O único jeito de revogar um token sem estado é parar de ser sem estado a
respeito dos que você revogou, e a troca merece ser vista às claras: o guard
passa a consultar o cache em toda requisição, então verificar um token deixou de
ser de graça.

O que mantém isso barato é que um token revogado só precisa ser lembrado até a
hora em que ele expiraria de qualquer forma. Uma lista de tudo que já foi
revogado cresceria para sempre; esta é feita de entradas com prazo, então fica
do tamanho de "revogado recentemente".

`revokeUser()` é o "sair de todos os dispositivos". Ele não tem como listar os
tokens do usuário — nada nunca os registrou — então registra o momento, e um
token cujo `iat` é mais antigo que esse momento é recusado. Um login *depois*
continua funcionando, e é isso que impede que sair de todo lugar tranque a
pessoa para fora de entrar de novo.

Os tokens são guardados com hash, nunca inteiros: um cache que alguém consiga
ler — um Redis compartilhado, um dump tirado para depurar — entregaria
credenciais funcionando para todo token que ainda não expirou.

> **A lista de revogados precisa ser compartilhada entre as instâncias.** Ela
> mora no cache, então com o `CACHE_DRIVER=file` padrão é local a uma máquina e
> um token revogado numa instância continua funcionando em outra.
> `CACHE_DRIVER=redis` resolve inteiro. Em outros lugares um cache por instância
> é uma escolha de desempenho; aqui é um buraco.

A verificação pode ser desligada por guard, num serviço em que os tokens sejam
curtos o bastante para a leitura extra não compensar:

```php
$guard = new TokenGuard($provider, 'id', checkRevocation: false);
```

### Lembrar o login

```php
use SfphpProject\src\Auth\RememberToken;

$token = RememberToken::issue();
// ['cookie' => 'selector:verifier', 'selector' => ..., 'hash' => ..., 'expires' => ...]
```

Um cookie de "lembrar de mim" é uma senha que nunca expira e que o usuário não
sabe que tem, então o formato dele importa. O cookie carrega um **selector** em
claro, que é a chave de busca, e um **verifier**, guardado apenas como hash
sha256. Um banco que alguém leia, portanto, não entrega cookies funcionando, e
achar a linha continua custando uma busca por índice em vez de uma varredura.

```php
$partes = RememberToken::parse($_COOKIE['remember'] ?? '');

if ($partes !== null && RememberToken::matches($partes['verifier'], $linha->remember_token)) {
    // Autentica e emite um token novo: um cookie funciona exatamente uma vez.
}
```

Rotacionar a cada uso é o que limita o estrago. Se um cookie roubado for usado,
a próxima requisição do usuário real falha e o roubo fica visível, em vez de
duas pessoas dividirem uma conta em silêncio por um mês.

### Autorização

```php
use SfphpProject\src\Auth\Gate;

Gate::policy(Post::class, PostPolicy::class);
Gate::define('acessar-admin', fn (?Authenticatable $u): bool
    => $u !== null && $u->papel === 'admin');
```

```php
Gate::allows('update', $post);     // chama PostPolicy::update($usuario, $post)
Gate::denies('update', $post);
Gate::authorize('update', $post);  // lança AuthorizationException
Gate::forUser($outro, 'update', $post);
```

```bash
./sfphp make:policy Post
```

```php
final class PostPolicy
{
    public function update(?Authenticatable $usuario, Post $post): bool
    {
        return $usuario !== null && $usuario->getAuthIdentifier() === $post->user_id;
    }
}
```

Duas decisões que valem conhecer:

**Uma habilidade que ninguém declarou é negada.** Permitir por padrão faria um
erro de digitação no nome da habilidade abrir uma porta em silêncio.

**Uma policy recebe `null` quando a requisição é anônima**, em vez de ser
recusada antes. É isso que permite uma regra pública — ler um post publicado,
por exemplo — conviver com as demais no mesmo lugar.

`AuthorizationException` é distinta de não estar autenticado: significa que o
framework sabe quem você é e a resposta ainda é não. Um é **403**, o outro é
**401**.

### Enumeração de contas

`Auth::attempt()` verifica uma senha **mesmo quando nenhum usuário casou**,
contra um hash descartável. Sem isso, uma tentativa de login para uma conta
inexistente retornaria mais rápido do que uma para uma conta existente com
senha errada — e essa diferença basta para descobrir quais contas existem.

O hash descartável precisa ter sido gerado com os mesmos parâmetros que o
`password_hash()` usa hoje. O PHP 8.4 subiu o custo padrão do bcrypt de 10
para 12, e um hash deixado em 10 verifica cerca de quatro vezes mais rápido
que um real — o que reabriria justamente a diferença que ele existe para
esconder. Há um teste afirmando que a constante não precisa de rehash, então
uma mudança futura do PHP é pega pelo CI.

### Mensagens

`auth.failed`, `auth.unauthenticated`, `auth.unauthorized` e `auth.logged_out`
vêm nos três idiomas que o framework acompanha. Veja
[Internacionalização](#internacionalização).

### O que não tem

| Ausente | Situação |
|---|---|
| O fluxo do "lembrar de mim" | O `RememberToken` emite e verifica o cookie; lê-lo numa requisição e reemiti-lo é da aplicação |
| Recuperação de senha | Sem tabela de tokens nem fluxo de e-mail |
| Verificação de e-mail | A coluna `email_verified_at` existe; o fluxo não |
| Dois fatores | Não existe |
| Papéis e permissões | `Gate` decide; quem guarda papéis é a sua aplicação |

O rate limiting no formulário de login **existe** — veja [Segurança](#segurança).

---

## Segurança

O que o framework faz por padrão, o que exige configuração, e o que
deliberadamente não faz.

### Atribuição em massa

Um modelo só pode ser preenchido a partir de um array depois de declarar
**quais colunas** aceita:

```php
final class User extends Model
{
    protected static array $fillable = ['name', 'email'];
}
```

Sem a lista, preencher lança `MassAssignmentException`. Isso é deliberado, e o
motivo é a linha mais natural que alguém escreve:

```php
User::create($request->all());
```

Sem lista, isso grava **toda coluna que o atacante resolveu enviar**. Um
formulário de cadastro que nunca mostrou um campo `is_admin` grava um assim
mesmo, se a requisição trouxer:

```php
// enviado: name, email, is_admin=1, balance=999999
$user = new User($request->all());
$user->is_admin;   // null — descartado
```

Chaves fora da lista são **descartadas**, não geram erro, para que um
formulário com um campo extra que o navegador acrescentou continue
funcionando. Já um modelo que não declara nada **falha alto**, na primeira vez
que é usado — bem antes de chegar a produção.

Permitir por padrão protegeria apenas quem já sabia que precisava declarar, que
é exatamente o conjunto errado de pessoas.

Para valores que a própria aplicação escolheu:

```php
$user->forceFill(['email_verified_at' => now()]);
```

### Proxies confiáveis

Headers `X-Forwarded-*` são controlados pelo cliente: qualquer um pode
enviá-los. Só fazem sentido quando a conexão vem de uma máquina que se sabe
reescrevê-los, então **nada é confiado** até a implantação dizer o quê:

```php
Request::setTrustedProxies(['10.0.0.0/8', '172.16.0.5']);
```

```ini
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.5
```

> **Atrás de um balanceador que termina TLS isto não é detalhe.** O processo
> PHP vê HTTP puro, então `isSecure()` responde falso e **o cookie de sessão
> perde o flag `secure`** — ele passa a trafegar em texto claro assim que o
> visitante alcançar o site por HTTP. Configurar os proxies é o que corrige.

Com proxies declarados:

```php
$request->ip();         // o IP real do cliente, não o do balanceador
$request->isSecure();   // true, lendo X-Forwarded-Proto
```

Sem eles, ou vindo de fora da faixa confiável, os headers são ignorados — um
visitante não consegue forjar o próprio endereço. Isso importa no momento em
que qualquer coisa limita taxa ou registra log por IP.

### Limite de requisições

```php
Router::post('/login', 'AuthController', 'login')
    ->middleware(new RateLimit(maxAttempts: 5, decaySeconds: 60));
```

Responde **429** com `Retry-After` quando o limite estoura, e acrescenta
`X-RateLimit-Limit` e `X-RateLimit-Remaining` às respostas normais.

Isto é o que dá sentido às outras defesas do login. Equalizar o tempo de uma
tentativa falha impede **descobrir quais contas existem**; não impede em nada
simplesmente testar senhas. Sem limite, o atacante não precisa enumerar nada.

Os contadores ficam no cache, então o limite vale entre processos quando há um
driver compartilhado. Uma requisição autenticada conta **por usuário**, para
que várias pessoas atrás do mesmo endereço de escritório não consumam a cota
umas das outras.

A contagem é um `Cache::increment()` **atômico**, não uma leitura seguida de
escrita. Essa distinção é o middleware inteiro: requisições contadas com
`get()` e `put()` sobrescrevem umas às outras, e o limite vaza exatamente sob o
tráfego paralelo que ele existe para recusar — quem tenta senhas abre várias
conexões ao mesmo tempo, em vez de esperar cada resposta. Ver
[Contadores](#contadores).

A janela **não** é renovada a cada tentativa: renovar deixaria quem continua
batendo manter a própria janela aberta indefinidamente, e o contador nunca
perdoaria. O `Retry-After` informa o tempo de vida restante do contador, então
ele decresce rumo ao fechamento da janela em vez de reiniciar a cada recusa.

> O endereço do cliente é tão confiável quanto a configuração de proxies. Atrás
> de um balanceador sem proxies declarados, **todo o site divide um único
> balde**.

### Headers de resposta

```php
$router->middleware(new SecurityHeaders());
```

Enviados por padrão:

| Header | Fecha |
|---|---|
| `X-Content-Type-Options: nosniff` | Um arquivo enviado e servido como `text/plain` ser executado como JavaScript porque os primeiros bytes parecem um script |
| `X-Frame-Options: DENY` | *Clickjacking* — o site ser enquadrado invisivelmente sobre algo que o visitante pretende clicar |
| `Referrer-Policy: strict-origin-when-cross-origin` | A URL completa, inclusive o que estiver na query string, vazar para todo site que o visitante alcançar por um link |

Dois ficam **desligados** até serem pedidos:

```php
new SecurityHeaders(
    contentSecurityPolicy: "default-src 'self'; style-src 'self' 'unsafe-inline'",
    hstsMaxAge: 31536000,
    hstsIncludeSubdomains: true,
);
```

**Content-Security-Policy** é o mais forte e o mais fácil de errar: uma
política que não bate com os próprios assets quebra a página **sem erro que o
desenvolvedor veja**, e o framework não tem como saber quais são esses assets.

> Note o `'unsafe-inline'` em `style-src` no exemplo: as páginas de erro do
> próprio framework usam CSS embutido, justamente para não depender de rede.
> Uma política sem ele deixa a página 404 sem estilo.

**Strict-Transport-Security** fica desligado porque ligá-lo é difícil de
desfazer — um navegador que o viu recusa HTTP puro durante todo o `max-age`,
inclusive para um site que depois precise servir HTTP por algum motivo. E só é
enviado sobre HTTPS: um navegador ignora HSTS em conexão insegura, então
enviá-lo ali pareceria proteção sem ser.

### Sessão e CSRF

- Cookie com `httponly`, `samesite=Lax` e `secure` quando a conexão é HTTPS —
  determinado pela requisição, respeitando os proxies confiáveis
- Id da sessão **regenerado no login e no logout**, contra *session fixation*
- `session.use_strict_mode` ligado, então um id que o PHP nunca emitiu é
  recusado em vez de adotado
- Um prazo **ocioso** e um **absoluto**, os dois aplicados no pipeline — ver
  [Sessões](#sessões)
- Um store que pode ser compartilhado entre instâncias, então a sessão não fica
  presa a uma máquina
- Token CSRF de 32 bytes, comparado com `hash_equals`
- `VerifyCsrfToken` aplica a verificação **por padrão** a toda requisição que
  altera estado; métodos seguros e requisições com Bearer passam

### Senhas e login

- `password_hash` com `PASSWORD_DEFAULT`, e `Hash::needsRehash()` para
  atualizar sem pedir a senha de novo
- `Auth::attempt()` verifica uma senha **mesmo sem usuário correspondente**,
  contra um hash descartável, para que uma conta inexistente não responda mais
  rápido que uma senha errada
- A sessão guarda **apenas o identificador**, nunca o usuário serializado

### Banco de dados

- Todo valor é vinculado; nenhum é concatenado
- Todo identificador (tabela, coluna, alias) é validado contra uma whitelist e
  citado conforme o driver — um identificador inválido lança em vez de ir para
  o SQL
- `EMULATE_PREPARES => false`, então o driver prepara de verdade
- Falha de conexão registra o detalhe no log e lança exceção genérica: host,
  banco e usuário não chegam ao visitante

### Upload

- Um arquivo é recusado a menos que o `is_uploaded_file()` concorde que é um,
  então um `$_FILES` forjado não faz o framework ler um caminho arbitrário
- O tipo é lido dos próprios bytes do arquivo, nunca do cabeçalho que o cliente
  mandou
- O nome guardado é gerado; o nome do cliente tem caminho e byte nulo removidos
  e serve só para exibição

Ver [Upload de arquivos](#upload-de-arquivos), inclusive por que o arquivo
guardado ainda pertence a fora do document root.

### Saída

- `{{ }}` do SFHT escapa por padrão; a saída crua exige `{!! !!}`
- `e()` para templates PHP crus
- Detalhe de exceção só aparece com `APP_ENV=development`

### O que não tem

| Ausente | Situação |
|---|---|
| Recuperação de senha, verificação de e-mail, 2FA | Os fluxos são da aplicação; o [E-mail](#e-mail) é a peça que o framework devia a eles |
| Um padrão seguro para mais de uma instância | O `CACHE_DRIVER` vem como `file`, o que está certo para uma máquina e errado para várias. O framework não tem como saber qual é o seu caso, então avisa em vez de adivinhar. Ver [Escolher o driver](#escolher-o-driver) |
| Abstração de armazenamento para upload | Arquivos são validados e guardados localmente; S3 ou volume compartilhado é da aplicação. Ver [Upload de arquivos](#upload-de-arquivos) |
| Log de auditoria | Os registros são estruturados e carregam id de requisição, mas nada escreve uma trilha deliberada de "quem mudou o quê". Ver [Log](#log) |

---

## Sessões

```php
use SfphpProject\src\Session\Session;

Session::put('cart_id', 42);
Session::get('cart_id');
Session::get('ausente', 'padrão');
Session::has('cart_id');
Session::forget('cart_id');
Session::all();
Session::regenerate();     // id novo, mesmos dados
Session::invalidate();     // id novo, sem dados
Session::id();
```

O `$_SESSION` continua existindo e funcionando, mas nada no framework encosta
nele mais. Passar pelo `Session` é o que torna os dois prazos abaixo
inescapáveis: um código que iniciasse a sessão de outro jeito teria pulado eles.

### Dois prazos

```ini
SESSION_LIFETIME=7200             # ocioso: segundos sem requisição
SESSION_ABSOLUTE_LIFETIME=43200   # absoluto: segundos desde o início da sessão
```

Antes disso, uma sessão durava o que o `php.ini` dissesse, que num host
compartilhado é um número que ninguém da aplicação escolheu.

O prazo **ocioso** fecha uma sessão deixada aberta numa máquina de que alguém se
afastou. O **absoluto** fecha uma sessão viva há tempo demais por mais movimento
que tenha tido, e é o que uma auditoria pergunta: é ele que limita por quanto
tempo um cookie roubado vale alguma coisa. Cada um pode ser desligado com `0`, e
os dois são aplicados pelo `StartSession`, que é o único lugar onde dá para
aplicá-los uma vez e cobrir todas as rotas.

Quando um prazo estoura, os dados vão embora **e o id muda junto**. Esvaziar os
dados mantendo o id deixaria o visitante com um cookie que ainda nomeia uma
sessão viva, que é quase tudo o que expirar uma deveria evitar.

```php
if (Session::expiredReason() === 'idle') {
    // mostrar "você foi desconectado após um período de inatividade"
}
```

Isso lê uma vez e esquece, então o aviso aparece na requisição seguinte à
expiração e não em toda requisição dali em diante.

### Onde as sessões ficam

```ini
SESSION_DRIVER=native      # native, database ou cache
```

| Driver | Guarda em | Use quando |
|---|---|---|
| `native` | Os arquivos do próprio PHP | Uma máquina. O padrão |
| `cache` | O cache, pelo `CacheManager` | Várias instâncias, com Redis configurado |
| `database` | Uma tabela `sessions` | Várias instâncias, e você já tem banco |

Arquivos nativos são locais a uma máquina, então duas instâncias da aplicação
não enxergam as sessões uma da outra. É isso que força sticky session num
balanceador, e é por isso que um deploy que acrescenta uma segunda máquina
desconecta todo mundo. Um handler compartilhado resolve, e é a única mudança que
torna o framework usável atrás de mais de um processo.

O `cache` é mais rápido e não é durável — um cache limpo é todo mundo
desconectado. O `database` custa uma leitura e uma escrita por requisição na
conexão que a aplicação já usa, e sobrevive a um restart. Com o driver de cache
em arquivo, o `cache` se comporta exatamente como o `native`: quem decide isso é
o driver, não o handler.

O driver de banco precisa da tabela dele:

```bash
./sfphp migrate
```

Um handler também pode ser passado direto, que é como um deploy conecta um
próprio:

```php
$router->middleware(new StartSession(new CacheHandler(), 1800, 28800));
```

Qualquer classe que implemente o `SessionHandlerInterface` do próprio PHP
funciona. Implementar também o `SessionUpdateTimestampHandlerInterface` — os
dois handlers que vêm no framework implementam — é o que faz a seção seguinte
funcionar.

### Fixação de sessão

Duas defesas, e elas fecham metades diferentes do mesmo ataque.

O id é **regenerado no login e no logout**, então um id que um atacante tenha
plantado antes não é o id com que a vítima termina.

E o `session.use_strict_mode` agora está ligado. Sem ele o PHP adota qualquer id
que o cookie carregue, inclusive um que ele nunca emitiu — que é a porta em que
o atacante bate antes de mais nada. Com ele, um id desconhecido é recusado e um
novo é emitido:

```
GET / com Cookie: PHPSESSID=um-id-que-ninguem-emitiu
→ Set-Cookie: PHPSESSID=636dbac9b2f1bc09c3d335c16115bc89
```

É por isso que um handler deve implementar o `validateId()`: é assim que o PHP
pergunta ao store se um id nomeia uma sessão que existe.

### O que falta

| Ausência | Situação |
|---|---|
| Listar ou revogar a sessão de outro dispositivo | A tabela do driver `database` torna possível construir; nada vem pronto |
| Rotação periódica do id | O id muda no login, no logout e na expiração, não por tempo |
| Criptografia em repouso | O payload é guardado como o PHP serializa; um banco ou cache com criptografia própria é a resposta |
| Dados de uma requisição só | Não há helper de "guarde isto por exatamente mais uma requisição" |

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

Na prática você raramente chama `csrf_verify()` à mão: o middleware
`VerifyCsrfToken` aplica a verificação por padrão. Veja
[Middleware](#middleware).

---

## JWT

```php
use SfphpProject\src\JWT;

$token = JWT::generate(['id' => 1, 'email' => 'joao@exemplo.com']);

if (JWT::validate($token)) {
    // token íntegro e não expirado
}

$claims = JWT::claims($token);   // valida e devolve o payload, ou null
```

Pontos que a assinatura impõe:

- `generate()` **exige** as claims `id` e `email`; sem elas lança
  `InvalidArgumentException`
- `validate()` devolve **`bool`**, não as claims, e não lança para token
  inválido
- `claims()` valida e devolve o payload numa passada só, que é o que um guard
  precisa — conferir a assinatura em separado significaria verificar duas
  vezes, ou ler um payload nunca verificado
- Valida assinatura, `alg` (só `HS256`), `typ` e `exp`. `alg: none` é rejeitado
- Expiração fixa em 1 hora
- `JWT_KEY` precisa ter ao menos 32 bytes; o placeholder do `.env-example` é
  recusado de propósito

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

---

## Depuração

```php
dump($pedido);             // mostra e segue
dump($a, $b, $c);          // vários de uma vez
dd($request->all());       // mostra e para
```

O `dd()` **substitui a resposta** por uma página que mostra só o que foi
despejado. É essa a diferença em relação a imprimir um valor no meio da página
que você já estava renderizando: você pediu para parar e olhar, então o que você
olha não está misturado com um layout pela metade.

A tela é feita com SFCSS — a mesma folha de estilo com que a aplicação escreve
as próprias páginas — e o CSS é embutido, não linkado, porque uma tela que o
framework renderiza precisa renderizar quando a aplicação em volta é o que está
quebrado.

O que ela mostra, e por que cada parte está lá:

| | |
|---|---|
| A linha que chamou | Um dump que você não consegue localizar é um enigma. `app/controllers/PedidoController.php:42` |
| Visibilidade da propriedade | Um `private $token` lido como público faz você procurar no lugar errado |
| Tamanho da string | Um valor que parece certo e tem 11 caracteres quando você esperava 10 é o bug |
| `already shown above` | Um valor que aponta para si mesmo é relatado, não seguido |
| `uninitialised` | Uma propriedade tipada nunca atribuída. Lê-la lança; esse estado costuma ser justamente a resposta procurada |
| `only the first 200 shown` | O que foi cortado é declarado. Um dump truncado que admite isso é melhor que um navegador que trava |

Os ramos colapsam. Usam `<details>`, então colapsar funciona sem nenhum script —
inclusive atrás de um Content-Security-Policy que bloqueie script inline.

### No terminal

```bash
./sfphp queue:work
```

Um worker de fila, um comando de console e uma rodada de testes não têm
navegador. Ali o mesmo dump vai para a saída padrão como texto indentado,
colorido com ANSI quando a saída é um terminal e puro quando é redirecionada ou
canalizada — códigos de escape num arquivo que você vai passar pelo `grep` são
ruído.

### Em produção

```php
dd($usuario);   // APP_ENV=production
```

O dump é **gravado no log** e o visitante recebe a página de erro comum. O
`dd()` continua parando, lançando.

Um dump entregue a um visitante mostra o que quer que tenham passado para ele:
um registro de usuário, os headers da requisição, um array de configuração.
Funcionar igual em todo ambiente significaria que um `dd()` esquecido é um
vazamento de dados; deste jeito é uma entrada no seu log e um 500 para ele. O
log passa pelo `LogManager`, então senhas e tokens são redigidos no caminho.

O `dump()` em produção também grava no log, e não para.

---

## Tratamento de erros

Uma exceção lançada dentro de uma action é capturada pelo router, num limite
que fica **fora** da pipeline. `ErrorHandler::toResponse()` é o renderizador
compartilhado.

O "fora" importa, e esta página afirmava o contrário. A metade de saída de um
middleware nunca roda numa requisição que falhou: a exceção desenrola por cima
dela, então um header que ela acrescentaria não é acrescentado. É por isso que
o router anexa o `X-Request-Id` à resposta de erro ele mesmo — ver [Log](#log)
— e vale saber disso antes de escrever um middleware que presume sempre ter a
vez dele na volta.

O limite deliberadamente não é um middleware. Um middleware pode ser registrado
na ordem errada e parar de capturar em silêncio; um `try/catch` em volta da
pipeline estruturalmente não pode.

O registro global (`ErrorHandler::register()`) continua existindo, porque cobre
o que um `try/catch` não alcança: um warning durante o bootstrap, e um fatal
reportado no shutdown — falta de memória, tempo de execução estourado, erro de
parse num arquivo incluído. Sem ele, esses casos viram página em branco.

Em ambos os caminhos a resposta é:

- **500** com `Content-Type` negociado — JSON se a requisição pediu ou enviou
  JSON, HTML caso contrário
- Mensagem real **apenas** com `APP_ENV=development`; em produção, o
  `http.server_error_message` traduzido
- O detalhe sempre vai para o logger, com o id da requisição anexado — ver
  [Log](#log)

As páginas 404, 405 e 500 usam CSS inline, sem nenhuma requisição externa, e
respeitam `prefers-color-scheme`. As três são renderizadas no idioma do
visitante; até esta versão só a de 500 não era, entregando título em português
e `lang="pt-br"` fosse qual fosse o pedido.

---

## Log

Um objeto JSON por linha, com timestamp em UTC.

```php
logger()->info('pedido criado', ['order_id' => $order->id]);
logger()->warning('pagamento repetido', ['attempt' => 3]);
logger()->error('gateway recusou', ['code' => $code]);
logger()->exception($throwable);
```

```json
{"timestamp":"2026-09-21T23:34:46.472Z","level":"info","message":"request handled","context":{"request_id":"cc13917b45e763edf3476b9e02818b09","method":"GET","path":"/","ip":"127.0.0.1","status":200,"duration_ms":3.488}}
```

JSON em vez de uma frase, porque uma linha de log é lida por um programa antes
de ser lida por uma pessoa: qualquer coletor entende isso, e dá para filtrar
por um campo sem uma expressão regular que quebra na primeira mensagem que
contém dois-pontos. UTC, porque linhas com horário local não podem ser
ordenadas, e a partir de duas máquinas essa ordenação é a única coisa que faz o
log valer alguma coisa.

### Níveis

Os oito do RFC 5424, que são os mesmos do PSR-3 — `debug`, `info`, `notice`,
`warning`, `error`, `critical`, `alert`, `emergency`. Casar com esses nomes
importa mesmo sem depender do pacote: todo coletor já classifica registros por
eles.

Qualquer coisa abaixo de `LOG_LEVEL` é descartada antes de chegar ao driver,
então uma chamada a `debug()` num caminho quente custa uma comparação em
produção, não uma escrita.

### Configuração

```ini
LOG_CHANNEL=stream          # stream (o padrão), error_log, ou null
LOG_PATH=php://stderr       # um stream ou um arquivo, para o canal stream
LOG_LEVEL=info              # debug em desenvolvimento, info nos demais
```

O `stderr` é o padrão porque não exige que um diretório exista nem que uma
permissão seja concedida, e é onde um container espera encontrar os logs da
aplicação. Um caminho também funciona, e o diretório dele é criado se faltar.

O `error_log` escreve pelo log de erro do próprio PHP, para um deploy em que
algo já coleta aquilo. O `null` descarta, que é o que a suíte de testes usa
para que falhas propositais não soterrem a saída em stack traces.

### O id da requisição

É o ponto da seção inteira. Uma falha em produção nunca é uma linha só: é a
requisição que entrou, a consulta que demorou e a exceção que saiu, escritas em
momentos diferentes e intercaladas com todas as outras requisições que o
servidor estava atendendo. Sem algo que as una, ler o log é adivinhação.

```php
$router->middleware(new LogRequests());
```

Esse middleware dá um id a cada requisição e o coloca em quatro lugares: no
contexto compartilhado do log, para que toda linha escrita depois o carregue;
na própria requisição, como o atributo `request_id`; na resposta, como
`X-Request-Id`; e no registro que ele escreve quando a requisição termina, com
o status e a duração.

Como chega à resposta, o id está na tela do visitante quando algo quebra — um
chamado de suporte pode carregar a única string que encontra tudo.

Um `X-Request-Id` que chega do cliente é honrado, que é como um trace segue uma
requisição de um serviço para o próximo. Também é entrada controlada pelo
cliente indo direto para os logs, então precisa casar com
`[A-Za-z0-9._-]{1,128}`: comprimento sem limite transforma um log numa conta de
disco, e caracteres de controle transformam um visualizador de log em algo que
não mostra mais o que diz mostrar. Um id que não casa é substituído, não
recusado, porque a requisição em si não é o problema.

**Registre-o primeiro**, ou o mais perto disso que o pipeline permitir. Só o
que roda depois dele é coberto, e uma requisição que não casa com rota nenhuma
nunca chega a um controller — um 404 merece ter log.

> **Sob runtime persistente este middleware é obrigatório.** O contexto
> compartilhado vive num objeto que sobrevive à requisição num worker Swoole ou
> FrankenPHP, então o id de um visitante seguiria para os logs do próximo. Ele
> chama `forgetContext()` no início de cada requisição e é o dono explícito
> desse reset — exatamente como o `SetLocale` é do idioma ativo e o
> `Authenticate` é do usuário.

### Falhas

Uma requisição que falha é reportada **uma vez**, pelo limite do próprio
router, e não pelo middleware. O limite tem execução garantida e um middleware
pode ser registrado na ordem errada, então logar nos dois significaria um
registro duplicado sempre que ambos estivessem presentes e nenhum sempre que
nenhum estivesse.

O registro ainda carrega o id da requisição, porque uma exceção desenrolando o
pipeline não toca o contexto compartilhado. Ele carrega a classe da exceção, o
arquivo, a linha e o trace como campos separados, para que um coletor possa
agrupar por classe sem analisar uma mensagem.

Uma exceção pula o resto do pipeline, então o middleware nunca tem a vez dele
de acrescentar o header à resposta. O router o anexa no lugar: o visitante que
vê um 500 é quem mais precisa do id.

### Segredos

Log estruturado convida a passar arrays inteiros adiante, e o corpo de um
formulário de login é o primeiro array em que alguém pega. Valores sob estas
chaves são trocados por `[redacted]`, em qualquer profundidade:

`password` `password_confirmation` `current_password` `new_password` `secret`
`token` `_token` `access_token` `refresh_token` `api_key` `apikey`
`authorization` `auth` `cookie` `set-cookie` `credit_card` `card_number` `cvv`
`ssn` `cpf`

```php
logger()->redact('pin', 'account_number');
```

Redigir por chave é grosseiro, e é a diferença entre uma senha chegar a um
agregador de logs e não chegar.

### O que falta

| Ausência | Situação |
|---|---|
| Tracing | O id de requisição amarra os registros de uma requisição; seguir uma chamada entre serviços exige um trace id propagado entre eles |
| Amostragem | Todo registro que passa do nível é escrito; não existe "um a cada cem" |
| Vários destinos ao mesmo tempo | Um driver por vez — sem espalhar para um arquivo e um coletor juntos |
| Rotação de log | O arquivo cresce; rotação é do `logrotate` ou da plataforma |

---

## Health check e métricas

### Health check

Um balanceador precisa de um lugar para perguntar se mandar tráfego para cá vai
funcionar, e "o processo está rodando" é a pergunta errada: uma instância cujo
banco está inalcançável ainda aceita conexão e ainda serve erro para todo mundo
que for roteado até ela.

```php
use SfphpProject\src\Health;

Health::registerDefaults(['database', 'cache']);
Health::register('pagamentos', fn (): bool => $gateway->ping());

$report = Health::check();
// ['healthy' => true, 'checks' => ['database' => ['ok' => true, 'ms' => 1.4], ...]]
```

O framework traz as verificações e não a rota, porque onde ela mora e quem pode
vê-la são decisões da aplicação:

```php
Router::get('/health', 'HealthController', 'show');

public function show(Request $request): Response
{
    $report = Health::check();

    return Response::json($report, $report['healthy'] ? HTTP_OK : 503);
}
```

Cada verificação é cronometrada, porque "o banco respondeu" e "o banco respondeu
em quatro segundos" são estados diferentes e só um deles aparece num booleano.
Uma verificação que lança conta como falha e a **mensagem** dela é reportada —
não o trace, que nomeia caminhos e classes que não são da conta de mais ninguém.

> **Um endpoint de health descreve a sua infraestrutura.** Deixado público, ele
> conta a qualquer um quais dependências você tem e quais estão fora no momento,
> que é a primeira coisa que vale saber antes de atacar algo. Ponha atrás da
> rede do balanceador, ou atrás de um token.

Nada é registrado por padrão: um endpoint reportando sobre um banco que a
aplicação não usa estaria respondendo à pergunta errada.

### Métricas

```php
use SfphpProject\src\Log\Metrics;

Metrics::count('orders.placed');
Metrics::count('payments.failed', ['gateway' => 'stripe']);

$report = Metrics::time('report.build', fn () => $builder->run());
```

Uma linha de log carrega uma duração, o que responde "quanto essa requisição
demorou". Não responde "quanto as requisições demoram", e a diferença é o motivo
de métricas existirem: uma é anedota, a outra é o formato do sistema.

O `time()` registra a chamada que **lançou**, além da que retornou — algo que só
fica lento quando está falhando é justamente o que vale ver.

O coletor é em processo. O `snapshot()` lê como array, e o `prometheus()`
renderiza o formato texto que um scraper entende, montado aqui e não por uma
biblioteca cliente:

```
orders_placed 2
payments_failed{gateway="stripe"} 1
report_build_ms_count 2
report_build_ms_sum 41.882
report_build_ms_min 18.204
report_build_ms_max 23.678
```

### O que falta

| Ausência | Situação |
|---|---|
| Agregação entre instâncias | Cada processo guarda a própria contagem; um scraper ou um push gateway faz a junção |
| Histogramas e percentis | Contagem, soma, mínimo e máximo são registrados; um p99 exige buckets que isso não guarda |
| Persistência | As contagens somem quando o processo acaba, o que sob php-fpm é a cada requisição. Faça scraping de um runtime persistente, ou empurre |

---

## CLI

`./sfphp` expõe **35 comandos**.

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

> Todo gerador agora produz código contra algo que existe e roda — `make:event`
> e `make:listener` inclusive, desde que o `Dispatcher` chegou. O que um arquivo
> gerado ainda deve a você é o registro dele: um listener precisa ser entregue
> ao `Dispatcher::listen()` onde a aplicação sobe. Veja [Eventos](#eventos).

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

Os quatro seguem o `CACHE_DRIVER` e o `QUEUE_DRIVER`. Um `cache:clear` que
esvaziasse um cache de arquivo enquanto a aplicação usa Redis relataria sucesso
e não teria mudado nada.

### Assets

```bash
./sfphp assets:publish                     # para public/assets
./sfphp assets:publish --path=web/static   # para outro lugar
./sfphp assets:publish --force             # sobrescreve o que estiver lá
./sfphp assets:publish --symlink           # link em vez de cópia
```

Copia o SFCSS e o SFJS de dentro do pacote para um diretório que o projeto
serve. O `composer install` e o `./sfphp serve` já rodam isso, então o comando
serve para uma atualização ou um layout fora do comum; uma execução que encontra
os mesmos arquivos não copia nada e avisa.

> **Por que os arquivos existem duas vezes.** O pacote os guarda onde eles são
> versionados e onde uma atualização os substitui; o navegador só consegue ler o
> que está sob o document root, e nenhum pacote pode escrever no seu `public/`
> na hora da instalação. Então um é a fonte e o outro é cópia publicada — o
> `public/assets/css` e o `public/assets/js` pertencem ao `.gitignore`, como o
> `vendor/`.
>
> O `--symlink` faz virar um arquivo só onde link simbólico funciona. Não é o
> padrão porque link é decisão de deploy: quebra quando o deploy copia em vez de
> mover, exige cuidado no Windows, e uma atualização passa a mudar o que um site
> no ar está servindo em vez de esperar você publicar.

### Servidor e utilitários

```bash
./sfphp serve          # http://localhost:8000
./sfphp routes         # tabela de rotas registradas; --path= para layout fora do comum
./sfphp env:example    # cria .env a partir de .env-example
./sfphp css:build      # gera o SFCSS a partir do config; --config= --output=
./sfphp js:build       # minifica o SFJS
./sfphp build --phpx   # compila os componentes .phpx
./sfphp reset          # remove a aplicação de exemplo; --force pula a pergunta
./sfphp tinker         # REPL — só para desenvolvimento local
./sfphp list
./sfphp version
./sfphp help [comando]
```

`tinker` avalia entrada com `eval()`. É uma ferramenta de desenvolvimento
local; nunca exponha o CLI a entrada não confiável.

### Começar do zero

O pacote traz uma aplicação: uma home, controllers, componentes, um model, um
seeder. Ela existe para ser lida e rodada, e passa a atrapalhar no instante em
que você começa a escrever a sua.

```bash
./sfphp reset            # pergunta antes
./sfphp reset --force    # para script
```

Ele esvazia `app/components`, `app/controllers`, `app/models`, `app/Jobs`,
`app/resources/views`, `database/migrations`, `database/seeders` e
`database/factories`, e reescreve o arquivo de rotas sem rota nenhuma — do
contrário a aplicação subiria apontando para um controller que não existe mais.
As pastas ficam, porque é nelas que a próxima coisa vai.

**As duas migrations que o framework distribui são preservadas**: as tabelas de
usuários e de sessões, contra as quais o guard de autenticação e o driver de
sessão em banco foram escritos, e cuja falta um projeto notaria no primeiro
login, não aqui. Uma migration que você escreveu é sua, e vai junto com o resto
do que você escreveu. Um `.gitkeep` também fica — ele existe para segurar uma
pasta vazia, que é justamente o que sobra.

Antes de apagar qualquer coisa ele imprime o que vai apagar, com a contagem por
pasta, e espera você digitar a palavra `reset`. Sem terminal para responder —
um pipe, um job de CI — ele recusa em vez de seguir no silêncio. Não há desfazer
e nada vai para uma lixeira.

---

## SFCSS

Framework CSS utilitário. **Ele chega pronto** — o `composer require` entrega a
folha de estilo, e o `composer create-project` e o `sfphp serve` copiam
para `public/assets`, então usar é uma linha de HTML:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nada precisa ser gerado para usar o SFCSS. O gerador existe para **mudá-lo**, o
que está [mais abaixo](#mudar-o-sfcss).

| | |
|---|---|
| Classes no total | **2.339** |
| — utilitárias base | 1.211 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Classes de cor | 600 de paleta (20 famílias × 10 tons × `bg`/`text`/`border`) + 25 de tema |
| Tamanho | 112KB cru · 94KB minificado · **16,4KB gzipped** |
| Dependências | nenhuma |

### Mudar o SFCSS

As cores, a escala de espaçamento, a escala tipográfica e os breakpoints vêm de
um config, e ele está no seu projeto — `tools/css-builder/sfcss.config.json`, ao
lado do gerador que o lê. Edite e gere de novo:

```bash
# tools/css-builder/sfcss.config.json — paletas, espaçamento, breakpoints, fontes
./sfphp css:build
```

O `css:build` escreve em `public/assets/css`, que é o que o navegador lê. Um
config colocado ao lado do `composer.json` tem precedência sobre o de `tools/`,
para um projeto que prefira manter o design separado do gerador.

```bash
./sfphp css:build --config=design/sfcss.json --output=web/css
```

> **Uma folha de estilo que você gerou não é sobrescrita.** O `composer install`
> e o `serve` publicam os assets do framework, e quando um dos seus é diferente
> eles dizem que o mantiveram em vez de trocar. O `assets:publish --force` traz
> a versão do framework de volta.

Para mudar só uma cor, editar o config é mais do que você precisa: o tema lê
variáveis CSS, então sobrescrevê-las na sua própria folha basta.

```css
:root { --primary: #ff6600; }
```

### O que as telas do próprio framework usam

`code`, `pre` e `kbd` têm estilo, o `font-mono` e o `font-sans` definem a
família, e as superfícies neutras são variáveis em vez de hex fixo:

```css
--surface  --surface-raised  --surface-sunken
--surface-border  --surface-border-strong
--body-color  --body-color-muted  --code-color
```

A página escolhe o tema com `data-theme` no elemento raiz — `light` (o padrão),
`dark`, ou `auto` para seguir a configuração de quem lê. Só essas oito mudam.
Cores de marca e de paleta mantêm o significado nos dois temas; o que precisa
mudar é o papel em que elas se apoiam, e uma página que não diz nada continua
clara.

Esse conjunto existe porque a página de erro e a tela de dump são feitas com
SFCSS e o embutem — um framework que tem a própria folha de estilo não deveria
ter as próprias telas escritas numa segunda.

Referência completa: [SFCSS](SFCSS.md) e
[referência de utilitários](SFCSS_UTILITIES.md).

---

## SFJS

Biblioteca JavaScript sem dependências — 11KB crus, 8KB minificados, **2,4KB
gzipped**. Exposta como `window.sf`.

```html
<script src="/assets/js/sfjs.min.js"></script>
<script src="/assets/js/sfjs.js"></script>     <!-- legível, para depurar -->
```

```bash
./sfphp js:build         # regera o sfjs.min.js a partir do sfjs.js
./sfphp assets:publish   # copia os dois para public/assets
```

O minificador remove comentários e colapsa espaço em branco, e de propósito não
reescreve tokens — nada de encurtar nomes, remover ponto e vírgula ou juntar
instruções numa linha. É aí que um minificador muda o sentido de um programa, e
o quilobyte a mais não compensa manter um parser de JavaScript num framework sem
dependências. Um teste confere que os dois builds expõem a mesma API e que o
minificado ainda é analisável.

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

Um formulário funciona com qualquer um deles: `@hxGet` e `@hxDelete` mandam os
campos como query string, os outros mandam no corpo. O que volta é trocado como
markup, então quem responde a um desses é um fragmento — renderizado pelo mesmo
componente que o renderiza dentro da página inteira, e não JSON para o
JavaScript remontar em HTML. A página em `/phpx` faz exatamente isso, e por isso
não tem script próprio.

`@hxSwap` aceita `innerHTML` (padrão), `outerHTML`, `beforebegin`,
`afterbegin`, `beforeend` e `afterend`.

`@validate` roda no `blur` e aceita `required`, `email`, `number`, `url`,
`minLength:N`, `maxLength:N` e `pattern:regex`.

---

## Testes

Runner próprio, sem PHPUnit — coerente com zero dependências.

```bash
composer run lint        # php -l em todo o projeto
composer run test        # 152 casos unitários
composer run test:db     # integração contra MySQL/PostgreSQL reais
composer run test:all
composer run docs        # os três idiomas concordam, e todo link resolve
```

`tests/db.php` precisa de DSN nas variáveis de ambiente e pula com aviso
quando não há:

```bash
SFPHP_TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=sf' \
SFPHP_TEST_MYSQL_USER=root SFPHP_TEST_MYSQL_PASS=secret \
SFPHP_TEST_REDIS_HOST=127.0.0.1 \
  composer run test:db
```

Cobre o schema builder nos dois dialetos, a fila em banco, a trava de migration
e — quando um host Redis é informado — o cache, o handler de sessão sobre ele e
o driver de fila Redis. Esses três não tinham nenhum teste rodando contra
servidor até esta versão, e por isso o driver de fila perdia o id de todo job.

Tudo cujo trabalho acontece **no servidor** pertence aqui, e não à suíte
unitária, porque esse tipo de código lê certo e mesmo assim não faz nada: a
trava de migration é `GET_LOCK` e `pg_try_advisory_lock`, então só uma segunda
conexão real sendo recusada mostra que ela segura.

O CI roda dois jobs: `unit` numa matriz PHP 8.1–8.4 **sem `mbstring`**, o que
garante que o tratamento UTF-8 não depende da extensão; e `integration` com
MySQL 8, PostgreSQL 16 e Redis 7 como serviços.

`composer run docs` roda no job `unit` também. A documentação existe em três
idiomas, e prosa não dá para comparar mecanicamente — mas estrutura dá. Ele
afirma que as três versões têm as mesmas seções, subseções, tabelas e blocos de
código, na mesma ordem e com a mesma linguagem de cerca, e que todo link
relativo e toda âncora interna resolvem. Isso pega as duas coisas que realmente
dão errado quando três arquivos são editados à mão: uma seção acrescentada num
idioma e esquecida nos outros, e um link deixado apontando para um arquivo que
mudou de lugar.

---

## Limitações conhecidas

Aqui estão as ausências reais. Elas não são bugs — são coisas que o framework
não faz, e que você deve saber antes de escolhê-lo.

| Ausência | Impacto |
|---|---|
| **Recuperação de senha e dois fatores** | O login existe; esses fluxos não, e são da aplicação escrever. Ver [Autenticação](#autenticação) e [E-mail](#e-mail) |
| **Barramento de eventos entre processos** | O `Dispatcher` entrega no mesmo processo, de forma síncrona. Avisar outro serviço de que algo aconteceu é um job na fila ou um message broker, não isto |
| **ORM completo** | Existe uma camada de [Models](#models) com hidratação, tipos de atributo, relacionamentos (incluindo muitos-para-muitos) e `with()`. Não existe identity map, unit of work, proxy de lazy loading, relação polimórfica nem schema derivado da classe — e [ORM ou Query Builder?](#orm-ou-query-builder) explica o motivo de cada um |
| **Datas relativas** | "3 horas atrás" não existe: a frase é por idioma e pertence à aplicação. Data e número localizados existem, pelo `Time::localised()` e pelo `Time::number()`. Ver [Tempo e fusos horários](#tempo-e-fusos-horários) |
| **Backend de métricas** | O `Metrics` conta e cronometra dentro do processo e imprime o texto do Prometheus; levar isso a um coletor, e mantê-lo entre requisições, é do deploy. Ver [Health e métricas](#health-check-e-métricas) |
| **Cache de rotas em disco** | Um caminho estático é casado por comparação e não por `preg_match`, mas uma rota com parâmetro ainda custa um match, e nada é compilado de antemão. Adequado a centenas, não a milhares |
| **Um language server para `.phpx`** | O editor ganha coloração, Emmet e autocomplete pela configuração que o pacote distribui, mas um `.phpx` não é PHP válido, então o diagnóstico fica desligado — e desligado para todo `.php` ao lado dele. Quem pega erro de verdade é o `./sfphp build --phpx` e o `composer run lint`. Veja [Componentes e .phpx](#componentes-e-phpx) |
| **Revogar sessão de outro lugar** | Encerrar a sessão de outro dispositivo dá para construir sobre a tabela do driver `database`; nada vem pronto. Ver [Sessões](#sessões) |

O SFHT também não tem variáveis automáticas de laço (`$loop`) nem herança
parcial de bloco (`@parent`).

---

*Documentação revisada em 2026-09-22 contra o código em execução.*

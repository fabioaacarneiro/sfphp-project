# Componentes com .phpx

> **Leia em:** [English](../en/PHPX_COMPONENTS.md) · [Português](PHPX_COMPONENTS.md) · [Español](../es/PHPX_COMPONENTS.md)

Um componente `.phpx` é **uma função PHP cujo markup mora dentro dela**. Os
parâmetros dela são as props, o corpo é markup SFHT, e ela devolve um valor
`Sfht` — markup que o framework sabe que já é seguro imprimir. O `./sfphp build
--phpx` compila cada arquivo `.phpx` em PHP puro, e o front controller carrega o
resultado, então um componente é chamado como qualquer outra função.

Este guia percorre o mecanismo inteiro, usando os componentes que a aplicação de
exemplo distribui em `app/components/`. A referência do framework tem a versão
curta e a comparação com templates:
[Componentes e .phpx](DOCUMENTATION.md#componentes-e-phpx). A sintaxe do markup
dentro de um componente é a do SFHT, descrita em
[Views e SFHT](DOCUMENTATION.md#views-e-sfht).

---

## Um primeiro componente

`app/components/Card.phpx`, como é distribuído:

```php
<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A card, written the way templ writes them: a function whose markup lives
 * inside it, with the parameters as the props.
 */
function Card(string $title, string $body, string $colour = 'blue'): Sfht
{
    return Sfht(
        <div class="card mb-4 border-{{ $colour }}-500">
            <div class="card-header">
                <h3 class="m-0 text-{{ $colour }}-600">{{ $title }}</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">{{ $body }}</p>
            </div>
        </div>
    );
}
```

Tudo o que está fora de `Sfht( … )` é PHP comum: o namespace, os `use`, o
docblock, a assinatura tipada, o tipo de retorno. O `Sfht(` abre uma **região
de markup** e o `)` que o equilibra a fecha — encontrado lendo o markup como
markup, então o texto dentro dela pode conter qualquer caractere (veja
[Aspas e parênteses no texto](#aspas-e-parênteses-no-texto)). Não existe função
chamada `Sfht()` em tempo de execução — o build substitui a região inteira por
PHP que a renderiza.

Chamado a partir de PHP, ele devolve o card:

```php
use function SfphpProject\app\components\Card;

echo Card('Welcome', 'This is a card component', 'green');
```

```html
<div class="card mb-4 border-green-500">
            <div class="card-header">
                <h3 class="m-0 text-green-600">Welcome</h3>
            </div>
            <div class="card-body">
                <p class="text-muted">This is a card component</p>
            </div>
        </div>
```

O markup mantém a indentação que tinha no fonte.

---

## Dentro de uma região de markup

### O que funciona

A região é compilada pelo mesmo compilador SFHT que compila os templates
`.sfht`, então a mesma sintaxe funciona:

| Sintaxe | Dentro de `Sfht( … )` |
|---|---|
| `{{ $value }}` | Imprime o valor, **escapado** — a não ser que seja um `Sfht` (veja [Escape](#escape)) |
| `{!! $html !!}` | Imprime o valor como está, sem escape |
| `{{ $value \| upper }}` | Os filtros padrão (veja [Filtros](#filtros)) |
| `{{-- comment --}}` | Removido em tempo de compilação |
| `@if` · `@elseif` · `@else` · `@unless` | Condicionais |
| `@foreach` · `@forelse` / `@empty` · `@for` · `@while` | Laços |
| `@php … @endphp` | PHP cru |
| Qualquer expressão PHP em `{{ }}` | Chamadas de função, operadores, índices — e outros componentes |

`app/components/BulletList.phpx` usa um laço:

```php
<?php

namespace SfphpProject\app\components;

use SfphpProject\src\View\Sfht;

/**
 * A list, showing that the SFHT directives work inside the markup.
 *
 * Composing it with {{ BulletList($items) }} renders, because a component
 * returns Sfht; a string in the same position would be escaped. The type
 * decides, so nobody has to remember which values are safe.
 */
function BulletList(array $items): Sfht
{
    return Sfht(
        <ul class="list-unstyled">
            @foreach ($items as $item)
                <li class="py-1">{{ $item }}</li>
            @endforeach
        </ul>
    );
}
```

### Filtros

Os filtros padrão funcionam como em um template: `upper`, `lower`,
`capitalize`, `truncate`, `length`, `reverse`, `escape`, `json`, `format`,
`trim`, `abs`, `round` e `default`.

```php
return Sfht(
    <h3>{{ $title | upper }}</h3>
    <p>{{ $body | truncate(80) }}</p>
);
```

Um componente é uma chamada de função, sem motor de templates em volta, então
os filtros dele são aplicados diretamente em vez de pedidos a um motor. Duas
consequências: um filtro registrado em um motor com `addFilter()` não está
disponível em um componente — chame uma função no lugar — e um nome que não é
filtro padrão interrompe o build, com a linha: *"Unknown filter "shout" on line
12; a component can use upper, lower, …"*.

### O que não funciona

`@include`, `@includeWhen`, `@component`, `@extends`, `@block` e `@use`
precisam do motor de templates — uma pasta de partials, um layout, uma tabela de
blocos — e um componente não tem nenhum deles. Um componente compõe outros
componentes chamando-os, e os importa com `use function` no topo do `.phpx`.
Cada uma dessas diretivas interrompe o build com o arquivo e a linha em que está:

```
Error in app/components/Card.phpx: @include on line 9 cannot be used in a .phpx component: call the other component instead, as {{ Card(...) }}.
```

### O que o markup enxerga

A região enxerga as variáveis da função **como estão quando o `Sfht(` é
alcançado**: os parâmetros, e qualquer variável local atribuída antes dele. Nada
de mais longe — nem globais, nem variáveis de quem chamou. É isso que faz da
assinatura o contrato do componente.

Um componente pode preparar valores antes do markup. O
`postcode/explain/HowItWorks.phpx` do exemplo lê um arquivo para `$source` e
depois o imprime:

```php
function HowItWorks(): Sfht
{
    $source = (string) file_get_contents(Bootstrap::basePath('app/components/postcode/lookup/Field.phpx'));

    return Sfht(
        <section class="card">
            …
                <pre class="bg-light p-3 rounded-md overflow-auto"><code>{{ $source }}</code></pre>
            …
        </section>
    );
}
```

Como `{{ }}` escapa, o código-fonte do componente aparece na página como texto,
sem nenhuma entidade escrita à mão.

### Aspas e parênteses no texto

O build encontra o `)` que fecha uma região lendo a estrutura do próprio markup.
Comentários (`<!-- -->`, `{{-- --}}`), expressões `{{ }}` e `{!! !!}`,
argumentos de diretiva (`@if (…)`) e tags — com seus atributos entre aspas — são
pulados inteiros, e os elementos abertos são acompanhados. Texto dentro de um
elemento é texto, seja o que for que ele contenha:

```html
<p>Don't panic</p>                      <!-- um apóstrofo -->
<p>Step 1) open the lid</p>             <!-- um parêntese desequilibrado -->
<span title="a)b">{{ "x)" }}</span>      <!-- dentro de atributos e expressões -->
<script>if (a < b) { go('it\'s'); }</script>  <!-- script e style são texto -->
```

Só **fora de todo elemento** um parêntese conta, e ali ele precisa estar
equilibrado, como em PHP: o `)` que equilibra o `Sfht(` é o que fecha a região.
Elementos vazios (`<br>`, `<img>`, `<input>` …) e tags autofechadas não abrem
nada, e uma tag de fechamento também fecha qualquer elemento deixado aberto
dentro dela, do jeito que o HTML trata um `<li>` sem `</li>`.

Um elemento que é aberto e nunca fechado mantém a região aberta, e o build diz
qual: *"A markup region opened at line 4 is never closed; <div> on line 5 is
still open, so the ) after it was read as its text."*

---

## Escape

`{{ }}` escapa tudo **exceto** um valor que seja um `Sfht`:

```php
// SfphpProject\src\View\Compiler, aquilo em que todo {{ }} é compilado
public static function text(mixed $value): string
{
    if ($value instanceof Sfht) {
        return (string) $value;
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

Então uma única posição de expressão faz a coisa certa para os dois tipos de
valor. Dado um texto que veio de um visitante, o card o escapa:

```php
echo Card('Welcome', '<script>alert(1)</script>', 'green');
```

```html
<p class="text-muted">&lt;script&gt;alert(1)&lt;/script&gt;</p>
```

E dado outro componente, ele o imprime como markup — que é o que permite ao
`Address` compor o `Field` com `{{ }}` em vez de `{!! !!}`:

```php
{{ Field('Street', $street) }}    o campo renderiza
{{ $street }}                      o texto é escapado
```

A alternativa — componentes que devolvem strings, compostos com `{!! !!}` — pede
que todo autor lembre quais valores são confiáveis, e é assim que um dia
`{!! $comment !!}` vai parar em produção. Com `Sfht`, o **tipo** diz qual é
qual.

`Sfht` é uma pequena classe de valor: guarda uma string e é `Stringable`, então
funciona em qualquer lugar em que uma string funciona — `echo`, concatenação,
um cast `(string)`.

> **Embrulhar uma string em `Sfht` contorna o escape.** É para isso que ele
> serve, e é por isso que `new Sfht($whatever)` merece um segundo olhar na
> revisão. O build cria valores `Sfht` a partir de markup que um autor escreveu;
> um criado a partir de uma requisição é uma decisão de confiar na requisição.

`{!! !!}` imprime o valor sem escape nenhum, seja `Sfht` ou não. Use-o apenas
para HTML que o seu próprio código produziu.

---

## O build

Um arquivo `.phpx` não é PHP válido — o markup fica onde o PHP espera uma
expressão — então ele precisa ser compilado antes de poder rodar:

```bash
./sfphp build --phpx                       # todo .phpx sob app/components
./sfphp build --phpx --from=src/ui         # lê os componentes de outro lugar
./sfphp build --phpx --to=build/components # escreve o PHP compilado em outro lugar
```

```
  app/components/BulletList.phpx -> app/components/compiled/BulletList.php
  app/components/Card.phpx -> app/components/compiled/Card.php
  …
  app/components/postcode/PostcodePage.phpx -> app/components/compiled/postcode/PostcodePage.php
  …

Compiled 22 component(s).
```

`--from` tem como padrão `app/components` e `--to`, `app/components/compiled`;
os dois aceitam um caminho relativo ao projeto ou um absoluto. A saída **não**
vai para o lado do fonte: todo arquivo compilado é escrito sob o destino. O build
percorre a árvore do fonte recursivamente e a **espelha** sob o destino, então
`postcode/lookup/Field.phpx` vira `compiled/postcode/lookup/Field.php`. A pasta
de destino é ignorada quando fica dentro do fonte, então arquivos compilados
nunca são compilados de novo.

### O que o build produz

Cada região vira uma closure chamada na hora. Ela recebe as variáveis da função
por `get_defined_vars()`, faz buffer do markup e o devolve como um `Sfht`. Todo
`{{ }}` vira uma chamada a `Compiler::text()`, e as instruções são separadas por
espaços em vez de quebras de linha, então cada linha de markup fica na linha em
que foi escrita. Este é o `compiled/postcode/lookup/Field.php`, gerado a partir
do componente mostrado em [Compor componentes](#compor-componentes):

```php
function Field(string $label, string $value): Sfht
{
    return 
(static function (array $__props): \SfphpProject\src\View\Sfht { extract($__props); ob_start(); echo '<div class="py-1">
            <span class="text-xs text-muted d-block">'; echo \SfphpProject\src\View\Compiler::text(($label)); echo '</span>
            <span class="font-semibold">'; echo \SfphpProject\src\View\Compiler::text(($value)); echo '</span>
        </div>';  return new \SfphpProject\src\View\Sfht((string) ob_get_clean()); })(get_defined_vars())
;
}
```

Tudo o que está fora da região — o namespace, os imports, o docblock — é
copiado sem alteração.

### Erros aparecem no build

Uma região que não compila — um `@if` não fechado, uma diretiva que um
componente não pode usar, um filtro desconhecido — interrompe o build antes de o
arquivo ser escrito, com o arquivo e a linha do `.phpx`:

```
Error in app/components/postcode/lookup/Field.phpx: Unclosed @if opened on line 19.
```

Depois de escrever cada arquivo, o build roda `php -l` nele. Um erro de sintaxe
interrompe o build com o nome do componente e a mensagem do PHP sobre o arquivo
compilado:

```
Error in app/components/postcode/lookup/Field.phpx:
PHP Parse error:  syntax error, unexpected token ";" in /path/to/project/app/components/compiled/postcode/lookup/Field.php on line 15
Errors parsing /path/to/project/app/components/compiled/postcode/lookup/Field.php
```

O arquivo compilado mantém **cada linha onde o autor a escreveu** — antes de uma
região, dentro dela e depois dela — então a linha que o PHP informa é a linha a
abrir no `.phpx`. O mesmo vale para um erro em tempo de execução em um stack
trace.

Uma região que nunca é fechada interrompe o build antes de qualquer coisa ser
escrita: *"Error in app/components/Card.phpx: A markup region opened at line 4
is never closed."*

### Recompilar

Rode o build de novo depois de toda mudança em um `.phpx` — nada recompila sob
demanda, ao contrário dos templates `.sfht`. O build sobrescreve os arquivos que
produz e nunca apaga nenhum: quando você renomear ou apagar um componente, apague
também o arquivo compilado dele. Um arquivo velho continua sendo carregado pelo
front controller, e dois arquivos que definem a mesma função no mesmo namespace
derrubam toda requisição com *"Cannot redeclare function"*.

A aplicação de exemplo mantém os arquivos compilados no repositório, então roda
logo depois da instalação, sem build.

---

## Carregar os componentes

O PHP autoloada **classes**, não funções. O Composer não consegue encontrar
`SfphpProject\app\components\Card()` sob demanda do jeito que encontra um
controller, então todo componente compilado precisa ser incluído com `require`
antes de ser chamado. O `public/index.php` faz isso uma vez, antes de as rotas
serem carregadas:

```php
$__compiled = __DIR__ . "/../app/components/compiled";

if (is_dir($__compiled)) {
    $__components = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($__compiled, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($__components as $__component) {
        if ($__component->getExtension() === "php") {
            require_once $__component->getPathname();
        }
    }
}
```

Duas consequências:

- O front controller carrega apenas `app/components/compiled`. Componentes
  compilados com `--to` em outro lugar precisam ser incluídos pelo seu próprio
  código, do mesmo jeito.
- Um script que não passa pelo `public/index.php` — um comando de console, um
  teste, um worker de fila — precisa incluir os componentes compilados que usa.
  Chamar um que não foi carregado falha com *"Call to undefined function"*.

---

## Um componente por arquivo

Cada componente mora em um arquivo próprio, **com o nome da função** —
`Field()` em `Field.phpx` — e os componentes de uma página moram em uma pasta
própria. A aplicação de exemplo:

```
app/components/
├── Card.phpx
├── BulletList.phpx
└── postcode/
    ├── PostcodePage.phpx          a página, composta das partes abaixo
    ├── layout/
    │   ├── PageHeader.phpx
    │   └── PageFooter.phpx
    ├── lookup/
    │   ├── PostcodeLookup.phpx    o formulário, e onde a resposta dele cai
    │   ├── Address.phpx           um endereço, feito de campos
    │   ├── Field.phpx             um rótulo e um valor
    │   └── Notice.phpx            a mensagem mostrada quando não há endereço
    └── explain/
        └── HowItWorks.phpx        a explicação abaixo do formulário
```

O namespace acompanha a pasta: `postcode/lookup/Field.phpx` declara
`namespace SfphpProject\app\components\postcode\lookup;`. O build não impõe
isso — o namespace de uma função é o que o arquivo declarar — mas seguir a regra
faz o nome de um componente dizer onde encontrá-lo, e duas páginas podem ter
cada uma o seu `PageHeader()` sem conflito.

Declare sempre um namespace. Nomes de função em PHP não diferenciam maiúsculas
de minúsculas e dividem um único espaço com os helpers globais do framework: um
componente chamado `E()` no namespace global colide com o helper de escape
`e()`.

---

## Compor componentes

Componentes no **mesmo namespace** chamam uns aos outros pelo nome, sem import.
`Address.phpx` e `Field.phpx` ficam na mesma pasta:

```php
<?php

namespace SfphpProject\app\components\postcode\lookup;

use SfphpProject\src\View\Sfht;

/**
 * One field of an address.
 *
 * Composed by Address, which the controller renders for both answers it gives
 * — the fragment SFJS swaps in and the whole page a browser without JavaScript
 * receives. Written once, so the two can never disagree.
 */
function Field(string $label, string $value): Sfht
{
    return Sfht(
        <div class="py-1">
            <span class="text-xs text-muted d-block">{{ $label }}</span>
            <span class="font-semibold">{{ $value }}</span>
        </div>
    );
}
```

```php
<?php

namespace SfphpProject\app\components\postcode\lookup;

use SfphpProject\src\View\Sfht;

/**
 * An address, composed of the fields beside it.
 *
 * This is the fragment the lookup swaps in, and it is also part of the whole
 * page when the browser asked for one — the same component either way, which
 * is what stops a page and its updates from drifting apart.
 */
function Address(string $street, string $district, string $city, string $state): Sfht
{
    return Sfht(
        <div class="card">
            <div class="card-body">
                {{ Field('Street', $street) }}
                {{ Field('District', $district) }}
                {{ Field('City', $city) }}
                {{ Field('State', $state) }}
            </div>
        </div>
    );
}
```

Atravessar uma pasta é um `use function`, igual a qualquer função com namespace
em PHP. O `PostcodePage.phpx` importa as partes dele de três pastas:

```php
<?php

namespace SfphpProject\app\components\postcode;

use SfphpProject\src\View\Sfht;

use function SfphpProject\app\components\postcode\explain\HowItWorks;
use function SfphpProject\app\components\postcode\layout\PageFooter;
use function SfphpProject\app\components\postcode\layout\PageHeader;
use function SfphpProject\app\components\postcode\lookup\PostcodeLookup;

function PostcodePage(?Sfht $result = null): Sfht
{
    return Sfht(
        <!DOCTYPE html>
        <html lang="{{ lang_tag() }}">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>.phpx + SFCSS + SFJS — SFPHP</title>
            <link rel="stylesheet" href="{{ asset('css/sfcss.min.css') }}">
        </head>
        <body class="bg-light">
            {{ PageHeader() }}

            <main class="container py-12 max-w-2xl mx-auto px-4">
                {{ PostcodeLookup($result) }}
                {{ HowItWorks() }}
            </main>

            {{ PageFooter() }}

            <script src="{{ asset('js/sfjs.min.js') }}"></script>
        </body>
        </html>
    );
}
```

Uma página inteira é um componente como qualquer outro, e helpers globais como
`lang_tag()` e `asset()` são chamados diretamente.

### Markup como prop

Um componente pode receber a saída de outro componente como parâmetro, tipado
`Sfht`. O `PostcodeLookup` recebe o conteúdo da área de resultado, e `null`
quando ainda não há nada:

```php
function PostcodeLookup(?Sfht $result = null): Sfht
{
    return Sfht(
        <section class="card mb-8">
            …
                <form method="get" action="/phpx/postcode" @get="/phpx/postcode" @target="#result">
                    …
                </form>

                <div id="result" class="mt-4">{{ $result }}</div>
            …
        </section>
    );
}
```

`{{ $result }}` imprime o markup quando é um `Sfht` e nada quando é `null`.
Tipar o parâmetro como `Sfht` em vez de `string` é o que impede quem chama de
passar texto cru ali por engano — uma string seria um erro de tipo, e não uma
string sem escape na página.

---

## Usar componentes

### A partir de um controller

Um componente devolve um `Sfht`, e uma action de controller devolve um
`Response`. O `Response::phpx()` transforma um no outro — o
`app/controllers/PhpxController.php` renderiza a página com ele:

```php
use function SfphpProject\app\components\postcode\PostcodePage;

public function index(Request $request): Response
{
    return Response::phpx(PostcodePage());
}
```

O `Response::phpx()` recebe o que o componente **devolve** — a chamada, não o
nome da função. Devolver o próprio `Sfht` de uma action é erro (*"… must return
SfphpProject\src\Http\Response, a string or an array; got
SfphpProject\src\View\Sfht"*): o `phpx()` é a única forma de responder com um
componente, como o `sfht()` é para um template.

**Os dados chegam ao componente como argumentos.** Os parâmetros são as suas
props, então o controller busca o que a página precisa e passa na chamada:

```php
use function SfphpProject\app\components\posts\PostPage;

public function show(Request $request, string $id): Response
{
    $post = Post::query()->find($id);

    return Response::phpx(PostPage($post, $request->user()));
}
```

```php
function PostPage(Post $post, ?User $user = null): Sfht
{
    return Sfht(
        <article>
            <h1>{{ $post->title }}</h1>
            @if ($user)
                <p>Signed in as {{ $user->name }}</p>
            @endif
        </article>
    );
}
```

Qualquer valor PHP serve — strings, números, arrays, models, coleções — com
valores padrão e argumentos nomeados (`PostPage(post: $post)`). A assinatura diz
exatamente do que o componente precisa, então o editor completa a chamada e o
PHP recusa um tipo errado. O `{{ }}` escapa o que imprime, então um título vindo
do banco não injeta marcação. Um componente repassa dados aos que o compõem do
mesmo jeito — o `PostPage` chamando `Comment($comment)`.

Essa é a diferença para um template: `Response::sfht('post', ['post' =>
$post])` entrega os dados como um array de nomes, enquanto um componente os
recebe como argumentos tipados que o editor consegue conferir.

> **Migrando da 0.30.** O `Response::view()` agora é `Response::sfht()`, e um
> componente é respondido com `Response::phpx(PostcodePage())` em vez de
> `Response::html((string) PostcodePage())`. Uma região de marcação abre com
> `Sfht(` — o nome do tipo que ela devolve — em vez de `sfht(`; o build recusa a
> grafia antiga com a linha e a correção.

A action de consulta responde com um **fragmento** quando o SFJS pediu um, e com
a página inteira quando um navegador enviou o formulário sem JavaScript:

```php
use function SfphpProject\app\components\postcode\lookup\Address;
use function SfphpProject\app\components\postcode\lookup\Notice;
use function SfphpProject\app\components\postcode\PostcodePage;

public function postcode(Request $request): Response
{
    $digits = preg_replace('/\D/', '', (string) $request->query('postcode', '')) ?? '';

    if (strlen($digits) !== 8) {
        return $this->answer($request, Notice('A Brazilian postcode has eight digits.'));
    }

    // … consulta o CEP e responde com Address(…) ou com um Notice
}

private function answer(Request $request, Sfht $result): Response
{
    return Response::fragment(
        $request,
        $result,
        page: static fn (Sfht $inner): Sfht => PostcodePage($inner)
    );
}
```

O `Response::fragment()` envia o `Address` sozinho para o SFJS, que o troca
dentro de `#result`, e o `PostcodePage($inner)` — o mesmo `Address` dentro da
página inteira — para um navegador sem JavaScript. Um só componente renderiza as
duas respostas, então elas não têm como divergir. Veja
[Responder com um fragmento](DOCUMENTATION.md#responder-com-um-fragmento).

As rotas, em `app/routes/web.php`:

```php
Router::get('/phpx', [PhpxController::class, 'index'])->name('phpx');
Router::get('/phpx/postcode', [PhpxController::class, 'postcode'])->name('phpx.postcode');
```

### A partir de um template SFHT

Um template compilado roda no namespace global, então o nome curto de um
componente não é encontrado ali sem um import: `{{ Card('Hello', $body) }}`
sozinho falha com *"Call to undefined function Card()"*. Importe-o com `@use`, o
próprio `use` do PHP escrito como diretiva:

```sfht
@use(function SfphpProject\app\components\Card)

{{ Card('Hello', $body) }}
```

O `@use` aceita o que o `use` do PHP aceita — `function Name`, `const NAME` ou
uma classe, cada um com um `as Alias` opcional — entre aspas ou não. O
compilador move todo import para o topo do arquivo compilado, o único lugar em
que o PHP aceita um, então o `@use` pode ser escrito em qualquer ponto do
template, inclusive dentro de `@if` ou `@block`. Escrito sem parênteses, `@use`
é texto, então um endereço como `someone@use.example` continua chegando à
página. O `@use` é para templates; um `.phpx` importa com `use function` no topo
do arquivo.

```sfht
{{ \SfphpProject\app\components\Card('Hello', $body) }}
```

O nome totalmente qualificado dispensa import.

```php
// No controller
return Response::sfht('home', ['card' => Card('Hello', $body)]);
```

```sfht
{{-- No template --}}
{{ $card }}
```

O componente é renderizado no controller e passado como dado. Ele é impresso
como markup, porque é um `Sfht`; uma string passada ao lado dele continua sendo
escapada.

Em todos os casos os parâmetros do próprio componente são escapados dentro
dele, então `$body` está seguro seja qual for o caminho pelo qual se chega ao
card.

### A partir de PHP puro

Em qualquer outro lugar — o corpo de um e-mail, um comando de console, um teste —
um componente é uma chamada de função, e o resultado é usado como string:

```php
use function SfphpProject\app\components\BulletList;

$html = (string) BulletList(['One', 'Two & three']);
```

```html
<ul class="list-unstyled">
            
                <li class="py-1">One</li>
            
                <li class="py-1">Two &amp; three</li>
            
        </ul>
```

Lembre que fora do `public/index.php` o arquivo compilado precisa ser incluído
antes (veja [Carregar os componentes](#carregar-os-componentes)).

---

## A página de exemplo

```bash
./sfphp serve
# abra http://localhost:8000/phpx
```

`/phpx` é o `PostcodePage()`: um cabeçalho, um formulário para consultar um CEP
brasileiro, a explicação do `HowItWorks()` e um rodapé. `/phpx/postcode` é a
consulta que o formulário envia, não uma página para abrir — sem um `postcode`
ela responde *"A Brazilian postcode has eight digits."*

O formulário leva o `@get` e o `@target="#result"` do SFJS: o SFJS o envia,
recebe o fragmento `Address` e o troca no lugar, sem nenhum JavaScript escrito
para a página. A consulta chama o serviço público ViaCEP pelo cliente HTTP do
framework com timeout de cinco segundos, e responde com um `Notice` quando o
serviço falha ou não conhece endereço para o CEP.

---

## .phpx ou .sfht

Use `.sfht` para **páginas**: layouts, blocos, qualquer coisa que um designer
possa abrir. Use `.phpx` para **pedaços**: um card, um campo, uma linha de
tabela — qualquer coisa que receba argumentos e apareça mais de uma vez. Um
partial vê o que estiver em escopo onde foi incluído, então o que ele precisa se
descobre lendo-o; os parâmetros de um componente são as props dele, então o que
ele precisa é a assinatura. A referência compara os dois em
[Componentes e .phpx](DOCUMENTATION.md#componentes-e-phpx).

| | `.sfht` | `.phpx` |
|---|---|---|
| Passo de build | Nenhum — compila sob demanda | `./sfphp build --phpx`, depois de toda mudança |
| Composição | `@include`, `@extends`, `@block` | Chamar a função |
| Filtros (`\|`) | Sim, inclusive os adicionados com `addFilter()` | Os padrão |
| Imports | `@use(function …)` | `use function …` no topo do arquivo |
| Recebe | O que estiver em escopo, mais o que for passado | Os parâmetros dele, e nada além |
| Carregado | Pelo motor de views, pelo nome | Incluído pelo front controller |

---

## Suporte do editor

Um arquivo `.phpx` é PHP com markup onde o PHP não o espera. O projeto
distribui configurações para EditorConfig, VS Code (com Intelephense) e Zed que
associam `.phpx` ao PHP, ligam o Emmet e desligam os diagnósticos que o markup
dispararia — veja [Suporte do editor](DOCUMENTATION.md#suporte-do-editor).
O `./sfphp build --phpx` e o `composer run lint` são o que pegam os erros de
verdade.

# Componentes con .phpx

> **Lee en:** [English](../en/PHPX_COMPONENTS.md) · [Português](../pt-BR/PHPX_COMPONENTS.md) · [Español](PHPX_COMPONENTS.md)

Un componente `.phpx` es **una función PHP cuyo marcado vive dentro de ella**.
Sus parámetros son sus props, su cuerpo es marcado SFHT, y devuelve un valor
`Sfht` — marcado que el framework sabe que ya es seguro imprimir. `./sfphp build
--phpx` compila cada archivo `.phpx` a PHP plano, y el controlador frontal carga
el resultado, así que un componente se llama como cualquier otra función.

Esta guía recorre el mecanismo completo, usando los componentes que la
aplicación de ejemplo incluye en `app/components/`. La referencia del framework
tiene la versión corta y la comparación con las plantillas:
[Componentes y .phpx](DOCUMENTATION.md#componentes-y-phpx). La sintaxis del
marcado dentro de un componente es la de SFHT, descrita en
[Vistas y SFHT](DOCUMENTATION.md#vistas-y-sfht).

---

## Un primer componente

`app/components/Card.phpx`, tal como se distribuye:

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

Todo lo que está fuera de `Sfht( … )` es PHP corriente: el namespace, las
sentencias `use`, el docblock, la firma tipada, el tipo de retorno. `Sfht(` abre
una **región de marcado** y el `)` que lo equilibra la cierra — se encuentra
leyendo el marcado como marcado, así que el texto de dentro puede contener
cualquier carácter (consulta
[Comillas y paréntesis en el texto](#comillas-y-paréntesis-en-el-texto)). No
existe ninguna función llamada `Sfht()` en tiempo de ejecución — la compilación
sustituye la región entera por PHP que la renderiza.

Llamado desde PHP, devuelve la tarjeta:

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

El marcado conserva la sangría que tenía en el fuente.

---

## Dentro de una región de marcado

### Qué funciona

La región la compila el mismo compilador SFHT que compila las plantillas
`.sfht`, así que funciona la misma sintaxis:

| Sintaxis | Dentro de `Sfht( … )` |
|---|---|
| `{{ $value }}` | Imprime el valor, **escapado** — salvo que sea un `Sfht` (consulta [Escapado](#escapado)) |
| `{!! $html !!}` | Imprime el valor tal cual, sin escapar |
| `{{ $value \| upper }}` | Los filtros estándar (consulta [Filtros](#filtros)) |
| `{{-- comment --}}` | Se elimina al compilar |
| `@if` · `@elseif` · `@else` · `@unless` | Condicionales |
| `@foreach` · `@forelse` / `@empty` · `@for` · `@while` | Bucles |
| `@php … @endphp` | PHP crudo |
| Cualquier expresión PHP en `{{ }}` | Llamadas a funciones, operadores, índices — y otros componentes |

`app/components/BulletList.phpx` usa un bucle:

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

Los filtros estándar funcionan igual que en una plantilla: `upper`, `lower`,
`capitalize`, `truncate`, `length`, `reverse`, `escape`, `json`, `format`,
`trim`, `abs`, `round` y `default`.

```php
return Sfht(
    <h3>{{ $title | upper }}</h3>
    <p>{{ $body | truncate(80) }}</p>
);
```

Un componente es una llamada a función, sin motor de plantillas alrededor, así
que sus filtros se aplican directamente en lugar de pedírselos a un motor. Dos
consecuencias: un filtro registrado en un motor con `addFilter()` no está
disponible en un componente — llama a una función en su lugar — y un nombre que
no es un filtro estándar detiene la compilación, con su línea: *"Unknown filter
"shout" on line 12; a component can use upper, lower, …"*.

### Qué no funciona

`@include`, `@includeWhen`, `@component`, `@extends`, `@block` y `@use`
necesitan el motor de plantillas — una carpeta de parciales, un layout, una
tabla de bloques — y un componente no tiene nada de eso. Un componente compone
otros componentes llamándolos, y los importa con `use function` al principio del
`.phpx`. Cada una de esas directivas detiene la compilación con el archivo y la
línea en que está:

```
Error in app/components/Card.phpx: @include on line 9 cannot be used in a .phpx component: call the other component instead, as {{ Card(...) }}.
```

### Qué ve el marcado

La región ve las variables de la función **tal como están cuando se llega a
`Sfht(`**: sus parámetros, y cualquier variable local asignada antes. Nada de
más afuera — ni globales, ni variables de quien llama. Eso es lo que hace de la
firma el contrato del componente.

Un componente puede preparar valores antes de su marcado. El
`postcode/explain/HowItWorks.phpx` del ejemplo lee un archivo en `$source` y
luego lo imprime:

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

Como `{{ }}` escapa, el código fuente del componente aparece en la página como
texto, sin ninguna entidad escrita a mano.

### Comillas y paréntesis en el texto

La compilación encuentra el `)` que cierra una región leyendo la estructura del
propio marcado. Los comentarios (`<!-- -->`, `{{-- --}}`), las expresiones
`{{ }}` y `{!! !!}`, los argumentos de directiva (`@if (…)`) y las etiquetas —
con sus atributos entre comillas — se saltan enteros, y se lleva la cuenta de
los elementos abiertos. El texto dentro de un elemento es texto, contenga lo que
contenga:

```html
<p>Don't panic</p>                      <!-- un apóstrofo -->
<p>Step 1) open the lid</p>             <!-- un paréntesis desequilibrado -->
<span title="a)b">{{ "x)" }}</span>      <!-- dentro de atributos y expresiones -->
<script>if (a < b) { go('it\'s'); }</script>  <!-- script y style son texto -->
```

Solo **fuera de todo elemento** cuenta un paréntesis, y ahí tiene que estar
equilibrado, como en PHP: el `)` que equilibra `Sfht(` es el que cierra la
región. Los elementos vacíos (`<br>`, `<img>`, `<input>` …) y las etiquetas
autocerradas no abren nada, y una etiqueta de cierre también cierra cualquier
elemento que haya quedado abierto dentro de ella, tal como HTML trata un `<li>`
sin `</li>`.

Un elemento que se abre y nunca se cierra mantiene la región abierta, y la
compilación dice cuál: *"A markup region opened at line 4 is never closed; <div>
on line 5 is still open, so the ) after it was read as its text."*

---

## Escapado

`{{ }}` escapa todo **excepto** un valor que sea un `Sfht`:

```php
// SfphpProject\src\View\Compiler, aquello en lo que se compila cada {{ }}
public static function text(mixed $value): string
{
    if ($value instanceof Sfht) {
        return (string) $value;
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

Así, una misma posición de expresión hace lo correcto con los dos tipos de
valor. Si recibe texto que vino de un visitante, la tarjeta lo escapa:

```php
echo Card('Welcome', '<script>alert(1)</script>', 'green');
```

```html
<p class="text-muted">&lt;script&gt;alert(1)&lt;/script&gt;</p>
```

Y si recibe otro componente, lo imprime como marcado — que es lo que permite a
`Address` componer `Field` con `{{ }}` en lugar de con `{!! !!}`:

```php
{{ Field('Street', $street) }}    el campo se renderiza
{{ $street }}                      el texto se escapa
```

La alternativa — componentes que devuelven cadenas, compuestos con `{!! !!}` —
exige que cada autor recuerde qué valores son de confianza, y así es como algún
día `{!! $comment !!}` acaba en producción. Con `Sfht`, el **tipo** dice cuál es
cuál.

`Sfht` es una pequeña clase de valor: guarda una cadena y es `Stringable`, así
que funciona en cualquier sitio donde funciona una cadena — `echo`,
concatenación, un cast `(string)`.

> **Envolver una cadena en `Sfht` se salta el escapado.** Para eso existe, y por
> eso `new Sfht($whatever)` merece una segunda mirada en la revisión. La
> compilación crea valores `Sfht` a partir de marcado que escribió un autor; uno
> creado a partir de una petición es una decisión de confiar en la petición.

`{!! !!}` imprime su valor sin escapar nada, sea `Sfht` o no. Úsalo solo para
HTML que haya producido tu propio código.

---

## Compilación

Un archivo `.phpx` no es PHP válido — el marcado está donde PHP espera una
expresión — así que hay que compilarlo antes de que pueda ejecutarse:

```bash
./sfphp build --phpx                       # todo .phpx bajo app/components
./sfphp build --phpx --from=src/ui         # lee los componentes de otro sitio
./sfphp build --phpx --to=build/components # escribe el PHP compilado en otro sitio
```

```
  app/components/BulletList.phpx -> app/components/compiled/BulletList.php
  app/components/Card.phpx -> app/components/compiled/Card.php
  …
  app/components/postcode/PostcodePage.phpx -> app/components/compiled/postcode/PostcodePage.php
  …

Compiled 22 component(s).
```

`--from` vale por defecto `app/components` y `--to`, `app/components/compiled`;
los dos aceptan una ruta relativa al proyecto o una absoluta. La salida **no**
va junto al fuente: cada archivo compilado se escribe bajo el destino. La
compilación recorre el árbol de fuentes de forma recursiva y lo **replica** bajo
el destino, así que `postcode/lookup/Field.phpx` pasa a ser
`compiled/postcode/lookup/Field.php`. La carpeta de destino se omite cuando está
dentro del fuente, así que los archivos compilados nunca se vuelven a compilar.

### Qué produce la compilación

Cada región se convierte en un closure que se llama en el acto. Recibe las
variables de la función mediante `get_defined_vars()`, almacena el marcado en un
búfer y lo devuelve como un `Sfht`. Cada `{{ }}` se convierte en una llamada a
`Compiler::text()`, y las sentencias se separan con espacios en lugar de saltos
de línea, así que cada línea de marcado se queda en la línea en que se escribió.
Este es `compiled/postcode/lookup/Field.php`, generado a partir del componente
que se muestra en [Componer componentes](#componer-componentes):

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

Todo lo que está fuera de la región — el namespace, los imports, el docblock —
se copia sin cambios.

### Los errores aparecen al compilar

Una región que no compila — un `@if` sin cerrar, una directiva que un componente
no puede usar, un filtro desconocido — detiene la compilación antes de escribir
el archivo, con el archivo y la línea del `.phpx`:

```
Error in app/components/postcode/lookup/Field.phpx: Unclosed @if opened on line 19.
```

Tras escribir cada archivo, la compilación ejecuta `php -l` sobre él. Un error
de sintaxis detiene la compilación con el nombre del componente y el mensaje de
PHP sobre el archivo compilado:

```
Error in app/components/postcode/lookup/Field.phpx:
PHP Parse error:  syntax error, unexpected token ";" in /path/to/project/app/components/compiled/postcode/lookup/Field.php on line 15
Errors parsing /path/to/project/app/components/compiled/postcode/lookup/Field.php
```

El archivo compilado conserva **cada línea donde la escribió el autor** — antes
de una región, dentro de ella y después de ella — así que la línea que informa
PHP es la línea que hay que abrir en el `.phpx`. Lo mismo vale para un error en
tiempo de ejecución en una traza de pila.

Una región que nunca se cierra detiene la compilación antes de escribir nada:
*"Error in app/components/Card.phpx: A markup region opened at line 4 is never
closed."*

### Recompilar

Ejecuta la compilación de nuevo después de cada cambio en un `.phpx` — nada se
recompila bajo demanda, a diferencia de las plantillas `.sfht`. La compilación
sobrescribe los archivos que produce y nunca borra ninguno: cuando renombres o
borres un componente, borra también su archivo compilado. Un archivo obsoleto
lo sigue cargando el controlador frontal, y dos archivos que definen la misma
función en el mismo namespace detienen todas las peticiones con *"Cannot
redeclare function"*.

La aplicación de ejemplo guarda sus archivos compilados en el repositorio, así
que funciona nada más instalarla, sin compilar.

---

## Cargar los componentes

PHP carga automáticamente **clases**, no funciones. Composer no puede encontrar
`SfphpProject\app\components\Card()` bajo demanda como encuentra un controlador,
así que cada componente compilado tiene que incluirse con `require` antes de
llamarlo. `public/index.php` lo hace una vez, antes de cargar las rutas:

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

Dos consecuencias:

- El controlador frontal carga solo `app/components/compiled`. Los componentes
  compilados con `--to` en otro sitio tiene que incluirlos tu propio código, de
  la misma manera.
- Un script que no pasa por `public/index.php` — un comando de consola, una
  prueba, un worker de cola — tiene que incluir los componentes compilados que
  usa. Llamar a uno que no se cargó falla con *"Call to undefined function"*.

---

## Un componente por archivo

Cada componente vive en un archivo propio, **con el nombre de la función** —
`Field()` en `Field.phpx` — y los componentes de una página viven en una carpeta
propia. La aplicación de ejemplo:

```
app/components/
├── Card.phpx
├── BulletList.phpx
└── postcode/
    ├── PostcodePage.phpx          la página, compuesta de las partes de abajo
    ├── layout/
    │   ├── PageHeader.phpx
    │   └── PageFooter.phpx
    ├── lookup/
    │   ├── PostcodeLookup.phpx    el formulario, y donde cae su respuesta
    │   ├── Address.phpx           una dirección, hecha de campos
    │   ├── Field.phpx             una etiqueta y un valor
    │   └── Notice.phpx            el mensaje que se muestra cuando no hay dirección
    └── explain/
        └── HowItWorks.phpx        la explicación debajo del formulario
```

El namespace sigue a la carpeta: `postcode/lookup/Field.phpx` declara
`namespace SfphpProject\app\components\postcode\lookup;`. La compilación no lo
impone — el namespace de una función es el que declare el archivo — pero
seguirlo hace que el nombre de un componente te diga dónde encontrarlo, y que
dos páginas puedan tener cada una su `PageHeader()` sin conflicto.

Declara siempre un namespace. En PHP los nombres de función no distinguen
mayúsculas de minúsculas y comparten un único espacio con los helpers globales
del framework: un componente llamado `E()` en el namespace global choca con el
helper de escapado `e()`.

---

## Componer componentes

Los componentes del **mismo namespace** se llaman entre sí por su nombre, sin
import. `Address.phpx` y `Field.phpx` están en la misma carpeta:

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

Cruzar una carpeta es un `use function`, igual que para cualquier función con
namespace en PHP. `PostcodePage.phpx` importa sus partes de tres carpetas:

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

Una página entera es un componente como cualquier otro, y los helpers globales
como `lang_tag()` y `asset()` se llaman directamente.

### Marcado como prop

Un componente puede recibir la salida de otro componente como parámetro, tipado
`Sfht`. `PostcodeLookup` recibe el contenido del área de resultado, y `null`
cuando todavía no hay nada:

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

`{{ $result }}` imprime el marcado cuando es un `Sfht` y nada cuando es `null`.
Tipar el parámetro como `Sfht` en lugar de `string` es lo que impide que quien
llama pase ahí texto crudo por error — una cadena sería un error de tipo, no una
cadena sin escapar en la página.

---

## Usar componentes

### Desde un controlador

Un componente devuelve un `Sfht`, y una acción de controlador devuelve un
`Response`. `Response::phpx()` convierte uno en otro —
`app/controllers/PhpxController.php` renderiza la página con él:

```php
use function SfphpProject\app\components\postcode\PostcodePage;

public function index(Request $request): Response
{
    return Response::phpx(PostcodePage());
}
```

`Response::phpx()` recibe lo que el componente **devuelve** — la llamada, no el
nombre de la función. Devolver el propio `Sfht` desde una acción es un error
(*"… must return SfphpProject\src\Http\Response, a string or an array; got
SfphpProject\src\View\Sfht"*): `phpx()` es la única forma de responder con un
componente, como `sfht()` lo es para una plantilla.

**Los datos llegan al componente como argumentos.** Sus parámetros son sus
props, así que el controlador busca lo que la página necesita y lo pasa en la
llamada:

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

Sirve cualquier valor PHP — cadenas, números, arrays, modelos, colecciones — con
valores por defecto y argumentos con nombre (`PostPage(post: $post)`). La firma
dice exactamente qué necesita el componente, así que el editor completa la
llamada y PHP rechaza un tipo equivocado. `{{ }}` escapa lo que imprime, así que
un título que viene de la base de datos no inyecta marcado. Un componente pasa
datos a los que lo componen de la misma forma — `PostPage` llamando a
`Comment($comment)`.

Esa es la diferencia con una plantilla: `Response::sfht('post', ['post' =>
$post])` entrega los datos como un array de nombres, mientras que un componente
los recibe como argumentos tipados que el editor puede comprobar.

> **Actualizar desde 0.30.** `Response::view()` ahora es `Response::sfht()`, y a
> un componente se responde con `Response::phpx(PostcodePage())` en lugar de
> `Response::html((string) PostcodePage())`. Una región de marcado se abre con
> `Sfht(` — el nombre del tipo que devuelve — en lugar de `sfht(`; la
> compilación rechaza la grafía antigua con la línea y la corrección.

La acción de búsqueda responde con un **fragmento** cuando SFJS lo pidió, y con
la página entera cuando un navegador envió el formulario sin JavaScript:

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

    // … busca el código postal y responde con Address(…) o con un Notice
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

`Response::fragment()` envía el `Address` solo a SFJS, que lo intercambia dentro
de `#result`, y `PostcodePage($inner)` — el mismo `Address` dentro de la página
entera — a un navegador sin JavaScript. Un único componente renderiza las dos
respuestas, así que no pueden divergir. Consulta
[Responder con un fragmento](DOCUMENTATION.md#responder-con-un-fragmento).

Las rutas, en `app/routes/web.php`:

```php
Router::get('/phpx', [PhpxController::class, 'index'])->name('phpx');
Router::get('/phpx/postcode', [PhpxController::class, 'postcode'])->name('phpx.postcode');
```

### Desde una plantilla SFHT

Una plantilla compilada se ejecuta en el namespace global, así que el nombre
corto de un componente no se encuentra ahí sin un import:
`{{ Card('Hello', $body) }}` por sí solo falla con *"Call to undefined function
Card()"*. Impórtalo con `@use`, el propio `use` de PHP escrito como directiva:

```sfht
@use(function SfphpProject\app\components\Card)

{{ Card('Hello', $body) }}
```

`@use` acepta lo que acepta el `use` de PHP — `function Name`, `const NAME` o
una clase, cada uno con un `as Alias` opcional — con o sin comillas. El
compilador mueve cada import al principio del archivo compilado, el único sitio
donde PHP acepta uno, así que `@use` puede escribirse en cualquier punto de la
plantilla, incluso dentro de `@if` o `@block`. Escrito sin paréntesis, `@use` es
texto, así que una dirección como `someone@use.example` sigue llegando a la
página. `@use` es para plantillas; un `.phpx` importa con `use function` al
principio del archivo.

```sfht
{{ \SfphpProject\app\components\Card('Hello', $body) }}
```

El nombre completamente cualificado no necesita import.

```php
// En el controlador
return Response::sfht('home', ['card' => Card('Hello', $body)]);
```

```sfht
{{-- En la plantilla --}}
{{ $card }}
```

El componente se renderiza en el controlador y se pasa como dato. Se imprime
como marcado, porque es un `Sfht`; una cadena pasada junto a él se sigue
escapando.

En todos los casos, los parámetros del propio componente se escapan dentro de
él, así que `$body` es seguro sea cual sea el camino por el que se llega a la
tarjeta.

### Desde PHP puro

En cualquier otro sitio — el cuerpo de un correo, un comando de consola, una
prueba — un componente es una llamada a función, y su resultado se usa como
cadena:

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

Recuerda que fuera de `public/index.php` hay que incluir antes el archivo
compilado (consulta [Cargar los componentes](#cargar-los-componentes)).

---

## La página de ejemplo

```bash
./sfphp serve
# abre http://localhost:8000/phpx
```

`/phpx` es `PostcodePage()`: una cabecera, un formulario para buscar un código
postal brasileño (CEP), la explicación de `HowItWorks()` y un pie. `/phpx/postcode`
es la búsqueda que envía el formulario, no una página para abrir — sin un
`postcode` responde *"A Brazilian postcode has eight digits."*

El formulario lleva `@get` y `@target="#result"` de SFJS: SFJS lo envía, recibe
el fragmento `Address` y lo intercambia, sin escribir JavaScript para la página.
La búsqueda llama al servicio público ViaCEP mediante el cliente HTTP del
framework con un timeout de cinco segundos, y responde con un `Notice` cuando el
servicio falla o no conoce ninguna dirección para el código postal.

---

## .phpx o .sfht

Usa `.sfht` para **páginas**: layouts, bloques, cualquier cosa que un diseñador
pueda abrir. Usa `.phpx` para **piezas**: una tarjeta, un campo, una fila de
tabla — cualquier cosa que reciba argumentos y aparezca más de una vez. Un
parcial ve lo que haya en el ámbito donde se incluyó, así que lo que necesita se
descubre leyéndolo; los parámetros de un componente son sus props, así que lo
que necesita es su firma. La referencia compara los dos en
[Componentes y .phpx](DOCUMENTATION.md#componentes-y-phpx).

| | `.sfht` | `.phpx` |
|---|---|---|
| Paso de compilación | Ninguno — se compila bajo demanda | `./sfphp build --phpx`, después de cada cambio |
| Composición | `@include`, `@extends`, `@block` | Llamar a la función |
| Filtros (`\|`) | Sí, incluidos los añadidos con `addFilter()` | Los estándar |
| Imports | `@use(function …)` | `use function …` al principio del archivo |
| Recibe | Lo que haya en el ámbito, más lo que se le pase | Sus parámetros, y nada más |
| Se carga | Por el motor de vistas, por nombre | Lo incluye el controlador frontal |

---

## Soporte del editor

Un archivo `.phpx` es PHP con marcado donde PHP no lo espera. El proyecto
incluye configuraciones para EditorConfig, VS Code (con Intelephense) y Zed que
asocian `.phpx` con PHP, activan Emmet y desactivan los diagnósticos que el
marcado dispararía — consulta [Soporte del editor](DOCUMENTATION.md#soporte-del-editor).
`./sfphp build --phpx` y `composer run lint` son los que detectan los errores
reales.

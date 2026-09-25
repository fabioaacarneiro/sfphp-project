# Components with .phpx

> **Read in:** [English](PHPX_COMPONENTS.md) · [Português](../pt-BR/PHPX_COMPONENTS.md) · [Español](../es/PHPX_COMPONENTS.md)

A `.phpx` component is **a PHP function whose markup lives inside it**. Its
parameters are its props, its body is SFHT markup, and it returns an `Sfht`
value — markup the framework knows is already safe to print. `./sfphp build
--phpx` compiles each `.phpx` file into plain PHP, and the front controller
loads the result, so a component is called like any other function.

This guide goes through the whole mechanism, using the components the example
application ships in `app/components/`. The framework reference has the short
version and the comparison with templates:
[Components and .phpx](DOCUMENTATION.md#components-and-phpx). The markup syntax
inside a component is SFHT's, described in
[Views and SFHT](DOCUMENTATION.md#views-and-sfht).

---

## A First Component

`app/components/Card.phpx`, as it ships:

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
    return sfht(
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

Everything outside `sfht( … )` is ordinary PHP: the namespace, the `use`
statements, the docblock, the typed signature, the return type. `sfht(` opens a
**markup region** and the `)` that balances it closes it — found by reading the
markup as markup, so the text inside can hold any character (see
[Quotes and parentheses in text](#quotes-and-parentheses-in-text)). There is no
function called `sfht()` at runtime — the build replaces the whole region with
PHP that renders it.

Called from PHP, it returns the card:

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

The markup keeps the indentation it had in the source.

---

## Inside a Markup Region

### What works

The region is compiled by the same SFHT compiler that compiles `.sfht`
templates, so the same syntax works:

| Syntax | Inside `sfht( … )` |
|---|---|
| `{{ $value }}` | Prints the value, **escaped** — unless it is an `Sfht` (see [Escaping](#escaping)) |
| `{!! $html !!}` | Prints the value as it is, with no escaping |
| `{{ $value \| upper }}` | The standard filters (see [Filters](#filters)) |
| `{{-- comment --}}` | Removed at compile time |
| `@if` · `@elseif` · `@else` · `@unless` | Conditionals |
| `@foreach` · `@forelse` / `@empty` · `@for` · `@while` | Loops |
| `@php … @endphp` | Raw PHP |
| Any PHP expression in `{{ }}` | Function calls, operators, indexes — and other components |

`app/components/BulletList.phpx` uses a loop:

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
    return sfht(
        <ul class="list-unstyled">
            @foreach ($items as $item)
                <li class="py-1">{{ $item }}</li>
            @endforeach
        </ul>
    );
}
```

### Filters

The standard filters work as they do in a template: `upper`, `lower`,
`capitalize`, `truncate`, `length`, `reverse`, `escape`, `json`, `format`,
`trim`, `abs`, `round` and `default`.

```php
return sfht(
    <h3>{{ $title | upper }}</h3>
    <p>{{ $body | truncate(80) }}</p>
);
```

A component is a function call, with no template engine around it, so its
filters are applied directly rather than asked of an engine. Two consequences:
a filter registered on an engine with `addFilter()` is not available in a
component — call a function instead — and a name that is not a standard filter
stops the build, with its line: *"Unknown filter "shout" on line 12; a component
can use upper, lower, …"*.

### What does not work

`@include`, `@includeWhen`, `@component`, `@extends`, `@block` and `@use` need
the template engine — a directory of partials, a layout, a table of blocks — and
a component has none. A component composes other components by calling them, and
imports them with `use function` at the top of the `.phpx`. Each of those
directives stops the build with the file and the line it is on:

```
Error in app/components/Card.phpx: @include on line 9 cannot be used in a .phpx component: call the other component instead, as {{ Card(...) }}.
```

### What the markup can see

The region sees the function's variables **as they are when `sfht(` is
reached**: its parameters, and any local variable assigned before it. Nothing
from further out — no globals, no variables of the caller. That is what makes
the signature the component's contract.

A component can prepare values before its markup. The example's
`postcode/explain/HowItWorks.phpx` reads a file into `$source` and then prints
it:

```php
function HowItWorks(): Sfht
{
    $source = (string) file_get_contents(Bootstrap::basePath('app/components/postcode/lookup/Field.phpx'));

    return sfht(
        <section class="card">
            …
                <pre class="bg-light p-3 rounded-md overflow-auto"><code>{{ $source }}</code></pre>
            …
        </section>
    );
}
```

Because `{{ }}` escapes, the component's source code appears on the page as
text, without an entity written by hand.

### Quotes and parentheses in text

The build finds the `)` that closes a region by reading the markup's own
structure. Comments (`<!-- -->`, `{{-- --}}`), `{{ }}` and `{!! !!}`
expressions, directive arguments (`@if (…)`) and tags — with their quoted
attributes — are stepped over whole, and the elements that are opened are
tracked. Text inside an element is text, whatever it holds:

```html
<p>Don't panic</p>                      <!-- an apostrophe -->
<p>Step 1) open the lid</p>             <!-- an unbalanced parenthesis -->
<span title="a)b">{{ "x)" }}</span>      <!-- inside attributes and expressions -->
<script>if (a < b) { go('it\'s'); }</script>  <!-- script and style are text -->
```

Only **outside every element** does a parenthesis count, and there it has to be
balanced, as in PHP: the `)` that balances `sfht(` is the one that closes the
region. Void elements (`<br>`, `<img>`, `<input>` …) and self-closing tags open
nothing, and a closing tag also closes any element left open inside it, the way
HTML treats an `<li>` without `</li>`.

An element that is opened and never closed keeps the region open, and the build
says which one: *"A markup region opened at line 4 is never closed; <div> on
line 5 is still open, so the ) after it was read as its text."*

---

## Escaping

`{{ }}` escapes everything **except** a value that is an `Sfht`:

```php
// SfphpProject\src\View\Compiler, what every {{ }} compiles to
public static function text(mixed $value): string
{
    if ($value instanceof Sfht) {
        return (string) $value;
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

So one expression position does the right thing for both kinds of value.
Given text that came from a visitor, the card escapes it:

```php
echo Card('Welcome', '<script>alert(1)</script>', 'green');
```

```html
<p class="text-muted">&lt;script&gt;alert(1)&lt;/script&gt;</p>
```

And given another component, it prints it as markup — which is what lets
`Address` compose `Field` with `{{ }}` rather than with `{!! !!}`:

```php
{{ Field('Street', $street) }}    the field renders
{{ $street }}                      the text is escaped
```

The alternative — components returning strings, composed with `{!! !!}` —
asks every author to remember which values are trusted, and that is how
`{!! $comment !!}` eventually ships. With `Sfht`, the **type** says which is
which.

`Sfht` is a small value class: it holds a string, and it is `Stringable`, so it
works anywhere a string does — `echo`, concatenation, a `(string)` cast.

> **Wrapping a string in `Sfht` bypasses escaping.** That is what it is for,
> and why `new Sfht($whatever)` deserves a second look in review. The build
> creates `Sfht` values from markup an author wrote; one created from a request
> is a decision to trust the request.

`{!! !!}` prints its value with no escaping at all, `Sfht` or not. Use it only
for HTML your own code produced.

---

## Building

A `.phpx` file is not valid PHP — the markup sits where PHP expects an
expression — so it has to be compiled before it can run:

```bash
./sfphp build --phpx                       # every .phpx under app/components
./sfphp build --phpx --from=src/ui         # read the components from somewhere else
./sfphp build --phpx --to=build/components # write the compiled PHP somewhere else
```

```
  app/components/BulletList.phpx -> app/components/compiled/BulletList.php
  app/components/Card.phpx -> app/components/compiled/Card.php
  …
  app/components/postcode/PostcodePage.phpx -> app/components/compiled/postcode/PostcodePage.php
  …

Compiled 22 component(s).
```

`--from` defaults to `app/components` and `--to` to `app/components/compiled`;
both take a path relative to the project or an absolute one. The output does
**not** go next to the source: every compiled file is written under the target. The build walks the
source tree recursively and **mirrors it** under the target, so
`postcode/lookup/Field.phpx` becomes `compiled/postcode/lookup/Field.php`. The
target directory is skipped when it sits inside the source, so compiled files
are never compiled again.

### What the build produces

Each region becomes a closure that is called on the spot. It receives the
function's variables through `get_defined_vars()`, buffers the markup, and
returns it as an `Sfht`. Every `{{ }}` becomes a call to `Compiler::text()`,
and the statements are separated by spaces rather than newlines, so each line of
markup stays on the line it was written on. This is
`compiled/postcode/lookup/Field.php`, generated from the component shown in
[Composing Components](#composing-components):

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

Everything outside the region — the namespace, the imports, the docblock — is
copied unchanged.

### Errors are caught at build time

A region that does not compile — an unclosed `@if`, a directive a component
cannot use, an unknown filter — stops the build before the file is written,
with the file and the line of the `.phpx`:

```
Error in app/components/postcode/lookup/Field.phpx: Unclosed @if opened on line 19.
```

After writing each file, the build runs `php -l` over it. A syntax error stops
the build with the component's name and PHP's message about the compiled file:

```
Error in app/components/postcode/lookup/Field.phpx:
PHP Parse error:  syntax error, unexpected token ";" in /path/to/project/app/components/compiled/postcode/lookup/Field.php on line 15
Errors parsing /path/to/project/app/components/compiled/postcode/lookup/Field.php
```

The compiled file keeps **every line where the author wrote it** — before a
region, inside it and after it — so the line PHP reports is the line to open in
the `.phpx`. The same holds for a runtime error in a stack trace.

A region that is never closed stops the build before anything is written:
*"Error in app/components/Card.phpx: A markup region opened at line 4 is never
closed."*

### Rebuilding

Run the build again after every change to a `.phpx` — nothing recompiles on
demand, unlike `.sfht` templates. The build overwrites the files it produces
and never deletes one: when you rename or delete a component, delete its
compiled file too. A stale file is still loaded by the front controller, and
two files defining the same function in the same namespace stop every request
with *"Cannot redeclare function"*.

The example application keeps its compiled files in the repository, so it runs
straight after installation without a build.

---

## Loading Components

PHP autoloads **classes**, not functions. Composer cannot find
`SfphpProject\app\components\Card()` on demand the way it finds a controller,
so every compiled component has to be `require`d before it is called.
`public/index.php` does it once, before the routes are loaded:

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

Two consequences:

- The front controller loads `app/components/compiled` only. Components built
  with `--to` somewhere else have to be required by your own code, in the same
  way.
- A script that does not go through `public/index.php` — a console command, a
  test, a queue worker — has to require the compiled components it uses.
  Calling one that was not loaded fails with *"Call to undefined function"*.

---

## One Component per File

Each component lives in a file of its own, **named after the function** —
`Field()` in `Field.phpx` — and the components of one page live in a folder of
their own. The example application:

```
app/components/
├── Card.phpx
├── BulletList.phpx
└── postcode/
    ├── PostcodePage.phpx          the page, composed of the parts below
    ├── layout/
    │   ├── PageHeader.phpx
    │   └── PageFooter.phpx
    ├── lookup/
    │   ├── PostcodeLookup.phpx    the form, and where its answer lands
    │   ├── Address.phpx           an address, made of fields
    │   ├── Field.phpx             one label and value
    │   └── Notice.phpx            the message shown when there is no address
    └── explain/
        └── HowItWorks.phpx        the explanation below the form
```

The namespace follows the folder: `postcode/lookup/Field.phpx` declares
`namespace SfphpProject\app\components\postcode\lookup;`. The build does not
enforce this — a function's namespace is whatever the file declares — but
following it means the name of a component tells you where to find it, and two
pages can each have a `PageHeader()` without a conflict.

Always declare a namespace. PHP function names are case-insensitive and share
one space with the framework's global helpers: a component called `E()` in the
global namespace collides with the `e()` escaping helper.

---

## Composing Components

Components in the **same namespace** call each other by name, with no import.
`Address.phpx` and `Field.phpx` sit in the same folder:

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
    return sfht(
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
    return sfht(
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

Crossing a folder is a `use function`, the same as for any namespaced function
in PHP. `PostcodePage.phpx` imports its parts from three folders:

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
    return sfht(
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

A whole page is a component like any other, and global helpers such as
`lang_tag()` and `asset()` are called directly.

### Markup as a prop

A component can take another component's output as a parameter, typed `Sfht`.
`PostcodeLookup` receives the result area's content, and `null` when there is
nothing yet:

```php
function PostcodeLookup(?Sfht $result = null): Sfht
{
    return sfht(
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

`{{ $result }}` prints the markup when it is an `Sfht` and nothing when it is
`null`. Typing the parameter `Sfht` rather than `string` is what keeps a caller
from passing raw text there by mistake — a string would be a type error, not an
unescaped string on the page.

---

## Using Components

### From a controller

A component returns an `Sfht`, and a controller action returns a `Response`.
`app/controllers/PhpxController.php` renders the page with `Response::html()`:

```php
use function SfphpProject\app\components\postcode\PostcodePage;

public function index(Request $request): Response
{
    return Response::html((string) PostcodePage());
}
```

The `(string)` cast is required: `Response::html()` takes a string. Returning
the `Sfht` itself from an action does not work either — the router turns a
string into HTML and an array into JSON, and anything else is an error
(*"PhpxController::index() must return SfphpProject\src\Http\Response, a string
or an array; got SfphpProject\src\View\Sfht"*).

The lookup action answers with a **fragment** when SFJS asked for one, and with
the whole page when a browser submitted the form without JavaScript:

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

    // … looks the postcode up and answers with Address(…) or a Notice
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

`Response::fragment()` sends the `Address` alone to SFJS, which swaps it into
`#result`, and `PostcodePage($inner)` — the same `Address` inside the whole page
— to a browser without JavaScript. One component renders both answers, so they
cannot drift apart. See
[Answering with a fragment](DOCUMENTATION.md#answering-with-a-fragment).

The routes, in `app/routes/web.php`:

```php
Router::get('/phpx', 'PhpxController', 'index')->name('phpx');
Router::get('/phpx/postcode', 'PhpxController', 'postcode')->name('phpx.postcode');
```

### From an SFHT template

A compiled template runs in the global namespace, so a component's bare name
is not found there without an import: `{{ Card('Hello', $body) }}` alone fails
with *"Call to undefined function Card()"*. Import it with `@use`, PHP's own
`use` written as a directive:

```sfht
@use(function SfphpProject\app\components\Card)

{{ Card('Hello', $body) }}
```

`@use` takes what PHP's `use` takes — `function Name`, `const NAME`, or a class,
each with an optional `as Alias` — quoted or not. The compiler moves every
import to the top of the compiled file, the only place PHP accepts one, so
`@use` can be written anywhere in the template, including inside `@if` or
`@block`. Written without parentheses, `@use` is text, so an address such as
`someone@use.example` still reaches the page. `@use` is for templates; a
`.phpx` imports with `use function` at the top of the file.

```sfht
{{ \SfphpProject\app\components\Card('Hello', $body) }}
```

The fully qualified name needs no import.

```php
// In the controller
return Response::view('home', ['card' => Card('Hello', $body)]);
```

```sfht
{{-- In the template --}}
{{ $card }}
```

The component is rendered in the controller and passed in as data. It prints as
markup, because it is an `Sfht`; a string passed next to it is still escaped.

In every case the component's own parameters are escaped inside it, so `$body`
is safe whichever way the card is reached.

### From plain PHP

Anywhere else — a mail body, a console command, a test — a component is a
function call, and its result is used as a string:

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

Remember that outside `public/index.php` the compiled file has to be required
first (see [Loading Components](#loading-components)).

---

## The Example Page

```bash
./sfphp serve
# open http://localhost:8000/phpx
```

`/phpx` is `PostcodePage()`: a header, a form to look up a Brazilian postcode
(CEP), the explanation from `HowItWorks()`, and a footer. `/phpx/postcode` is
the lookup the form sends, not a page to open — without a `postcode` it answers
*"A Brazilian postcode has eight digits."*

The form carries SFJS's `@get` and `@target="#result"`: SFJS sends it, receives
the `Address` fragment and swaps it in, with no JavaScript written for the
page. The lookup calls the public ViaCEP service through the framework's HTTP
client with a five-second timeout, and answers with a `Notice` when the service
fails or knows no address for the postcode.

---

## .phpx or .sfht

Use `.sfht` for **pages**: layouts, blocks, anything a designer might open. Use
`.phpx` for **pieces**: a card, a field, a table row — anything that takes
arguments and appears more than once. A partial sees whatever was in scope
where it was included, so what it needs is discovered by reading it; a
component's parameters are its props, so what it needs is its signature. The
reference weighs the two in
[Components and .phpx](DOCUMENTATION.md#components-and-phpx).

| | `.sfht` | `.phpx` |
|---|---|---|
| Build step | None — compiled on demand | `./sfphp build --phpx`, after every change |
| Composition | `@include`, `@extends`, `@block` | Calling the function |
| Filters (`\|`) | Yes, including ones added with `addFilter()` | The standard ones |
| Imports | `@use(function …)` | `use function …` at the top of the file |
| Receives | Whatever is in scope, plus what is passed | Its parameters, and nothing else |
| Loaded | By the view engine, by name | Required by the front controller |

---

## Editor Support

A `.phpx` file is PHP with markup where PHP does not expect it. The project
ships settings for EditorConfig, VS Code (with Intelephense) and Zed that
associate `.phpx` with PHP, turn on Emmet and turn off the diagnostics the
markup would trigger — see [Editor support](DOCUMENTATION.md#editor-support).
`./sfphp build --phpx` and `composer run lint` are what catch real errors.

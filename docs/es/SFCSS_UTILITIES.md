# SFCSS — Referencia completa de utilidades

> **Tamaño:** 237KB en crudo · 195KB minificado · **33,1KB comprimidos**
> **Clases:** 3.836 en total — 2.091 base, 628 `hover:`, 1.096 de punto de ruptura, 21 `print:`
> **Colores:** 600 clases de paleta (20 familias × 10 tonos × `bg`/`text`/`border`), más los colores de rol y sus variantes
>
> 🌍 Disponible también en [English](../en/SFCSS_UTILITIES.md) y
> [Português](../pt-BR/SFCSS_UTILITIES.md).

Cada clase de esta página está definida en la hoja de estilos construida. La
mayoría sale del mapa de utilidades de `tools/css-builder/sfcss-builder.php`,
que lee las escalas de `sfcss.config.json` — cambia una escala y las clases la
siguen. La paleta, los degradados, los colores de rol y el contenedor se
generan directamente a partir de la configuración, y unas pocas ayudas
(`text-truncate`, `line-clamp`, `sticky-*`/`fixed-*`, las antiguas
`h-[…]`/`w-[…]`) están escritas en `sfcss-base.css`.
Los componentes (botones, formularios, tablas, navegación, modales…) están
documentados en [SFCSS](SFCSS.md).

## Contenido

- [Escalas](#escalas)
- [Espaciado](#espaciado) — margin, padding, gap, espacio entre hijos
- [Tamaño](#tamaño) — width, height, min, max, size
- [Disposición](#disposición) — display, flexbox, grid, contenedor
- [Tipografía](#tipografía)
- [Colores](#colores)
- [Bordes y redondeo](#bordes-y-redondeo)
- [Efectos](#efectos) — sombra, opacidad, degradados, transición
- [Posición](#posición)
- [Interacción](#interacción) — overflow, cursor, pointer events, selección
- [Variantes](#variantes) — `hover:`, puntos de ruptura, `print:`
- [Totales](#totales)

---

## Escalas

Cuatro escalas de la configuración gobiernan la mayoría de las utilidades.

**Espaciado** (`config.spacing`) — margin, padding, gap, `space-*`:

| Clave | 0 | 0.5 | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 10 | 12 | 16 | 20 | 24 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| rem | 0 | 0,125 | 0,25 | 0,5 | 1 | 1,5 | 2 | 2,5 | 3 | 3,75 | 4,5 | 6 | 7,5 | 9 |

**Tamaño** (`config.sizing`) — width, height, `min-*`, `max-*`, `size-*`: cada
clave es `n × 0.25rem`, así que `w-8` es 2rem y `h-64` es 16rem.

| Claves | 0 1 2 3 4 5 6 8 10 12 16 20 24 28 32 36 40 44 48 52 56 60 64 80 96 |
|---|---|

**Tamaño de fuente** (`config.typography.sizes`):

| `text-xs` | `text-sm` | `text-base` | `text-lg` | `text-xl` | `text-2xl` | `text-3xl` | `text-4xl` | `text-5xl` |
|---|---|---|---|---|---|---|---|---|
| 0,75rem | 0,875rem | 1rem | 1,125rem | 1,25rem | 1,5rem | 1,875rem | 2,25rem | 3rem |

**Redondeo** (`config.radii`):

| `rounded-none` | `rounded-sm` | `rounded` | `rounded-md` | `rounded-lg` | `rounded-xl` | `rounded-2xl` | `rounded-full` |
|---|---|---|---|---|---|---|---|
| 0 | 0,125rem | 0,375rem | 0,5rem | 0,75rem | 1rem | 1,5rem | 9999px |

---

## Espaciado

### Margin y padding

`{property}{side}-{key}`, donde la clave viene de la escala de espaciado:

| Prefijo | Aplica a |
|---|---|
| `m-` / `p-` | los cuatro lados |
| `mt-` `mb-` / `pt-` `pb-` | arriba, abajo |
| `ml-` `mr-` / `pl-` `pr-` | izquierda, derecha |
| `mx-` `my-` / `px-` `py-` | izquierda y derecha, arriba y abajo |
| `ms-` `me-` / `ps-` `pe-` | inicio y fin — siguen la dirección de escritura, así que se reflejan con `dir="rtl"` |

Cada margin tiene también `-auto`: `mx-auto` centra un bloque, `ms-auto` empuja
un elemento al final de una fila flex.

```html
<div class="p-4 mb-3 mx-auto">
<div class="d-flex"><span>Logo</span><nav class="ms-auto">…</nav></div>
```

### Gap

Espacio entre los elementos de un contenedor grid o flex: `gap-{key}`, y
`gap-x-{key}` / `gap-y-{key}` para un solo eje.

### Espacio entre hijos

Un margen en cada hijo excepto el primero, así una pila queda espaciada sin que
el último elemento empuje lo que viene después. `space-y-{key}` (vertical) y
`space-x-{key}` (horizontal, según la dirección de escritura):

```html
<div class="space-y-4">
  <p>First</p>
  <p>Second</p>
</div>
```

---

## Tamaño

| Utilidad | Valores |
|---|---|
| `w-{key}` | la escala de tamaño, y `auto` `full` `screen` `min` `max` `fit`, y las fracciones `1/2` `1/3` `2/3` `1/4` `3/4` `1/5` `2/5` `3/5` `4/5` `1/6` `5/6` |
| `h-{key}` | la escala de tamaño, y `auto` `full` `screen` `min` `max` `fit` `1/2` `1/3` `2/3` `1/4` `3/4` |
| `min-w-{key}` | `0` `full` `min` `max` `fit` |
| `max-w-{key}` | `xs` 20rem · `sm` 24rem · `md` 28rem · `lg` 32rem · `xl` 36rem · `2xl` 42rem · `3xl` 48rem · `4xl` 56rem · `5xl` 64rem · `6xl` 72rem · `7xl` 80rem · `full` · `none` · `prose` (65ch, una línea de texto cómoda) |
| `min-h-{key}` | la escala de tamaño, y `full` `screen` `fit` |
| `max-h-{key}` | la escala de tamaño, y `full` `screen` `none` `fit` |
| `size-{key}` | anchura **y** altura a partir de la escala de tamaño, y `full` — `size-10` para un avatar o un botón de icono |

```html
<div class="min-h-32 max-h-64 overflow-y-auto">   <!-- crece con su contenido y después se desplaza -->
<div class="w-full md:w-1/2">                      <!-- todo el ancho en móviles, la mitad a partir de md -->
```

Las antiguas clases de lista fija `h-[29px]`, `w-[120px]`, `h-[50%]` y los
enganches de estilo `--h-arbitrary` siguen ahí para las páginas escritas contra
ellas.

---

## Disposición

### Display

`d-{value}` y los nombres sin prefijo, ambos con variantes de punto de ruptura y
`print:`:

| `d-*` | `none` `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `contents` `table` `table-cell` `table-row` |
|---|---|
| sin prefijo | `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `hidden` `none` |

```html
<nav class="d-none md:d-flex">          <!-- oculto en móviles -->
<aside class="print:d-none">            <!-- no se imprime -->
```

### Flexbox

| Utilidad | Valores |
|---|---|
| `flex-{direction}` | `row` `row-reverse` `column` `column-reverse` `col` |
| `flex-{wrap}` | `wrap` `nowrap` `wrap-reverse` |
| `flex-{n}` | `1` (crece desde cero) · `auto` · `initial` · `none` · `fill` |
| `flex-grow-{0,1}` · `flex-shrink-{0,1}` | |
| `justify-{value}` | `start` `center` `end` `between` `around` `evenly` `stretch` |
| `items-{value}` | `start` `center` `end` `stretch` `baseline` |
| `self-{value}` | `auto` `start` `center` `end` `stretch` `baseline` |
| `content-{value}` | `start` `center` `end` `between` `around` `stretch` |
| `justify-items-{value}` | `start` `center` `end` `stretch` |
| `place-{value}` | `center` `start` `end` (place-items) |
| `order-{value}` | `first` `last` `none` `1`–`5` |

### Rejilla

| Utilidad | Valores |
|---|---|
| `grid` | `display: grid` con una separación de 1,5rem |
| `grid-cols-{n}` | `1`–`12`, `none` |
| `grid-auto-fit` / `grid-auto-fill` | junto a `grid`: tantas columnas como quepan, cada una de al menos `--grid-min` (16rem) — una rejilla de tarjetas sin ningún punto de ruptura |
| `col-span-{n}` | `1`–`12`, `full`, `auto` |
| `col-start-{n}` | `1`–`13`, `auto` |
| `row-span-{n}` | `1`–`3`, `full` |

```html
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
  <main class="md:col-span-2">…</main>
  <aside>…</aside>
</div>

<ul class="grid grid-auto-fit gap-4" style="--grid-min: 12rem">…</ul>
```

### Contenedor

`container` es una columna centrada cuya anchura máxima es la del punto de
ruptura actual; `container-fluid` ocupa siempre todo el ancho;
`container-{sm,md,lg,xl}` es fluido por debajo de ese punto de ruptura. El
relleno viene de `--container-padding` (1rem).

---

## Tipografía

| Utilidad | Valores |
|---|---|
| `text-{size}` | la escala de tamaños de fuente |
| `text-{align}` | `left` `center` `right` `justify` `start` `end` |
| `font-{weight}` | `light` 300 · `normal` 400 · `medium` 500 · `semibold` 600 · `bold` 700 · `extrabold` 800 |
| `font-sans` · `font-mono` | las dos familias tipográficas |
| `italic` · `not-italic` | |
| `leading-{value}` | interlineado: `none` 1 · `tight` 1.25 · `snug` 1.375 · `normal` 1.5 · `relaxed` 1.625 · `loose` 2 |
| `tracking-{value}` | espaciado entre letras: `tight` `normal` `wide` `wider` `widest` |
| `underline` · `no-underline` · `line-through` | con variantes `hover:` |
| `underline-offset-{1,2,4,8}` | |
| `uppercase` · `lowercase` · `capitalize` · `normal-case` | |
| `whitespace-{value}` | `normal` `nowrap` `pre` `pre-line` `pre-wrap` `break-spaces` |
| `break-{value}` | `normal` · `words` (parte una palabra demasiado larga para su línea) · `all` |
| `text-{wrap}` | `balance` (líneas equilibradas, para títulos) · `pretty` · `wrap` · `nowrap` |
| `align-{value}` | vertical-align: `baseline` `top` `middle` `bottom` `text-top` `text-bottom` |
| `text-truncate` | una línea, con puntos suspensivos |
| `line-clamp` | varias líneas, con puntos suspensivos — `style="--lines: 3"` |

`whitespace-pre-wrap` es lo que necesita el texto que llega por fragmentos — un
stream, un log, texto generado: conserva los saltos de línea que envió el
servidor y aun así ajusta las líneas.

Los tamaños de fuente y la alineación tienen variantes de punto de ruptura:
`text-2xl md:text-4xl`, `text-center md:text-start`.

---

## Colores

### Las 20 familias

`slate` `gray` `zinc` `red` `orange` `amber` `yellow` `lime` `green` `emerald`
`teal` `cyan` `sky` `blue` `indigo` `violet` `purple` `fuchsia` `pink` `rose` —
cada una con los tonos `50` `100` `200` `300` `400` `500` `600` `700` `800`
`900`, como `text-`, `bg-` y `border-`, cada uno con una variante `hover:`:

```html
<p class="text-slate-600">
<div class="bg-blue-50 border border-blue-200">
<a class="text-blue-600 hover:text-blue-800">
```

Son colores fijos y mantienen su valor en el tema oscuro.

### Colores de rol

Para cada color de `config.colors` — por defecto `primary` `secondary`
`success` `danger` `warning` `info` `light` `dark` (`white` y `black` reciben
solo `text-`, `bg-` y `border-`):

| Clase | Usa |
|---|---|
| `text-{c}` · `bg-{c}` · `border-{c}` | el propio color — `text-{c}` en su forma legible, `--{c}-text`, para que pase AA en la página |
| `text-bg-{c}` | el color como fondo, con el color de texto que se lee sobre él |
| `bg-{c}-subtle` · `border-{c}-subtle` · `text-{c}-emphasis` | el fondo pálido, su borde y el texto que se lee sobre él — siguen el tema oscuro |
| `link-{c}` | el color como texto legible (`warning`, por ejemplo, oscurecido hasta que se lee sobre la página) |

Y los de la propia página: `text-body`, `text-muted`, `bg-body`,
`bg-body-raised`, `bg-body-sunken`, `bg-transparent`, `bg-current`.

---

## Bordes y redondeo

| Utilidad | Valores |
|---|---|
| `border` · `border-0` | un borde en todos los lados / ninguno |
| `border-{t,r,b,l,s,e}` | un lado (`s` y `e` son inicio y fin) |
| `border-{t,r,b,l,s,e}-0` | quita un lado |
| `border-{1,2,4,8}` · `border-{side}-{1,2,4,8}` | una anchura en píxeles |
| `border-{colour}` | colores de paleta y de rol, `transparent`, `current` |
| `rounded-{key}` | la escala de redondeo |
| `rounded-{corner}-{key}` | `t` `r` `b` `l` (dos esquinas), `tl` `tr` `br` `bl` (una), `s` `e` (lados de inicio y fin) — `rounded-t-md` para la parte superior de la cabecera de una tarjeta |

---

## Efectos

| Utilidad | Valores |
|---|---|
| `shadow-sm` · `shadow` · `shadow-lg` · `shadow-none` | con variantes `hover:` |
| `opacity-{n}` | de `0` a `100` en pasos de 5, con variantes `hover:` |
| `bg-gradient-to-{t,tr,r,br,b,bl,l,tl}` | un degradado lineal en esa dirección… |
| `from-{family}-{shade}` · `to-{family}-{shade}` | …entre estos dos puntos (tonos de `options.gradientShades`, `500` `600` `700` por defecto), más `from-`/`to-` `white` `black` `transparent` |
| `transition` · `transition-none` | la transición por defecto, o ninguna |

```html
<header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white">
```

Un degradado con solo `from-` se desvanece hacia transparente.

---

## Posición

| Utilidad | Valores |
|---|---|
| `static` `relative` `absolute` `fixed` `sticky` | position |
| `inset-0` · `inset-auto` | los cuatro desplazamientos |
| `top-{0,50,100,auto}` · `bottom-{…}` | porcentajes |
| `start-{0,50,100,auto}` · `end-{…}` | según la dirección de escritura |
| `z-{0,10,20,30,40,50,auto}` | z-index |
| `sticky-top` · `sticky-bottom` · `fixed-top` · `fixed-bottom` | barras fijadas con su propio z-index |

---

## Interacción

| Utilidad | Valores |
|---|---|
| `overflow-{value}` | `auto` `hidden` `visible` `scroll` `clip` |
| `overflow-x-{value}` · `overflow-y-{value}` | `auto` `hidden` `scroll` |
| `visible` · `invisible` | visibility (conserva su espacio, a diferencia de `d-none`) |
| `object-{value}` | object-fit: `contain` `cover` `fill` `none` `scale-down` |
| `aspect-{value}` | `auto` `square` `video` (16/9) `4/3` `21/9` |
| `cursor-{value}` | `auto` `default` `pointer` `wait` `text` `move` `not-allowed` `grab` `help` |
| `pointer-events-{none,auto}` | |
| `select-{none,text,all,auto}` | user-select |

---

## Variantes

### `hover:`

Colores de paleta, subrayado, opacidad y sombra: `hover:bg-blue-700`,
`hover:underline`, `hover:opacity-80`, `hover:shadow-lg`. Se desactivan con
`options.hoverVariants: false`.

### Puntos de ruptura

Mobile-first — `md:` se aplica **a partir de** 768px:

| Prefijo | A partir de |
|---|---|
| `sm:` | 640px |
| `md:` | 768px |
| `lg:` | 1024px |
| `xl:` | 1280px |

Tienen variantes de punto de ruptura:

- **Display:** `d-*` y los nombres sin prefijo
- **Flexbox:** dirección, ajuste, `flex-{n}`, `justify-*`, `items-*`, `order-*`
- **Rejilla:** `grid-cols-*`, `grid-auto-*`, `col-span-*`
- **Espaciado:** `gap-*`, y margin y padding en todos los lados, arriba, abajo,
  `x` e `y` (`md:m-4`, `md:px-6`, `md:mt-0`) — no `l`, `r`, `s`, `e`
- **Anchura:** las palabras clave y las fracciones (`md:w-1/2`, `lg:w-auto`)
- **Tipografía:** tamaño de fuente y alineación del texto

Los puntos de ruptura vienen de `config.breakpoints`; el contenedor y los
componentes responsivos (`navbar-expand-{bp}`, `table-responsive-{bp}`) leen
los mismos números. Cualquier otra utilidad los obtiene con
`"responsive": true` en su entrada del mapa.

### `print:`

`print:d-none`, `print:d-block` y los demás valores de display se aplican solo
cuando la página se imprime.

---

## Totales

| | |
|---|---|
| Selectores únicos | **3.836** |
| Utilidades base y componentes | 2.091 |
| Variantes `hover:` | 628 |
| Variantes de punto de ruptura | 1.096 (274 por punto de ruptura) |
| Variantes `print:` | 21 |
| Familias de color | 20 |
| Tonos por familia | 10 |
| Clases de color de paleta | 600 |
| Prefijos de punto de ruptura | 4 |
| Variables CSS | 194 |
| En crudo · minificado · comprimido | 237KB · 195KB · **33,1KB** |

Los componentes — botones, formularios, tablas, navegación, modales, menús
desplegables, toasts — están documentados en [SFCSS](SFCSS.md).

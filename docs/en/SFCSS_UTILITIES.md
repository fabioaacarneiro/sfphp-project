# SFCSS — Complete utilities reference

> **Size:** 237KB raw · 195KB minified · **33.1KB gzipped**
> **Classes:** 3,836 in total — 2,091 base, 628 `hover:`, 1,096 breakpoint, 21 `print:`
> **Colours:** 600 palette classes (20 families × 10 shades × `bg`/`text`/`border`), plus the role colours and their variants
>
> 🌍 Also available in [Português](../pt-BR/SFCSS_UTILITIES.md) and
> [Español](../es/SFCSS_UTILITIES.md).

Every class on this page is defined in the built stylesheet. Most come from the
utility map in `tools/css-builder/sfcss-builder.php`, reading the scales in
`sfcss.config.json` — change a scale, and the classes follow. The palette,
gradients, role colours and container are generated from the config directly,
and a few helpers (`text-truncate`, `line-clamp`, `sticky-*`/`fixed-*`, the
legacy `h-[…]`/`w-[…]`) are written in `sfcss-base.css`.
Components (buttons, forms, tables, navs, modals…) are documented in
[SFCSS](SFCSS.md).

## Contents

- [Scales](#scales)
- [Spacing](#spacing) — margin, padding, gap, space between
- [Sizing](#sizing) — width, height, min, max, size
- [Layout](#layout) — display, flexbox, grid, container
- [Typography](#typography)
- [Colours](#colours)
- [Borders and radius](#borders-and-radius)
- [Effects](#effects) — shadow, opacity, gradients, transition
- [Position](#position)
- [Interaction](#interaction) — overflow, cursor, pointer events, selection
- [Variants](#variants) — `hover:`, breakpoints, `print:`
- [Totals](#totals)

---

## Scales

Four scales in the config drive most utilities.

**Spacing** (`config.spacing`) — margin, padding, gap, `space-*`:

| Key | 0 | 0.5 | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 10 | 12 | 16 | 20 | 24 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| rem | 0 | 0.125 | 0.25 | 0.5 | 1 | 1.5 | 2 | 2.5 | 3 | 3.75 | 4.5 | 6 | 7.5 | 9 |

**Sizing** (`config.sizing`) — width, height, `min-*`, `max-*`, `size-*`: each
key is `n × 0.25rem`, so `w-8` is 2rem and `h-64` is 16rem.

| Keys | 0 1 2 3 4 5 6 8 10 12 16 20 24 28 32 36 40 44 48 52 56 60 64 80 96 |
|---|---|

**Font size** (`config.typography.sizes`):

| `text-xs` | `text-sm` | `text-base` | `text-lg` | `text-xl` | `text-2xl` | `text-3xl` | `text-4xl` | `text-5xl` |
|---|---|---|---|---|---|---|---|---|
| 0.75rem | 0.875rem | 1rem | 1.125rem | 1.25rem | 1.5rem | 1.875rem | 2.25rem | 3rem |

**Radius** (`config.radii`):

| `rounded-none` | `rounded-sm` | `rounded` | `rounded-md` | `rounded-lg` | `rounded-xl` | `rounded-2xl` | `rounded-full` |
|---|---|---|---|---|---|---|---|
| 0 | 0.125rem | 0.375rem | 0.5rem | 0.75rem | 1rem | 1.5rem | 9999px |

---

## Spacing

### Margin and padding

`{property}{side}-{key}`, where the key is from the spacing scale:

| Prefix | Sets |
|---|---|
| `m-` / `p-` | all four sides |
| `mt-` `mb-` / `pt-` `pb-` | top, bottom |
| `ml-` `mr-` / `pl-` `pr-` | left, right |
| `mx-` `my-` / `px-` `py-` | left and right, top and bottom |
| `ms-` `me-` / `ps-` `pe-` | start and end — they follow the writing direction, so they mirror under `dir="rtl"` |

Every margin also has `-auto`: `mx-auto` centres a block, `ms-auto` pushes an
item to the end of a flex row.

```html
<div class="p-4 mb-3 mx-auto">
<div class="d-flex"><span>Logo</span><nav class="ms-auto">…</nav></div>
```

### Gap

Space between the items of a grid or flex container: `gap-{key}`, and
`gap-x-{key}` / `gap-y-{key}` for one axis.

### Space between children

A margin on every child except the first, so a stack is spaced without the last
item pushing on what follows. `space-y-{key}` (vertical) and `space-x-{key}`
(horizontal, along the writing direction):

```html
<div class="space-y-4">
  <p>First</p>
  <p>Second</p>
</div>
```

---

## Sizing

| Utility | Values |
|---|---|
| `w-{key}` | the sizing scale, and `auto` `full` `screen` `min` `max` `fit`, and the fractions `1/2` `1/3` `2/3` `1/4` `3/4` `1/5` `2/5` `3/5` `4/5` `1/6` `5/6` |
| `h-{key}` | the sizing scale, and `auto` `full` `screen` `min` `max` `fit` `1/2` `1/3` `2/3` `1/4` `3/4` |
| `min-w-{key}` | `0` `full` `min` `max` `fit` |
| `max-w-{key}` | `xs` 20rem · `sm` 24rem · `md` 28rem · `lg` 32rem · `xl` 36rem · `2xl` 42rem · `3xl` 48rem · `4xl` 56rem · `5xl` 64rem · `6xl` 72rem · `7xl` 80rem · `full` · `none` · `prose` (65ch, a comfortable line of text) |
| `min-h-{key}` | the sizing scale, and `full` `screen` `fit` |
| `max-h-{key}` | the sizing scale, and `full` `screen` `none` `fit` |
| `size-{key}` | width **and** height from the sizing scale, and `full` — `size-10` for an avatar or an icon button |

```html
<div class="min-h-32 max-h-64 overflow-y-auto">   <!-- grows with its content, then scrolls -->
<div class="w-full md:w-1/2">                      <!-- full width on phones, half from md up -->
```

The older fixed-list classes `h-[29px]`, `w-[120px]`, `h-[50%]` and the
`--h-arbitrary` style hooks are still there for pages written against them.

---

## Layout

### Display

`d-{value}` and the bare names, both with breakpoint and `print:` variants:

| `d-*` | `none` `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `contents` `table` `table-cell` `table-row` |
|---|---|
| bare | `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `hidden` `none` |

```html
<nav class="d-none md:d-flex">          <!-- hidden on phones -->
<aside class="print:d-none">            <!-- not printed -->
```

### Flexbox

| Utility | Values |
|---|---|
| `flex-{direction}` | `row` `row-reverse` `column` `column-reverse` `col` |
| `flex-{wrap}` | `wrap` `nowrap` `wrap-reverse` |
| `flex-{n}` | `1` (grow from zero) · `auto` · `initial` · `none` · `fill` |
| `flex-grow-{0,1}` · `flex-shrink-{0,1}` | |
| `justify-{value}` | `start` `center` `end` `between` `around` `evenly` `stretch` |
| `items-{value}` | `start` `center` `end` `stretch` `baseline` |
| `self-{value}` | `auto` `start` `center` `end` `stretch` `baseline` |
| `content-{value}` | `start` `center` `end` `between` `around` `stretch` |
| `justify-items-{value}` | `start` `center` `end` `stretch` |
| `place-{value}` | `center` `start` `end` (place-items) |
| `order-{value}` | `first` `last` `none` `1`–`5` |

### Grid

| Utility | Values |
|---|---|
| `grid` | `display: grid` with a 1.5rem gap |
| `grid-cols-{n}` | `1`–`12`, `none` |
| `grid-auto-fit` / `grid-auto-fill` | as many columns as fit, each at least `--grid-min` (16rem) — a card grid with no breakpoint at all |
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

### Container

`container` is a centred column whose maximum width is the current breakpoint;
`container-fluid` is always full width; `container-{sm,md,lg,xl}` is fluid below
that breakpoint. Padding comes from `--container-padding` (1rem).

---

## Typography

| Utility | Values |
|---|---|
| `text-{size}` | the font-size scale |
| `text-{align}` | `left` `center` `right` `justify` `start` `end` |
| `font-{weight}` | `light` 300 · `normal` 400 · `medium` 500 · `semibold` 600 · `bold` 700 · `extrabold` 800 |
| `font-sans` · `font-mono` | the two font families |
| `italic` · `not-italic` | |
| `leading-{value}` | line height: `none` 1 · `tight` 1.25 · `snug` 1.375 · `normal` 1.5 · `relaxed` 1.625 · `loose` 2 |
| `tracking-{value}` | letter spacing: `tight` `normal` `wide` `wider` `widest` |
| `underline` · `no-underline` · `line-through` | with `hover:` variants |
| `underline-offset-{1,2,4,8}` | |
| `uppercase` · `lowercase` · `capitalize` · `normal-case` | |
| `whitespace-{value}` | `normal` `nowrap` `pre` `pre-line` `pre-wrap` `break-spaces` |
| `break-{value}` | `normal` · `words` (break a word too long for its line) · `all` |
| `text-{wrap}` | `balance` (even lines, for headings) · `pretty` · `wrap` · `nowrap` |
| `align-{value}` | vertical-align: `baseline` `top` `middle` `bottom` `text-top` `text-bottom` |
| `text-truncate` | one line, ellipsis |
| `line-clamp` | several lines, ellipsis — `style="--lines: 3"` |

`whitespace-pre-wrap` is what text arriving in chunks needs — a stream, a log,
generated text: it keeps the line breaks the server sent and still wraps.

Font sizes and alignment have breakpoint variants: `text-2xl md:text-4xl`,
`text-center md:text-start`.

---

## Colours

### The 20 families

`slate` `gray` `zinc` `red` `orange` `amber` `yellow` `lime` `green` `emerald`
`teal` `cyan` `sky` `blue` `indigo` `violet` `purple` `fuchsia` `pink` `rose` —
each with the shades `50` `100` `200` `300` `400` `500` `600` `700` `800` `900`,
as `text-`, `bg-` and `border-`, each with a `hover:` variant:

```html
<p class="text-slate-600">
<div class="bg-blue-50 border border-blue-200">
<a class="text-blue-600 hover:text-blue-800">
```

These are fixed colours and keep their value in the dark theme.

### Role colours

For every colour in `config.colors` — by default `primary` `secondary` `success`
`danger` `warning` `info` `light` `dark` (`white` and `black` get only `text-`,
`bg-` and `border-`):

| Class | Uses |
|---|---|
| `text-{c}` · `bg-{c}` · `border-{c}` | the colour itself |
| `text-bg-{c}` | the colour as background, with the text colour that reads on it |
| `bg-{c}-subtle` · `border-{c}-subtle` · `text-{c}-emphasis` | the pale background, its border, and the text that reads on it — they follow the dark theme |
| `link-{c}` | the colour as readable text (`warning`, for instance, darkened until it reads on the page) |

And the page's own: `text-body`, `text-muted`, `bg-body`, `bg-body-raised`,
`bg-body-sunken`, `bg-transparent`, `bg-current`.

---

## Borders and radius

| Utility | Values |
|---|---|
| `border` · `border-0` | a border on every side / none |
| `border-{t,r,b,l,s,e}` | one side (`s` and `e` are start and end) |
| `border-{t,r,b,l,s,e}-0` | remove one side |
| `border-{1,2,4,8}` · `border-{side}-{1,2,4,8}` | a width in pixels |
| `border-{colour}` | palette and role colours, `transparent`, `current` |
| `rounded-{key}` | the radius scale |
| `rounded-{corner}-{key}` | `t` `r` `b` `l` (two corners), `tl` `tr` `br` `bl` (one), `s` `e` (start and end sides) — `rounded-t-md` for the top of a card header |

---

## Effects

| Utility | Values |
|---|---|
| `shadow-sm` · `shadow` · `shadow-lg` · `shadow-none` | with `hover:` variants |
| `opacity-{n}` | `0`–`100` in steps of 5, with `hover:` variants |
| `bg-gradient-to-{t,tr,r,br,b,bl,l,tl}` | a linear gradient in that direction… |
| `from-{family}-{shade}` · `to-{family}-{shade}` | …between these two stops (shades from `options.gradientShades`, `500` `600` `700` by default), plus `from-`/`to-` `white` `black` `transparent` |
| `transition` · `transition-none` | the default transition, or none |

```html
<header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white">
```

A gradient with only `from-` fades to transparent.

---

## Position

| Utility | Values |
|---|---|
| `static` `relative` `absolute` `fixed` `sticky` | position |
| `inset-0` · `inset-auto` | all four offsets |
| `top-{0,50,100,auto}` · `bottom-{…}` | percentages |
| `start-{0,50,100,auto}` · `end-{…}` | along the writing direction |
| `z-{0,10,20,30,40,50,auto}` | z-index |
| `sticky-top` · `sticky-bottom` · `fixed-top` · `fixed-bottom` | pinned bars with their own z-index |

---

## Interaction

| Utility | Values |
|---|---|
| `overflow-{value}` | `auto` `hidden` `visible` `scroll` `clip` |
| `overflow-x-{value}` · `overflow-y-{value}` | `auto` `hidden` `scroll` |
| `visible` · `invisible` | visibility (keeps its space, unlike `d-none`) |
| `object-{value}` | object-fit: `contain` `cover` `fill` `none` `scale-down` |
| `aspect-{value}` | `auto` `square` `video` (16/9) `4/3` `21/9` |
| `cursor-{value}` | `auto` `default` `pointer` `wait` `text` `move` `not-allowed` `grab` `help` |
| `pointer-events-{none,auto}` | |
| `select-{none,text,all,auto}` | user-select |

---

## Variants

### `hover:`

Palette colours, underline, opacity and shadow: `hover:bg-blue-700`,
`hover:underline`, `hover:opacity-80`, `hover:shadow-lg`. Off with
`options.hoverVariants: false`.

### Breakpoints

Mobile-first — `md:` applies **from** 768px up:

| Prefix | From |
|---|---|
| `sm:` | 640px |
| `md:` | 768px |
| `lg:` | 1024px |
| `xl:` | 1280px |

These have breakpoint variants:

- **Display:** `d-*` and the bare names
- **Flexbox:** direction, wrap, `flex-{n}`, `justify-*`, `items-*`, `order-*`
- **Grid:** `grid-cols-*`, `grid-auto-*`, `col-span-*`
- **Spacing:** `gap-*`, and margin and padding on all sides, top, bottom, `x`
  and `y` (`md:m-4`, `md:px-6`, `md:mt-0`) — not `l`, `r`, `s`, `e`
- **Width:** the keywords and fractions (`md:w-1/2`, `lg:w-auto`)
- **Typography:** font size and text alignment

Breakpoints come from `config.breakpoints`; the container and the responsive
components (`navbar-expand-{bp}`, `table-responsive-{bp}`) read the same
numbers. Another utility gets them with `"responsive": true` in its map entry.

### `print:`

`print:d-none`, `print:d-block` and the other display values apply only when
the page is printed.

---

## Totals

| | |
|---|---|
| Unique selectors | **3,836** |
| Base utilities and components | 2,091 |
| `hover:` variants | 628 |
| Breakpoint variants | 1,096 (274 per breakpoint) |
| `print:` variants | 21 |
| Colour families | 20 |
| Shades per family | 10 |
| Palette colour classes | 600 |
| Breakpoint prefixes | 4 |
| CSS variables | 194 |
| Raw · minified · gzipped | 237KB · 195KB · **33.1KB** |

Components — buttons, forms, tables, navs, modals, dropdowns, toasts — are
documented in [SFCSS](SFCSS.md).

# SFCSS — Referência completa de utilitários

> **Tamanho:** 237KB cru · 195KB minificado · **33,1KB gzipped**
> **Classes:** 3.836 no total — 2.091 base, 628 `hover:`, 1.096 de breakpoint, 21 `print:`
> **Cores:** 600 classes de paleta (20 famílias × 10 tons × `bg`/`text`/`border`), mais as cores de papel e as suas variantes
>
> 🌍 Disponível também em [English](../en/SFCSS_UTILITIES.md) e
> [Español](../es/SFCSS_UTILITIES.md).

Toda classe desta página está definida na folha de estilos gerada. A maioria vem
do mapa de utilitários em `tools/css-builder/sfcss-builder.php`, lendo as escalas
de `sfcss.config.json` — mude uma escala, e as classes acompanham. A paleta, os
gradientes, as cores de papel e o container são gerados diretamente a partir do
config, e alguns auxiliares (`text-truncate`, `line-clamp`, `sticky-*`/`fixed-*`,
os legados `h-[…]`/`w-[…]`) são escritos em `sfcss-base.css`. Os componentes (botões, formulários, tabelas, navs, modais…) estão documentados em
[SFCSS](SFCSS.md).

## Índice

- [Escalas](#escalas)
- [Spacing](#spacing) — margin, padding, gap, espaço entre filhos
- [Sizing](#sizing) — width, height, min, max, size
- [Layout](#layout) — display, flexbox, grid, container
- [Tipografia](#tipografia)
- [Cores](#cores)
- [Bordas e radius](#bordas-e-radius)
- [Efeitos](#efeitos) — sombra, opacidade, gradientes, transição
- [Position](#position)
- [Interação](#interação) — overflow, cursor, pointer events, seleção
- [Variantes](#variantes) — `hover:`, breakpoints, `print:`
- [Totais](#totais)

---

## Escalas

Quatro escalas no config controlam a maior parte dos utilitários.

**Spacing** (`config.spacing`) — margin, padding, gap, `space-*`:

| Chave | 0 | 0.5 | 1 | 2 | 3 | 4 | 5 | 6 | 8 | 10 | 12 | 16 | 20 | 24 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| rem | 0 | 0,125 | 0,25 | 0,5 | 1 | 1,5 | 2 | 2,5 | 3 | 3,75 | 4,5 | 6 | 7,5 | 9 |

**Sizing** (`config.sizing`) — width, height, `min-*`, `max-*`, `size-*`: cada
chave é `n × 0.25rem`, então `w-8` é 2rem e `h-64` é 16rem.

| Chaves | 0 1 2 3 4 5 6 8 10 12 16 20 24 28 32 36 40 44 48 52 56 60 64 80 96 |
|---|---|

**Tamanho da fonte** (`config.typography.sizes`):

| `text-xs` | `text-sm` | `text-base` | `text-lg` | `text-xl` | `text-2xl` | `text-3xl` | `text-4xl` | `text-5xl` |
|---|---|---|---|---|---|---|---|---|
| 0.75rem | 0.875rem | 1rem | 1.125rem | 1.25rem | 1.5rem | 1.875rem | 2.25rem | 3rem |

**Radius** (`config.radii`):

| `rounded-none` | `rounded-sm` | `rounded` | `rounded-md` | `rounded-lg` | `rounded-xl` | `rounded-2xl` | `rounded-full` |
|---|---|---|---|---|---|---|---|
| 0 | 0.125rem | 0.375rem | 0.5rem | 0.75rem | 1rem | 1.5rem | 9999px |

---

## Spacing

### Margin e padding

`{property}{side}-{key}`, em que a chave vem da escala de spacing:

| Prefixo | Define |
|---|---|
| `m-` / `p-` | os quatro lados |
| `mt-` `mb-` / `pt-` `pb-` | topo, base |
| `ml-` `mr-` / `pl-` `pr-` | esquerda, direita |
| `mx-` `my-` / `px-` `py-` | esquerda e direita, topo e base |
| `ms-` `me-` / `ps-` `pe-` | início e fim — seguem a direção da escrita, então se espelham com `dir="rtl"` |

Toda margin também tem `-auto`: `mx-auto` centraliza um bloco, `ms-auto`
empurra um item para o fim de uma linha flex.

```html
<div class="p-4 mb-3 mx-auto">
<div class="d-flex"><span>Logo</span><nav class="ms-auto">…</nav></div>
```

### Gap

Espaço entre os itens de um container grid ou flex: `gap-{key}`, e
`gap-x-{key}` / `gap-y-{key}` para um só eixo.

### Espaço entre filhos

Uma margin em cada filho exceto o primeiro, de modo que uma pilha fica
espaçada sem que o último item empurre o que vem depois. `space-y-{key}`
(vertical) e `space-x-{key}` (horizontal, na direção da escrita):

```html
<div class="space-y-4">
  <p>First</p>
  <p>Second</p>
</div>
```

---

## Sizing

| Utilitário | Valores |
|---|---|
| `w-{key}` | a escala de sizing, e `auto` `full` `screen` `min` `max` `fit`, e as frações `1/2` `1/3` `2/3` `1/4` `3/4` `1/5` `2/5` `3/5` `4/5` `1/6` `5/6` |
| `h-{key}` | a escala de sizing, e `auto` `full` `screen` `min` `max` `fit` `1/2` `1/3` `2/3` `1/4` `3/4` |
| `min-w-{key}` | `0` `full` `min` `max` `fit` |
| `max-w-{key}` | `xs` 20rem · `sm` 24rem · `md` 28rem · `lg` 32rem · `xl` 36rem · `2xl` 42rem · `3xl` 48rem · `4xl` 56rem · `5xl` 64rem · `6xl` 72rem · `7xl` 80rem · `full` · `none` · `prose` (65ch, uma linha de texto confortável) |
| `min-h-{key}` | a escala de sizing, e `full` `screen` `fit` |
| `max-h-{key}` | a escala de sizing, e `full` `screen` `none` `fit` |
| `size-{key}` | largura **e** altura a partir da escala de sizing, e `full` — `size-10` para um avatar ou um botão de ícone |

```html
<div class="min-h-32 max-h-64 overflow-y-auto">   <!-- cresce com o conteúdo, depois rola -->
<div class="w-full md:w-1/2">                      <!-- largura total no celular, metade de md para cima -->
```

As classes antigas de lista fixa `h-[29px]`, `w-[120px]`, `h-[50%]` e os
ganchos de estilo `--h-arbitrary` continuam lá para as páginas escritas com
eles.

---

## Layout

### Display

`d-{value}` e os nomes sem prefixo, ambos com variantes de breakpoint e
`print:`:

| `d-*` | `none` `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `contents` `table` `table-cell` `table-row` |
|---|---|
| sem prefixo | `block` `inline` `inline-block` `flex` `inline-flex` `grid` `inline-grid` `hidden` `none` |

```html
<nav class="d-none md:d-flex">          <!-- escondido no celular -->
<aside class="print:d-none">            <!-- não é impresso -->
```

### Flexbox

| Utilitário | Valores |
|---|---|
| `flex-{direction}` | `row` `row-reverse` `column` `column-reverse` `col` |
| `flex-{wrap}` | `wrap` `nowrap` `wrap-reverse` |
| `flex-{n}` | `1` (cresce a partir de zero) · `auto` · `initial` · `none` · `fill` |
| `flex-grow-{0,1}` · `flex-shrink-{0,1}` | |
| `justify-{value}` | `start` `center` `end` `between` `around` `evenly` `stretch` |
| `items-{value}` | `start` `center` `end` `stretch` `baseline` |
| `self-{value}` | `auto` `start` `center` `end` `stretch` `baseline` |
| `content-{value}` | `start` `center` `end` `between` `around` `stretch` |
| `justify-items-{value}` | `start` `center` `end` `stretch` |
| `place-{value}` | `center` `start` `end` (place-items) |
| `order-{value}` | `first` `last` `none` `1`–`5` |

### Grid

| Utilitário | Valores |
|---|---|
| `grid` | `display: grid` com um gap de 1,5rem |
| `grid-cols-{n}` | `1`–`12`, `none` |
| `grid-auto-fit` / `grid-auto-fill` | tantas colunas quantas couberem, cada uma com pelo menos `--grid-min` (16rem) — um grid de cards sem breakpoint nenhum |
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

`container` é uma coluna centralizada cuja largura máxima é a do breakpoint
atual; `container-fluid` tem sempre largura total; `container-{sm,md,lg,xl}` é
fluido abaixo daquele breakpoint. O padding vem de `--container-padding` (1rem).

---

## Tipografia

| Utilitário | Valores |
|---|---|
| `text-{size}` | a escala de tamanhos de fonte |
| `text-{align}` | `left` `center` `right` `justify` `start` `end` |
| `font-{weight}` | `light` 300 · `normal` 400 · `medium` 500 · `semibold` 600 · `bold` 700 · `extrabold` 800 |
| `font-sans` · `font-mono` | as duas famílias de fonte |
| `italic` · `not-italic` | |
| `leading-{value}` | altura de linha: `none` 1 · `tight` 1.25 · `snug` 1.375 · `normal` 1.5 · `relaxed` 1.625 · `loose` 2 |
| `tracking-{value}` | espaçamento entre letras: `tight` `normal` `wide` `wider` `widest` |
| `underline` · `no-underline` · `line-through` | com variantes `hover:` |
| `underline-offset-{1,2,4,8}` | |
| `uppercase` · `lowercase` · `capitalize` · `normal-case` | |
| `whitespace-{value}` | `normal` `nowrap` `pre` `pre-line` `pre-wrap` `break-spaces` |
| `break-{value}` | `normal` · `words` (quebra uma palavra longa demais para a linha) · `all` |
| `text-{wrap}` | `balance` (linhas equilibradas, para títulos) · `pretty` · `wrap` · `nowrap` |
| `align-{value}` | vertical-align: `baseline` `top` `middle` `bottom` `text-top` `text-bottom` |
| `text-truncate` | uma linha, reticências |
| `line-clamp` | várias linhas, reticências — `style="--lines: 3"` |

`whitespace-pre-wrap` é o que um texto que chega em pedaços precisa — um
stream, um log, texto gerado: mantém as quebras de linha que o servidor enviou
e ainda assim quebra a linha.

Tamanhos de fonte e alinhamento têm variantes de breakpoint:
`text-2xl md:text-4xl`, `text-center md:text-start`.

---

## Cores

### As 20 famílias

`slate` `gray` `zinc` `red` `orange` `amber` `yellow` `lime` `green` `emerald`
`teal` `cyan` `sky` `blue` `indigo` `violet` `purple` `fuchsia` `pink` `rose` —
cada uma com os tons `50` `100` `200` `300` `400` `500` `600` `700` `800` `900`,
como `text-`, `bg-` e `border-`, cada um com uma variante `hover:`:

```html
<p class="text-slate-600">
<div class="bg-blue-50 border border-blue-200">
<a class="text-blue-600 hover:text-blue-800">
```

Estas são cores fixas e mantêm o seu valor no tema escuro.

### Cores de papel

Para cada cor em `config.colors` — por padrão `primary` `secondary` `success`
`danger` `warning` `info` `light` `dark` (`white` e `black` ganham apenas `text-`,
`bg-` e `border-`):

| Classe | Usa |
|---|---|
| `text-{c}` · `bg-{c}` · `border-{c}` | a própria cor |
| `text-bg-{c}` | a cor como fundo, com a cor de texto que fica legível sobre ela |
| `bg-{c}-subtle` · `border-{c}-subtle` · `text-{c}-emphasis` | o fundo claro, a sua borda e o texto que fica legível sobre ele — acompanham o tema escuro |
| `link-{c}` | a cor como texto legível (`warning`, por exemplo, escurecida até ficar legível sobre a página) |

E as da própria página: `text-body`, `text-muted`, `bg-body`, `bg-body-raised`,
`bg-body-sunken`, `bg-transparent`, `bg-current`.

---

## Bordas e radius

| Utilitário | Valores |
|---|---|
| `border` · `border-0` | uma borda em todos os lados / nenhuma |
| `border-{t,r,b,l,s,e}` | um lado (`s` e `e` são início e fim) |
| `border-{t,r,b,l,s,e}-0` | remove um lado |
| `border-{1,2,4,8}` · `border-{side}-{1,2,4,8}` | uma largura em pixels |
| `border-{colour}` | cores da paleta e de papel, `transparent`, `current` |
| `rounded-{key}` | a escala de radius |
| `rounded-{corner}-{key}` | `t` `r` `b` `l` (dois cantos), `tl` `tr` `br` `bl` (um), `s` `e` (lados de início e fim) — `rounded-t-md` para o topo de um cabeçalho de card |

---

## Efeitos

| Utilitário | Valores |
|---|---|
| `shadow-sm` · `shadow` · `shadow-lg` · `shadow-none` | com variantes `hover:` |
| `opacity-{n}` | `0`–`100` em passos de 5, com variantes `hover:` |
| `bg-gradient-to-{t,tr,r,br,b,bl,l,tl}` | um gradiente linear nessa direção… |
| `from-{family}-{shade}` · `to-{family}-{shade}` | …entre estes dois pontos (tons de `options.gradientShades`, `500` `600` `700` por padrão), mais `from-`/`to-` `white` `black` `transparent` |
| `transition` · `transition-none` | a transição padrão, ou nenhuma |

```html
<header class="bg-gradient-to-r from-indigo-600 to-blue-600 text-white">
```

Um gradiente só com `from-` se dissolve em transparente.

---

## Position

| Utilitário | Valores |
|---|---|
| `static` `relative` `absolute` `fixed` `sticky` | position |
| `inset-0` · `inset-auto` | os quatro deslocamentos |
| `top-{0,50,100,auto}` · `bottom-{…}` | porcentagens |
| `start-{0,50,100,auto}` · `end-{…}` | na direção da escrita |
| `z-{0,10,20,30,40,50,auto}` | z-index |
| `sticky-top` · `sticky-bottom` · `fixed-top` · `fixed-bottom` | barras fixas com o seu próprio z-index |

---

## Interação

| Utilitário | Valores |
|---|---|
| `overflow-{value}` | `auto` `hidden` `visible` `scroll` `clip` |
| `overflow-x-{value}` · `overflow-y-{value}` | `auto` `hidden` `scroll` |
| `visible` · `invisible` | visibility (mantém o seu espaço, ao contrário de `d-none`) |
| `object-{value}` | object-fit: `contain` `cover` `fill` `none` `scale-down` |
| `aspect-{value}` | `auto` `square` `video` (16/9) `4/3` `21/9` |
| `cursor-{value}` | `auto` `default` `pointer` `wait` `text` `move` `not-allowed` `grab` `help` |
| `pointer-events-{none,auto}` | |
| `select-{none,text,all,auto}` | user-select |

---

## Variantes

### `hover:`

Cores da paleta, sublinhado, opacidade e sombra: `hover:bg-blue-700`,
`hover:underline`, `hover:opacity-80`, `hover:shadow-lg`. Desligadas com
`options.hoverVariants: false`.

### Breakpoints

Mobile-first — `md:` vale **a partir de** 768px:

| Prefixo | A partir de |
|---|---|
| `sm:` | 640px |
| `md:` | 768px |
| `lg:` | 1024px |
| `xl:` | 1280px |

Estes têm variantes de breakpoint:

- **Display:** `d-*` e os nomes sem prefixo
- **Flexbox:** direção, wrap, `flex-{n}`, `justify-*`, `items-*`, `order-*`
- **Grid:** `grid-cols-*`, `grid-auto-*`, `col-span-*`
- **Spacing:** `gap-*`, e margin e padding em todos os lados, topo, base, `x`
  e `y` (`md:m-4`, `md:px-6`, `md:mt-0`) — não `l`, `r`, `s`, `e`
- **Largura:** as palavras-chave e as frações (`md:w-1/2`, `lg:w-auto`)
- **Tipografia:** tamanho da fonte e alinhamento do texto

Os breakpoints vêm de `config.breakpoints`; o container e os componentes
responsivos (`navbar-expand-{bp}`, `table-responsive-{bp}`) leem os mesmos
números. Outro utilitário os ganha com `"responsive": true` na sua entrada do
mapa.

### `print:`

`print:d-none`, `print:d-block` e os demais valores de display valem apenas
quando a página é impressa.

---

## Totais

| | |
|---|---|
| Seletores únicos | **3.836** |
| Utilitários base e componentes | 2.091 |
| Variantes `hover:` | 628 |
| Variantes de breakpoint | 1.096 (274 por breakpoint) |
| Variantes `print:` | 21 |
| Famílias de cores | 20 |
| Tons por família | 10 |
| Classes de cor da paleta | 600 |
| Prefixos de breakpoint | 4 |
| Variáveis CSS | 194 |
| Cru · minificado · gzipped | 237KB · 195KB · **33,1KB** |

Os componentes — botões, formulários, tabelas, navs, modais, dropdowns, toasts —
estão documentados em [SFCSS](SFCSS.md).

# SFCSS — Referência completa de utilitários

> **Tamanho:** 115KB cru · 95KB minificado · **16,8KB gzipped**
> **Classes:** 2.382 no total — 1.254 base, 600 `hover:`, 528 responsivas
> **Cores:** 600 classes de paleta (20 famílias × 10 tons × `bg`/`text`/`border`) mais 25 classes de tema
>
> 🌍 Disponível também em [English](../en/SFCSS_UTILITIES.md) e
> [Español](../es/SFCSS_UTILITIES.md).

Toda classe desta página está definida na folha de estilos gerada. Os
componentes — botões, cards, formulários, tabelas, badges, alerts — estão
documentados em [SFCSS](SFCSS.md).

## Índice

- [Spacing](#spacing) — margin, padding, gap
- [Sizing](#sizing) — width, height, max/min
- [Layout](#layout) — display, flexbox, grid
- [Tipografia](#tipografia) — tamanho, peso, alinhamento, transformação
- [Cores](#cores) — 20 famílias com 10 tons cada
- [Bordas e radius](#bordas-e-radius)
- [Sombras e efeitos](#sombras-e-efeitos)
- [Position e visibilidade](#position-e-visibilidade)
- [Overflow e cursor](#overflow-e-cursor)
- [Responsivo](#responsivo)

---

## Spacing

**Escala:** 0, 0.25rem, 0.5rem, 0.75rem, 1rem, 1.25rem, 1.5rem, 2rem, 2.5rem,
3rem, 4rem, 5rem, 6rem — endereçadas como `0 1 2 3 4 5 6 8 10 12 16 20 24`.

### Margin

```html
<!-- Todos os lados -->
<div class="m-0 m-1 m-2 m-3 m-4 m-5 m-6 m-8 m-12 m-16 m-20 m-24">

<!-- Topo -->
<div class="mt-0 mt-1 mt-2 mt-3 mt-4 mt-5 mt-6 mt-8 mt-12 mt-16 mt-20 mt-24">

<!-- Base -->
<div class="mb-0 mb-1 mb-2 mb-3 mb-4 mb-5 mb-6 mb-8 mb-12 mb-16 mb-20 mb-24">

<!-- Esquerda, com auto -->
<div class="ml-0 ml-1 ml-2 ml-3 ml-4 ml-5 ml-6 ml-8 ml-12 ml-16 ml-20 ml-24 ml-auto">

<!-- Direita, com auto -->
<div class="mr-0 mr-1 mr-2 mr-3 mr-4 mr-5 mr-6 mr-8 mr-12 mr-16 mr-20 mr-24 mr-auto">

<!-- Horizontal: esquerda + direita -->
<div class="mx-0 mx-1 mx-2 mx-3 mx-4 mx-5 mx-6 mx-8 mx-12 mx-16 mx-20 mx-24 mx-auto">

<!-- Vertical: topo + base -->
<div class="my-0 my-1 my-2 my-3 my-4 my-5 my-6 my-8 my-12 my-16 my-20">
```

`mx-auto` é como se centraliza horizontalmente um bloco de largura fixa.

### Padding

```html
<!-- Todos os lados -->
<div class="p-0 p-1 p-2 p-3 p-4 p-5 p-6 p-8 p-12 p-16 p-20 p-24">

<!-- Lados individuais -->
<div class="pt-0 pt-1 pt-2 pt-3 pt-4 pt-5 pt-6 pt-8 pt-12 pt-16 pt-20 pt-24">
<div class="pb-0 pb-1 pb-2 pb-3 pb-4 pb-5 pb-6 pb-8 pb-12 pb-16 pb-20 pb-24">
<div class="pl-0 pl-1 pl-2 pl-3 pl-4 pl-5 pl-6 pl-8 pl-12 pl-16 pl-20 pl-24">
<div class="pr-0 pr-1 pr-2 pr-3 pr-4 pr-5 pr-6 pr-8 pr-12 pr-16 pr-20 pr-24">

<!-- Pares -->
<div class="px-0 px-1 px-2 px-3 px-4 px-5 px-6 px-8 px-12 px-16 px-20 px-24">
<div class="py-0 py-1 py-2 py-3 py-4 py-5 py-6 py-8 py-12 py-16 py-20 py-24">
```

### Gap

Espaço entre itens de um grid ou de um container flex:

```html
<div class="grid grid-cols-3 gap-0 gap-1 gap-2 gap-3 gap-4 gap-5 gap-6 gap-8">
```

### Espaço entre filhos

Uma margem em todo filho exceto o primeiro, então uma pilha fica espaçada sem o
último item empurrar o que vem depois. Os passos são os da escala de margem:
`space-y-4` é a mesma distância que `mt-4`.

```html
<div class="space-y-1 space-y-2 space-y-3 space-y-4 space-y-6 space-y-8">
<div class="d-flex space-x-1 space-x-2 space-x-3 space-x-4 space-x-6 space-x-8">
```

---

## Sizing

### Largura

```html
<!-- Fixas, em rem -->
<div class="w-0 w-1 w-2 w-3 w-4 w-5 w-6 w-8 w-10 w-12 w-16 w-20 w-24 w-28 w-32 w-36 w-40 w-44 w-48 w-52 w-56 w-60 w-64">

<!-- Palavras-chave -->
<div class="w-auto">          <!-- auto -->
<div class="w-full">          <!-- 100% -->
<div class="w-screen">        <!-- 100vw -->
<div class="w-min">           <!-- min-content -->
<div class="w-max">           <!-- max-content -->
<div class="w-fit">           <!-- fit-content -->

<!-- Frações -->
<div class="w-1/2">           <!-- 50% -->
<div class="w-1/3 w-2/3">     <!-- 33,333% / 66,667% -->
<div class="w-1/4 w-3/4">     <!-- 25% / 75% -->
<div class="w-1/5 w-2/5 w-3/5 w-4/5">
<div class="w-1/6 w-5/6">

<!-- Porcentagens de dez em dez -->
<div class="w-[0%] w-[10%] w-[20%] w-[30%] w-[40%] w-[50%]">
<div class="w-[60%] w-[70%] w-[80%] w-[90%] w-[100%]">

<!-- Porcentagens fracionárias -->
<div class="w-[25%] w-[33%] w-[66%] w-[75%]">

<!-- Pixels arbitrários -->
<div class="w-[10px] w-[16px] w-[20px] w-[24px] w-[29px]">
<div class="w-[30px] w-[32px] w-[36px] w-[40px] w-[44px]">
<div class="w-[48px] w-[52px] w-[56px] w-[60px] w-[64px]">
<div class="w-[72px] w-[80px] w-[96px] w-[120px] w-[128px]">
<div class="w-[144px] w-[160px] w-[192px] w-[224px] w-[256px]">
```

Os valores entre colchetes são um **conjunto fixo** gerado pelo builder, não uma
sintaxe aberta: `w-[137px]` não existe porque nada o gerou. Acrescente o valor
ao builder, ou escreva a regra você mesmo.

### Altura

```html
<!-- Fixas, em rem -->
<div class="h-0 h-1 h-2 h-3 h-4 h-5 h-6 h-8 h-10 h-12 h-16 h-20 h-24 h-28 h-32 h-36 h-40 h-44 h-48 h-52 h-56 h-60 h-64">

<!-- Palavras-chave -->
<div class="h-auto h-full h-screen h-min h-max h-fit">

<!-- Frações -->
<div class="h-1/2 h-1/3 h-2/3 h-1/4 h-3/4">

<!-- Porcentagens -->
<div class="h-[0%] h-[10%] h-[20%] h-[30%] h-[40%]">
<div class="h-[50%] h-[60%] h-[70%] h-[80%] h-[90%] h-[100%]">

<!-- Pixels arbitrários -->
<div class="h-[10px] h-[16px] h-[24px] h-[29px] h-[30px]">
<div class="h-[32px] h-[36px] h-[40px] h-[48px] h-[64px]">
<div class="h-[96px] h-[128px] h-[192px] h-[256px]">
```

### Altura máxima e mínima

A mesma escala em rem do `h-*`, então `min-h-32` tem a altura de `h-32`:

```html
<div class="max-h-32 max-h-40 max-h-48 max-h-64 max-h-80 max-h-96">
<div class="max-h-full max-h-screen max-h-none">

<div class="min-h-0 min-h-16 min-h-24 min-h-32 min-h-40 min-h-48 min-h-64">
<div class="min-h-full min-h-screen">
```

Uma caixa que cresce com o conteúdo até um limite e então rola:

```html
<div class="min-h-32 max-h-64 overflow-y-auto">
```

### Largura máxima e mínima

```html
<div class="max-w-sm">        <!-- 24rem -->
<div class="max-w-md">        <!-- 28rem -->
<div class="max-w-lg">        <!-- 32rem -->
<div class="max-w-xl">        <!-- 36rem -->
<div class="max-w-2xl">       <!-- 42rem -->
<div class="max-w-3xl">       <!-- 48rem -->
<div class="max-w-4xl">       <!-- 56rem -->
<div class="max-w-5xl">       <!-- 64rem -->
<div class="max-w-6xl">       <!-- 72rem -->
<div class="max-w-7xl">       <!-- 80rem -->
<div class="max-w-full">      <!-- 100% -->
<div class="max-w-none">      <!-- none -->

<div class="min-w-0">         <!-- 0 -->
<div class="min-w-full">      <!-- 100% -->
```

---

## Layout

### Display

Existem as duas grafias — o nome puro e o prefixo `d-`:

```html
<div class="block">           <div class="d-block">
<div class="inline">          <div class="d-inline">
<div class="inline-block">    <div class="d-inline-block">
<div class="flex">            <div class="d-flex">
<div class="inline-flex">     <!-- sem forma d- na largura base -->
<div class="grid">            <div class="d-grid">
<div class="none">            <div class="d-none">
```

`inline-flex` é a única exceção: o builder não emite um `d-inline-flex` base,
embora `md:d-inline-flex` e as demais formas com breakpoint existam.

### Flexbox

```html
<!-- Direção e quebra -->
<div class="flex-row">        <!-- row (padrão) -->
<div class="flex-column">     <!-- column -->
<div class="flex-wrap">       <!-- wrap -->
<div class="flex-nowrap">     <!-- nowrap -->

<!-- Justify content: ao longo do eixo principal -->
<div class="justify-start">   <!-- flex-start -->
<div class="justify-center">  <!-- center -->
<div class="justify-end">     <!-- flex-end -->
<div class="justify-between"> <!-- space-between -->
<div class="justify-around">  <!-- space-around -->
<div class="justify-evenly">  <!-- space-evenly -->

<!-- Align items: no eixo transversal -->
<div class="items-start">     <!-- flex-start -->
<div class="items-center">    <!-- center -->
<div class="items-end">       <!-- flex-end -->
<div class="items-stretch">   <!-- stretch -->
<div class="items-baseline">  <!-- baseline -->

<!-- Crescer e encolher -->
<div class="flex-grow-0 flex-grow-1">
<div class="flex-shrink-0 flex-shrink-1">
```

`flex-column` e não `flex-col`: a classe tem o nome do valor CSS que ela define.

### Grid

```html
<div class="grid">            <!-- display: grid -->
<div class="grid-cols-1">
<div class="grid-cols-2">
<div class="grid-cols-3">
<div class="grid-cols-4">
<div class="grid-cols-5">
<div class="grid-cols-6">
<div class="grid-cols-12">
```

---

## Tipografia

### Tamanho da fonte

```html
<p class="text-xs">           <!-- 0.75rem -->
<p class="text-sm">           <!-- 0.875rem -->
<p class="text-base">         <!-- 1rem -->
<p class="text-lg">           <!-- 1.125rem -->
<p class="text-xl">           <!-- 1.25rem -->
<p class="text-2xl">          <!-- 1.5rem -->
<p class="text-3xl">          <!-- 1.875rem -->
<p class="text-4xl">          <!-- 2.25rem -->
```

`text-5xl` existe **apenas com prefixo de breakpoint** — `md:text-5xl`,
`lg:text-5xl` — porque a camada responsiva gera um tamanho além da escala base.

### Peso da fonte

```html
<p class="font-normal">       <!-- 400 -->
<p class="font-semibold">     <!-- 600 -->
<p class="font-bold">         <!-- 700 -->
<p class="font-extrabold">    <!-- 800 -->
```

### Família da fonte

```html
<p class="font-sans">         <!-- --font-family, o padrão -->
<p class="font-mono">         <!-- --font-family-mono -->
```

`code`, `pre`, `kbd` e `samp` já pegam a família monoespaçada da folha base,
então não precisam de classe.

### Alinhamento

```html
<p class="text-left">
<p class="text-center">
<p class="text-right">
<p class="text-justify">
```

### Transformação

```html
<p class="uppercase">
<p class="lowercase">
<p class="capitalize">
<p class="normal-case">
```

### Decoração e estilo

```html
<a class="underline">
<a class="no-underline">
<s class="line-through">
<p class="italic">
<p class="not-italic">
```

### Espaços e quebra de linha

```html
<p class="whitespace-normal">        <!-- junta espaços e quebras (padrão) -->
<p class="whitespace-nowrap">        <!-- uma linha, nunca quebra -->
<p class="whitespace-pre">           <!-- mantém tudo, nunca quebra -->
<p class="whitespace-pre-line">      <!-- mantém quebras, junta espaços -->
<p class="whitespace-pre-wrap">      <!-- mantém tudo, e ainda quebra -->
<p class="whitespace-break-spaces">

<p class="break-normal">             <!-- quebra só onde o idioma permite -->
<p class="break-words">              <!-- quebra uma palavra longa demais para a linha -->
<p class="break-all">                <!-- quebra em qualquer ponto -->
```

`whitespace-pre-wrap` é o que texto chegando em partes precisa — um stream, um
log, texto gerado: sem ele, cada quebra de linha que o servidor enviou vira um
espaço e a saída se emenda numa linha só.

---

## Cores

### As 20 famílias

| Grupo | Famílias |
|---|---|
| Neutros | `slate` `gray` `zinc` |
| Azuis | `blue` `indigo` `sky` `cyan` |
| Verdes | `green` `emerald` `teal` |
| Amarelos | `lime` `yellow` `amber` |
| Quentes | `orange` `red` |
| Roxos e rosas | `purple` `violet` `fuchsia` `pink` `rose` |

Cada uma tem dez tons: `50 100 200 300 400 500 600 700 800 900`.

### Três prefixos

```html
<!-- Texto -->
<p class="text-blue-50 text-blue-100 text-blue-200 text-blue-300">
<p class="text-blue-400 text-blue-500 text-blue-600 text-blue-700">
<p class="text-blue-800 text-blue-900">

<!-- Fundo -->
<div class="bg-blue-50 bg-blue-100 bg-blue-200 bg-blue-300">
<div class="bg-blue-400 bg-blue-500 bg-blue-600 bg-blue-700">
<div class="bg-blue-800 bg-blue-900">

<!-- Borda -->
<div class="border-blue-50 border-blue-100 border-blue-200 border-blue-300">
```

### A conta

- 20 famílias × 10 tons × 3 prefixos (`text` `bg` `border`) = **600 classes**
- Cada uma dessas 600 tem variante `hover:` correspondente — **mais 600**
- E **25 classes de tema** vindas das variáveis CSS:

```html
<p class="text-primary text-secondary text-success text-danger">
<p class="text-warning text-info text-white text-dark">
<div class="bg-primary bg-secondary bg-success bg-danger">
<div class="bg-warning bg-info bg-light bg-dark bg-white">
<div class="border-primary border-secondary border-success border-danger">
<div class="border-warning border-info border-white border-black">
```

As classes de tema leem as variáveis CSS, então sobrescrever `--primary` muda
todas elas. As classes de paleta são geradas como valores de cor literais —
mudá-las exige editar `sfcss.config.json` e regerar.

---

## Bordas e radius

### Borda

```html
<div class="border">          <!-- 1px solid -->
<div class="border-0">        <!-- none -->

<!-- Um lado só -->
<div class="border-t">        <!-- topo -->
<div class="border-r">        <!-- direita -->
<div class="border-b">        <!-- base -->
<div class="border-l">        <!-- esquerda -->

<!-- Espessuras por lado -->
<div class="border-t-0 border-t-1 border-t-2 border-t-4 border-t-8">
<div class="border-r-0 border-r-1 border-r-2 border-r-4 border-r-8">
<div class="border-b-0 border-b-1 border-b-2 border-b-4 border-b-8">
<div class="border-l-0 border-l-1 border-l-2 border-l-4 border-l-8">
```

### Cor da borda

```html
<div class="border-primary border-secondary border-success">
<div class="border-danger border-warning border-info">

<!-- e as 600 classes de borda da paleta -->
<div class="border-blue-500 border-red-300 border-slate-200">
```

### Radius

```html
<div class="rounded-sm">      <!-- 0.125rem -->
<div class="rounded">         <!-- 0.375rem -->
<div class="rounded-md">      <!-- 0.5rem -->
<div class="rounded-lg">      <!-- 0.75rem -->
<div class="rounded-xl">      <!-- 1rem -->
<div class="rounded-full">    <!-- 9999px -->
```

---

## Sombras e efeitos

### Sombra

```html
<div class="shadow-none">
<div class="shadow-sm">
<div class="shadow">
<div class="shadow-lg">
```

### Opacidade

```html
<div class="opacity-0">
<div class="opacity-25">
<div class="opacity-50">
<div class="opacity-75">
<div class="opacity-100">
```

---

## Position e visibilidade

```html
<div class="static">
<div class="relative">
<div class="absolute">
<div class="fixed">
<div class="sticky">

<div class="visible">
<div class="invisible">       <!-- oculto, mas ainda ocupa espaço -->
```

`invisible` difere de `d-none`: a primeira mantém a caixa no layout, a segunda
a remove.

---

## Overflow e cursor

```html
<div class="overflow-auto">
<div class="overflow-hidden">
<div class="overflow-visible">
<div class="overflow-scroll">
<div class="overflow-x-auto">
<div class="overflow-y-auto">

<div class="cursor-auto">
<div class="cursor-default">
<div class="cursor-pointer">
<div class="cursor-wait">
<div class="cursor-text">
<div class="cursor-move">
<div class="cursor-not-allowed">
```

`overflow-x-auto` é o que impede uma tabela larga de esticar a página.

---

## Responsivo

### Breakpoints

Os prefixos vêm de `breakpoints` em `sfcss.config.json` e são **`min-width`**:
a classe sem prefixo vale em qualquer largura, e cada prefixo assume a partir
da sua própria largura para cima.

| Prefixo | A partir de | Exemplo |
|---|---|---|
| *(nenhum)* | qualquer largura | `p-3` |
| `sm:` | 480px | `sm:p-4` |
| `md:` | 768px | `md:p-5` |
| `lg:` | 1024px | `lg:p-6` |
| `xl:` | 1280px | `xl:p-8` |

### Quais utilidades têm variantes

132 utilidades são geradas em cada um dos quatro breakpoints — 528 classes:

| Grupo | Classes |
|---|---|
| Colunas de grid | `grid-cols-1` … `grid-cols-6`, `grid-cols-12` |
| Display | `block` `inline` `inline-block` `flex` `inline-flex` `grid` `none`, e as formas `d-` |
| Flex | `flex-row` `flex-column` `flex-wrap` `flex-nowrap` |
| Padding | `p-*` `px-*` `py-*` |
| Margin | `m-*` `mx-*` `my-*` |
| Gap | `gap-*` |
| Tamanho de fonte | `text-xs` … `text-5xl` |
| Largura | `w-auto` `w-full` `w-1/2` `w-1/3` `w-2/3` `w-1/4` `w-3/4` |

Cores, bordas, sombras, position e cursor **não** têm variantes responsivas:
gerar todas as utilidades em todos os breakpoints multiplicaria a folha várias
vezes por classes que ninguém escreve de forma responsiva.

### Exemplos

```html
<!-- Uma coluna no celular, duas no tablet, três no desktop -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div class="card">Card 1</div>
  <div class="card">Card 2</div>
  <div class="card">Card 3</div>
</div>

<!-- Padding que cresce com a tela -->
<div class="p-2 sm:p-3 md:p-4 lg:p-6">Conteúdo</div>

<!-- Texto que cresce com a tela -->
<h1 class="text-lg md:text-2xl lg:text-3xl">Título</h1>

<!-- Empilhado no celular, lado a lado do tablet para cima -->
<div class="block md:flex">
  <div class="w-full md:w-1/2">Coluna 1</div>
  <div class="w-full md:w-1/2">Coluna 2</div>
</div>
```

### Container

```html
<div class="container">
  <!-- 100% de largura, com padding e um max-width que sobe por breakpoint -->
</div>
```

---

## Totais

| | |
|---|---|
| Seletores únicos | **2.382** |
| Utilitários base e componentes | 1.254 |
| Variantes `hover:` | 600 |
| Variantes responsivas | 528 |
| Famílias de cor | 20 |
| Tons por família | 10 |
| Classes de cor da paleta | 600 |
| Classes de cor de tema | 25 |
| Prefixos de breakpoint | 4 |
| Cru · minificado · gzipped | 115KB · 95KB · **16,8KB** |

Os componentes — botões, cards, formulários, tabelas, badges, alerts — estão
documentados em [SFCSS](SFCSS.md).

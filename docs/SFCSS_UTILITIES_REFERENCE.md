# SFCSS - Referência Completa de Utilidades

> **Versão:** 49KB | **Classes:** 1490+ | **Cores:** 620+ | **Todos os testes:** ✅

## Índice Rápido

- [Spacing](#spacing) - Margin, Padding, Gap
- [Sizing](#sizing) - Width, Height, Max/Min
- [Layout](#layout) - Display, Flexbox, Grid
- [Typography](#typography) - Font Size, Weight, Align, Transform
- [Colors](#colors) - 20 famílias de cores com 10 shades cada
- [Borders & Radius](#borders--radius)
- [Shadows & Effects](#shadows--effects)
- [Position & Visibility](#position--visibility)
- [Overflow & Cursor](#overflow--cursor)
- [Responsive](#responsive)

---

## Spacing

### Margin (Espaço Externo)

**Escala:** 0, 0.25rem, 0.5rem, 1rem, 1.5rem, 2rem, 2.5rem, 3rem, 4.5rem, 6rem, 7.5rem, 9rem

#### Todos os lados (m-)
```html
<div class="m-0 m-1 m-2 m-3 m-4 m-5 m-6 m-8 m-12 m-16 m-20 m-24">
```

#### Topo (mt-)
```html
<div class="mt-0 mt-1 mt-2 mt-3 mt-4 mt-5 mt-6 mt-8 mt-12 mt-16 mt-20 mt-24">
```

#### Base (mb-)
```html
<div class="mb-0 mb-1 mb-2 mb-3 mb-4 mb-5 mb-6 mb-8 mb-12 mb-16 mb-20 mb-24">
```

#### Esquerda (ml-)
```html
<div class="ml-0 ml-1 ml-2 ml-3 ml-4 ml-5 ml-6 ml-8 ml-12 ml-16 ml-20 ml-24 ml-auto">
```

#### Direita (mr-)
```html
<div class="mr-0 mr-1 mr-2 mr-3 mr-4 mr-5 mr-6 mr-8 mr-12 mr-16 mr-20 mr-24 mr-auto">
```

#### Horizontal (mx- = left + right)
```html
<div class="mx-0 mx-1 mx-2 mx-3 mx-4 mx-5 mx-6 mx-8 mx-12 mx-16 mx-20 mx-24 mx-auto">
```

#### Vertical (my- = top + bottom)
```html
<div class="my-0 my-1 my-2 my-3 my-4 my-5 my-6 my-8 my-12 my-16 my-20">
```

### Padding (Espaço Interno)

#### Todos os lados (p-)
```html
<div class="p-0 p-1 p-2 p-3 p-4 p-5 p-6 p-8 p-12 p-16 p-20 p-24">
```

#### Topo (pt-)
```html
<div class="pt-0 pt-1 pt-2 pt-3 pt-4 pt-5 pt-6 pt-8 pt-12 pt-16 pt-20 pt-24">
```

#### Base (pb-)
```html
<div class="pb-0 pb-1 pb-2 pb-3 pb-4 pb-5 pb-6 pb-8 pb-12 pb-16 pb-20 pb-24">
```

#### Esquerda (pl-)
```html
<div class="pl-0 pl-1 pl-2 pl-3 pl-4 pl-5 pl-6 pl-8 pl-12 pl-16 pl-20 pl-24">
```

#### Direita (pr-)
```html
<div class="pr-0 pr-1 pr-2 pr-3 pr-4 pr-5 pr-6 pr-8 pr-12 pr-16 pr-20 pr-24">
```

#### Horizontal (px- = left + right)
```html
<div class="px-0 px-1 px-2 px-3 px-4 px-5 px-6 px-8 px-12 px-16 px-20 px-24">
```

#### Vertical (py- = top + bottom)
```html
<div class="py-0 py-1 py-2 py-3 py-4 py-5 py-6 py-8 py-12 py-16 py-20 py-24">
```

### Gap (Espaço entre itens em Grid/Flex)

```html
<div class="gap-0 gap-1 gap-2 gap-3 gap-4 gap-5 gap-6 gap-8">
```

---

## Sizing

### Width (w-)

#### Valores Fixos (rem-based)
```html
<div class="w-0 w-1 w-2 w-3 w-4 w-5 w-6 w-8 w-10 w-12 w-16 w-20 w-24 w-28 w-32 w-36 w-40 w-44 w-48 w-52 w-56 w-60 w-64">
```

#### Valores Especiais
```html
<div class="w-auto">          <!-- Auto -->
<div class="w-full">          <!-- 100% -->
<div class="w-screen">        <!-- 100vw -->
<div class="w-min">           <!-- min-content -->
<div class="w-max">           <!-- max-content -->
<div class="w-fit">           <!-- fit-content -->
```

#### Frações
```html
<div class="w-1/2">           <!-- 50% -->
<div class="w-1/3">           <!-- 33.333% -->
<div class="w-2/3">           <!-- 66.667% -->
<div class="w-1/4">           <!-- 25% -->
<div class="w-3/4">           <!-- 75% -->
<div class="w-1/5">           <!-- 20% -->
<div class="w-2/5">           <!-- 40% -->
<div class="w-3/5">           <!-- 60% -->
<div class="w-4/5">           <!-- 80% -->
<div class="w-1/6">           <!-- 16.667% -->
<div class="w-5/6">           <!-- 83.333% -->
```

#### Porcentagens em 10%
```html
<div class="w-[10%] w-[20%] w-[30%] w-[40%] w-[50%]">
<div class="w-[60%] w-[70%] w-[80%] w-[90%] w-[100%]">
<div class="w-[0%]">          <!-- 0% -->
```

#### Porcentagens Fracionais
```html
<div class="w-[25%]">         <!-- 1/4 -->
<div class="w-[33%]">         <!-- 1/3 -->
<div class="w-[66%]">         <!-- 2/3 -->
<div class="w-[75%]">         <!-- 3/4 -->
```

#### Pixels Arbitrários
```html
<div class="w-[10px] w-[16px] w-[20px] w-[24px] w-[29px]">
<div class="w-[30px] w-[32px] w-[36px] w-[40px] w-[44px]">
<div class="w-[48px] w-[52px] w-[56px] w-[60px] w-[64px]">
<div class="w-[72px] w-[80px] w-[96px] w-[120px] w-[128px]">
<div class="w-[144px] w-[160px] w-[192px] w-[224px] w-[256px]">
```

### Height (h-)

#### Valores Fixos (rem-based)
```html
<div class="h-0 h-1 h-2 h-3 h-4 h-5 h-6 h-8 h-10 h-12 h-16 h-20 h-24 h-28 h-32 h-36 h-40 h-44 h-48 h-52 h-56 h-60 h-64">
```

#### Valores Especiais
```html
<div class="h-auto">          <!-- Auto -->
<div class="h-full">          <!-- 100% -->
<div class="h-screen">        <!-- 100vh -->
<div class="h-min">           <!-- min-content -->
<div class="h-max">           <!-- max-content -->
<div class="h-fit">           <!-- fit-content -->
```

#### Frações
```html
<div class="h-1/2">           <!-- 50% -->
<div class="h-1/3">           <!-- 33.333% -->
<div class="h-2/3">           <!-- 66.667% -->
<div class="h-1/4">           <!-- 25% -->
<div class="h-3/4">           <!-- 75% -->
```

#### Porcentagens em 10%
```html
<div class="h-[0%] h-[10%] h-[20%] h-[30%] h-[40%]">
<div class="h-[50%] h-[60%] h-[70%] h-[80%] h-[90%] h-[100%]">
```

#### Pixels Arbitrários
```html
<div class="h-[10px] h-[16px] h-[24px] h-[29px] h-[30px]">
<div class="h-[32px] h-[36px] h-[40px] h-[48px] h-[64px]">
<div class="h-[96px] h-[128px] h-[192px] h-[256px]">
```

### Max/Min Width

#### Max Width (max-w-)
```html
<div class="max-w-sm">        <!-- 24rem -->
<div class="max-w-md">        <!-- 28rem -->
<div class="max-w-lg">        <!-- 32rem -->
<div class="max-w-xl">        <!-- 36rem -->
<div class="max-w-2xl">       <!-- 42rem -->
<div class="max-w-3xl">       <!-- 48rem -->
<div class="max-w-full">      <!-- 100% -->
<div class="max-w-none">      <!-- none -->
```

#### Min Width (min-w-)
```html
<div class="min-w-0">         <!-- 0 -->
<div class="min-w-full">      <!-- 100% -->
```

---

## Layout

### Display

```html
<div class="d-block">         <!-- block -->
<div class="d-inline">        <!-- inline -->
<div class="d-inline-block">  <!-- inline-block -->
<div class="d-flex">          <!-- flex -->
<div class="d-grid">          <!-- grid -->
<div class="d-none">          <!-- none (hidden) -->
```

### Flexbox

#### Direction
```html
<div class="flex-row">        <!-- row (padrão) -->
<div class="flex-column">     <!-- column -->
<div class="flex-wrap">       <!-- wrap -->
<div class="flex-nowrap">     <!-- nowrap -->
```

#### Justify Content (horizontal em row)
```html
<div class="justify-start">   <!-- flex-start -->
<div class="justify-center">  <!-- center -->
<div class="justify-end">     <!-- flex-end -->
<div class="justify-between"> <!-- space-between -->
<div class="justify-around">  <!-- space-around -->
<div class="justify-evenly">  <!-- space-evenly -->
```

#### Align Items (vertical em row)
```html
<div class="items-start">     <!-- flex-start -->
<div class="items-center">    <!-- center -->
<div class="items-end">       <!-- flex-end -->
<div class="items-stretch">   <!-- stretch -->
<div class="items-baseline">  <!-- baseline -->
```

#### Grow & Shrink
```html
<div class="flex-grow-0">     <!-- flex-grow: 0 -->
<div class="flex-grow-1">     <!-- flex-grow: 1 -->
<div class="flex-shrink-0">   <!-- flex-shrink: 0 -->
<div class="flex-shrink-1">   <!-- flex-shrink: 1 -->
```

### Grid

```html
<div class="grid">            <!-- display: grid -->
<div class="grid-cols-1">     <!-- 1 coluna -->
<div class="grid-cols-2">     <!-- 2 colunas -->
<div class="grid-cols-3">     <!-- 3 colunas -->
<div class="grid-cols-4">     <!-- 4 colunas -->
<div class="grid-cols-5">     <!-- 5 colunas -->
<div class="grid-cols-6">     <!-- 6 colunas -->
<div class="grid-cols-12">    <!-- 12 colunas -->
```

---

## Typography

### Font Size (text-)

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

### Font Weight

```html
<p class="font-normal">       <!-- 400 -->
<p class="font-semibold">     <!-- 600 -->
<p class="font-bold">         <!-- 700 -->
<p class="font-extrabold">    <!-- 800 -->
```

### Text Align

```html
<p class="text-center">       <!-- center -->
<p class="text-left">         <!-- left -->
<p class="text-right">        <!-- right -->
<p class="text-justify">      <!-- justify -->
```

### Text Transform

```html
<p class="uppercase">         <!-- uppercase -->
<p class="lowercase">         <!-- lowercase -->
<p class="capitalize">        <!-- capitalize -->
<p class="normal-case">       <!-- none -->
```

### Text Decoration

```html
<a class="underline">         <!-- underline -->
<a class="no-underline">      <!-- none -->
<s class="line-through">      <!-- line-through -->
```

### Text Style

```html
<p class="italic">            <!-- italic -->
<p class="not-italic">        <!-- normal -->
```

---

## Colors

### Famílias de Cores (20 total com 10 shades cada)

**Neutros:** slate, gray, zinc  
**Azuis:** blue, indigo, sky  
**Verdes:** green, emerald, teal  
**Amarelos:** lime, yellow, amber  
**Quentes:** orange, red  
**Roxos/Rosas:** purple, violet, fuchsia, pink, rose, cyan

### Cada cor tem 10 shades

Exemplo com `blue`:

```html
<!-- Texto -->
<p class="text-blue-50 text-blue-100 text-blue-200 text-blue-300">
<p class="text-blue-400 text-blue-500 text-blue-600 text-blue-700">
<p class="text-blue-800 text-blue-900">

<!-- Fundo -->
<div class="bg-blue-50 bg-blue-100 bg-blue-200 bg-blue-300">
<div class="bg-blue-400 bg-blue-500 bg-blue-600 bg-blue-700">
<div class="bg-blue-800 bg-blue-900">

<!-- Border -->
<div class="border-blue-50 border-blue-100 border-blue-200 border-blue-300">
```

### Total: 620+ Classes de Cor

- 20 families × 3 tipos (text, bg, border) × 10 shades = **600 classes**
- + Cores do tema (primary, secondary, success, danger, warning, info)
- **Total: 620+**

---

## Borders & Radius

### Border (borda)

```html
<div class="border">          <!-- 1px solid -->
<div class="border-0">        <!-- none -->
<div class="border-t">        <!-- top only -->
<div class="border-r">        <!-- right only -->
<div class="border-b">        <!-- bottom only -->
<div class="border-l">        <!-- left only -->
```

### Border Color

```html
<div class="border-primary">
<div class="border-secondary">
<div class="border-success">
<div class="border-danger">
<div class="border-warning">
<div class="border-info">

<!-- + todas as 20 cores com 10 shades -->
<div class="border-blue-500 border-red-300 border-slate-200">
```

### Border Radius (arredondado)

```html
<div class="rounded">         <!-- 0.375rem -->
<div class="rounded-sm">      <!-- 0.125rem -->
<div class="rounded-md">      <!-- 0.5rem -->
<div class="rounded-lg">      <!-- 0.75rem -->
<div class="rounded-xl">      <!-- 1rem -->
<div class="rounded-full">    <!-- 9999px (circular) -->
```

---

## Shadows & Effects

### Shadow (sombra)

```html
<div class="shadow-none">     <!-- none -->
<div class="shadow-sm">       <!-- small -->
<div class="shadow">          <!-- normal -->
<div class="shadow-lg">       <!-- large -->
```

### Opacity (transparência)

```html
<div class="opacity-0">       <!-- 0% -->
<div class="opacity-25">      <!-- 25% -->
<div class="opacity-50">      <!-- 50% -->
<div class="opacity-75">      <!-- 75% -->
<div class="opacity-100">     <!-- 100% -->
```

---

## Position & Visibility

### Position

```html
<div class="static">          <!-- static -->
<div class="fixed">           <!-- fixed -->
<div class="absolute">        <!-- absolute -->
<div class="relative">        <!-- relative -->
<div class="sticky">          <!-- sticky -->
```

### Visibility

```html
<div class="visible">         <!-- visible -->
<div class="invisible">       <!-- hidden (mas reserva espaço) -->
```

---

## Overflow & Cursor

### Overflow

```html
<div class="overflow-auto">   <!-- auto -->
<div class="overflow-hidden"> <!-- hidden -->
<div class="overflow-visible"><!-- visible -->
<div class="overflow-scroll"><!-- scroll -->
<div class="overflow-x-auto"><!-- x overflow -->
<div class="overflow-y-auto"><!-- y overflow -->
```

### Cursor

```html
<div class="cursor-auto">
<div class="cursor-default">
<div class="cursor-pointer">
<div class="cursor-wait">
<div class="cursor-text">
<div class="cursor-move">
<div class="cursor-not-allowed">
```

---

## Responsive

### Breakpoints

| Prefixo | Tamanho  | Uso                    |
|---------|----------|------------------------|
| (nenhum)| Mobile   | `.p-3`                 |
| `sm:`   | ≥ 640px  | `.sm:p-4`              |
| `md:`   | ≥ 768px  | `.md:p-5`              |
| `lg:`   | ≥ 1024px | `.lg:p-6`              |
| `xl:`   | ≥ 1280px | `.xl:p-8`              |

### Exemplos

```html
<!-- Grid responsivo -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Padding responsivo -->
<div class="p-2 sm:p-3 md:p-4 lg:p-6">Conteúdo</div>

<!-- Font size responsivo -->
<h1 class="text-lg md:text-2xl lg:text-3xl">Título</h1>

<!-- Display responsivo -->
<div class="block md:flex">
  <div class="w-full md:w-1/2">Coluna 1</div>
  <div class="w-full md:w-1/2">Coluna 2</div>
</div>
```

---

## Container Queries

Para centralizar conteúdo com max-width automático:

```html
<div class="container">
  <!-- width: 100%, com padding e max-width responsivo -->
  <!-- Responsive max-widths: 640px → 768px → 1024px → 1280px -->
</div>
```

---

## Componentes Base

Todos os componentes base estão documentados em [SFCSS_DOCUMENTATION.md](./SFCSS_DOCUMENTATION.md):
- Buttons (Botões)
- Cards (Cartões)
- Forms (Formulários)
- Tables (Tabelas)
- Badges (Etiquetas)
- Alerts (Alertas)

---

## Stats

- **Total de Classes:** 1490+
- **Linhas de CSS:** 1490
- **Tamanho Completo:** 49KB
- **Tamanho Minificado:** 39KB (17% redução)
- **Famílias de Cores:** 20
- **Shades por Cor:** 10
- **Classes de Cor:** 620+
- **Breakpoints Responsivos:** 5

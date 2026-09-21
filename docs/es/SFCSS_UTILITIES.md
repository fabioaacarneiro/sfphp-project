# SFCSS — Referencia completa de utilidades

> **Tamaño:** 110KB en crudo · 92KB minificado · **16,1KB comprimidos**
> **Clases:** 2.337 en total — 1.209 base, 600 `hover:`, 528 responsivas
> **Colores:** 600 clases de paleta (20 familias × 10 tonos × `bg`/`text`/`border`) más 25 clases del tema
>
> 🌍 Disponible también en [English](../en/SFCSS_UTILITIES.md) y
> [Português](../pt-BR/SFCSS_UTILITIES.md).

Cada clase de esta página está definida en la hoja de estilos construida. Los
componentes — botones, tarjetas, formularios, tablas, etiquetas, alertas —
están documentados en [SFCSS](SFCSS.md).

## Contenido

- [Espaciado](#espaciado) — margin, padding, gap
- [Tamaño](#tamaño) — width, height, max/min
- [Disposición](#disposición) — display, flexbox, grid
- [Tipografía](#tipografía) — tamaño, peso, alineación, transformación
- [Colores](#colores) — 20 familias con 10 tonos cada una
- [Bordes y redondeo](#bordes-y-redondeo)
- [Sombras y efectos](#sombras-y-efectos)
- [Posición y visibilidad](#posición-y-visibilidad)
- [Desbordamiento y cursor](#desbordamiento-y-cursor)
- [Responsivo](#responsivo)

---

## Espaciado

**Escala:** 0, 0.25rem, 0.5rem, 0.75rem, 1rem, 1.25rem, 1.5rem, 2rem, 2.5rem,
3rem, 4rem, 5rem, 6rem — direccionadas como `0 1 2 3 4 5 6 8 10 12 16 20 24`.

### Margin

```html
<!-- Todos los lados -->
<div class="m-0 m-1 m-2 m-3 m-4 m-5 m-6 m-8 m-12 m-16 m-20 m-24">

<!-- Arriba -->
<div class="mt-0 mt-1 mt-2 mt-3 mt-4 mt-5 mt-6 mt-8 mt-12 mt-16 mt-20 mt-24">

<!-- Abajo -->
<div class="mb-0 mb-1 mb-2 mb-3 mb-4 mb-5 mb-6 mb-8 mb-12 mb-16 mb-20 mb-24">

<!-- Izquierda, con auto -->
<div class="ml-0 ml-1 ml-2 ml-3 ml-4 ml-5 ml-6 ml-8 ml-12 ml-16 ml-20 ml-24 ml-auto">

<!-- Derecha, con auto -->
<div class="mr-0 mr-1 mr-2 mr-3 mr-4 mr-5 mr-6 mr-8 mr-12 mr-16 mr-20 mr-24 mr-auto">

<!-- Horizontal: izquierda + derecha -->
<div class="mx-0 mx-1 mx-2 mx-3 mx-4 mx-5 mx-6 mx-8 mx-12 mx-16 mx-20 mx-24 mx-auto">

<!-- Vertical: arriba + abajo -->
<div class="my-0 my-1 my-2 my-3 my-4 my-5 my-6 my-8 my-12 my-16 my-20">
```

`mx-auto` es como se centra horizontalmente un bloque de anchura fija.

### Padding

```html
<!-- Todos los lados -->
<div class="p-0 p-1 p-2 p-3 p-4 p-5 p-6 p-8 p-12 p-16 p-20 p-24">

<!-- Lados individuales -->
<div class="pt-0 pt-1 pt-2 pt-3 pt-4 pt-5 pt-6 pt-8 pt-12 pt-16 pt-20 pt-24">
<div class="pb-0 pb-1 pb-2 pb-3 pb-4 pb-5 pb-6 pb-8 pb-12 pb-16 pb-20 pb-24">
<div class="pl-0 pl-1 pl-2 pl-3 pl-4 pl-5 pl-6 pl-8 pl-12 pl-16 pl-20 pl-24">
<div class="pr-0 pr-1 pr-2 pr-3 pr-4 pr-5 pr-6 pr-8 pr-12 pr-16 pr-20 pr-24">

<!-- Pares -->
<div class="px-0 px-1 px-2 px-3 px-4 px-5 px-6 px-8 px-12 px-16 px-20 px-24">
<div class="py-0 py-1 py-2 py-3 py-4 py-5 py-6 py-8 py-12 py-16 py-20 py-24">
```

### Gap

Espacio entre elementos de una rejilla o un contenedor flex:

```html
<div class="grid grid-cols-3 gap-0 gap-1 gap-2 gap-3 gap-4 gap-5 gap-6 gap-8">
```

---

## Tamaño

### Anchura

```html
<!-- Fijas, en rem -->
<div class="w-0 w-1 w-2 w-3 w-4 w-5 w-6 w-8 w-10 w-12 w-16 w-20 w-24 w-28 w-32 w-36 w-40 w-44 w-48 w-52 w-56 w-60 w-64">

<!-- Palabras clave -->
<div class="w-auto">          <!-- auto -->
<div class="w-full">          <!-- 100% -->
<div class="w-screen">        <!-- 100vw -->
<div class="w-min">           <!-- min-content -->
<div class="w-max">           <!-- max-content -->
<div class="w-fit">           <!-- fit-content -->

<!-- Fracciones -->
<div class="w-1/2">           <!-- 50% -->
<div class="w-1/3 w-2/3">     <!-- 33,333% / 66,667% -->
<div class="w-1/4 w-3/4">     <!-- 25% / 75% -->
<div class="w-1/5 w-2/5 w-3/5 w-4/5">
<div class="w-1/6 w-5/6">

<!-- Porcentajes de diez en diez -->
<div class="w-[0%] w-[10%] w-[20%] w-[30%] w-[40%] w-[50%]">
<div class="w-[60%] w-[70%] w-[80%] w-[90%] w-[100%]">

<!-- Porcentajes fraccionarios -->
<div class="w-[25%] w-[33%] w-[66%] w-[75%]">

<!-- Píxeles arbitrarios -->
<div class="w-[10px] w-[16px] w-[20px] w-[24px] w-[29px]">
<div class="w-[30px] w-[32px] w-[36px] w-[40px] w-[44px]">
<div class="w-[48px] w-[52px] w-[56px] w-[60px] w-[64px]">
<div class="w-[72px] w-[80px] w-[96px] w-[120px] w-[128px]">
<div class="w-[144px] w-[160px] w-[192px] w-[224px] w-[256px]">
```

Los valores entre corchetes son un **conjunto fijo** generado por el
constructor, no una sintaxis abierta: `w-[137px]` no existe porque nada lo
generó. Añade el valor al constructor, o escribe tú la regla.

### Altura

```html
<!-- Fijas, en rem -->
<div class="h-0 h-1 h-2 h-3 h-4 h-5 h-6 h-8 h-10 h-12 h-16 h-20 h-24 h-28 h-32 h-36 h-40 h-44 h-48 h-52 h-56 h-60 h-64">

<!-- Palabras clave -->
<div class="h-auto h-full h-screen h-min h-max h-fit">

<!-- Fracciones -->
<div class="h-1/2 h-1/3 h-2/3 h-1/4 h-3/4">

<!-- Porcentajes -->
<div class="h-[0%] h-[10%] h-[20%] h-[30%] h-[40%]">
<div class="h-[50%] h-[60%] h-[70%] h-[80%] h-[90%] h-[100%]">

<!-- Píxeles arbitrarios -->
<div class="h-[10px] h-[16px] h-[24px] h-[29px] h-[30px]">
<div class="h-[32px] h-[36px] h-[40px] h-[48px] h-[64px]">
<div class="h-[96px] h-[128px] h-[192px] h-[256px]">
```

### Anchura máxima y mínima

```html
<div class="max-w-sm">        <!-- 24rem -->
<div class="max-w-md">        <!-- 28rem -->
<div class="max-w-lg">        <!-- 32rem -->
<div class="max-w-xl">        <!-- 36rem -->
<div class="max-w-2xl">       <!-- 42rem -->
<div class="max-w-3xl">       <!-- 48rem -->
<div class="max-w-full">      <!-- 100% -->
<div class="max-w-none">      <!-- none -->

<div class="min-w-0">         <!-- 0 -->
<div class="min-w-full">      <!-- 100% -->
```

---

## Disposición

### Display

Existen las dos formas — el nombre a secas y el prefijo `d-`:

```html
<div class="block">           <div class="d-block">
<div class="inline">          <div class="d-inline">
<div class="inline-block">    <div class="d-inline-block">
<div class="flex">            <div class="d-flex">
<div class="inline-flex">     <!-- sin forma d- a la anchura base -->
<div class="grid">            <div class="d-grid">
<div class="none">            <div class="d-none">
```

`inline-flex` es la única excepción: el constructor no emite un
`d-inline-flex` base, aunque `md:d-inline-flex` y las demás formas con punto de
ruptura sí existen.

### Flexbox

```html
<!-- Dirección y ajuste -->
<div class="flex-row">        <!-- row (por defecto) -->
<div class="flex-column">     <!-- column -->
<div class="flex-wrap">       <!-- wrap -->
<div class="flex-nowrap">     <!-- nowrap -->

<!-- Justify content: a lo largo del eje principal -->
<div class="justify-start">   <!-- flex-start -->
<div class="justify-center">  <!-- center -->
<div class="justify-end">     <!-- flex-end -->
<div class="justify-between"> <!-- space-between -->
<div class="justify-around">  <!-- space-around -->
<div class="justify-evenly">  <!-- space-evenly -->

<!-- Align items: a través del eje principal -->
<div class="items-start">     <!-- flex-start -->
<div class="items-center">    <!-- center -->
<div class="items-end">       <!-- flex-end -->
<div class="items-stretch">   <!-- stretch -->
<div class="items-baseline">  <!-- baseline -->

<!-- Crecer y encoger -->
<div class="flex-grow-0 flex-grow-1">
<div class="flex-shrink-0 flex-shrink-1">
```

`flex-column` y no `flex-col`: la clase se llama como el valor CSS que
establece.

### Rejilla

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

## Tipografía

### Tamaño de fuente

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

`text-5xl` existe **solo con prefijo de punto de ruptura** — `md:text-5xl`,
`lg:text-5xl` — porque la capa responsiva genera un tamaño más allá de la
escala base.

### Peso de fuente

```html
<p class="font-normal">       <!-- 400 -->
<p class="font-semibold">     <!-- 600 -->
<p class="font-bold">         <!-- 700 -->
<p class="font-extrabold">    <!-- 800 -->
```

### Alineación

```html
<p class="text-left">
<p class="text-center">
<p class="text-right">
<p class="text-justify">
```

### Transformación

```html
<p class="uppercase">
<p class="lowercase">
<p class="capitalize">
<p class="normal-case">
```

### Decoración y estilo

```html
<a class="underline">
<a class="no-underline">
<s class="line-through">
<p class="italic">
<p class="not-italic">
```

---

## Colores

### Las 20 familias

| Grupo | Familias |
|---|---|
| Neutros | `slate` `gray` `zinc` |
| Azules | `blue` `indigo` `sky` `cyan` |
| Verdes | `green` `emerald` `teal` |
| Amarillos | `lime` `yellow` `amber` |
| Cálidos | `orange` `red` |
| Morados y rosas | `purple` `violet` `fuchsia` `pink` `rose` |

Cada una tiene diez tonos: `50 100 200 300 400 500 600 700 800 900`.

### Tres prefijos

```html
<!-- Texto -->
<p class="text-blue-50 text-blue-100 text-blue-200 text-blue-300">
<p class="text-blue-400 text-blue-500 text-blue-600 text-blue-700">
<p class="text-blue-800 text-blue-900">

<!-- Fondo -->
<div class="bg-blue-50 bg-blue-100 bg-blue-200 bg-blue-300">
<div class="bg-blue-400 bg-blue-500 bg-blue-600 bg-blue-700">
<div class="bg-blue-800 bg-blue-900">

<!-- Borde -->
<div class="border-blue-50 border-blue-100 border-blue-200 border-blue-300">
```

### La cuenta

- 20 familias × 10 tonos × 3 prefijos (`text` `bg` `border`) = **600 clases**
- Cada una de esas 600 tiene su variante `hover:` — **600 más**
- Y **25 clases del tema** tomadas de las variables CSS:

```html
<p class="text-primary text-secondary text-success text-danger">
<p class="text-warning text-info text-white text-dark">
<div class="bg-primary bg-secondary bg-success bg-danger">
<div class="bg-warning bg-info bg-light bg-dark bg-white">
<div class="border-primary border-secondary border-success border-danger">
<div class="border-warning border-info border-white border-black">
```

Las clases del tema leen las variables CSS, así que sobrescribir `--primary`
las cambia todas. Las clases de paleta se generan como valores de color
literales — cambiarlas exige editar `sfcss.config.json` y reconstruir.

---

## Bordes y redondeo

### Borde

```html
<div class="border">          <!-- 1px solid -->
<div class="border-0">        <!-- none -->

<!-- Un solo lado -->
<div class="border-t">        <!-- arriba -->
<div class="border-r">        <!-- derecha -->
<div class="border-b">        <!-- abajo -->
<div class="border-l">        <!-- izquierda -->

<!-- Grosores por lado -->
<div class="border-t-0 border-t-1 border-t-2 border-t-4 border-t-8">
<div class="border-r-0 border-r-1 border-r-2 border-r-4 border-r-8">
<div class="border-b-0 border-b-1 border-b-2 border-b-4 border-b-8">
<div class="border-l-0 border-l-1 border-l-2 border-l-4 border-l-8">
```

### Color del borde

```html
<div class="border-primary border-secondary border-success">
<div class="border-danger border-warning border-info">

<!-- y las 600 clases de borde de la paleta -->
<div class="border-blue-500 border-red-300 border-slate-200">
```

### Redondeo

```html
<div class="rounded-sm">      <!-- 0.125rem -->
<div class="rounded">         <!-- 0.375rem -->
<div class="rounded-md">      <!-- 0.5rem -->
<div class="rounded-lg">      <!-- 0.75rem -->
<div class="rounded-xl">      <!-- 1rem -->
<div class="rounded-full">    <!-- 9999px -->
```

---

## Sombras y efectos

### Sombra

```html
<div class="shadow-none">
<div class="shadow-sm">
<div class="shadow">
<div class="shadow-lg">
```

### Opacidad

```html
<div class="opacity-0">
<div class="opacity-25">
<div class="opacity-50">
<div class="opacity-75">
<div class="opacity-100">
```

---

## Posición y visibilidad

```html
<div class="static">
<div class="relative">
<div class="absolute">
<div class="fixed">
<div class="sticky">

<div class="visible">
<div class="invisible">       <!-- oculto, pero sigue ocupando espacio -->
```

`invisible` se diferencia de `d-none`: la primera mantiene la caja en la
disposición, la segunda la quita.

---

## Desbordamiento y cursor

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

`overflow-x-auto` es lo que impide que una tabla ancha estire la página.

---

## Responsivo

### Puntos de ruptura

Los prefijos vienen de `breakpoints` en `sfcss.config.json` y son
**`min-width`**: la clase sin prefijo se aplica a cualquier anchura, y cada
prefijo toma el relevo desde su propia anchura hacia arriba.

| Prefijo | A partir de | Ejemplo |
|---|---|---|
| *(ninguno)* | cualquier anchura | `p-3` |
| `sm:` | 480px | `sm:p-4` |
| `md:` | 768px | `md:p-5` |
| `lg:` | 1024px | `lg:p-6` |
| `xl:` | 1280px | `xl:p-8` |

### Qué utilidades tienen variantes

Se generan 132 utilidades en cada uno de los cuatro puntos de ruptura — 528
clases:

| Grupo | Clases |
|---|---|
| Columnas de rejilla | `grid-cols-1` … `grid-cols-6`, `grid-cols-12` |
| Display | `block` `inline` `inline-block` `flex` `inline-flex` `grid` `none`, y las formas `d-` |
| Flex | `flex-row` `flex-column` `flex-wrap` `flex-nowrap` |
| Padding | `p-*` `px-*` `py-*` |
| Margin | `m-*` `mx-*` `my-*` |
| Gap | `gap-*` |
| Tamaño de fuente | `text-xs` … `text-5xl` |
| Anchura | `w-auto` `w-full` `w-1/2` `w-1/3` `w-2/3` `w-1/4` `w-3/4` |

Los colores, los bordes, las sombras, la posición y el cursor **no** tienen
variantes responsivas: generar todas las utilidades en todos los puntos de
ruptura multiplicaría la hoja varias veces por clases que nadie escribe de
forma responsiva.

### Ejemplos

```html
<!-- Una columna en el móvil, dos en la tableta, tres en el escritorio -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div class="card">Tarjeta 1</div>
  <div class="card">Tarjeta 2</div>
  <div class="card">Tarjeta 3</div>
</div>

<!-- Relleno que crece con la pantalla -->
<div class="p-2 sm:p-3 md:p-4 lg:p-6">Contenido</div>

<!-- Texto que crece con la pantalla -->
<h1 class="text-lg md:text-2xl lg:text-3xl">Título</h1>

<!-- Apilado en el móvil, lado a lado desde la tableta -->
<div class="block md:flex">
  <div class="w-full md:w-1/2">Columna 1</div>
  <div class="w-full md:w-1/2">Columna 2</div>
</div>
```

### Contenedor

```html
<div class="container">
  <!-- 100% de ancho, con relleno y un max-width que sube por punto de ruptura -->
</div>
```

---

## Totales

| | |
|---|---|
| Selectores únicos | **2.337** |
| Utilidades base y componentes | 1.209 |
| Variantes `hover:` | 600 |
| Variantes responsivas | 528 |
| Familias de color | 20 |
| Tonos por familia | 10 |
| Clases de color de la paleta | 600 |
| Clases de color del tema | 25 |
| Prefijos de punto de ruptura | 4 |
| Crudo · minificado · comprimido | 110KB · 92KB · **16,1KB** |

Los componentes — botones, tarjetas, formularios, tablas, etiquetas, alertas —
están documentados en [SFCSS](SFCSS.md).

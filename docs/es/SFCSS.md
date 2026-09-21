# SFCSS — Simple Framework CSS

> Verificado contra la hoja de estilos construida · 2.337 clases · 16,1KB comprimidos
>
> 🌍 Disponible también en [English](../en/SFCSS.md) y
> [Português](../pt-BR/SFCSS.md).

Un framework CSS de utilidades **sin dependencias**, generado a partir de un
archivo de configuración. Combina la idea de Pico CSS de «dar estilo al HTML
plano sin configurar nada» con clases de utilidad al estilo de Tailwind, de
modo que el marcado semántico se ve bien antes de escribir una sola clase, y
las utilidades están ahí cuando las necesitas.

Lista completa de clases:
[referencia de utilidades](SFCSS_UTILITIES.md).

---

## Contenido

- [Instalación](#instalación)
- [Construcción y personalización](#construcción-y-personalización)
- [Componentes](#componentes)
- [Variantes de estado y responsivas](#variantes-de-estado-y-responsivas)
- [Las utilidades de un vistazo](#las-utilidades-de-un-vistazo)
- [Variables CSS](#variables-css)
- [Buenas prácticas](#buenas-prácticas)
- [Tamaño y compatibilidad](#tamaño-y-compatibilidad)

---

## Instalación

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

O la versión minificada:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nada más — sin JavaScript, sin paso de compilación en el navegador, sin
tipografías ni iconos traídos de un CDN.

---

## Construcción y personalización

### Los archivos

```
tools/css-builder/
├── sfcss.config.json      colores, espaciado, tipografía, puntos de ruptura
├── sfcss-base.css         estilos base y componentes
└── sfcss-builder.php      el script de construcción
```

### Reconstruir

Después de editar cualquiera de los dos archivos:

```bash
./sfphp css:build
```

o, de forma equivalente:

```bash
php tools/css-builder/sfcss-builder.php
```

Eso escribe las dos salidas:

- `public/assets/css/sfcss.css` — 110KB, legible
- `public/assets/css/sfcss.min.css` — 92KB, minificado (16,1KB comprimidos)

### Qué hace el constructor

1. **Lee la configuración** de `sfcss.config.json`
2. **Emite la hoja base** desde `sfcss-base.css`
3. **Genera las utilidades de color** — 620 clases (20 familias × 10 tonos ×
   `bg`/`text`/`border`)
4. **Genera las variantes `hover:`** — 600 clases
5. **Genera las variantes responsivas** — 528 clases, a partir de los puntos de
   ruptura de la configuración
6. **Minifica** — elimina comentarios y espacios innecesarios — y escribe
   `sfcss.min.css`

### Qué editar

| Para cambiar | Edita | Clave |
|---|---|---|
| Colores | `sfcss.config.json` | `colorPalettes`, `colors` |
| Escala de espaciado | `sfcss.config.json` | `spacing` |
| Tipografía | `sfcss.config.json` | `typography` |
| Puntos de ruptura | `sfcss.config.json` | `breakpoints` |
| Componentes | `sfcss-base.css` | añade tus propias reglas |

Todo lo generado — colores, variantes `hover:` y variantes responsivas — sale
de la configuración. Cambia ahí un punto de ruptura y toda la capa responsiva
lo sigue.

---

## Componentes

### Botones

```html
<!-- Variantes de color -->
<button class="btn">Por defecto</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-info">Info</button>

<!-- Tamaños -->
<button class="btn btn-sm">Pequeño</button>
<button class="btn btn-md">Normal</button>
<button class="btn btn-lg">Grande</button>

<!-- Estados -->
<button class="btn" disabled>Deshabilitado</button>

<!-- Un enlace con aspecto de botón -->
<a href="#" class="btn btn-primary">Enlace botón</a>
```

### Tarjetas

```html
<div class="card">
  <div class="card-body">
    Contenido de la tarjeta
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Título</h3>
  </div>
  <div class="card-body">
    Contenido principal
  </div>
  <div class="card-footer">
    Pie
  </div>
</div>
```

### Formularios

Los campos reciben estilo por elemento, así que el marcado plano ya se ve bien:

```html
<div class="form-group">
  <label class="form-label">Nombre</label>
  <input type="text" placeholder="Tu nombre">
</div>

<input type="text" placeholder="Marcador de posición">
<textarea placeholder="Tu mensaje..."></textarea>

<select>
  <option>Opción 1</option>
  <option>Opción 2</option>
</select>

<label>
  <input type="checkbox">
  Acepto los términos
</label>

<label>
  <input type="radio" name="option">
  Opción A
</label>

<input type="text" disabled>
<select disabled>
  <option>Deshabilitado</option>
</select>
```

### Tablas

```html
<table>
  <thead>
    <tr>
      <th>Columna 1</th>
      <th>Columna 2</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Dato 1</td>
      <td>Dato 2</td>
    </tr>
  </tbody>
</table>
```

### Etiquetas

```html
<span class="badge">Por defecto</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-danger">Danger</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-info">Info</span>
```

Las etiquetas no tienen variante `secondary` — los botones sí, las etiquetas
no.

### Alertas

```html
<div class="alert">Alerta por defecto</div>
<div class="alert alert-primary">Alerta primaria</div>
<div class="alert alert-success">¡Éxito!</div>
<div class="alert alert-danger">¡Error!</div>
<div class="alert alert-warning">¡Atención!</div>
<div class="alert alert-info">Información</div>
```

Un botón para cerrar no forma parte del framework: cerrar una alerta necesita
JavaScript, y SFCSS no trae ninguno. Usa un botón normal y
[SFJS](DOCUMENTATION.md#sfjs), o tu propio manejador.

### Rejilla

```html
<!-- Disposición automática -->
<div class="grid">
  <div>Elemento 1</div>
  <div>Elemento 2</div>
  <div>Elemento 3</div>
</div>

<!-- Columnas fijas -->
<div class="grid grid-cols-2">Dos columnas</div>
<div class="grid grid-cols-3">Tres columnas</div>
<div class="grid grid-cols-12">Doce columnas</div>

<!-- Responsiva -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div class="card">Tarjeta</div>
</div>
```

### Contenedor

```html
<div class="container">
  <!-- ancho completo, con relleno y un max-width que crece por punto de ruptura -->
</div>
```

---

## Variantes de estado y responsivas

### `hover:`

Cada clase de color tiene su variante `hover:` correspondiente — 600 en total:

```html
<a class="text-blue-500 hover:text-blue-700">Enlace</a>
<button class="bg-blue-600 hover:bg-blue-700 text-white">Enviar</button>
<div class="border-slate-200 hover:border-slate-400">Tarjeta</div>
```

### Puntos de ruptura

Los prefijos vienen de `breakpoints` en `sfcss.config.json` y son
**`min-width`**, de modo que la clase base corresponde a la pantalla más
pequeña y cada prefijo toma el relevo desde su anchura hacia arriba:

| Prefijo | A partir de |
|---|---|
| *(ninguno)* | cualquier anchura |
| `sm:` | 480px |
| `md:` | 768px |
| `lg:` | 1024px |
| `xl:` | 1280px |

```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<div class="p-4 md:p-6 lg:p-8">
<h1 class="text-2xl md:text-4xl">
<div class="w-full md:w-1/2">
<nav class="d-none md:d-flex">
```

**No todas las utilidades tienen variante responsiva** — solo las de
disposición:

- `grid-cols-*`
- display (`block`, `flex`, `grid`, `none`, y las formas `d-`)
- dirección y ajuste de flex
- espaciado: `p`, `px`, `py`, `m`, `mx`, `my`, `gap`
- tamaños de fuente
- anchuras fraccionarias

Generar todas las utilidades en todos los puntos de ruptura multiplicaría la
hoja varias veces por clases que nadie escribe de forma responsiva.

---

## Las utilidades de un vistazo

La lista completa está en la
[referencia de utilidades](SFCSS_UTILITIES.md). Un resumen:

| Grupo | Cubre |
|---|---|
| Espaciado | `m-*` y `p-*` en cada lado, más `gap-*` |
| Tamaño | `w-*` / `h-*` en rem, fracciones, porcentajes y píxeles arbitrarios |
| Disposición | display, flexbox, rejilla |
| Tipografía | 8 tamaños, 4 pesos, alineación, transformación, decoración |
| Colores | 20 familias × 10 tonos × `bg`/`text`/`border` |
| Efectos | bordes, redondeo, sombras, opacidad |
| Posición | static, relative, absolute, fixed, sticky |
| Desbordamiento y cursor | comportamiento de desplazamiento y formas del puntero |

### Espaciado

```html
<div class="m-4 mt-8 mb-12 mx-auto">Espaciado externo</div>
<div class="p-4 px-6 py-8">Espaciado interno</div>
```

### Tamaño

```html
<div class="h-12">3rem de alto</div>
<div class="w-full md:w-1/2">Anchura responsiva</div>
<div class="w-[75%]">75% de ancho</div>
<div class="h-[48px] w-[96px]">Píxeles concretos</div>
```

### Tipografía

```html
<p class="text-sm">Pequeño</p>
<p class="text-base">Normal</p>
<h1 class="text-3xl font-bold">Título grande</h1>
<p class="text-center uppercase">Centrado y en mayúsculas</p>
```

### Colores

Veinte familias, diez tonos cada una:

```
slate  gray   zinc     blue    indigo
purple pink   red      orange  amber
yellow lime   green    emerald teal
cyan   sky    violet   fuchsia rose
```

```html
<p class="text-blue-500">Azul medio</p>
<div class="bg-slate-100 text-slate-900 p-4">Fondo claro, texto oscuro</div>
<button class="border border-red-600">Borde rojo</button>
```

### Disposición

```html
<div class="d-flex justify-center items-center gap-4">
  <div>Elemento 1</div>
  <div>Elemento 2</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <div class="card">Tarjeta 1</div>
  <div class="card">Tarjeta 2</div>
</div>
```

### Posición y bordes

```html
<div class="relative">Relativo</div>
<div class="absolute">Absoluto</div>
<div class="fixed">Fijo</div>

<div class="border border-blue-500 rounded-lg">Con borde y redondeado</div>
<div class="shadow-lg rounded-full">Con sombra y circular</div>
```

---

## Variables CSS

Los colores del tema, la escala de espaciado y los ajustes tipográficos son
propiedades personalizadas de CSS, así que se pueden sobrescribir sin
reconstruir:

```css
/* Colores del tema */
--primary: #0066cc;
--secondary: #6c757d;
--success: #28a745;
--danger: #dc3545;
--warning: #ffc107;
--info: #17a2b8;
--light: #f8f9fa;
--dark: #343a40;

/* Espaciado */
--xs: 0.25rem;
--sm: 0.5rem;
--md: 1rem;
--lg: 1.5rem;
--xl: 3rem;

/* Tipografía */
--font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
--font-size-base: 1rem;
--font-size-sm: 0.875rem;
--font-size-lg: 1.125rem;
```

### Sobrescribir

```html
<style>
  :root {
    --primary: #ff0000;
    --lg: 2rem;
  }
</style>

<button class="btn btn-primary">Ahora rojo</button>
```

Sobrescribir una variable cambia los componentes que la usan. Cambiar una
**paleta** — `blue-500` y similares — exige editar la configuración y
reconstruir, porque esas se generan como valores literales y no como
referencias a variables.

---

## Buenas prácticas

1. **Empieza por los componentes.** `.btn`, `.card`, `.alert` y los elementos
   de formulario cubren la mayor parte de una página antes de escribir ninguna
   utilidad.
2. **Recurre a las utilidades para ajustar**, no para reconstruir: `p-3 mt-2
   text-center` es mejor que una nueva clase de componente.
3. **Baja a CSS puro** cuando un diseño supere lo que expresan las utilidades.
   Eso no es un fracaso del enfoque; una disposición única pertenece a un CSS
   único.
4. **Escribe primero la clase base y después los puntos de ruptura.** Los
   prefijos son `min-width`, así que el valor sin prefijo es el que reciben las
   pantallas pequeñas.
5. **Mantén el marcado semántico.** `<button>`, `<form>`, `<table>` y `<nav>`
   reciben estilo como lo que son, y un lector de pantalla depende de ellos.

---

## Tamaño y compatibilidad

| | |
|---|---|
| Clases en total | **2.337** |
| — utilidades base y componentes | 1.209 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Clases de color | 620 |
| En crudo | 110KB |
| Minificado | 92KB |
| **Comprimido** | **16,1KB** |
| Dependencias | ninguna |
| JavaScript | ninguno |

Funciona en todos los navegadores actuales. Se sirve como archivo estático, así
que el navegador lo cachea como cualquier otro recurso.

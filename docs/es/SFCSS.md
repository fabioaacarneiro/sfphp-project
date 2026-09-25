# SFCSS — Simple Framework CSS

> Verificado contra la hoja de estilos construida · 3.836 clases · 33,1KB comprimidos
>
> 🌍 Disponible también en [English](../en/SFCSS.md) y
> [Português](../pt-BR/SFCSS.md).

Un framework CSS **sin dependencias**, generado por PHP a partir de un archivo
de configuración. Incluye componentes (botones, formularios, navegación,
modales, menús desplegables, toasts…) y clases de utilidad (`p-3`,
`md:grid-cols-2`, `text-center`…). Describes el diseño una sola vez, en la
configuración, y el constructor deriva de ahí todo lo demás: el texto que sigue
siendo legible sobre cada color, los tonos de hover, los fondos pálidos, el
tema oscuro y cada variante de cada componente.

Lista completa de clases: [referencia de utilidades](SFCSS_UTILITIES.md).

---

## Contenido

- [Instalación](#instalación)
- [Cómo está organizado](#cómo-está-organizado)
- [Personalización](#personalización)
- [Temas: claro, oscuro y automático](#temas-claro-oscuro-y-automático)
- [Accesibilidad](#accesibilidad)
- [Componentes](#componentes)
- [Utilidades](#utilidades)
- [Variables CSS](#variables-css)
- [Actualizar desde 0.27](#actualizar-desde-027)
- [Tamaño y compatibilidad](#tamaño-y-compatibilidad)

---

## Instalación

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

`sfcss.css` es la versión legible de la misma hoja de estilos. No hace falta
nada más — ningún paso de compilación en el navegador, ninguna tipografía ni
icono traídos de un CDN. La
hoja de estilos se distribuye en el paquete, en `resources/assets/css`;
`create-project` y `./sfphp serve` la copian a `public/assets/`, y
`./sfphp assets:publish` lo hace bajo demanda.

Los componentes interactivos (modal, menú desplegable, tooltip, pestañas,
toasts) reciben su estilo de SFCSS y su comportamiento de SFJS; ese
comportamiento está documentado en la sección de SFJS de la
[documentación](DOCUMENTATION.md).

---

## Cómo está organizado

```
tools/css-builder/
├── sfcss.config.json      design decisions: colours, scales, options
├── sfcss-base.css         reset, accessibility, typography, components
└── sfcss-builder.php      reads the config and writes the stylesheet
```

La hoja de estilos se emite en el orden de la cascada:

1. **Tokens** — variables CSS en `:root`, y sus valores del tema oscuro.
2. **Contenedor** — anchuras a partir de los puntos de ruptura.
3. **Reset y base de accesibilidad** — resets puntuales, `:focus-visible`,
   `[hidden]`, `.visually-hidden`, tipografía y algunas ayudas de disposición
   (`text-truncate`, `line-clamp`, `sticky-top`, `ratio`…).
4. **Componentes** — que leen variables, nunca colores.
5. **Colores de rol y variantes por color** — generados para cada color de
   `config.colors` (`text-primary`, `bg-primary-subtle`, `btn-primary`…).
6. **Paleta** — 20 familias de color × 10 tonos, con variantes `hover:` y
   puntos de degradado.
7. **Utilidades** — expandidas a partir del mapa de utilidades, con variantes
   de estado, de punto de ruptura y de impresión.

Los colores y las utilidades van después de los componentes a propósito: `m-0`
o `bg-blue-50` escrito sobre una `.card` gana sin `!important`.

### Reconstruir

```bash
./sfphp css:build [--config=path] [--output=dir]
php tools/css-builder/sfcss-builder.php [config.json] [output directory]
```

En este repositorio, `css:build` escribe en `resources/assets/css` (ejecuta
`./sfphp assets:publish` después); en un proyecto que instaló el framework,
escribe en `public/assets/css`. El constructor escribe `sfcss.css` y
`sfcss.min.css`, y muestra una advertencia por todo aquello de lo que conviene
enterarse — un color sobre el que ningún texto se puede leer, dos utilidades
que producen la misma clase.

---

## Personalización

### La configuración de un proyecto contiene solo lo que cambia

Un proyecto mantiene su propio `sfcss.config.json` — en la raíz del proyecto o
en `tools/css-builder/sfcss.config.json`, o indicado con `--config=`. Se **fusiona sobre los valores por defecto**, mapa
por mapa, así que contiene solo lo que el proyecto cambia:

```json
{
  "colors": { "primary": "#7c3aed", "brand": "#0f766e" },
  "options": { "prefix": "sf", "hoverVariants": false }
}
```

Los mapas se fusionan clave por clave; una lista o un escalar sustituye lo que
sobrescribe. Un mapa vacío (`"colorPalettes": {}`) sustituye el valor por
defecto por nada.

### Qué contiene la configuración

| Clave | Qué decide |
|---|---|
| `colors` | Los colores de rol — `primary`, `secondary`, `success`, `danger`, `warning`, `info`, `light`, `dark`, `white`, `black` — y los que añadas |
| `colorPalettes` | Las 20 familias fijas de la paleta, 10 tonos cada una |
| `contrast` | Los dos colores de texto entre los que elige el constructor (`light`, `dark`) |
| `surfaces` | Fondo de la página, superficies elevadas y hundidas, bordes y texto del cuerpo — para `light` y para `dark` |
| `spacing` | La escala detrás de margin, padding, gap y `space-*` |
| `sizing` | La escala detrás de width, height, `min-h-*`, `max-h-*` y `size-*` (`min-w-*` y `max-w-*` tienen listas fijas) |
| `typography` | Familias tipográficas, la escala de tamaños de fuente, el interlineado, los pesos |
| `radii` | La escala de redondeo (`DEFAULT` es el `.rounded` sin más) |
| `border`, `shadows`, `transition` | Anchura, color y redondeo del borde (`--border-radius`), las tres sombras, la transición por defecto |
| `breakpoints` | `sm`, `md`, `lg`, `xl` — el contenedor, cada clase `md:` y cada componente responsivo los leen |
| `options` | Funcionalidades y comportamiento, más abajo |
| `utilities` | Añadidos y eliminaciones en el mapa de utilidades, más abajo |

### Opciones

| Opción | Por defecto | Efecto |
|---|---|---|
| `prefix` | `""` | Antepone un prefijo a cada variable CSS: `"sf"` convierte `--primary` en `--sf-primary`, para páginas que cargan otra hoja de estilos con su propio `--primary`. Los nombres de clase no cambian; las variables fijadas en un atributo `style` también llevan el prefijo (`--sf-card-padding-x`). `--sf-anchor`, que SFJS escribe en los popovers, conserva su nombre |
| `components` | `true` | `false` elimina los componentes y sus variantes por color; el reset, la base de accesibilidad, la tipografía, las ayudas de disposición, los colores de rol, la paleta y las utilidades se mantienen |
| `rounded` | `true` | `false` pone a 0 todas las variables de redondeo, así que los componentes y las clases `rounded-*` quedan en ángulo recto (`rounded-full` sigue siendo redondo) |
| `shadows` | `true` | `false` pone a `none` todas las variables de sombra (las clases `shadow-*` y los componentes que las leen) |
| `transitions` | `true` | `false` pone `--transition` (la clase `transition`) a `none`; los componentes conservan sus propias transiciones cortas, que es `reducedMotion` lo que detiene |
| `reducedMotion` | `true` | Respeta el ajuste «reducir movimiento» de quien lee (véase [Accesibilidad](#accesibilidad)) |
| `darkMode` | `true` | Emite el tema oscuro |
| `hoverVariants` | `true` | Emite las clases `hover:` |
| `responsiveVariants` | `true` | Emite las clases `sm:` `md:` `lg:` `xl:` |
| `importantUtilities` | `false` | Añade `!important` a cada clase generada a partir del mapa de utilidades (no a la paleta, los colores de rol ni las ayudas de disposición) |
| `minContrast` | `4.5` | La relación de contraste que significa «legible» — WCAG AA para el texto del cuerpo |
| `gradientShades` | `["500","600","700"]` | Qué tonos de la paleta reciben clases `from-*` y `to-*` |

### Los colores, y todo lo que se deriva de ellos

Para cada color de `colors` — salvo `white` y `black`, que reciben solo
`--white`, `--white-rgb` y las clases `text-`, `bg-` y `border-` — el
constructor calcula, en ambos temas:

| Variable | Qué es |
|---|---|
| `--primary` | El color |
| `--primary-rgb` | Sus canales, para `rgb(var(--primary-rgb) / 0.5)` |
| `--primary-contrast` | El texto que sigue siendo legible sobre él |
| `--primary-hover`, `--primary-active` | Los tonos de un botón al pasar el puntero y al pulsarlo |
| `--primary-subtle` | Un fondo pálido — alertas, toasts, elementos de lista |
| `--primary-border` | El borde que lo acompaña |
| `--primary-emphasis` | Texto que se lee sobre el fondo pálido |
| `--primary-text` | El color como texto sobre la página — oscurecido solo lo necesario para ser legible |

«Legible» se calcula, no se espera. Sobre un color se prefiere el texto blanco,
que solo pierde frente al oscuro cuando quedaría por debajo de `minContrast`;
un color sobre el que ninguno de los dos lo alcanza se informa al construir la
hoja de estilos. La forma de texto de `warning`, por ejemplo, es el mismo tono
oscurecido hasta que se lee sobre blanco.

Y cada color recibe sus clases, sin CSS escrito a mano:

```
.text-brand  .bg-brand  .border-brand       .text-bg-brand     .link-brand
.bg-brand-subtle  .border-brand-subtle  .text-brand-emphasis
.btn-brand  .btn-outline-brand  .badge-brand  .badge-brand-subtle
.alert-brand  .toast-brand  .list-group-item-brand  .table-brand
.progress-bar-brand  .spinner-brand
```

### El mapa de utilidades

La mayoría de las clases de utilidad salen de un único mapa en el constructor
(la paleta, los degradados, los colores de rol y el contenedor se generan
directamente a partir de la configuración, y unas pocas ayudas viven en
`sfcss-base.css`). Una entrada dice qué propiedad fija la clase, a partir de qué valores, y qué variantes
recibe:

```json
{
  "utilities": {
    "tab-size": {
      "class": "tab",
      "property": "tab-size",
      "values": { "2": "2", "4": "4", "8": "8" },
      "responsive": true
    },
    "cursor": false
  }
}
```

Eso añade `.tab-2`, `.tab-4`, `.tab-8` con `md:tab-4` y los demás puntos de
ruptura, y elimina todas las clases `cursor-*`.

| Campo | Significado |
|---|---|
| `class` | El prefijo: `"m"` da `m-3`. `""` usa solo el nombre del valor (`"flex"`, `"italic"`) |
| `property` | Una propiedad CSS, o una lista para clases que fijan varias |
| `values` | Un mapa de nombre → valor, o `"$spacing"`, `"$sizing"`, `"$radii"`, `"$fontSizes"` para leer una escala de la configuración. Un valor llamado `DEFAULT` da la clase sin sufijo (`.rounded`) |
| `extra` | Más valores, añadidos a una escala |
| `responsive` | `true` para variantes de punto de ruptura de todos los valores, o una lista de los nombres de valor que las reciben |
| `states` | Variantes de pseudoclase, p. ej. `["hover", "focus"]` — todas se omiten cuando `options.hoverVariants` es `false` |
| `print` | Emite también una variante `print:` |
| `selector` | Un patrón para clases que no son un simple `.nombre` — `"{class} > * + *"` es como está escrito `space-y-*` |

Una entrada con el nombre de una incorporada se fusiona con ella, así que
`"opacity": { "responsive": true }` da `md:opacity-50` sin repetir los valores.
`false` la elimina.

Los nombres de las entradas no siempre son el prefijo de la clase. Las
incorporadas:

| Entrada | Clases |
|---|---|
| `margin`, `margin-t`, `margin-b`, `margin-l`, `margin-r`, `margin-x`, `margin-y`, `margin-s`, `margin-e` | `m-*`, `mt-*`, `mb-*`, `ml-*`, `mr-*`, `mx-*`, `my-*`, `ms-*`, `me-*` |
| `padding`, `padding-t` … `padding-e` | `p-*`, `pt-*` … `pe-*` |
| `gap`, `gap-x`, `gap-y`, `space-y`, `space-x` | `gap-*`, `gap-x-*`, `gap-y-*`, `space-y-*`, `space-x-*` |
| `display`, `display-bare` | `d-*`; `block`, `flex`, `hidden`… |
| `flex-direction`, `flex-wrap`, `flex`, `flex-grow`, `flex-shrink` | `flex-row`…, `flex-wrap`…, `flex-1`…, `flex-grow-*`, `flex-shrink-*` |
| `justify`, `justify-items`, `items`, `content`, `self`, `place`, `order` | `justify-*`, `justify-items-*`, `items-*`, `content-*`, `self-*`, `place-*`, `order-*` |
| `grid-cols`, `grid-auto`, `col-span`, `col-start`, `row-span` | `grid-cols-*`, `grid-auto-*`, `col-span-*`, `col-start-*`, `row-span-*` |
| `width`, `height`, `min-width`, `max-width`, `min-height`, `max-height`, `size` | `w-*`, `h-*`, `min-w-*`, `max-w-*`, `min-h-*`, `max-h-*`, `size-*` |
| `font-size`, `text-align`, `font-weight`, `font-family`, `font-style` | `text-{size}`, `text-{align}`, `font-{weight}`, `font-sans`/`font-mono`, `italic`/`not-italic` |
| `leading`, `tracking`, `text-decoration`, `underline-offset`, `text-transform`, `whitespace`, `word-break`, `text-wrap`, `vertical-align` | `leading-*`, `tracking-*`, `underline`…, `underline-offset-*`, `uppercase`…, `whitespace-*`, `break-*`, `text-balance`…, `align-*` |
| `opacity`, `shadow`, `rounded`, `rounded-corners`, `transition` | `opacity-*`, `shadow-*`, `rounded-*`, `rounded-t-*`…, `transition` |
| `border`, `border-side`, `border-width`, `border-colour`, `background` | `border`, `border-t`…, `border-2`…, `border-transparent`/`current`, `bg-transparent`/`current` |
| `position`, `inset`, `top`, `bottom`, `start`, `end`, `z` | `relative`…, `inset-*`, `top-*`, `bottom-*`, `start-*`, `end-*`, `z-*` |
| `overflow`, `overflow-x`, `overflow-y`, `visibility`, `object-fit`, `aspect`, `cursor`, `pointer-events`, `select` | `overflow-*`…, `visible`/`invisible`, `object-*`, `aspect-*`, `cursor-*`, `pointer-events-*`, `select-*` |

---

## Temas: claro, oscuro y automático

El tema oscuro es **opt-in**. Una página dice lo que quiere en el elemento raíz:

```html
<html data-theme="dark">    <!-- siempre oscuro -->
<html data-theme="light">   <!-- siempre claro -->
<html data-theme="auto">    <!-- sigue el ajuste del sistema de quien lee -->
```

No decir nada deja el tema claro, de modo que ninguna página cambia sin que
nadie lo pida.

Lo que cambia entre temas son los **roles**, no los colores: las superficies,
el texto del cuerpo y los `-subtle`, `-border`, `-emphasis` y `-text` de cada
color. Las alertas, tablas, formularios, tarjetas, etiquetas, grupos de lista y
toasts leen esos valores, así que siguen el tema sin escribir nada para ello.

Las clases de paleta (`bg-blue-50`, `text-slate-600`) mantienen su valor en
ambos temas a propósito — `bg-blue-50` es un color, no un rol. Un diseño que
deba seguir el tema usa las clases de rol: `bg-primary-subtle`,
`text-primary-emphasis`, `bg-body-raised`, `text-body`, `text-muted`.

---

## Accesibilidad

Incorporada, no opcional:

- **Foco de teclado visible.** `:focus-visible` dibuja un contorno solo para
  quien usa el teclado — un clic del ratón no lo activa, así que nadie tiene
  motivo para quitarlo. Los campos de formulario dibujan un anillo de foco y
  mantienen un contorno transparente, que el modo de alto contraste de Windows
  pinta con el color del sistema.
- **Colores legibles.** El texto sobre un color se elige para alcanzar
  `minContrast` (WCAG AA, 4,5:1). Los valores por defecto de `primary`,
  `success`, `danger` e `info` son los tonos que admiten texto blanco con AA.
- **Movimiento reducido.** Con el ajuste «reducir movimiento» de quien lee
  activado, las animaciones y transiciones se reducen casi a cero (siguen
  disparando sus eventos de finalización para el código que los espera). Los
  spinners siguen girando, despacio: uno que se detiene parece una página
  congelada.
- **Texto para lectores de pantalla.** `.visually-hidden` oculta visualmente y
  mantiene el texto para las tecnologías de apoyo; `.visually-hidden-focusable`
  se muestra al recibir el foco, para los enlaces de salto. `.skip-link` es uno
  listo para usar: `<a class="skip-link" href="#main">`.
- **`[hidden]` significa oculto**, sea cual sea el `display` que una clase dé al
  elemento.
- **Estado a partir de ARIA.** La pestaña, página o enlace actual recibe su
  estilo de `aria-selected="true"` y `aria-current="page"`, una región ocupada
  de `aria-busy="true"`, un campo no válido de `aria-invalid="true"` — así, el
  marcado que le dice la verdad a un lector de pantalla es también el marcado
  que se ve bien.
- **Idiomas de derecha a izquierda.** Los componentes usan propiedades lógicas
  (`margin-inline-start`, `text-align: start`), así que una página con
  `dir="rtl"` se refleja sin una segunda hoja de estilos. Las utilidades vienen
  en las dos formas: `ms-3` / `me-3` (inicio/fin) junto a `ml-3` / `mr-3`
  (izquierda/derecha).

---

## Componentes

Cada componente lee sus propias variables, que una variante fija y que una
página también puede fijar — sin sobrescribir ningún selector:

```html
<div class="card" style="--card-padding-x: 2rem; --card-radius: 0">…</div>
<button class="btn btn-primary" style="--btn-padding-x: 2.5rem">Wide</button>
```

### Botones

```html
<button class="btn btn-primary">Primary</button>
<button class="btn btn-outline-primary">Outline</button>
<button class="btn btn-link">Link</button>
<button class="btn">Plain</button>

<button class="btn btn-primary btn-sm">Small</button>
<button class="btn btn-primary btn-lg">Large</button>

<button class="btn btn-primary" disabled>Disabled</button>
<a class="btn btn-primary" aria-disabled="true">Disabled link</a>
<button class="btn btn-secondary" aria-pressed="true">Toggled</button>
```

Cada color tiene `btn-{colour}` y `btn-outline-{colour}`. El borde siempre está
ahí — transparente cuando una variante no lo tiene — así que un botón junto a
un campo de texto tiene la misma altura.

**Grupos y barras de herramientas:**

```html
<div class="btn-group" role="group" aria-label="Alignment">
  <button class="btn btn-outline-primary">Left</button>
  <button class="btn btn-outline-primary">Centre</button>
  <button class="btn btn-outline-primary">Right</button>
</div>

<!-- Botones de opción con aspecto de grupo de botones -->
<div class="btn-group" role="group">
  <input type="radio" class="btn-check" name="view" id="v1" checked>
  <label class="btn btn-outline-secondary" for="v1">List</label>
  <input type="radio" class="btn-check" name="view" id="v2">
  <label class="btn btn-outline-secondary" for="v2">Grid</label>
</div>

<div class="btn-toolbar">…groups…</div>
```

`btn-group-vertical`, `btn-group-sm` y `btn-group-lg` hacen lo que su nombre
indica.

**Botón de cierre** — la cruz es una máscara, así que toma el color del texto
que la rodea:

```html
<button class="btn-close" aria-label="Close"></button>
<button class="btn-close btn-close-white" aria-label="Close"></button>
```

### Formularios

Reciben estilo **por clase**. Un `<input>` sin clase solo hereda la tipografía
de la página, de modo que las casillas, los widgets de terceros y los campos
ocultos quedan intactos.

```html
<div class="form-group">
  <label class="form-label" for="email">Email</label>
  <input class="form-control" id="email" type="email" placeholder="you@example.com">
  <div class="form-text">We never share it.</div>
</div>

<select class="form-select"><option>Brazil</option></select>
<textarea class="form-control" rows="4"></textarea>
<input class="form-control" type="file">
<input class="form-control form-control-color" type="color" value="#2563eb">
<input class="form-range" type="range">

<input class="form-control form-control-sm"> <input class="form-control form-control-lg">
<input class="form-control-plaintext" readonly value="Shown as text">
```

**Casillas, botones de opción e interruptores:**

```html
<div class="form-check">
  <input class="form-check-input" type="checkbox" id="terms">
  <label class="form-check-label" for="terms">I agree</label>
</div>

<div class="form-check">
  <input class="form-check-input" type="radio" name="plan" id="p1" checked>
  <label class="form-check-label" for="p1">Monthly</label>
</div>

<div class="form-check form-switch">
  <input class="form-check-input" type="checkbox" role="switch" id="alerts">
  <label class="form-check-label" for="alerts">Email alerts</label>
</div>
```

`form-check-inline` pone varios en una misma línea. El estado indeterminado
(`input.indeterminate = true`) se dibuja como un guion.

**Grupos de campos:**

```html
<div class="input-group">
  <span class="input-group-text">$</span>
  <input class="form-control" aria-label="Amount">
  <button class="btn btn-outline-secondary">Apply</button>
</div>
```

**Etiquetas flotantes** — el campo necesita un placeholder; basta con un
espacio:

```html
<div class="form-floating">
  <input class="form-control" id="name" placeholder=" ">
  <label for="name">Full name</label>
</div>
```

**Validación.** Tres orígenes, un mismo aspecto:

```html
<!-- El servidor marca el campo -->
<input class="form-control is-invalid" aria-describedby="name-error">
<div class="invalid-feedback" id="name-error">Use at least 3 characters.</div>

<!-- SFJS fija aria-invalid="true" y rellena el mensaje -->

<!-- O las restricciones propias del navegador, una vez que el usuario ha tocado el campo -->
<input class="form-control" required minlength="3">
```

`.is-valid` / `.valid-feedback` son sus equivalentes positivos; `.was-validated`
en un formulario muestra de una vez el veredicto del navegador sobre cada
campo. Un mensaje sin contenido no se muestra, así que el elemento se puede
renderizar siempre.

### Tarjetas

```html
<div class="card">
  <img class="card-img-top" src="…" alt="…">
  <div class="card-header"><h3>Title</h3></div>
  <div class="card-body">
    <h5 class="card-title">Card title</h5>
    <p class="card-subtitle">Subtitle</p>
    <p class="card-text">Content.</p>
    <a class="card-link" href="#">Link</a>
  </div>
  <div class="card-footer">Footer</div>
</div>
```

Variables: `--card-padding-x`, `--card-padding-y`, `--card-radius`, `--card-bg`,
`--card-border-color`, `--card-cap-bg`.

### Etiquetas

```html
<span class="badge">Default</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-danger-subtle">Pale</span>
<span class="badge badge-success badge-pill">Pill</span>
```

### Alertas

```html
<div class="alert alert-warning" role="alert">
  <h4 class="alert-heading">Check your details</h4>
  Your card expires this month. <a class="alert-link" href="#">Update it</a>.
</div>

<div class="alert alert-success alert-dismissible" role="alert">
  Saved.
  <button class="btn-close" @dismiss aria-label="Close"></button>
</div>
```

El fondo, el texto y el acento salen del `-subtle`, el `-emphasis` y el propio
color, así que cada alerta es legible en ambos temas.

### Tablas

Reciben estilo por clase, como los formularios:

```html
<div class="table-responsive">
  <table class="table table-striped table-hover">
    <caption>Users</caption>
    <thead><tr><th>Name</th><th>Role</th></tr></thead>
    <tbody>
      <tr><td>Ana</td><td>Admin</td></tr>
      <tr class="table-danger"><td>Bruno</td><td>Suspended</td></tr>
    </tbody>
  </table>
</div>
```

| Clase | Efecto |
|---|---|
| `table-striped` | Filas alternas sombreadas |
| `table-hover` | La fila bajo el puntero, sombreada |
| `table-bordered` / `table-borderless` | Todas las celdas con borde / sin bordes |
| `table-sm` | Celdas compactas |
| `table-align-middle` | Celdas centradas verticalmente |
| `table-{colour}` | En una tabla, fila o celda |
| `table-responsive`, `table-responsive-{bp}` | Desplazamiento lateral (por debajo de un punto de ruptura) en lugar de ensanchar la página |

Las rayas y el hover pintan una capa sobre la celda, así que una fila
`table-danger` se sigue viendo a través de ellas.

### Navegación y pestañas

```html
<ul class="nav nav-tabs">
  <li><a class="nav-link" aria-current="page" href="/">Overview</a></li>
  <li><a class="nav-link" href="/settings">Settings</a></li>
  <li><a class="nav-link" aria-disabled="true">Billing</a></li>
</ul>
```

`nav-tabs`, `nav-pills` y `nav-underline` son tres aspectos; `nav-fill` y
`nav-justified` reparten los elementos; `nav-vertical` los apila. El elemento
actual es el marcado con `.active`, `aria-current="page"` o
`aria-selected="true"`. Las pestañas interactivas — `role="tablist"`, teclas de
flecha, paneles — son el `@tabs` de SFJS.

### Barra de navegación

```html
<nav class="navbar navbar-expand-md">
  <a class="navbar-brand" href="/">SFPHP</a>
  <button class="navbar-toggler" @toggle="#menu" aria-label="Menu"></button>
  <div class="navbar-collapse" id="menu" hidden>
    <ul class="navbar-nav">
      <li><a class="nav-link" aria-current="page" href="/">Home</a></li>
      <li><a class="nav-link" href="/docs">Docs</a></li>
    </ul>
  </div>
</nav>
```

Apilada por debajo del punto de ruptura, en fila a partir de él; `navbar-expand`
es una fila en cualquier anchura. La parte colapsable se muestra por encima del
punto de ruptura aunque tenga `hidden`. `navbar-dark` es la versión de texto
claro sobre fondo oscuro; `--navbar-bg` y las demás variables `--navbar-*` la
ajustan.

### Migas de pan y paginación

```html
<nav aria-label="Breadcrumb">
  <ol class="breadcrumb" style="--breadcrumb-divider: '›'">
    <li class="breadcrumb-item"><a href="/">Home</a></li>
    <li class="breadcrumb-item" aria-current="page">Settings</li>
  </ol>
</nav>

<nav aria-label="Pages">
  <ul class="pagination">
    <li class="page-item disabled"><a class="page-link">Previous</a></li>
    <li class="page-item"><a class="page-link" href="?page=1">1</a></li>
    <li class="page-item"><a class="page-link" aria-current="page" href="?page=2">2</a></li>
    <li class="page-item"><a class="page-link" href="?page=3">Next</a></li>
  </ul>
</nav>
```

`pagination-sm` y `pagination-lg` cambian el tamaño.

### Grupo de lista

```html
<ul class="list-group">
  <li class="list-group-item" aria-current="true">Active</li>
  <li class="list-group-item">Second</li>
  <li class="list-group-item list-group-item-warning">Needs attention</li>
</ul>

<div class="list-group">
  <a class="list-group-item list-group-item-action" href="#">A link item</a>
  <button class="list-group-item list-group-item-action">A button item</button>
</div>
```

También `list-group-flush` (sin borde exterior, para usar dentro de una
tarjeta), `list-group-numbered` y `list-group-horizontal`.

### Progreso y spinners

```html
<div class="progress" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100">
  <div class="progress-bar" style="--value: 40%">40%</div>
</div>
<div class="progress"><div class="progress-bar progress-bar-success progress-bar-striped progress-bar-animated" style="width: 70%"></div></div>

<span class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></span>
<span class="spinner-grow spinner-primary spinner-grow-sm" role="status"></span>
```

`--progress-height` y `--spinner-size` los ajustan.

### Acordeón

Construido sobre `<details>` — se abre y se cierra sin JavaScript, todos los
lectores de pantalla lo anuncian como expandible y la búsqueda en la página lo
abre. Los elementos con el mismo `name` se cierran entre sí:

```html
<div class="accordion">
  <details class="accordion-item" name="faq" open>
    <summary class="accordion-header">Is there a free plan?</summary>
    <div class="accordion-body">Yes, for up to three users.</div>
  </details>
  <details class="accordion-item" name="faq">
    <summary class="accordion-header">Can I cancel?</summary>
    <div class="accordion-body">At any time.</div>
  </details>
</div>
```

### Menús desplegables, popovers y tooltips

Los tres son popovers nativos: el navegador los pone en la capa superior (nunca
recortados por una tarjeta, sin `z-index` que gestionar), los cierra con Escape
o con un clic fuera y devuelve el foco. SFJS vincula cada uno a su botón; donde
existe el posicionamiento por anclaje de CSS, es CSS quien lo coloca, y en los
demás casos SFJS calcula la posición. No interviene ninguna biblioteca de
posicionamiento.

```html
<button class="btn btn-secondary dropdown-toggle" popovertarget="account">Account</button>
<div class="dropdown-menu" id="account" popover role="menu">
  <span class="dropdown-header">Signed in as Ana</span>
  <a class="dropdown-item" role="menuitem" href="/profile">Profile</a>
  <hr class="dropdown-divider">
  <button class="dropdown-item" role="menuitem">Sign out</button>
</div>

<button class="btn btn-outline-primary" popovertarget="help">Help</button>
<div class="popover" id="help" popover>
  <div class="popover-header">About this field</div>
  <div class="popover-body">Used only for delivery.</div>
</div>

<button class="btn" @tooltip="Copy to clipboard" aria-label="Copy">📋</button>
```

`dropdown-menu-end` alinea un menú con el final de su botón.
`@tooltip-placement="bottom|left|right"` coloca un tooltip (arriba es la
posición por defecto): SFJS pone en el tooltip la clase `tooltip-{side}`
correspondiente, que SFCSS usa para posicionarlo. La navegación por teclado de
los menús y el propio tooltip vienen de SFJS (`sfjs.min.js`).

### Modales y offcanvas

Ambos son `<dialog>`. Abiertos con `showModal()` (en SFJS: `@modal="#id"`), el
navegador deja inerte el resto de la página, mantiene el foco dentro, se cierran
con Escape y devuelve el foco a lo que los abrió. La página de detrás no se
desplaza.

```html
<dialog class="modal" id="confirm" aria-labelledby="confirm-title">
  <div class="modal-header">
    <h2 class="modal-title" id="confirm-title">Delete project?</h2>
    <button class="btn-close" @dismiss aria-label="Close"></button>
  </div>
  <div class="modal-body">This cannot be undone.</div>
  <div class="modal-footer">
    <button class="btn" @dismiss>Cancel</button>
    <button class="btn btn-danger">Delete</button>
  </div>
</dialog>

<dialog class="offcanvas offcanvas-end" id="filters" aria-label="Filters">
  <div class="offcanvas-header">
    <h2 class="offcanvas-title">Filters</h2>
    <button class="btn-close" @dismiss aria-label="Close"></button>
  </div>
  <div class="offcanvas-body">…</div>
</dialog>
```

Tamaños: `modal-sm`, `modal-lg`, `modal-xl`, `modal-fullscreen`, o
`--modal-width`. Lados del offcanvas: `offcanvas-start`, `-end`, `-top`,
`-bottom`, con el tamaño dado por `--offcanvas-size`.

### Toasts

`sf.toast('Saved.', { variant: 'success' })` los crea — véase SFJS. La pila es
una región viva, así que cada toast se anuncia. Marcado estático:

```html
<div class="toast-stack">
  <div class="toast toast-success" role="status">
    <div class="toast-body">Saved.</div>
    <button class="btn-close" @dismiss aria-label="Close"></button>
  </div>
</div>
```

### Ayudas de disposición

| Clase | Qué hace |
|---|---|
| `container`, `container-fluid`, `container-{bp}` | Una columna centrada cuyas anchuras son los puntos de ruptura; `-fluid` ocupa siempre todo el ancho; `-md` es fluido por debajo de `md` |
| `grid` + `grid-cols-{1–12}` | Rejilla CSS; `md:grid-cols-3` la cambia por punto de ruptura |
| `grid-auto-fit`, `grid-auto-fill` | Tantas columnas como quepan, sin necesidad de puntos de ruptura; `--grid-min` fija la más estrecha |
| `col-span-{n}`, `col-span-full`, `col-start-{n}` | Dónde se sitúa un elemento en la rejilla |
| `hstack`, `vstack` | Una fila o una columna con separación (`--stack-gap`) |
| `ratio ratio-16x9` | Una caja de forma fija, para iframes y vídeos (`1x1`, `4x3`, `21x9`, o `--ratio`) |
| `stretched-link` | Hace clicable entero el ancestro posicionado más cercano |
| `sticky-top`, `fixed-top`, `fixed-bottom` | Barras fijadas |
| `vr` | Una línea vertical dentro de una fila flex |
| `text-truncate`, `line-clamp` | Una línea con puntos suspensivos; varias (`--lines`) |
| `img-fluid`, `img-thumbnail`, `figure` | Imágenes que se ajustan, enmarcadas, con pie |
| `clearfix` | Contiene los floats |

### Tipografía

Los títulos, párrafos y listas reciben estilo como lo que son. Además: `lead`,
`display-1` … `display-6` (fluidos, se escalan con el viewport), `small`,
`mark`, `blockquote` con `blockquote-footer`, `list-unstyled`, `list-inline`
con `list-inline-item`, y `code`, `kbd`, `pre`.

---

## Utilidades

Margin y padding (`m-3`, `px-4`, `mt-auto`, `ms-2`), gap, anchura y altura,
display, flexbox, rejilla, tipografía, color, bordes, redondeo, sombras,
opacidad, posición, desbordamiento, z-index, object-fit, relación de aspecto,
cursor y más — con variantes `hover:`, de punto de ruptura y `print:` donde
tienen sentido. La lista completa, con valores:
[referencia de utilidades](SFCSS_UTILITIES.md).

**Escribe primero la clase base y después los puntos de ruptura.** Los prefijos
son `min-width`, así que la clase sin prefijo es la que reciben las pantallas
pequeñas:

```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">…</div>
```

---

## Variables CSS

Todo lo que leen los componentes es una variable en `:root`, y se puede fijar
ahí, en una sección o en un solo elemento:

```css
:root {
  --radius: 0.25rem;          /* .rounded, buttons, fields, dropdowns… */
  --surface: #fafafa;         /* the page */
}

.checkout {
  --primary: #047857;
  --primary-rgb: 4 120 87;
}
```

Fijar `--primary` a mano no recalcula `--primary-contrast` ni los demás valores
derivados — eso lo hace el constructor. Para cambiar un color en todo el sitio,
cámbialo en la configuración y reconstruye.

| Grupo | Variables |
|---|---|
| Superficies | `--surface`, `--surface-raised`, `--surface-sunken`, `--surface-border`, `--surface-border-strong`, `--body-color`, `--body-color-muted`, `--code-color` |
| Cada color | `--{c}`, `--{c}-rgb`, `--{c}-contrast`, `--{c}-hover`, `--{c}-active`, `--{c}-subtle`, `--{c}-border`, `--{c}-emphasis`, `--{c}-text` |
| Foco | `--focus-ring`, `--focus-ring-color` |
| Escalas | `--spacing-{n}` (`--spacing-0_5` para el paso 0.5), `--font-size-{name}`, `--font-weight-{name}`, `--radius`, `--radius-{name}`, `--shadow`, `--shadow-sm`, `--shadow-lg`, `--transition` |
| Enganches de disposición | `--container-padding`, `--grid-min`, `--stack-gap`, `--lines`, `--ratio`, `--value` (barra de progreso), `--breadcrumb-divider`, `--gradient-from`, `--gradient-to` |
| Tipografía | `--font-family`, `--font-family-mono`, `--line-height` |
| Bordes | `--border`, `--border-width`, `--border-color`, `--border-radius` |
| Componentes | `--btn-*`, `--card-*`, `--badge-*`, `--alert-*`, `--table-*`, `--nav-link-*`, `--navbar-*`, `--page-*`, `--list-group-*`, `--progress-*`, `--spinner-size`, `--accordion-*`, `--modal-*`, `--offcanvas-size`, `--toast-accent`, `--tooltip-*` |

---

## Actualizar desde 0.27

Lo que cambia para una página escrita contra la hoja de estilos anterior:

| Antes | Ahora |
|---|---|
| Todos los `<input>`, `<select>`, `<textarea>` recibían estilo | Añade `form-control` / `form-select` (y `form-check-input` para las casillas) |
| Todas las `<table>` recibían estilo, y todas las filas se resaltaban al pasar el puntero | Añade `table` (y `table-hover` si quieres el resaltado) |
| `* { margin: 0; padding: 0 }` | Las listas conservan su sangría; los títulos, párrafos y listas reciben un margen inferior |
| `w-3`…`w-8`, `h-3`…`h-8` iban de 1rem a 3rem | Siguen `n × 0.25rem`: `w-8` es 2rem, como ya hacían `w-10`…`w-64` (`w-80` y `w-96` son nuevas) |
| `md:p-3` era 0.75rem mientras `p-3` era 1rem | Ambos son 1rem; cada clase de punto de ruptura lee la misma escala que su clase sin prefijo |
| `sm:` empezaba en 480px, el contenedor en 640px | Ambos en 640px |
| Un bloque max-width forzaba `md:grid-cols-*` a una columna por debajo de 768px y aplicaba `md:p-2` en todas partes | Eliminado — las clases de punto de ruptura son solo `min-width` |
| `primary` `#3b82f6`, `success` `#22c55e`, `danger` `#ef4444`, `info` `#06b6d4` | `#2563eb`, `#15803d`, `#dc2626`, `#0e7490`: los tonos que admiten texto blanco a 4,5:1 |
| `lime` y `emerald` eran colores de otras familias, `orange` estaba desplazado un tono | Corregidos, junto con otros siete pequeños errores en otras familias |
| Variables de espaciado `--xs` … `--xl`, `--font-size-size-2xl` | `--spacing-{n}`, `--font-size-2xl` |

---

## Tamaño y compatibilidad

| | |
|---|---|
| Clases en total | **3.836** |
| — utilidades base y componentes | 2.091 |
| — variantes `hover:` | 628 |
| — variantes de punto de ruptura (`sm` `md` `lg` `xl`) | 1.096 (274 por punto de ruptura) |
| — variantes `print:` | 21 |
| Clases de paleta | 600 (20 familias × 10 tonos × `bg`/`text`/`border`) |
| Variables CSS | 194 |
| En crudo · minificado | 237KB · 195KB |
| **Comprimido** | **33,1KB** |
| Dependencias | ninguna |
| JavaScript | ninguno — los componentes interactivos usan SFJS |

Un proyecto que quiera menos desactiva lo que no usa: `components: false`
ahorra unos 8KB comprimidos, `hoverVariants: false` unos 4,7KB, y un
`colorPalettes` vacío unos 9,5KB.

Funciona en todos los navegadores actuales. El posicionamiento por anclaje
(para menús desplegables y tooltips) y `:user-invalid` se usan donde el
navegador los tiene; en los demás, SFJS posiciona los popovers y la validación
recurre a las clases y los atributos ARIA.

# SFCSS — Simple Framework CSS

> Verified against the built stylesheet · 2,339 classes · 16.4KB gzipped
>
> 🌍 Also available in [Português](../pt-BR/SFCSS.md) and
> [Español](../es/SFCSS.md).

A utility CSS framework with **no dependencies**, generated from a config file.
It combines the "styles plain HTML out of the box" idea of Pico CSS with
Tailwind-style utility classes, so semantic markup looks right before you write
a single class, and utilities are there when you need them.

Full class list: [utilities reference](SFCSS_UTILITIES.md).

---

## Contents

- [Installing](#installing)
- [Building and customising](#building-and-customising)
- [Components](#components)
- [State and responsive variants](#state-and-responsive-variants)
- [Utilities at a glance](#utilities-at-a-glance)
- [CSS variables](#css-variables)
- [Good practice](#good-practice)
- [Size and compatibility](#size-and-compatibility)

---

## Installing

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

Or the minified build:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nothing else — no JavaScript, no build step in the browser, no font or icon
fetched from a CDN.

---

## Building and customising

### The files

```
tools/css-builder/
├── sfcss.config.json      colours, spacing, typography, breakpoints
├── sfcss-base.css         base styles and components
└── sfcss-builder.php      the build script
```

### Rebuilding

After editing either file:

```bash
./sfphp css:build
```

or, equivalently:

```bash
php tools/css-builder/sfcss-builder.php
```

That writes both outputs:

- `resources/assets/css/sfcss.css` — 112KB, readable
- `resources/assets/css/sfcss.min.css` — 94KB, minified (16.4KB gzipped)

### What the builder does

1. **Reads the config** from `sfcss.config.json`
2. **Emits the base stylesheet** from `sfcss-base.css`
3. **Generates the colour utilities** — 620 classes (20 families × 10 shades ×
   `bg`/`text`/`border`)
4. **Generates the `hover:` variants** — 600 classes
5. **Generates the responsive variants** — 528 classes, from the breakpoints in
   the config
6. **Minifies** — strips comments and unnecessary whitespace — and writes
   `sfcss.min.css`

### What to edit

| To change | Edit | Key |
|---|---|---|
| Colours | `sfcss.config.json` | `colorPalettes`, `colors` |
| Spacing scale | `sfcss.config.json` | `spacing` |
| Typography | `sfcss.config.json` | `typography` |
| Breakpoints | `sfcss.config.json` | `breakpoints` |
| Components | `sfcss-base.css` | append your own rules |

Everything generated — colours, `hover:` variants and responsive variants —
comes out of the config. Change a breakpoint there and the whole responsive
layer follows.

---

## Components

### Buttons

```html
<!-- Colour variants -->
<button class="btn">Default</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-info">Info</button>

<!-- Sizes -->
<button class="btn btn-sm">Small</button>
<button class="btn btn-md">Normal</button>
<button class="btn btn-lg">Large</button>

<!-- States -->
<button class="btn" disabled>Disabled</button>

<!-- A link that looks like a button -->
<a href="#" class="btn btn-primary">Link button</a>
```

### Cards

```html
<div class="card">
  <div class="card-body">
    Card content
  </div>
</div>

<div class="card">
  <div class="card-header">
    <h3>Title</h3>
  </div>
  <div class="card-body">
    Main content
  </div>
  <div class="card-footer">
    Footer
  </div>
</div>
```

### Forms

Inputs are styled by element, so plain markup already looks right:

```html
<div class="form-group">
  <label class="form-label">Name</label>
  <input type="text" placeholder="Your name">
</div>

<input type="text" placeholder="Placeholder">
<textarea placeholder="Your message..."></textarea>

<select>
  <option>Option 1</option>
  <option>Option 2</option>
</select>

<label>
  <input type="checkbox">
  I agree to the terms
</label>

<label>
  <input type="radio" name="option">
  Option A
</label>

<input type="text" disabled>
<select disabled>
  <option>Disabled</option>
</select>
```

### Tables

```html
<table>
  <thead>
    <tr>
      <th>Column 1</th>
      <th>Column 2</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Data 1</td>
      <td>Data 2</td>
    </tr>
  </tbody>
</table>
```

### Badges

```html
<span class="badge">Default</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-danger">Danger</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-info">Info</span>
```

Badges have no `secondary` variant — buttons do, badges do not.

### Alerts

```html
<div class="alert">Default alert</div>
<div class="alert alert-primary">Primary alert</div>
<div class="alert alert-success">Success!</div>
<div class="alert alert-danger">Error!</div>
<div class="alert alert-warning">Careful!</div>
<div class="alert alert-info">Information</div>
```

A dismiss button is not part of the framework: closing an alert needs
JavaScript, and SFCSS ships none. Use a plain button and
[SFJS](DOCUMENTATION.md#sfjs), or your own handler.

### Grid layout

```html
<!-- Auto layout -->
<div class="grid">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Fixed columns -->
<div class="grid grid-cols-2">Two columns</div>
<div class="grid grid-cols-3">Three columns</div>
<div class="grid grid-cols-12">Twelve columns</div>

<!-- Responsive -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div class="card">Card</div>
</div>
```

### Container

```html
<div class="container">
  <!-- full width, with padding and a max-width that grows by breakpoint -->
</div>
```

---

## State and responsive variants

### `hover:`

Every colour class has a matching `hover:` variant — 600 in total:

```html
<a class="text-blue-500 hover:text-blue-700">Link</a>
<button class="bg-blue-600 hover:bg-blue-700 text-white">Send</button>
<div class="border-slate-200 hover:border-slate-400">Card</div>
```

### Breakpoints

The prefixes come from `breakpoints` in `sfcss.config.json` and are
**`min-width`**, so the base class is the smallest screen and each prefix takes
over from its width upward:

| Prefix | From |
|---|---|
| *(none)* | every width |
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

**Not every utility has a responsive variant** — only the layout ones:

- `grid-cols-*`
- display (`block`, `flex`, `grid`, `none`, and the `d-` forms)
- flex direction and wrap
- spacing: `p`, `px`, `py`, `m`, `mx`, `my`, `gap`
- font sizes
- fractional widths

Generating every utility at every breakpoint would multiply the stylesheet
several times over for classes nobody writes responsively.

---

## Utilities at a glance

The complete list is in the [utilities reference](SFCSS_UTILITIES.md). A
summary:

| Group | Covers |
|---|---|
| Spacing | `m-*` and `p-*` on every side, plus `gap-*` |
| Sizing | `w-*` / `h-*` in rem, fractions, percentages and arbitrary pixels |
| Layout | display, flexbox, grid |
| Typography | 8 sizes, 4 weights, 2 families, alignment, transform, decoration |
| Colours | 20 families × 10 shades × `bg`/`text`/`border` |
| Effects | borders, radius, shadows, opacity |
| Position | static, relative, absolute, fixed, sticky |
| Overflow and cursor | scroll behaviour and pointer shapes |

### Spacing

```html
<div class="m-4 mt-8 mb-12 mx-auto">Outer spacing</div>
<div class="p-4 px-6 py-8">Inner spacing</div>
```

### Sizing

```html
<div class="h-12">3rem tall</div>
<div class="w-full md:w-1/2">Responsive width</div>
<div class="w-[75%]">75% wide</div>
<div class="h-[48px] w-[96px]">Specific pixels</div>
```

### Typography

```html
<p class="text-sm">Small</p>
<p class="text-base">Normal</p>
<h1 class="text-3xl font-bold">Large heading</h1>
<p class="text-center uppercase">Centred and upper case</p>
<p class="font-mono">Monospace</p>
```

`code`, `pre` and `kbd` are styled by the base sheet, so a snippet needs no
class at all:

```html
<p>Run <code>composer install</code> first.</p>
<pre><code>./sfphp migrate</code></pre>
<p>Press <kbd>Ctrl</kbd> + <kbd>C</kbd> to stop.</p>
```

### Surfaces and the dark theme

Eight variables carry the neutrals, and a `prefers-color-scheme: dark` block
redefines those eight and nothing else — a palette colour means the same thing
in both themes; what changes is the paper it sits on.

| Variable | Used for |
|---|---|
| `--surface` | The page, and a card's own background |
| `--surface-raised` | A card header and footer |
| `--surface-sunken` | `code` and `pre` backgrounds |
| `--surface-border` | Card and divider borders |
| `--surface-border-strong` | A `kbd` outline |
| `--body-color` | Ordinary text |
| `--body-color-muted` | `.text-muted` |
| `--code-color` | Inline `code` |

Anything built on these follows the theme without a second stylesheet — which
is how the framework's own error page and dump screen are written.

### Colours

Twenty families, ten shades each:

```
slate  gray   zinc     blue    indigo
purple pink   red      orange  amber
yellow lime   green    emerald teal
cyan   sky    violet   fuchsia rose
```

```html
<p class="text-blue-500">Mid blue</p>
<div class="bg-slate-100 text-slate-900 p-4">Light ground, dark text</div>
<button class="border border-red-600">Red border</button>
```

### Layout

```html
<div class="d-flex justify-center items-center gap-4">
  <div>Item 1</div>
  <div>Item 2</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <div class="card">Card 1</div>
  <div class="card">Card 2</div>
</div>
```

### Position and borders

```html
<div class="relative">Relative</div>
<div class="absolute">Absolute</div>
<div class="fixed">Fixed</div>

<div class="border border-blue-500 rounded-lg">Bordered and rounded</div>
<div class="shadow-lg rounded-full">Shadowed and circular</div>
```

---

## CSS variables

The theme colours, the spacing scale and the type settings are CSS custom
properties, so they can be overridden without rebuilding:

```css
/* Theme colours */
--primary: #0066cc;
--secondary: #6c757d;
--success: #28a745;
--danger: #dc3545;
--warning: #ffc107;
--info: #17a2b8;
--light: #f8f9fa;
--dark: #343a40;

/* Spacing */
--xs: 0.25rem;
--sm: 0.5rem;
--md: 1rem;
--lg: 1.5rem;
--xl: 3rem;

/* Typography */
--font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
--font-size-base: 1rem;
--font-size-sm: 0.875rem;
--font-size-lg: 1.125rem;
```

### Overriding

```html
<style>
  :root {
    --primary: #ff0000;
    --lg: 2rem;
  }
</style>

<button class="btn btn-primary">Now red</button>
```

Overriding a variable changes the components that use it. Changing a **palette**
— `blue-500` and the like — means editing the config and rebuilding, because
those are generated as literal values rather than variable references.

---

## Good practice

1. **Start with the components.** `.btn`, `.card`, `.alert` and the form
   elements cover most of a page before any utility is written.
2. **Reach for utilities to adjust**, not to rebuild: `p-3 mt-2 text-center`
   beats a new component class.
3. **Drop to plain CSS** when a design goes past what utilities express. That
   is not a failure of the approach; a one-off layout belongs in one-off CSS.
4. **Write the base class first, then the breakpoints.** The prefixes are
   `min-width`, so the unprefixed value is what small screens get.
5. **Keep the markup semantic.** `<button>`, `<form>`, `<table>` and `<nav>`
   are styled as themselves, and a screen reader depends on them.

---

## Size and compatibility

| | |
|---|---|
| Classes in total | **2,339** |
| — base utilities and components | 1,211 |
| — `hover:` variants | 600 |
| — responsive variants (`sm` `md` `lg` `xl`) | 528 |
| Colour classes | 620 |
| Raw | 112KB |
| Minified | 94KB |
| **Gzipped** | **16.4KB** |
| Dependencies | none |
| JavaScript | none |

Works in every current browser. Served as a static file, so the browser caches
it like any other asset.

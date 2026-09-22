# PHPX — Component-Oriented Web Pages

PHPX is a unique feature of SFPHP that lets you build **component-oriented web pages** using **PHP functions** combined with **SFHT templates**.

> `.phpx` = **PHP** functions + **SFHT** templates

## What is PHPX?

PHPX files define reusable components as PHP functions that return rendered HTML. Each function becomes a component that:

- Takes parameters (props)
- Returns SFHT-rendered output
- Can nest other components
- Lives alongside its template file

## Structure

A PHPX page consists of **two files**:

### 1. Template File (`.phpx`)

```
app/components/MyComponent.phpx
```

The template file uses **SFHT syntax** with directives, loops, conditionals, and component composition.

### 2. Compiled PHP File (`.php`)

```
app/components/compiled/MyComponent.php
```

Built automatically by `./sfphp build --phpx`, this file contains the PHP function that renders the template.

## Example: Simple Component

### Template: `app/components/Card.phpx`

```sfht
<div class="card shadow-lg">
  <div class="card-header bg-{{ $variant }}-50">
    <h3 class="text-{{ $variant }}-600 font-bold">{{ $title }}</h3>
  </div>
  <div class="card-body">
    {{ $content }}
  </div>
</div>
```

### Compiled: `app/components/compiled/Card.php`

```php
<?php

function Card($title, $content, $variant = 'blue') {
    ob_start();
    ?>
    <div class="card shadow-lg">
      <div class="card-header bg-<?= $variant ?>-50">
        <h3 class="text-<?= $variant ?>-600 font-bold"><?= $title ?></h3>
      </div>
      <div class="card-body">
        <?= $content ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}
```

### Usage in Views

```php
<?= Card("Welcome", "This is a card component", "green") ?>
```

## Real Example: Postcode Lookup

The framework includes a complete **postcode lookup** example that demonstrates PHPX:

### File Structure

```
app/components/
├── postcode/
│   ├── PostcodePage.phpx          # Main page component
│   ├── layout/
│   │   ├── PageHeader.phpx        # Header component
│   │   └── PageFooter.phpx        # Footer component
│   ├── lookup/
│   │   ├── PostcodeLookup.phpx    # Form component
│   │   ├── Field.phpx            # Form field component
│   │   └── Address.phpx           # Result display component
│   └── explain/
│       └── HowItWorks.phpx        # Info section component
└── compiled/
    └── postcode/
        ├── PostcodePage.php
        ├── layout/
        │   ├── PageHeader.php
        │   └── PageFooter.php
        ├── lookup/
        │   ├── PostcodeLookup.php
        │   ├── Field.php
        │   └── Address.php
        └── explain/
            └── HowItWorks.php
```

### Example: Field Component

**Template: `app/components/postcode/lookup/Field.phpx`**

```sfht
<div class="form-group">
  <label class="form-label font-semibold text-slate-700">{{ $label }}</label>
  <input 
    type="text"
    name="{{ $name }}"
    class="w-full px-3 py-2 border border-slate-300 rounded-md"
    placeholder="{{ $placeholder }}"
    {{ $disabled ? 'disabled' : '' }}
  >
</div>
```

**Usage in PostcodeLookup.phpx:**

```sfht
{{ Field('Postcode', 'postcode', 'Enter UK postcode', false) }}
{{ Field('Address', 'address', 'Address will appear here', true) }}
```

## Building Components

Build all `.phpx` files into compiled PHP:

```bash
./sfphp build --phpx
```

This:
1. Reads all `.phpx` files in `app/components/`
2. Compiles them to PHP functions
3. Saves to `app/components/compiled/`

## Advantages

✅ **Separation of Concerns** — Logic (PHP functions) separate from markup (SFHT)  
✅ **Reusability** — Components are just functions, easy to use anywhere  
✅ **Type Safety** — Use PHP's type hints on component props  
✅ **Testability** — Functions are testable; no JavaScript framework overhead  
✅ **Performance** — Compiled to plain PHP, zero JavaScript needed  
✅ **Full Power** — Access any PHP library, database queries, or helper functions  

## Comparison

| | PHPX | JavaScript Framework |
|---|---|---|
| **Runtime** | Server-side (PHP) | Browser (Node/JavaScript) |
| **Dependencies** | Zero | Hundreds of npm packages |
| **Build Step** | One command | Complex webpack/vite setup |
| **Database Access** | Direct (same server) | API required |
| **SEO** | Native HTML (great) | Requires SSR |
| **Learning Curve** | PHP + SFHT | React/Vue/Angular + build tools |

## Page Routes with PHPX

Define PHPX pages in **`app/routes/web.php`**:

```php
Router::get("/", "MainController", "index");
Router::get("/postcode", "PhpxController", "postcode");
```

Then in your controller:

```php
class PhpxController {
    public function postcode() {
        return $this->view('PostcodePage', []);
    }
}
```

This renders the compiled `PostcodePage()` function with full SFPHP features (middleware, routing, etc).

## Next Steps

1. **Explore** the postcode example: `http://localhost:8000/phpx/postcode`
2. **Create** your own component in `app/components/MyComponent.phpx`
3. **Build** with `./sfphp build --phpx`
4. **Use** in views: `<?= MyComponent("data") ?>`

## See Also

- [SFHT Template Engine](./SFHT_DOCUMENTATION.md) — Template syntax guide
- [README](../README.md) — SFPHP overview

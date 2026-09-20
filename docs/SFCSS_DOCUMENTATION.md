# SFCSS - Simple Framework CSS

## Visão Geral

**SFCSS** é um framework CSS minimalista (~8KB) que combina a simplicidade do Pico CSS com a flexibilidade de utilitários tipo Tailwind. Nenhuma dependência, funciona com HTML semântico puro.

## Instalação

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

## Customização

Edite `public/css/sfcss.config.json` e regenere:

```bash
php public/css/sfcss-builder.php > public/assets/css/sfcss.css
```

---

## Componentes

### Buttons (Botões)

```html
<!-- Variantes de cores -->
<button class="btn">Default</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-info">Info</button>

<!-- Tamanhos -->
<button class="btn btn-sm">Small</button>
<button class="btn">Normal</button>
<button class="btn btn-lg">Large</button>

<!-- Estados -->
<button class="btn" disabled>Disabled</button>
<button class="btn btn-primary" disabled>Disabled Primary</button>

<!-- Links como botões -->
<a href="#" class="btn btn-primary">Link Button</a>
```

### Cards

```html
<!-- Card simples -->
<div class="card">
  <div class="card-body">
    Conteúdo do card
  </div>
</div>

<!-- Card com header e footer -->
<div class="card">
  <div class="card-header">
    <h3>Título</h3>
  </div>
  <div class="card-body">
    Conteúdo principal
  </div>
  <div class="card-footer">
    Rodapé
  </div>
</div>
```

### Formulários

```html
<!-- Form Group (recomendado) -->
<div class="form-group">
  <label class="form-label">Nome</label>
  <input type="text" placeholder="Seu nome">
</div>

<!-- Input text -->
<input type="text" placeholder="Placeholder">

<!-- Textarea -->
<textarea placeholder="Sua mensagem..."></textarea>

<!-- Select -->
<select>
  <option>Opção 1</option>
  <option>Opção 2</option>
</select>

<!-- Checkbox -->
<label>
  <input type="checkbox">
  Concordo com os termos
</label>

<!-- Radio -->
<label>
  <input type="radio" name="option">
  Opção A
</label>

<!-- Inputs desabilitados -->
<input type="text" disabled>
<select disabled>
  <option>Desabilitado</option>
</select>
```

### Tabelas

```html
<table>
  <thead>
    <tr>
      <th>Coluna 1</th>
      <th>Coluna 2</th>
      <th>Coluna 3</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Dados 1</td>
      <td>Dados 2</td>
      <td>Dados 3</td>
    </tr>
  </tbody>
</table>
```

### Badges (Etiquetas)

```html
<!-- Variantes de cores -->
<span class="badge">Default</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-secondary">Secondary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-danger">Danger</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-info">Info</span>
```

### Alerts (Alertas)

```html
<!-- Variantes de cores -->
<div class="alert">Alerta padrão</div>
<div class="alert alert-primary">Alerta primário</div>
<div class="alert alert-success">Sucesso!</div>
<div class="alert alert-danger">Erro!</div>
<div class="alert alert-warning">Atenção!</div>
<div class="alert alert-info">Informação</div>

<!-- Com closable button -->
<div class="alert alert-success">
  Mensagem de sucesso
  <button type="button" class="btn-close">&times;</button>
</div>
```

### Grid Layout

```html
<!-- Auto layout (responsive) -->
<div class="grid">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Colunas fixas -->
<div class="grid grid-cols-1">1 coluna</div>
<div class="grid grid-cols-2">2 colunas</div>
<div class="grid grid-cols-3">3 colunas</div>
<div class="grid grid-cols-4">4 colunas</div>
<div class="grid grid-cols-6">6 colunas</div>
<div class="grid grid-cols-12">12 colunas</div>

<!-- Responsivo -->
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
  <div>Item</div>
</div>
```

---

## Utilitários

### Spacing

#### Margin (Espaço Externo)

```html
<!-- Todos os lados -->
<div class="m-1">Margin 1</div>
<div class="m-2">Margin 2</div>
<div class="m-3">Margin 3</div>
<div class="m-4">Margin 4</div>

<!-- Horizontal (left + right) -->
<div class="mx-2">Margin X</div>
<div class="mx-3">Margin X</div>

<!-- Vertical (top + bottom) -->
<div class="my-2">Margin Y</div>
<div class="my-3">Margin Y</div>

<!-- Individual -->
<div class="mt-2">Margin top</div>
<div class="mb-3">Margin bottom</div>
<div class="ml-2">Margin left</div>
<div class="mr-3">Margin right</div>
```

#### Padding (Espaço Interno)

```html
<!-- Todos os lados -->
<div class="p-2">Padding 2</div>
<div class="p-3">Padding 3</div>

<!-- Horizontal -->
<div class="px-2">Padding X</div>

<!-- Vertical -->
<div class="py-3">Padding Y</div>

<!-- Individual -->
<div class="pt-2">Padding top</div>
<div class="pb-3">Padding bottom</div>
<div class="pl-2">Padding left</div>
<div class="pr-3">Padding right</div>
```

### Tipografia

#### Font Size (Tamanho)

```html
<small class="text-sm">Texto pequeno</small>
<p>Texto normal</p>
<h1 class="text-lg">Texto grande</h1>
<h1 class="text-xl">Texto extra grande</h1>
<h1 class="text-2xl">Texto 2x</h1>
<h1 class="text-3xl">Texto 3x</h1>
```

#### Font Weight (Espessura)

```html
<p class="font-light">Texto light (300)</p>
<p>Texto normal (400)</p>
<p class="font-semibold">Texto semibold (600)</p>
<p class="font-bold">Texto bold (700)</p>
```

#### Text Align (Alinhamento)

```html
<p class="text-left">Alinhado à esquerda</p>
<p class="text-center">Alinhado ao centro</p>
<p class="text-right">Alinhado à direita</p>
<p class="text-justify">Alinhado justificado</p>
```

#### Line Height (Altura da Linha)

```html
<p class="leading-tight">Linha apertada</p>
<p class="leading-normal">Linha normal</p>
<p class="leading-relaxed">Linha relaxada</p>
<p class="leading-loose">Linha solta</p>
```

### Cores

#### Text Color (Cor do Texto)

```html
<!-- Cores do tema -->
<p class="text-primary">Texto primário</p>
<p class="text-secondary">Texto secundário</p>
<p class="text-success">Texto sucesso</p>
<p class="text-danger">Texto perigo</p>
<p class="text-warning">Texto atenção</p>
<p class="text-info">Texto informação</p>

<!-- Tons de cinza -->
<p class="text-muted">Texto atenuado</p>
<p class="text-body">Texto corpo</p>
```

#### Background Color (Cor de Fundo)

```html
<!-- Cores do tema -->
<div class="bg-primary p-3">Fundo primário</div>
<div class="bg-secondary p-3">Fundo secundário</div>
<div class="bg-success p-3">Fundo sucesso</div>
<div class="bg-danger p-3">Fundo perigo</div>

<!-- Tons de cinza -->
<div class="bg-light p-3">Fundo claro</div>
<div class="bg-dark text-white p-3">Fundo escuro</div>
```

### Display

```html
<!-- Block -->
<div class="d-block">Bloco</div>

<!-- Inline -->
<span class="d-inline">Inline</span>

<!-- Inline-block -->
<div class="d-inline-block">Inline-block</div>

<!-- Flex -->
<div class="d-flex">Flex container</div>

<!-- Grid -->
<div class="d-grid">Grid container</div>

<!-- Hidden -->
<div class="d-none">Escondido</div>
```

### Flexbox

```html
<!-- Justificar conteúdo -->
<div class="d-flex justify-start">Início</div>
<div class="d-flex justify-center">Centro</div>
<div class="d-flex justify-end">Fim</div>
<div class="d-flex justify-between">Espaço entre</div>
<div class="d-flex justify-around">Espaço ao redor</div>

<!-- Alinhar itens -->
<div class="d-flex align-start">Início</div>
<div class="d-flex align-center">Centro</div>
<div class="d-flex align-end">Fim</div>
<div class="d-flex align-stretch">Esticar</div>

<!-- Direção -->
<div class="flex-row">Linha (padrão)</div>
<div class="flex-column">Coluna</div>
<div class="flex-row-reverse">Linha reversa</div>

<!-- Wrap -->
<div class="flex-wrap">Com quebra</div>
<div class="flex-nowrap">Sem quebra</div>
<div class="flex-wrap-reverse">Quebra reversa</div>
```

### Borders (Bordas)

```html
<!-- Border simples -->
<div class="border p-3">Com borda</div>

<!-- Borda por lado -->
<div class="border-top">Borda superior</div>
<div class="border-bottom">Borda inferior</div>
<div class="border-left">Borda esquerda</div>
<div class="border-right">Borda direita</div>

<!-- Border radius (arredondado) -->
<div class="rounded p-3">Levemente arredondado</div>
<div class="rounded-md p-3">Médio arredondado</div>
<div class="rounded-lg p-3">Muito arredondado</div>
<div class="rounded-full p-3">Completamente arredondado</div>
```

### Shadows (Sombras)

```html
<div class="shadow-sm p-3">Sombra pequena</div>
<div class="shadow p-3">Sombra normal</div>
<div class="shadow-md p-3">Sombra média</div>
<div class="shadow-lg p-3">Sombra grande</div>
```

### Opacity (Transparência)

```html
<div class="opacity-25 p-3">25% opacidade</div>
<div class="opacity-50 p-3">50% opacidade</div>
<div class="opacity-75 p-3">75% opacidade</div>
<div class="opacity-100 p-3">100% opacidade</div>
```

### Position e Size

```html
<!-- Position -->
<div class="position-relative">Relativo</div>
<div class="position-absolute">Absoluto</div>
<div class="position-fixed">Fixo</div>
<div class="position-sticky">Pegajoso</div>

<!-- Width -->
<div class="w-25">25% de largura</div>
<div class="w-50">50% de largura</div>
<div class="w-75">75% de largura</div>
<div class="w-100">100% de largura</div>

<!-- Height -->
<div class="h-auto">Auto</div>
<div class="h-100">100%</div>

<!-- Max/Min -->
<div class="max-w-100">Largura máxima</div>
<div class="min-h-100">Altura mínima</div>
```

---

## Breakpoints Responsivos

SFCSS usa breakpoints mobile-first:

| Prefixo | Tamanho  | Exemplo        |
|---------|----------|----------------|
| (nenhum)| default  | `.p-3`         |
| `sm:`   | ≥ 640px  | `.sm:p-4`      |
| `md:`   | ≥ 768px  | `.md:p-5`      |
| `lg:`   | ≥ 1024px | `.lg:p-6`      |
| `xl:`   | ≥ 1280px | `.xl:p-8`      |

### Exemplo de Uso

```html
<!-- Mobile: 1 coluna, Tablet: 2 colunas, Desktop: 3 colunas -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3">
  <div class="card">Card 1</div>
  <div class="card">Card 2</div>
  <div class="card">Card 3</div>
</div>

<!-- Font size responsivo -->
<h1 class="text-lg md:text-2xl lg:text-3xl">Título Responsivo</h1>

<!-- Padding responsivo -->
<div class="p-2 sm:p-3 md:p-4 lg:p-6">Conteúdo com padding responsivo</div>
```

---

## Variáveis CSS Customizáveis

Todas as cores, espaçamentos e fontes podem ser customizadas através de CSS variables:

```css
/* Cores */
--primary: #0066cc;
--secondary: #6c757d;
--success: #28a745;
--danger: #dc3545;
--warning: #ffc107;
--info: #17a2b8;
--light: #f8f9fa;
--dark: #343a40;

/* Espaçamento */
--xs: 0.25rem;
--sm: 0.5rem;
--md: 1rem;
--lg: 1.5rem;
--xl: 2rem;

/* Fonts */
--font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
--font-size-base: 1rem;
--font-size-sm: 0.875rem;
--font-size-lg: 1.125rem;
```

### Sobrescrever Variáveis

```html
<style>
  :root {
    --primary: #ff0000;
    --lg: 2rem;
  }
</style>

<!-- Agora todos os elementos usam as cores customizadas -->
<button class="btn btn-primary">Botão com cor vermelha</button>
```

---

## Boas Práticas

1. **Comece com componentes:** Use `.btn`, `.card`, `.alert` ao invés de criar estilos custom
2. **Use utilitários:** Combine classes como `.p-3 .mt-2 .text-center` para layouts simples
3. **Customize com CSS:** Quando precisar além dos utilitários, use CSS puro ou variables
4. **Mobile-first:** Escreva estilos base e use breakpoints para crescer
5. **Mantenha semântico:** Use tags HTML apropriadas (`<button>`, `<form>`, `<table>`)

---

## Performance

- **Tamanho:** ~8KB gzipped (sem dependências)
- **Compatibilidade:** Todos os navegadores modernos (IE 11+)
- **Cache:** Serve como arquivo estático, cache do navegador automático
- **Sem JavaScript:** 100% CSS puro, nenhuma dependência


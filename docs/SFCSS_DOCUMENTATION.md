# SFCSS - Simple Framework CSS

## Visão Geral

**SFCSS** é um framework CSS minimalista (~8KB) que combina a simplicidade do Pico CSS com a flexibilidade de utilitários tipo Tailwind. Nenhuma dependência, funciona com HTML semântico puro.

## Instalação

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

## Build & Customização

### Estrutura de Arquivos

```
tools/css-builder/
├── sfcss.config.json      # Configuração de cores, spacing, tipografia
├── sfcss-base.css          # Estilos base, componentes, utilities
└── sfcss-builder.php       # Script de build
```

### Regenerar CSS

Após editar `sfcss.config.json` ou `sfcss-base.css`, execute:

```bash
php tools/css-builder/sfcss-builder.php
```

Isso gera **automaticamente**:
- `public/assets/css/sfcss.css` (49KB, legível)
- `public/assets/css/sfcss.min.css` (39KB, minificado - 17% redução)

### O Builder Faz

1. **Lê configuração** → `sfcss.config.json`
2. **Gera CSS base** → `sfcss-base.css`
3. **Gera utilidades de cor** → 620+ classes (20 cores × 10 shades)
4. **Gera CSS completo** → `sfcss.css`
5. **Minifica** → Remove comentários, espaços, caracteres desnecessários
6. **Salva versão otimizada** → `sfcss.min.css`

### Customização

**Cores:** Edite `sfcss.config.json` - `colorPalettes` (Tailwind-style: slate, blue, red, etc)

**Spacing:** Edite `sfcss.config.json` - `spacing` (margin, padding escala)

**Tipografia:** Edite `sfcss.config.json` - `typography` (fontes, tamanhos)

**Componentes:** Edite `sfcss-base.css` - adicione classes direto ao final

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

> 📖 **VER REFERÊNCIA COMPLETA:** [SFCSS_UTILITIES_REFERENCE.md](./SFCSS_UTILITIES_REFERENCE.md)  
> Documentação detalhada de TODOS os 1490+ classes CSS disponíveis

### Resumo Rápido

SFCSS fornece utilitários completos para todos os aspectos do design:

**Spacing:** `m-0` a `m-24`, `p-0` a `p-24` (todos os lados)  
**Sizing:** `h-0` a `h-64`, `w-0` a `w-64`, valores em pixels arbitrários  
**Layout:** flexbox, grid, display  
**Typography:** 8 tamanhos, 4 pesos, transformações  
**Cores:** 20 famílias × 10 shades = 620+ classes  
**Efeitos:** borders, radius, shadows, opacity  
**Responsivo:** sm, md, lg, xl breakpoints  

### Exemplos Comuns

#### Spacing (Espaçamento)

```html
<!-- Margin: todos os lados, individual, ou pares -->
<div class="m-4 mt-8 mb-12 mx-auto">Espaçamento externo</div>

<!-- Padding: similar structure -->
<div class="p-4 px-6 py-8">Espaçamento interno</div>
```

#### Sizing (Tamanho)

```html
<!-- Altura em rem (0.25rem por unidade) -->
<div class="h-12">Altura: 3rem</div>

<!-- Largura com múltiplas opções -->
<div class="w-full md:w-1/2">Largura responsiva</div>
<div class="w-[75%]">75% de largura</div>
<div class="h-[48px] w-[96px]">Pixels específicos</div>
```

#### Tipografia

```html
<p class="text-sm">Pequeno</p>
<p class="text-base">Normal</p>
<h1 class="text-3xl font-bold">Título Grande</h1>
<p class="text-center uppercase">Centralizado e maiúsculo</p>
```

#### Cores (20 Famílias)

```html
<!-- Exemplo com cores azuis -->
<p class="text-blue-500">Azul médio</p>
<div class="bg-slate-100 text-slate-900 p-4">Fundo claro com texto escuro</div>
<button class="border border-red-600">Com borda vermelha</button>

<!-- Outras cores: gray, zinc, slate, indigo, sky, green, emerald, teal, cyan,
     lime, yellow, amber, orange, red, purple, violet, fuchsia, pink, rose -->
```

#### Layout (Flexbox & Grid)

```html
<!-- Flex com alinhamento -->
<div class="d-flex justify-center items-center gap-4">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Grid responsivo -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
  <div class="card">Card 1</div>
  <div class="card">Card 2</div>
  <div class="card">Card 3</div>
</div>
```

#### Position & Borders

```html
<div class="relative">Relativo</div>
<div class="absolute">Absoluto</div>
<div class="fixed">Fixo</div>

<div class="border border-blue-500 rounded-lg">Com borda e arredondado</div>
<div class="shadow-lg rounded-full">Sombra e circular</div>
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


# SFCSS — Simple Framework CSS

> Verificado contra a folha de estilos gerada · 2.337 classes · 16,1KB gzipped
>
> 🌍 Disponível também em [English](../en/SFCSS.md) e
> [Español](../es/SFCSS.md).

Um framework CSS utilitário **sem dependências**, gerado a partir de um arquivo
de configuração. Combina a ideia do Pico CSS de "estilizar HTML puro sem
configurar nada" com classes utilitárias no estilo do Tailwind, de modo que
marcação semântica já fica com boa aparência antes de você escrever uma única
classe, e os utilitários estão lá quando precisar.

Lista completa de classes:
[referência de utilitários](SFCSS_UTILITIES.md).

---

## Índice

- [Instalação](#instalação)
- [Build e customização](#build-e-customização)
- [Componentes](#componentes)
- [Variantes de estado e responsivas](#variantes-de-estado-e-responsivas)
- [Os utilitários em resumo](#os-utilitários-em-resumo)
- [Variáveis CSS](#variáveis-css)
- [Boas práticas](#boas-práticas)
- [Tamanho e compatibilidade](#tamanho-e-compatibilidade)

---

## Instalação

```html
<link rel="stylesheet" href="/assets/css/sfcss.css">
```

Ou a versão minificada:

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

Nada além disso — sem JavaScript, sem etapa de build no navegador, sem fonte ou
ícone buscado em CDN.

---

## Build e customização

### Os arquivos

```
tools/css-builder/
├── sfcss.config.json      cores, spacing, tipografia, breakpoints
├── sfcss-base.css         estilos base e componentes
└── sfcss-builder.php      o script de build
```

### Regenerar

Depois de editar qualquer um dos dois arquivos:

```bash
./sfphp css:build
```

ou, equivalentemente:

```bash
php tools/css-builder/sfcss-builder.php
```

Isso escreve as duas saídas:

- `public/assets/css/sfcss.css` — 110KB, legível
- `public/assets/css/sfcss.min.css` — 92KB, minificado (16,1KB gzipped)

### O que o builder faz

1. **Lê a configuração** de `sfcss.config.json`
2. **Emite a folha base** a partir de `sfcss-base.css`
3. **Gera os utilitários de cor** — 620 classes (20 famílias × 10 tons ×
   `bg`/`text`/`border`)
4. **Gera as variantes `hover:`** — 600 classes
5. **Gera as variantes responsivas** — 528 classes, a partir dos breakpoints do
   config
6. **Minifica** — remove comentários e espaços desnecessários — e escreve
   `sfcss.min.css`

### O que editar

| Para mudar | Edite | Chave |
|---|---|---|
| Cores | `sfcss.config.json` | `colorPalettes`, `colors` |
| Escala de spacing | `sfcss.config.json` | `spacing` |
| Tipografia | `sfcss.config.json` | `typography` |
| Breakpoints | `sfcss.config.json` | `breakpoints` |
| Componentes | `sfcss-base.css` | acrescente suas próprias regras |

Tudo o que é gerado — cores, variantes `hover:` e variantes responsivas — sai
do config. Mude um breakpoint lá e toda a camada responsiva acompanha.

---

## Componentes

### Botões

```html
<!-- Variantes de cor -->
<button class="btn">Padrão</button>
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-info">Info</button>

<!-- Tamanhos -->
<button class="btn btn-sm">Pequeno</button>
<button class="btn btn-md">Normal</button>
<button class="btn btn-lg">Grande</button>

<!-- Estados -->
<button class="btn" disabled>Desabilitado</button>

<!-- Um link com aparência de botão -->
<a href="#" class="btn btn-primary">Link botão</a>
```

### Cards

```html
<div class="card">
  <div class="card-body">
    Conteúdo do card
  </div>
</div>

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

Os campos são estilizados por elemento, então marcação pura já fica certa:

```html
<div class="form-group">
  <label class="form-label">Nome</label>
  <input type="text" placeholder="Seu nome">
</div>

<input type="text" placeholder="Placeholder">
<textarea placeholder="Sua mensagem..."></textarea>

<select>
  <option>Opção 1</option>
  <option>Opção 2</option>
</select>

<label>
  <input type="checkbox">
  Concordo com os termos
</label>

<label>
  <input type="radio" name="option">
  Opção A
</label>

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
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Dado 1</td>
      <td>Dado 2</td>
    </tr>
  </tbody>
</table>
```

### Badges

```html
<span class="badge">Padrão</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-success">Success</span>
<span class="badge badge-danger">Danger</span>
<span class="badge badge-warning">Warning</span>
<span class="badge badge-info">Info</span>
```

Badges não têm variante `secondary` — botões têm, badges não.

### Alerts

```html
<div class="alert">Alerta padrão</div>
<div class="alert alert-primary">Alerta primário</div>
<div class="alert alert-success">Sucesso!</div>
<div class="alert alert-danger">Erro!</div>
<div class="alert alert-warning">Atenção!</div>
<div class="alert alert-info">Informação</div>
```

Um botão de fechar não faz parte do framework: fechar um alerta precisa de
JavaScript, e o SFCSS não traz nenhum. Use um botão comum e o
[SFJS](DOCUMENTATION.md#sfjs), ou seu próprio handler.

### Grid

```html
<!-- Layout automático -->
<div class="grid">
  <div>Item 1</div>
  <div>Item 2</div>
  <div>Item 3</div>
</div>

<!-- Colunas fixas -->
<div class="grid grid-cols-2">Duas colunas</div>
<div class="grid grid-cols-3">Três colunas</div>
<div class="grid grid-cols-12">Doze colunas</div>

<!-- Responsivo -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
  <div class="card">Card</div>
</div>
```

### Container

```html
<div class="container">
  <!-- largura total, com padding e um max-width que cresce por breakpoint -->
</div>
```

---

## Variantes de estado e responsivas

### `hover:`

Toda classe de cor tem variante `hover:` correspondente — 600 no total:

```html
<a class="text-blue-500 hover:text-blue-700">Link</a>
<button class="bg-blue-600 hover:bg-blue-700 text-white">Enviar</button>
<div class="border-slate-200 hover:border-slate-400">Card</div>
```

### Breakpoints

Os prefixos vêm de `breakpoints` em `sfcss.config.json` e são **`min-width`**,
então a classe base vale para a menor tela e cada prefixo assume a partir da
sua largura para cima:

| Prefixo | A partir de |
|---|---|
| *(nenhum)* | qualquer largura |
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

**Nem toda utilidade tem variante responsiva** — só as de layout:

- `grid-cols-*`
- display (`block`, `flex`, `grid`, `none`, e as formas `d-`)
- direção e wrap de flex
- espaçamento: `p`, `px`, `py`, `m`, `mx`, `my`, `gap`
- tamanhos de fonte
- larguras fracionárias

Gerar todas as utilidades em todos os breakpoints multiplicaria a folha várias
vezes por classes que ninguém escreve de forma responsiva.

---

## Os utilitários em resumo

A lista completa está na
[referência de utilitários](SFCSS_UTILITIES.md). Um resumo:

| Grupo | Cobre |
|---|---|
| Spacing | `m-*` e `p-*` em todos os lados, mais `gap-*` |
| Sizing | `w-*` / `h-*` em rem, frações, porcentagens e pixels arbitrários |
| Layout | display, flexbox, grid |
| Tipografia | 8 tamanhos, 4 pesos, alinhamento, transformação, decoração |
| Cores | 20 famílias × 10 tons × `bg`/`text`/`border` |
| Efeitos | bordas, radius, sombras, opacidade |
| Position | static, relative, absolute, fixed, sticky |
| Overflow e cursor | comportamento de rolagem e formas do ponteiro |

### Spacing

```html
<div class="m-4 mt-8 mb-12 mx-auto">Espaçamento externo</div>
<div class="p-4 px-6 py-8">Espaçamento interno</div>
```

### Sizing

```html
<div class="h-12">3rem de altura</div>
<div class="w-full md:w-1/2">Largura responsiva</div>
<div class="w-[75%]">75% de largura</div>
<div class="h-[48px] w-[96px]">Pixels específicos</div>
```

### Tipografia

```html
<p class="text-sm">Pequeno</p>
<p class="text-base">Normal</p>
<h1 class="text-3xl font-bold">Título grande</h1>
<p class="text-center uppercase">Centralizado e em maiúsculas</p>
```

### Cores

Vinte famílias, dez tons cada:

```
slate  gray   zinc     blue    indigo
purple pink   red      orange  amber
yellow lime   green    emerald teal
cyan   sky    violet   fuchsia rose
```

```html
<p class="text-blue-500">Azul médio</p>
<div class="bg-slate-100 text-slate-900 p-4">Fundo claro, texto escuro</div>
<button class="border border-red-600">Borda vermelha</button>
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

### Position e bordas

```html
<div class="relative">Relativo</div>
<div class="absolute">Absoluto</div>
<div class="fixed">Fixo</div>

<div class="border border-blue-500 rounded-lg">Com borda e arredondado</div>
<div class="shadow-lg rounded-full">Com sombra e circular</div>
```

---

## Variáveis CSS

As cores do tema, a escala de spacing e os ajustes de tipografia são custom
properties do CSS, então podem ser sobrescritos sem regerar nada:

```css
/* Cores do tema */
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

/* Tipografia */
--font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
--font-size-base: 1rem;
--font-size-sm: 0.875rem;
--font-size-lg: 1.125rem;
```

### Sobrescrever

```html
<style>
  :root {
    --primary: #ff0000;
    --lg: 2rem;
  }
</style>

<button class="btn btn-primary">Agora vermelho</button>
```

Sobrescrever uma variável muda os componentes que a usam. Mudar uma **paleta**
— `blue-500` e afins — exige editar o config e regerar, porque essas são
geradas como valores literais, não como referências a variáveis.

---

## Boas práticas

1. **Comece pelos componentes.** `.btn`, `.card`, `.alert` e os elementos de
   formulário cobrem a maior parte de uma página antes de qualquer utilitário.
2. **Use utilitários para ajustar**, não para reconstruir: `p-3 mt-2
   text-center` é melhor que uma nova classe de componente.
3. **Desça para CSS puro** quando um design passar do que os utilitários
   expressam. Isso não é falha da abordagem; um layout único pertence a um CSS
   único.
4. **Escreva primeiro a classe base, depois os breakpoints.** Os prefixos são
   `min-width`, então o valor sem prefixo é o que as telas pequenas recebem.
5. **Mantenha a marcação semântica.** `<button>`, `<form>`, `<table>` e `<nav>`
   são estilizados como o que são, e um leitor de tela depende disso.

---

## Tamanho e compatibilidade

| | |
|---|---|
| Classes no total | **2.337** |
| — utilitários base e componentes | 1.209 |
| — variantes `hover:` | 600 |
| — variantes responsivas (`sm` `md` `lg` `xl`) | 528 |
| Classes de cor | 620 |
| Cru | 110KB |
| Minificado | 92KB |
| **Gzipped** | **16,1KB** |
| Dependências | nenhuma |
| JavaScript | nenhum |

Funciona em todos os navegadores atuais. É servido como arquivo estático, então
o navegador faz cache como de qualquer outro asset.

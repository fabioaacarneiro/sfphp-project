# SFCSS — Simple Framework CSS

> Verificado contra a folha de estilos gerada · 3.836 classes · 33,1KB gzipped
>
> 🌍 Disponível também em [English](../en/SFCSS.md) e
> [Español](../es/SFCSS.md).

Um framework CSS **sem dependências**, gerado por PHP a partir de um arquivo de
configuração. Ele traz componentes (botões, formulários, navegação, modais,
dropdowns, toasts…) e classes utilitárias (`p-3`, `md:grid-cols-2`,
`text-center`…). Você descreve o design uma vez, no config, e o builder deriva
dele tudo o que vem depois: o texto que continua legível sobre cada cor, os tons
de hover, os fundos claros, o tema escuro e todas as variantes de todos os
componentes.

Lista completa de classes: [referência de utilitários](SFCSS_UTILITIES.md).

---

## Índice

- [Instalação](#instalação)
- [Como está organizado](#como-está-organizado)
- [Customização](#customização)
- [Temas: claro, escuro e automático](#temas-claro-escuro-e-automático)
- [Acessibilidade](#acessibilidade)
- [Componentes](#componentes)
- [Utilitários](#utilitários)
- [Variáveis CSS](#variáveis-css)
- [Migrando da 0.27](#migrando-da-027)
- [Tamanho e compatibilidade](#tamanho-e-compatibilidade)

---

## Instalação

```html
<link rel="stylesheet" href="/assets/css/sfcss.min.css">
```

`sfcss.css` é a versão legível da mesma folha de estilos. Nada além disso é
necessário — sem etapa de build no navegador, sem fonte ou ícone buscado em CDN. A
folha de estilos é distribuída no pacote em `resources/assets/css`; o
`create-project` e o `./sfphp serve` a copiam para `public/assets/`, e o
`./sfphp assets:publish` faz isso sob demanda.

Os componentes interativos (modal, dropdown, tooltip, abas, toasts) são
estilizados pelo SFCSS e controlados pelo SFJS; o comportamento deles está
documentado na seção de SFJS da [documentação](DOCUMENTATION.md).

---

## Como está organizado

```
tools/css-builder/
├── sfcss.config.json      design decisions: colours, scales, options
├── sfcss-base.css         reset, accessibility, typography, components
└── sfcss-builder.php      reads the config and writes the stylesheet
```

A folha de estilos é emitida na ordem da cascata:

1. **Tokens** — variáveis CSS em `:root`, e os valores delas no tema escuro.
2. **Container** — larguras a partir dos breakpoints.
3. **Reset e base de acessibilidade** — resets pontuais, `:focus-visible`,
   `[hidden]`, `.visually-hidden`, tipografia e alguns auxiliares de layout
   (`text-truncate`, `line-clamp`, `sticky-top`, `ratio`…).
4. **Componentes** — que leem variáveis, nunca cores.
5. **Cores de papel e variantes por cor** — geradas para cada cor em
   `config.colors` (`text-primary`, `bg-primary-subtle`, `btn-primary`…).
6. **Paleta** — 20 famílias de cores × 10 tons, com variantes `hover:` e
   pontos de gradiente.
7. **Utilitários** — expandidos a partir do mapa de utilitários, com variantes
   de estado, de breakpoint e de impressão.

As cores e os utilitários vêm depois dos componentes de propósito: `m-0` ou
`bg-blue-50` escrito num `.card` vence sem `!important`.

### Regenerar

```bash
./sfphp css:build [--config=path] [--output=dir]
php tools/css-builder/sfcss-builder.php [config.json] [output directory]
```

Neste repositório, o `css:build` escreve em `resources/assets/css` (rode
`./sfphp assets:publish` em seguida); num projeto que instalou o framework, ele
escreve em `public/assets/css`. O builder escreve `sfcss.css` e
`sfcss.min.css`, e imprime um aviso sobre tudo o que quem lê precisa saber —
uma cor sobre a qual nenhum texto fica legível, dois utilitários que produzem a
mesma classe.

---

## Customização

### O config de um projeto contém só o que ele muda

Um projeto mantém o seu próprio `sfcss.config.json` — na raiz do projeto ou em
`tools/css-builder/sfcss.config.json`, ou passado com `--config=`. Ele é **mesclado sobre os padrões**, mapa por mapa,
então contém apenas o que o projeto muda:

```json
{
  "colors": { "primary": "#7c3aed", "brand": "#0f766e" },
  "options": { "prefix": "sf", "hoverVariants": false }
}
```

Mapas são mesclados chave por chave; uma lista ou um valor escalar substitui o
que sobrescreve. Um mapa vazio (`"colorPalettes": {}`) substitui o padrão por
nada.

### O que o config contém

| Chave | O que ela decide |
|---|---|
| `colors` | As cores de papel — `primary`, `secondary`, `success`, `danger`, `warning`, `info`, `light`, `dark`, `white`, `black` — e qualquer uma que você acrescentar |
| `colorPalettes` | As 20 famílias fixas da paleta, com 10 tons cada |
| `contrast` | As duas cores de texto entre as quais o builder escolhe (`light`, `dark`) |
| `surfaces` | Fundo da página, superfícies elevadas e rebaixadas, bordas e texto do corpo — para `light` e para `dark` |
| `spacing` | A escala por trás de margin, padding, gap e `space-*` |
| `sizing` | A escala por trás de width, height, `min-h-*`, `max-h-*` e `size-*` (`min-w-*` e `max-w-*` têm listas fixas) |
| `typography` | Famílias de fonte, a escala de tamanhos de fonte, altura de linha, pesos |
| `radii` | A escala de radius (`DEFAULT` é o `.rounded` simples) |
| `border`, `shadows`, `transition` | Largura, cor e radius da borda (`--border-radius`), as três sombras, a transição padrão |
| `breakpoints` | `sm`, `md`, `lg`, `xl` — o container, toda classe `md:` e todo componente responsivo leem estes valores |
| `options` | Recursos e comportamento, abaixo |
| `utilities` | Acréscimos e remoções no mapa de utilitários, abaixo |

### Opções

| Opção | Padrão | Efeito |
|---|---|---|
| `prefix` | `""` | Prefixa toda variável CSS: `"sf"` transforma `--primary` em `--sf-primary`, para páginas que carregam outra folha de estilos com o seu próprio `--primary`. Os nomes das classes não mudam; variáveis definidas num atributo `style` também levam o prefixo (`--sf-card-padding-x`). `--sf-anchor`, que o SFJS escreve nos popovers, mantém o seu nome |
| `components` | `true` | `false` remove os componentes e as suas variantes por cor; o reset, a base de acessibilidade, a tipografia, os auxiliares de layout, as cores de papel, a paleta e os utilitários continuam |
| `rounded` | `true` | `false` zera todas as variáveis de radius, então os componentes e as classes `rounded-*` ficam quadrados (`rounded-full` continua redondo) |
| `shadows` | `true` | `false` define todas as variáveis de sombra como `none` (as classes `shadow-*` e os componentes que as leem) |
| `transitions` | `true` | `false` define `--transition` (a classe `transition`) como `none`; os componentes mantêm as suas próprias transições curtas, e é o `reducedMotion` que as interrompe |
| `reducedMotion` | `true` | Respeita a configuração "reduzir movimento" de quem lê (veja [Acessibilidade](#acessibilidade)) |
| `darkMode` | `true` | Emite o tema escuro |
| `hoverVariants` | `true` | Emite as classes `hover:` |
| `responsiveVariants` | `true` | Emite as classes `sm:` `md:` `lg:` `xl:` |
| `importantUtilities` | `false` | Acrescenta `!important` a toda classe gerada a partir do mapa de utilitários (não à paleta, às cores de papel nem aos auxiliares de layout) |
| `minContrast` | `4.5` | A razão de contraste que "legível" significa — WCAG AA para texto do corpo |
| `gradientShades` | `["500","600","700"]` | Quais tons da paleta ganham classes `from-*` e `to-*` |

### As cores, e tudo o que deriva delas

Para cada cor em `colors` — exceto `white` e `black`, que ganham apenas
`--white`, `--white-rgb` e as classes `text-`, `bg-` e `border-` — o builder
calcula, nos dois temas:

| Variável | O que é |
|---|---|
| `--primary` | A cor |
| `--primary-rgb` | Os canais dela, para `rgb(var(--primary-rgb) / 0.5)` |
| `--primary-contrast` | O texto que continua legível sobre ela |
| `--primary-hover`, `--primary-active` | Os tons de um botão pressionado |
| `--primary-subtle` | Um fundo claro — alerts, toasts, itens de lista |
| `--primary-border` | A borda que combina com ela |
| `--primary-emphasis` | Texto que fica legível sobre o fundo claro |
| `--primary-text` | A cor como texto na página — escurecida só o quanto precisa para ficar legível |

"Legível" é calculado, não esperado. Texto branco é o preferido sobre uma cor e
só perde para o escuro quando ficaria abaixo de `minContrast`; uma cor sobre a
qual nenhum dos dois chega lá é informada quando a folha de estilos é gerada. A
forma de texto de `warning`, por exemplo, é o mesmo matiz escurecido até ficar
legível sobre o branco.

E cada cor ganha as suas classes, sem nenhum CSS escrito à mão:

```
.text-brand  .bg-brand  .border-brand       .text-bg-brand     .link-brand
.bg-brand-subtle  .border-brand-subtle  .text-brand-emphasis
.btn-brand  .btn-outline-brand  .badge-brand  .badge-brand-subtle
.alert-brand  .toast-brand  .list-group-item-brand  .table-brand
.progress-bar-brand  .spinner-brand
```

### O mapa de utilitários

A maioria das classes utilitárias vem de um único mapa no builder (a paleta, os
gradientes, as cores de papel e o container são gerados diretamente a partir do
config, e alguns auxiliares ficam em `sfcss-base.css`). Uma entrada diz qual
propriedade a classe define, a partir de quais valores, e quais variantes ela
ganha:

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

Isso acrescenta `.tab-2`, `.tab-4`, `.tab-8` com `md:tab-4` e os demais
breakpoints, e remove todas as classes `cursor-*`.

| Campo | Significado |
|---|---|
| `class` | O prefixo: `"m"` gera `m-3`. `""` usa só o nome do valor (`"flex"`, `"italic"`) |
| `property` | Uma propriedade CSS, ou uma lista para classes que definem várias |
| `values` | Um mapa de nome → valor, ou `"$spacing"`, `"$sizing"`, `"$radii"`, `"$fontSizes"` para ler uma escala do config. Um valor chamado `DEFAULT` gera a classe sem sufixo (`.rounded`) |
| `extra` | Mais valores, acrescentados a uma escala |
| `responsive` | `true` para variantes de breakpoint de todos os valores, ou uma lista dos nomes de valor que as recebem |
| `states` | Variantes de pseudo-classe, por exemplo `["hover", "focus"]` — todas são omitidas quando `options.hoverVariants` é `false` |
| `print` | Emite também uma variante `print:` |
| `selector` | Um padrão para classes que não são um simples `.nome` — `"{class} > * + *"` é como `space-y-*` é escrito |

Uma entrada com o nome de uma entrada embutida é mesclada a ela, então
`"opacity": { "responsive": true }` gera `md:opacity-50` sem repetir os
valores. `false` a remove.

Os nomes das entradas nem sempre são o prefixo da classe. As embutidas:

| Entrada | Classes |
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

## Temas: claro, escuro e automático

O tema escuro é **opt-in**. A página diz o que quer no elemento raiz:

```html
<html data-theme="dark">    <!-- sempre escuro -->
<html data-theme="light">   <!-- sempre claro -->
<html data-theme="auto">    <!-- segue a configuração do sistema de quem lê -->
```

Não dizer nada mantém o tema claro, então nenhuma página muda sem que ninguém
tenha pedido.

O que muda entre os temas são os **papéis**, não as cores: as superfícies, o
texto do corpo, e o `-subtle`, `-border`, `-emphasis` e `-text` de cada cor.
Alerts, tabelas, formulários, cards, badges, list groups e toasts leem esses
valores, então acompanham o tema sem nada escrito para isso.

As classes de paleta (`bg-blue-50`, `text-slate-600`) mantêm o seu valor nos
dois temas de propósito — `bg-blue-50` é uma cor, não um papel. Um design que
precisa acompanhar o tema usa as classes de papel: `bg-primary-subtle`,
`text-primary-emphasis`, `bg-body-raised`, `text-body`, `text-muted`.

---

## Acessibilidade

Embutida, não opcional:

- **Foco de teclado visível.** `:focus-visible` desenha um contorno só para
  quem usa o teclado — um clique de mouse não o ativa, então ninguém tem motivo
  para removê-lo. Os campos de formulário desenham um anel de foco e mantêm um
  outline transparente, que o modo de alto contraste do Windows pinta na cor do
  sistema.
- **Cores legíveis.** O texto sobre uma cor é escolhido para alcançar
  `minContrast` (WCAG AA, 4,5:1). Os padrões de `primary`, `success`, `danger` e
  `info` são os tons que sustentam texto branco em AA.
- **Movimento reduzido.** Com a configuração "reduzir movimento" de quem lê
  ativada, animações e transições encolhem para quase zero (elas continuam
  disparando os seus eventos de fim para o código que espera por eles). Os
  spinners continuam girando, devagar: um que para parece uma página que
  travou.
- **Texto para leitores de tela.** `.visually-hidden` esconde visualmente e
  mantém o texto para tecnologias assistivas; `.visually-hidden-focusable`
  aparece ao receber foco, para skip links. `.skip-link` é um pronto:
  `<a class="skip-link" href="#main">`.
- **`[hidden]` significa escondido**, qualquer que seja o `display` que uma
  classe dê ao elemento.
- **Estado a partir de ARIA.** A aba, página ou link atual é estilizado a partir
  de `aria-selected="true"` e `aria-current="page"`, uma região ocupada a partir
  de `aria-busy="true"`, um campo inválido a partir de `aria-invalid="true"` —
  então a marcação que diz a verdade a um leitor de tela é também a marcação que
  fica com a aparência certa.
- **Idiomas da direita para a esquerda.** Os componentes usam propriedades
  lógicas (`margin-inline-start`, `text-align: start`), então uma página com
  `dir="rtl"` se espelha sem uma segunda folha de estilos. Os utilitários vêm
  nas duas formas: `ms-3` / `me-3` (início/fim) ao lado de `ml-3` / `mr-3`
  (esquerda/direita).

---

## Componentes

Todo componente lê as suas próprias variáveis, que uma variante define e que uma
página também pode definir — sem sobrescrever um seletor:

```html
<div class="card" style="--card-padding-x: 2rem; --card-radius: 0">…</div>
<button class="btn btn-primary" style="--btn-padding-x: 2.5rem">Wide</button>
```

### Botões

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

Toda cor tem `btn-{colour}` e `btn-outline-{colour}`. A borda está sempre lá —
transparente quando a variante não tem uma —, então um botão ao lado de um campo
de texto tem a mesma altura.

**Grupos e toolbars:**

```html
<div class="btn-group" role="group" aria-label="Alignment">
  <button class="btn btn-outline-primary">Left</button>
  <button class="btn btn-outline-primary">Centre</button>
  <button class="btn btn-outline-primary">Right</button>
</div>

<!-- Radio buttons com aparência de grupo de botões -->
<div class="btn-group" role="group">
  <input type="radio" class="btn-check" name="view" id="v1" checked>
  <label class="btn btn-outline-secondary" for="v1">List</label>
  <input type="radio" class="btn-check" name="view" id="v2">
  <label class="btn btn-outline-secondary" for="v2">Grid</label>
</div>

<div class="btn-toolbar">…groups…</div>
```

`btn-group-vertical`, `btn-group-sm` e `btn-group-lg` fazem o que o nome diz.

**Botão de fechar** — o X é uma máscara, então assume a cor do texto ao redor:

```html
<button class="btn-close" aria-label="Close"></button>
<button class="btn-close btn-close-white" aria-label="Close"></button>
```

### Formulários

Estilizados **por classe**. Um `<input>` sem classe apenas herda a fonte da
página, então checkboxes, widgets de terceiros e campos ocultos ficam intactos.

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

**Checkboxes, radios e switches:**

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

`form-check-inline` coloca vários na mesma linha. O estado indeterminado
(`input.indeterminate = true`) é desenhado como um traço.

**Input groups:**

```html
<div class="input-group">
  <span class="input-group-text">$</span>
  <input class="form-control" aria-label="Amount">
  <button class="btn btn-outline-secondary">Apply</button>
</div>
```

**Floating labels** — o campo precisa de um placeholder; um único espaço basta:

```html
<div class="form-floating">
  <input class="form-control" id="name" placeholder=" ">
  <label for="name">Full name</label>
</div>
```

**Validação.** Três origens, uma aparência:

```html
<!-- O servidor marca o campo -->
<input class="form-control is-invalid" aria-describedby="name-error">
<div class="invalid-feedback" id="name-error">Use at least 3 characters.</div>

<!-- O SFJS define aria-invalid="true" e preenche o feedback -->

<!-- Ou as próprias restrições do navegador, depois que o usuário mexeu no campo -->
<input class="form-control" required minlength="3">
```

`.is-valid` / `.valid-feedback` são as contrapartes positivas; `.was-validated`
num formulário mostra de uma vez o veredito do navegador sobre todos os campos.
Um feedback sem conteúdo não é exibido, então o elemento sempre pode ser
renderizado.

### Cards

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

Variáveis: `--card-padding-x`, `--card-padding-y`, `--card-radius`, `--card-bg`,
`--card-border-color`, `--card-cap-bg`.

### Badges

```html
<span class="badge">Default</span>
<span class="badge badge-primary">Primary</span>
<span class="badge badge-danger-subtle">Pale</span>
<span class="badge badge-success badge-pill">Pill</span>
```

### Alerts

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

Fundo, texto e destaque vêm do `-subtle` e do `-emphasis` da cor e da própria
cor, então todo alert fica legível nos dois temas.

### Tabelas

Estilizadas por classe, como os formulários:

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

| Classe | Efeito |
|---|---|
| `table-striped` | Linhas alternadas sombreadas |
| `table-hover` | A linha sob o ponteiro sombreada |
| `table-bordered` / `table-borderless` | Borda em todas as células / sem bordas |
| `table-sm` | Células compactas |
| `table-align-middle` | Células centralizadas verticalmente |
| `table-{colour}` | Numa tabela, linha ou célula |
| `table-responsive`, `table-responsive-{bp}` | Rola na horizontal (abaixo de um breakpoint) em vez de alargar a página |

As listras e o hover pintam uma camada sobre a célula, então uma linha
`table-danger` continua aparecendo através deles.

### Navs e abas

```html
<ul class="nav nav-tabs">
  <li><a class="nav-link" aria-current="page" href="/">Overview</a></li>
  <li><a class="nav-link" href="/settings">Settings</a></li>
  <li><a class="nav-link" aria-disabled="true">Billing</a></li>
</ul>
```

`nav-tabs`, `nav-pills` e `nav-underline` são três aparências; `nav-fill` e
`nav-justified` distribuem os itens; `nav-vertical` os empilha. O item atual é o
marcado com `.active`, `aria-current="page"` ou `aria-selected="true"`. Abas
interativas — `role="tablist"`, teclas de seta, painéis — são o `@tabs` do SFJS.

### Navbar

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

Empilhada abaixo do breakpoint, em linha dele para cima; `navbar-expand` fica em
linha em qualquer largura. O collapse é exibido acima do breakpoint mesmo
enquanto está `hidden`. `navbar-dark` é a versão clara sobre escuro; `--navbar-bg`
e as outras variáveis `--navbar-*` a ajustam.

### Breadcrumb e paginação

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

`pagination-sm` e `pagination-lg` mudam o tamanho.

### List group

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

Há também `list-group-flush` (sem borda externa, para usar dentro de um card),
`list-group-numbered` e `list-group-horizontal`.

### Progresso e spinners

```html
<div class="progress" role="progressbar" aria-valuenow="40" aria-valuemin="0" aria-valuemax="100">
  <div class="progress-bar" style="--value: 40%">40%</div>
</div>
<div class="progress"><div class="progress-bar progress-bar-success progress-bar-striped progress-bar-animated" style="width: 70%"></div></div>

<span class="spinner-border" role="status"><span class="visually-hidden">Loading…</span></span>
<span class="spinner-grow spinner-primary spinner-grow-sm" role="status"></span>
```

`--progress-height` e `--spinner-size` os ajustam.

### Accordion

Construído sobre `<details>` — abre e fecha sem JavaScript, todo leitor de tela
o anuncia como expansível, e a busca na página o abre. Itens com o mesmo `name`
fecham uns aos outros:

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

### Dropdowns, popovers e tooltips

Os três são popovers nativos: o navegador os coloca na top layer (nunca
cortados por um card, sem `z-index` para administrar), os fecha com Escape ou um
clique fora, e devolve o foco. O SFJS liga cada um ao seu botão; onde existe CSS
anchor positioning, o CSS então o posiciona, e nos demais casos o SFJS calcula a
posição. Nenhuma biblioteca de posicionamento está envolvida.

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

`dropdown-menu-end` alinha um menu ao fim do seu botão.
`@tooltip-placement="bottom|left|right"` posiciona um tooltip (em cima é o
padrão): o SFJS define no tooltip a classe `tooltip-{side}` correspondente, que
o SFCSS usa para posicioná-lo. A navegação por teclado nos menus e o próprio
tooltip vêm do SFJS (`sfjs.min.js`).

### Modais e offcanvas

Os dois são `<dialog>`. Abertos com `showModal()` (no SFJS: `@modal="#id"`), o
navegador torna o resto da página inerte, mantém o foco dentro, fecha com Escape
e devolve o foco a quem os abriu. A página por trás não rola.

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

Tamanhos: `modal-sm`, `modal-lg`, `modal-xl`, `modal-fullscreen`, ou
`--modal-width`. Lados do offcanvas: `offcanvas-start`, `-end`, `-top`,
`-bottom`, dimensionados por `--offcanvas-size`.

### Toasts

`sf.toast('Saved.', { variant: 'success' })` os cria — veja o SFJS. A pilha é
uma live region, então cada toast é anunciado. Marcação estática:

```html
<div class="toast-stack">
  <div class="toast toast-success" role="status">
    <div class="toast-body">Saved.</div>
    <button class="btn-close" @dismiss aria-label="Close"></button>
  </div>
</div>
```

### Auxiliares de layout

| Classe | O que faz |
|---|---|
| `container`, `container-fluid`, `container-{bp}` | Uma coluna centralizada cujas larguras são os breakpoints; `-fluid` tem sempre largura total; `-md` é fluido abaixo de `md` |
| `grid` + `grid-cols-{1–12}` | CSS grid; `md:grid-cols-3` o muda por breakpoint |
| `grid-auto-fit`, `grid-auto-fill` | Tantas colunas quantas couberem, sem precisar de breakpoint; `--grid-min` define a mais estreita |
| `col-span-{n}`, `col-span-full`, `col-start-{n}` | Onde um item fica no grid |
| `hstack`, `vstack` | Uma linha ou uma coluna com gap (`--stack-gap`) |
| `ratio ratio-16x9` | Uma caixa de formato fixo, para iframes e vídeos (`1x1`, `4x3`, `21x9`, ou `--ratio`) |
| `stretched-link` | Torna clicável todo o ancestral posicionado mais próximo |
| `sticky-top`, `fixed-top`, `fixed-bottom` | Barras fixas |
| `vr` | Um divisor vertical dentro de uma linha flex |
| `text-truncate`, `line-clamp` | Uma linha com reticências; várias (`--lines`) |
| `img-fluid`, `img-thumbnail`, `figure` | Imagens que se ajustam, com moldura, com legenda |
| `clearfix` | Contém floats |

### Tipografia

Títulos, parágrafos e listas são estilizados como o que são. Além disso: `lead`,
`display-1` … `display-6` (fluidos, acompanham o tamanho da viewport), `small`,
`mark`, `blockquote` com `blockquote-footer`, `list-unstyled`, `list-inline`
com `list-inline-item`, e `code`, `kbd`, `pre`.

---

## Utilitários

Margin e padding (`m-3`, `px-4`, `mt-auto`, `ms-2`), gap, width e height,
display, flexbox, grid, tipografia, cor, bordas, radius, sombras, opacidade,
position, overflow, z-index, object-fit, aspect ratio, cursor e mais — com
variantes `hover:`, de breakpoint e `print:` onde fazem sentido. A lista
completa, com os valores: [referência de utilitários](SFCSS_UTILITIES.md).

**Escreva primeiro a classe base, depois os breakpoints.** Os prefixos são
`min-width`, então a classe sem prefixo é o que as telas pequenas recebem:

```html
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">…</div>
```

---

## Variáveis CSS

Tudo o que os componentes leem é uma variável em `:root`, e pode ser definido
ali, numa seção ou num único elemento:

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

Definir `--primary` à mão não recalcula `--primary-contrast` nem os outros
valores derivados — quem faz isso é o builder. Para mudar uma cor no site
inteiro, mude-a no config e regere.

| Grupo | Variáveis |
|---|---|
| Superfícies | `--surface`, `--surface-raised`, `--surface-sunken`, `--surface-border`, `--surface-border-strong`, `--body-color`, `--body-color-muted`, `--code-color` |
| Cada cor | `--{c}`, `--{c}-rgb`, `--{c}-contrast`, `--{c}-hover`, `--{c}-active`, `--{c}-subtle`, `--{c}-border`, `--{c}-emphasis`, `--{c}-text` |
| Foco | `--focus-ring`, `--focus-ring-color` |
| Escalas | `--spacing-{n}` (`--spacing-0_5` para o passo 0,5), `--font-size-{name}`, `--font-weight-{name}`, `--radius`, `--radius-{name}`, `--shadow`, `--shadow-sm`, `--shadow-lg`, `--transition` |
| Ganchos de layout | `--container-padding`, `--grid-min`, `--stack-gap`, `--lines`, `--ratio`, `--value` (barra de progresso), `--breadcrumb-divider`, `--gradient-from`, `--gradient-to` |
| Tipografia | `--font-family`, `--font-family-mono`, `--line-height` |
| Bordas | `--border`, `--border-width`, `--border-color`, `--border-radius` |
| Componentes | `--btn-*`, `--card-*`, `--badge-*`, `--alert-*`, `--table-*`, `--nav-link-*`, `--navbar-*`, `--page-*`, `--list-group-*`, `--progress-*`, `--spinner-size`, `--accordion-*`, `--modal-*`, `--offcanvas-size`, `--toast-accent`, `--tooltip-*` |

---

## Migrando da 0.27

O que muda para uma página escrita contra a folha de estilos anterior:

| Antes | Agora |
|---|---|
| Todo `<input>`, `<select>`, `<textarea>` era estilizado | Acrescente `form-control` / `form-select` (e `form-check-input` para checkboxes) |
| Toda `<table>` era estilizada, e toda linha destacada no hover | Acrescente `table` (e `table-hover` se quiser o destaque) |
| `* { margin: 0; padding: 0 }` | As listas mantêm a indentação; títulos, parágrafos e listas ganham uma margem inferior |
| `w-3`…`w-8`, `h-3`…`h-8` iam de 1rem a 3rem | Seguem `n × 0.25rem`: `w-8` é 2rem, como `w-10`…`w-64` já faziam (`w-80` e `w-96` são novos) |
| `md:p-3` era 0,75rem enquanto `p-3` era 1rem | Os dois são 1rem; toda classe de breakpoint lê a mesma escala que a sua classe simples |
| `sm:` começava em 480px, o container em 640px | Os dois em 640px |
| Um bloco de max-width forçava `md:grid-cols-*` a uma coluna abaixo de 768px e aplicava `md:p-2` em toda parte | Removido — as classes de breakpoint são apenas `min-width` |
| `primary` `#3b82f6`, `success` `#22c55e`, `danger` `#ef4444`, `info` `#06b6d4` | `#2563eb`, `#15803d`, `#dc2626`, `#0e7490`: os tons que sustentam texto branco a 4,5:1 |
| `lime` e `emerald` tinham as cores de outras famílias, `orange` estava um tom deslocado | Corrigidos, junto com sete deslizes menores em outras famílias |
| Variáveis de spacing `--xs` … `--xl`, `--font-size-size-2xl` | `--spacing-{n}`, `--font-size-2xl` |

---

## Tamanho e compatibilidade

| | |
|---|---|
| Classes no total | **3.836** |
| — utilitários base e componentes | 2.091 |
| — variantes `hover:` | 628 |
| — variantes de breakpoint (`sm` `md` `lg` `xl`) | 1.096 (274 por breakpoint) |
| — variantes `print:` | 21 |
| Classes de paleta | 600 (20 famílias × 10 tons × `bg`/`text`/`border`) |
| Variáveis CSS | 194 |
| Cru · minificado | 237KB · 195KB |
| **Gzipped** | **33,1KB** |
| Dependências | nenhuma |
| JavaScript | nenhum — os componentes interativos usam o SFJS |

Um projeto que quer menos desliga o que não usa: `components: false` economiza
cerca de 8KB gzipped, `hoverVariants: false` cerca de 4,7KB, um `colorPalettes`
vazio cerca de 9,5KB.

Funciona em todos os navegadores atuais. Anchor positioning (para dropdowns e
tooltips) e `:user-invalid` são usados onde o navegador os tem; nos demais, o
SFJS posiciona os popovers e a validação recorre às classes e aos atributos
ARIA.

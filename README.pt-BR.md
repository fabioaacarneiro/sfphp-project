# 🚀 SFPHP — Simple Framework PHP

> **Curte o SFPHP?** ⭐ [Dê uma estrela no GitHub](https://github.com/fabioaacarneiro/sfphp-project) — isso nos ajuda a crescer e mantém o framework vivo!

> **Leia isso em:** [English](README.md) · [Português](README.pt-BR.md) · [Español](README.es.md)

**O framework PHP para desenvolvedores que se importam com performance, segurança e simplicidade.**

Um framework PHP full-stack, pronto para produção, com **zero dependências em runtime**, feito para ser rápido e pensado para entregar. Só PHP 8.1+, o seu banco de dados e o seu código — nada mais é necessário.

---

## Por que escolher o SFPHP?

### ⚡ **Performance de verdade**
- **Sem inchaço de dependências** — apenas a biblioteca padrão do PHP e o driver do seu banco de dados
- **HTTP concorrente, medido** — três requisições de saída custam o que custa uma; `benchmarks/` tem os scripts que reproduzem os números
- **Queries eficientes** — relações carregadas antecipadamente com `with()` para evitar queries N+1, cache inteligente
- **Overhead mínimo do framework** — o seu código roda imediatamente, não enterrado em camadas

### 🔒 **Segurança embutida**
- **Zero-trust por padrão** — proteção CSRF, SQL binding e escape de XSS em toda parte
- **Autenticação testada em batalha** — sessões, tokens JWT, policies, remember-me
- **Validação de requisições** — parâmetros de rota tipados, filtragem de entrada
- **Sem teatro de segurança** — implementamos o que importa e deixamos de lado o que o cargo-culting criou

### 🎯 **Experiência do desenvolvedor**
- **Tipagem em toda parte** — atributos do PHP 8.1, parâmetros tipados, autocompletar na IDE
- **Geradores para ganhar tempo** — 16 geradores `make:*` para models, migrations, controllers, testes e mais
- **CLI completa** — 38 comandos para gerenciar a sua aplicação
- **API intuitiva** — aprenda uma vez, funciona igual em toda parte

### 🌍 **Multilíngue de verdade**
- **UTF-8 em primeiro lugar** — comprimento, validação, roteamento e conversão de maiúsculas e minúsculas são corretos em Unicode
- **i18n embutida** — catálogos de idioma, regras de pluralização, negociação de Accept-Language
- **Mensagens do framework no idioma do visitante** — até os erros 404 respeitam o locale

### 📦 **Tudo o que você precisa, nada do que não precisa**
- Mais de 150 recursos de segurança embutidos
- Migrations e seeders de banco de dados
- E-mail com SMTP/TLS
- Cache em Redis e em arquivo
- Filas de jobs em segundo plano
- Gerenciamento de sessão
- Tratamento de upload de arquivos
- Logging e depuração

---

## O que o SFPHP entrega

### Backend e núcleo

| Recurso | O que você ganha |
|---------|-------------|
| **Roteamento** | Parâmetros tipados, grupos, rotas nomeadas, middleware por rota |
| **HTTP** | Objetos Request/Response, pipeline de middleware, status codes |
| **Banco de dados** | Query builder com relações, migrations, seeders, transações |
| **ORM (Models)** | Hidratação de objetos, tipos de atributo, with() para eager loading |
| **Schema Builder** | Mais de 40 tipos de coluna, paridade perfeita entre MySQL 8 e PostgreSQL 12 |
| **Autenticação** | Sessões, tokens JWT, hash de senha, policies, remember-me |
| **Autorização** | Controle de acesso baseado em Gate, classes de policy |
| **Validação** | Validação de formulários, regras personalizadas, mensagens de erro |
| **Middleware** | Global, por grupo, por rota, verificação automática de CSRF |

### Frontend e views

| Recurso | O que você ganha |
|---------|-------------|
| **Templates SFHT** | Escape automático, herança de layout, composição de componentes |
| **Componentes .phpx** | Marcação dentro de funções PHP, compilada no build |
| **Framework SFCSS** | 3.836 classes — componentes e utilitários — a partir de um único config: formulários, navs, modais, dropdowns, tema escuro, contraste calculado para WCAG AA, 33KB gzipped |
| **Biblioteca SFJS** | Um arquivo só: AJAX, validação acessível, streaming, modal, dropdown, tooltip, abas e toasts — 14KB gzipped |
| **Assets embutidos** | Publicados em `public/` sem nenhuma configuração |

### Recursos avançados

| Recurso | O que você ganha |
|---------|-------------|
| **Sistema Async/Await** | Fibers do PHP sobre um event loop de verdade: requisições HTTP se sobrepõem, timers e timeouts são do loop. As queries são agendadas, não sobrepostas. |
| **Broadcasting de eventos** | Pub/sub com curingas `user.*`, histórico de eventos, disparo assíncrono |
| **Cache** | Drivers de arquivo, memória e Redis com invalidação inteligente |
| **Filas de jobs** | Workers em segundo plano com novas tentativas, drivers de banco de dados ou Redis |
| **E-mail** | SMTP com TLS, texto puro + HTML, anexos |
| **Upload de arquivos** | Detecção do tipo pelos bytes, armazenamento seguro, rejeição de arquivos forjados |
| **Logging** | Linhas JSON em UTC, rastreamento de requisições, ocultação de segredos |
| **Horas e datas** | UTC em toda parte, fuso horário só na exibição |
| **Depuração** | Páginas de erro bonitas, `dump()` e `dd()`, saída no terminal |

---

## Perfeito para estes cenários

### 📱 **APIs de alto tráfego**
Por que o SFPHP vence: chamadas de saída que se sobrepõem, cache inteligente, query builder otimizado, e a pegada de zero dependências significa memória mínima por requisição.

**Exemplo:** o seu endpoint chama três serviços. Medido contra uma origem local que responde em 100 ms, um endpoint que faz três chamadas tem a mesma latência e a mesma vazão que um que faz uma só — 80 RPS, p50 de 208 ms, sob a carga descrita em `benchmarks/server.php`.

### 🌐 **Plataformas multilíngues**
Por que o SFPHP vence: suporte de primeira classe a i18n com negociação de idioma, tratamento de UTF-8 correto em Unicode de ponta a ponta, mensagens do framework no idioma do visitante.

**Exemplo:** um marketplace atendendo mais de 10 idiomas — regras de pluralização, localização de conteúdo e negociação de Accept-Language embutidas.

### 🛡️ **Aplicações em que a segurança é crítica**
Por que o SFPHP vence: design com a segurança em primeiro lugar — CSRF por padrão, SQL binding sempre, escape de XSS automático, validação estrita de JWT, regeneração da sessão no login.

**Exemplo:** dashboards financeiros, sistemas de prontuário médico e painéis administrativos que não podem se dar ao luxo de concessões.

### ⚡ **Aplicações em tempo real**
Por que o SFPHP vence: streaming HTTP e Server-Sent Events embutidos (`@stream` no SFJS, `Response::stream()` no servidor), broadcasting de eventos assíncrono, estado reativo com invalidação de cache.

**Exemplo:** dashboards ao vivo, aplicações de chat, ferramentas colaborativas em que as atualizações precisam se propagar na hora.

### 📊 **Sistemas com muitos dados**
Por que o SFPHP vence: processamento em stream com map/filter/reduce, operações em lote, processamento assíncrono em lotes, paginação eficiente para grandes volumes de dados.

**Exemplo:** importações de CSV, geração de relatórios, ferramentas de pipeline de dados que processam milhões de registros sem explodir a memória.

### 🔄 **Aplicações monolíticas**
Por que o SFPHP vence: baterias incluídas — autenticação, autorização, validação, logging, e-mail, filas. Nada de pular entre 20 pacotes.

**Exemplo:** sistemas de gerenciamento de conteúdo, plataformas SaaS, aplicações de negócio em que você quer tudo num único framework.

### 💼 **Integrações corporativas**
Por que o SFPHP vence: zero dependências significa o mínimo de CVEs, facilidade para análise estática, nada de inferno de versões, trilha de auditoria para conformidade de segurança.

**Exemplo:** sistemas que precisam se integrar a código legado, APIs de bancos ou infraestrutura corporativa sem arrastar árvores de dependências.

### 🚀 **MVP de startup**
Por que o SFPHP vence: rápido de programar, difícil de quebrar, nada para configurar, 16 geradores na CLI, migrations embutidas, e o deploy é só arquivos PHP.

**Exemplo:** lance um SaaS, um marketplace ou um serviço sem semanas de decisões de infraestrutura.

---

## Primeiros passos

### Instalação

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

É só isso. Abra `http://localhost:8000` e você tem:
- ✅ Uma aplicação funcionando, com código de exemplo
- ✅ Uma migration, um seeder e uma factory de usuários
- ✅ JWT configurado com uma chave secreta de verdade
- ✅ CSS e JavaScript publicados e prontos
- ✅ A CLI completa disponível em `./sfphp`

### Gere o seu primeiro model

```bash
./sfphp make:model Product
./sfphp make:migration create_products name:string price:decimal timestamps
./sfphp migrate
```

### Crie um controller

```bash
./sfphp make:controller Product     # creates app/controllers/ProductController.php
```

### Monte uma rota

```php
Router::get('/products', [ProductController::class, 'index']);
Router::get('/products/id:number', [ProductController::class, 'show']);   // add show() to the controller
```

### Chame três serviços de uma vez

```php
use SfphpProject\src\Http\Http;
use function SfphpProject\src\Async\await;

$a = Http::getAsync('https://billing.internal/invoices/7');
$b = Http::getAsync('https://catalog.internal/products/42');
$c = Http::getAsync('https://ratings.internal/products/42');

[$invoice, $product, $ratings] = [await($a), await($b), await($c)];
```

As três requisições já estão na rede antes do primeiro `await`, então isso custa
mais ou menos o tempo da mais lenta, e não a soma das três. Medido: 301 ms para
três requisições de 300 ms, 309 ms para cinquenta.

> **As queries não funcionam assim.** `await(User::query()->getAsync())` agenda
> a query — não a sobrepõe, porque o PDO não tem API assíncrona e nenhuma Fiber
> muda isso. Três queries aguardadas juntas levam o mesmo que três queries:
> 609 ms contra 603 ms, medido. Isso mostra a diferença entre *agendamento
> assíncrono* e *I/O não bloqueante*.

### Feito para qualquer idioma

```php
Str::length('日本語');           // 3 (not 9 bytes)
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/products/name:alpha', ...);  // matches /products/café
__('http.not_found_message');  // in the visitor's language
```

---

## A filosofia do SFPHP

**Simples** — Fazer uma coisa bem feita é melhor do que fazer muitas pela metade.

**Seguro** — A segurança não é acrescentada, é o padrão. Sem atalhos. Sem "só desta vez".

**Rápido** — Com async/await, queries nativas ao banco de dados e zero inchaço, a sua aplicação é rápida sem esforço.

**Seu** — O framework é `/src/` — apague o que você não precisa. Todo o resto é o seu código.

**Transparente** — Sem mágica. Sem camadas escondidas. Leia o código quando tiver curiosidade; está tudo num lugar só.

---

## O que NÃO está incluído (e por quê)

- **Recuperação de senha** — A sua aplicação precisa enviá-la por e-mail de qualquer forma; nós cuidamos da infraestrutura
- **Autenticação em dois fatores** — Não existe solução única para todos; o Mail cuida da parte que cabia ao framework
- **Message brokers** — As filas embutidas costumam bastar; conecte o RabbitMQ quando precisar
- **ORM completo** — Um query builder com relações é melhor para a maioria das aplicações; mantém você no controle
- **Relações polimórficas** — Caso de borda; não justifica 200 linhas de código para a maioria dos sistemas

**Princípio:** nunca acrescente complexidade antes de provar que precisa dela. O SFPHP dá a você as peças para construir o que é certo para a sua aplicação.

---

## Documentação

Completa em três idiomas — inglês, português e espanhol, cada um uma versão integral:

| Idioma | Framework | Async | Streaming | PWA | Estilos | Componentes |
|----------|-----------|-------|-----------|-----|---------|------------|
| 🇬🇧 **English** | [Docs](docs/en/DOCUMENTATION.md) | [Async/Await](docs/en/ASYNC.md) | [Streaming](docs/en/STREAMING.md) | [PWA Guide](docs/en/PWA_GUIDE.md) | [SFCSS](docs/en/SFCSS.md) | [.phpx](docs/en/PHPX_COMPONENTS.md) |
| 🇧🇷 **Português** | [Docs](docs/pt-BR/DOCUMENTATION.md) | [Async/Await](docs/pt-BR/ASYNC.md) | [Streaming](docs/pt-BR/STREAMING.md) | [Guia PWA](docs/pt-BR/PWA_GUIDE.md) | [SFCSS](docs/pt-BR/SFCSS.md) | [.phpx](docs/pt-BR/PHPX_COMPONENTS.md) |
| 🇪🇸 **Español** | [Docs](docs/es/DOCUMENTATION.md) | [Async/Await](docs/es/ASYNC.md) | [Streaming](docs/es/STREAMING.md) | [Guía PWA](docs/es/PWA_GUIDE.md) | [SFCSS](docs/es/SFCSS.md) | [.phpx](docs/es/PHPX_COMPONENTS.md) |

**[SFHT](docs/pt-BR/DOCUMENTATION.md#views-e-sfht)** é marcação num arquivo; **.phpx** é um componente escrito como uma função PHP
com a marcação dentro dela. Os dois vêm com o framework, e a documentação diz
quando cada um é o formato certo.

**Começando rápido?**
- 📱 [Guia de configuração de PWA](docs/pt-BR/PWA_GUIDE.md)
- 📖 [Documentação completa](docs/pt-BR/DOCUMENTATION.md)

---

## Requisitos do sistema

- **PHP** 8.1 ou superior
- **Composer** 2.0+
- **Banco de dados** (opcional) — qualquer banco compatível com PDO (MySQL 8+, PostgreSQL 12+, SQLite)
- **Cache** (opcional) — arquivo, memória ou Redis
- **Fila** (opcional) — banco de dados ou Redis

Requer extensões que vêm com o PHP: `ext-ctype`, `ext-curl`, `ext-fileinfo`, `ext-filter`, `ext-json`, `ext-mbstring`, `ext-openssl`, `ext-pdo`, `ext-session`, `ext-tokenizer` — mais o driver PDO do seu banco de dados. Opcionais: `ext-redis` para os drivers Redis, `ext-pcntl` para workers de fila com encerramento gracioso (Unix), `ext-posix`, `ext-gd` para os ícones de PWA, `ext-readline` para o tinker. Nenhuma dependência do Composer.

---

## Testes

```bash
composer run test       # the unit suite (php tests/run.php)
composer run test:db    # Integration tests on real MySQL & PostgreSQL
composer run lint       # PHP syntax check
composer run docs       # Verify documentation consistency
```

A suíte de testes roda no **PHP 8.1–8.4** na CI.

---

## Segurança

Levamos a segurança a sério. Veja o [SECURITY.md](SECURITY.md) para:
- Como reportar vulnerabilidades
- As práticas de segurança usadas no SFPHP
- Onde encontrar a documentação de segurança detalhada

---

## Licença

MIT — Veja [LICENSE](LICENSE)

**Criado por** Fabio Carneiro  
**Contribuidores** A comunidade

---

## Pronto para construir?

```bash
composer create-project fabioaacarneiro/sfphp-framework my-app
cd my-app
./sfphp serve
```

A sua próxima grande aplicação começa agora. 🚀

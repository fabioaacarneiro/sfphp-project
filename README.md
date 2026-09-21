# SFPHP — Simple Framework PHP

Framework PHP full-stack com **zero dependências de runtime**, pensado para uso
em qualquer idioma e alfabeto.

`composer.json` exige apenas `php ^8.1`, `ext-json` e `ext-pdo`. O diretório
`vendor/` contém só o autoloader do Composer. Nenhuma página servida pelo
framework — nem as de erro — carrega CSS, fontes ou JavaScript de um CDN.

## Requisitos

- PHP 8.1 ou superior
- Composer 2
- PDO com o driver do seu banco (opcional — só se usar banco)

## Instalação

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
```

Ajuste o `.env`:

- **`JWT_KEY`** — obrigatória para emitir ou validar tokens. Gere com
  `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- **Banco de dados** — opcional. Configure só se precisar.

O `.env` é opcional: um clone novo sobe sem configuração, e cada recurso que
realmente precisa de um valor falha com mensagem específica.

## Executando

```bash
./sfphp serve                                   # http://localhost:8000
php -S localhost:8000 -t public server.php      # equivalente
```

Em produção, aponte o `DocumentRoot` para `public/`.

## O que tem

| | |
|---|---|
| **Roteamento** | Parâmetros tipados com suporte a qualquer alfabeto, grupos, rotas nomeadas, geração de URL |
| **HTTP** | Objetos Request/Response e pipeline de middleware global, por grupo e por rota |
| **Container DI** | Autowiring por reflexão, fábricas preguiçosas, detecção de ciclo |
| **Query Builder** | Identificadores validados por whitelist, bind em todo valor, paginação por dialeto |
| **Models** | Hidratação em objetos, relacionamentos e `with()` contra N+1 — sem virar um ORM completo |
| **Schema Builder** | 30+ tipos de coluna com paridade real MySQL 8 ↔ PostgreSQL 12 |
| **SFHT** | Template engine com escape automático, herança de layout e cache compatível com OPcache |
| **Cache** | Drivers de arquivo, memória e Redis |
| **Queue** | Workers com retry, drivers de banco e Redis |
| **SFCSS** | 2.337 classes utilitárias com variantes `hover:` e responsivas, 16,1KB gzipped |
| **SFJS** | AJAX, DOM, validação e atributos declarativos — 3,0KB gzipped |
| **CLI** | 32 comandos, 12 geradores de código |

## Unicode

O tratamento UTF-8 é construído sobre **PCRE com `/u`**, não sobre `mbstring`
— PCRE está sempre compilado no PHP, `mbstring` é opcional.

```php
Str::length('日本語');            // 3, não 9
Str::truncate('日本語テキスト', 5); // 日本... nunca um byte partido ao meio
Validator::validate(['n' => 'José'], ['n' => 'alpha'])->passes();  // true
Router::get('/produtos/nome:alpha', 'ProdutoController', 'show');   // casa /produtos/café
```

## Segurança

- **CSRF** — token de 32 bytes, comparação em tempo constante com `hash_equals`,
  cookie `httponly` + `samesite=Lax` + `secure` sob HTTPS. Helpers
  `csrf_field()`, `csrf_meta()`, `csrf_verify()`
- **JWT** — HS256, valida assinatura, `alg`, `typ` e `exp`; rejeita `alg: none`
  e exige chave de 32 bytes
- **SQL** — todo valor é vinculado, todo identificador validado contra whitelist
- **XSS** — `{{ }}` do SFHT escapa por padrão; a saída crua exige `{!! !!}`
- **Middleware** — `VerifyCsrfToken` aplica a verificação de CSRF por padrão a toda
  requisição que altera estado

O framework segue a regra **validar na entrada, escapar na saída**. Valores da
requisição chegam inalterados de propósito: escapar na entrada corromperia o
dado no banco sem proteger o destino real.

## Qualidade

```bash
composer run lint        # php -l em todo o projeto
composer run test        # 60 casos unitários
composer run test:db     # integração contra MySQL/PostgreSQL reais
```

O CI roda a suíte numa matriz PHP 8.1–8.4 **sem `mbstring`**, garantindo que o
tratamento Unicode não depende da extensão, e os testes de schema contra MySQL 8
e PostgreSQL 16 reais.

## O que não tem

Dito de frente, para você decidir com informação: não há autenticação, sistema
de eventos, i18n nem rate limiting. A camada de Models cobre hidratação e
relacionamentos, mas não é um ORM completo — sem identity map, unit of work,
proxy de lazy loading ou relação muitos-para-muitos. A lista completa, com o impacto de cada ausência, está
em [docs/DOCUMENTATION.md § Limitações conhecidas](docs/DOCUMENTATION.md#limitações-conhecidas).

## Documentação

- [Documentação completa](docs/DOCUMENTATION.md)
- [SFCSS](docs/SFCSS_DOCUMENTATION.md) · [referência de utilitários](docs/SFCSS_UTILITIES_REFERENCE.md)

## Licença

MIT. Criado por Fabio Carneiro.

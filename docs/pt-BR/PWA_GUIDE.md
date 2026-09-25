# Guia de Progressive Web App (PWA) do SFPHP

> **Leia em:** [English](../en/PWA_GUIDE.md) · [Português](PWA_GUIDE.md) · [Español](../es/PWA_GUIDE.md)

Este guia cobre o que o `./sfphp make:pwa` gera, como configurá-lo e o que o
service worker gerado realmente faz. Ele descreve o código como ele é,
incluindo o que fica a seu cargo.

---

## Índice

1. [Visão geral](#visão-geral)
2. [Requisitos](#requisitos)
3. [Início rápido](#início-rápido)
4. [O comando make:pwa](#o-comando-makepwa)
5. [Configuração](#configuração)
6. [Adicionando o PWA ao seu layout](#adicionando-o-pwa-ao-seu-layout)
7. [Estratégia de cache](#estratégia-de-cache)
8. [Nome do cache e versionamento](#nome-do-cache-e-versionamento)
9. [Suporte offline](#suporte-offline)
10. [Ícones e instalação](#ícones-e-instalação)
11. [Notificações push](#notificações-push)
12. [Background Sync](#background-sync)
13. [A API window.pwa](#a-api-windowpwa)
14. [Testes e depuração](#testes-e-depuração)
15. [Deploy](#deploy)
16. [Solução de problemas](#solução-de-problemas)

---

## Visão geral

Um Progressive Web App é um site que o navegador consegue instalar como um app.
Ele precisa de três coisas: um **web app manifest** que descreve o app, um
**service worker** que fica entre a página e a rede, e **HTTPS**.

O SFPHP gera as duas primeiras com um comando. Tudo são arquivos estáticos em
`public/`. Nenhum PHP roda para o PWA no momento da requisição, e nada é
acrescentado ao `composer.json`.

| Peça | Arquivo | Produzido por |
|-------|------|-------------|
| Manifest | `public/manifest.json` | `src/Pwa/ManifestGenerator.php` |
| Service worker | `public/service-worker.js` | `src/Pwa/ServiceWorkerGenerator.php` |
| Página offline | `public/offline.html` | cópia de `resources/pwa/offline-template.html` |
| Script de registro | `public/install-sw.js` | cópia de `resources/pwa/install-sw.js` |
| Ícones | `public/assets/icons/*.png` | `src/Pwa/IconGenerator.php`, só com `--logo` |
| Configurações | `app/pwa/config.php` | você o edita. O `make:pwa` o lê, mas nunca o escreve |

---

## Requisitos

- **PHP 8.1+**, como o resto do framework.
- **ext-gd**, mas só para gerar ícones a partir do `--logo`. Ela lê PNG, JPEG e
  GIF, e WebP quando a sua build do GD tem suporte. Sem o GD, o `make:pwa`
  ainda escreve todos os outros arquivos. Ele imprime `⚠ Could not generate
  icons: The GD extension (ext-gd) is required to generate icons.` e você mesmo
  coloca os PNGs em `public/assets/icons/`.
- **A ext-fileinfo não é necessária.** O tipo da imagem é lido com
  `getimagesize()`, que faz parte do próprio PHP.
- **HTTPS em produção.** Os navegadores só registram service workers em origens
  seguras. `http://localhost` e `http://127.0.0.1` contam como seguras, então o
  `./sfphp serve` funciona para desenvolvimento.

---

## Início rápido

```bash
# 1. Gere tudo (os ícones precisam da ext-gd)
./sfphp make:pwa --name="My App" --logo=path/to/logo.png

# 2. Acrescente as quatro tags de "Adicionando o PWA ao seu layout" ao <head> do seu layout

# 3. Rode e confira
./sfphp serve
```

Abra `http://127.0.0.1:8000`, aperte F12 e vá até **Application**. Em
**Manifest** você deve ver o nome e os ícones. Em **Service workers**, o
`/service-worker.js` deve aparecer como *activated and running*.

O projeto já vem com `app/pwa/config.php`, e se esse arquivo existe o `--name` é
opcional. Um `./sfphp make:pwa` simples então pega o nome do arquivo, que lê o
`APP_NAME` do `.env`.

---

## O comando make:pwa

```bash
./sfphp make:pwa --name="My App" [options]
```

| Opção | Efeito | Padrão |
|--------|--------|---------|
| `--name="..."` | O `name` do manifest, e a base do nome do cache. **Obrigatório quando `app/pwa/config.php` não existe.** | `name` do config |
| `--short="..."` | O `short_name` do manifest | Quando `--name` é passado: os 12 primeiros caracteres dele. Caso contrário, `short_name` do config |
| `--description="..."` | A `description` do manifest | `description` do config, ou vazio |
| `--color="#hex"` | O `theme_color` do manifest | `theme_color` do config, ou `#007AFF` |
| `--background="#hex"` | O `background_color` do manifest | `background_color` do config, ou `#ffffff` |
| `--logo=path` | Gera os ícones a partir desta imagem | nenhum. Os ícones são pulados |
| `--enable-push` | Acrescenta os handlers `push` e `notificationclick` ao service worker | `service_worker.enable_push_notifications` |
| `--enable-sync` | Acrescenta o handler `sync` ao service worker | `service_worker.enable_background_sync` |

Os valores são resolvidos nesta ordem: **flag, depois `app/pwa/config.php`,
depois o padrão embutido.** Uma flag só vale para aquela execução e não altera o
arquivo. `--enable-push` e `--enable-sync` só conseguem ligar um recurso. Para
desligar um recurso que o config liga, defina-o como `false` no arquivo.

Algumas configurações não têm flag: `start_url`, `scope`, `display`,
`orientation`, `icons`, e tudo sob `service_worker` exceto as duas chaves de
recurso. Defina-as no arquivo de config.

Toda execução **sobrescreve** `manifest.json`, `service-worker.js`,
`offline.html` e `install-sw.js`. Se você editar um arquivo gerado à mão, rodar
o comando de novo substitui a sua edição. O comando nunca edita os seus
templates. Ele imprime as tags do `<head>` no final, e acrescentá-las ao seu
layout fica por sua conta.

Exemplos:

```bash
# Nome, cores e ícones
./sfphp make:pwa --name="Task Tracker" --short="Tasks" \
  --description="Track tasks and goals" \
  --color="#2563eb" --background="#ffffff" \
  --logo=resources/logo.png

# Com os handlers de notificação push
./sfphp make:pwa --name="Task Tracker" --enable-push

# Regenere a partir de app/pwa/config.php depois de editá-lo
./sfphp make:pwa
```

---

## Configuração

O `app/pwa/config.php` devolve um array. Só o `make:pwa` o lê, e nada o lê no
momento da requisição, então **uma mudança só chega ao navegador depois que você
roda `./sfphp make:pwa` de novo.**

O arquivo que acompanha o projeto lê o nome e a descrição do app do `.env`
através do `Config::get()`, o leitor de configuração do framework:

```php
<?php

use SfphpProject\src\Config;

$appName = Config::get('APP_NAME', 'SFPHP Application');
$appDescription = Config::get('APP_DESCRIPTION', '');

return [
    'name' => $appName,
    'short_name' => mb_substr($appName, 0, 12),
    'description' => $appDescription,
    'start_url' => '/',
    'scope' => '/',
    'display' => 'standalone',        // fullscreen, standalone, minimal-ui, browser
    'theme_color' => '#007AFF',
    'background_color' => '#ffffff',
    'orientation' => 'portrait-primary',

    'service_worker' => [
        'version' => 'v1',             // parte do nome do cache, veja "Nome do cache e versionamento"
        'static_assets' => [           // pré-cacheados quando o service worker é instalado
            '/assets/css/sfcss.min.css',
            '/assets/js/sfjs.min.js',
            '/offline.html',
        ],
        'api_routes' => [],            // padrões de caminho, '*' como curinga; nunca cacheados
        'offline_fallback' => '/offline.html',
        'enable_push_notifications' => false,
        'enable_background_sync' => false,
    ],

    'icons' => [
        ['src' => '/assets/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => '/assets/icons/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ],
];
```

Observações:

- **Não existem variáveis de ambiente `PWA_*`.** `APP_NAME` e `APP_DESCRIPTION`
  são os únicos valores que o arquivo que acompanha o projeto tira do `.env`.
  Para controlar outra configuração pelo ambiente, leia-a do mesmo jeito:
  `'theme_color' => Config::get('PWA_THEME_COLOR', '#007AFF')`. O SFPHP não tem
  uma função `env()`.
- **Uma chave ausente recai no seu padrão.** Uma lista substitui a lista padrão
  em vez de se mesclar com ela. Se você definir `static_assets` com duas URLs, o
  service worker pré-cacheia exatamente essas duas.
- **`display` e `orientation` são verificados.** Um valor fora do conjunto
  permitido interrompe o comando com um erro, e nada é escrito. Para
  `orientation`, os valores permitidos são `portrait-primary`,
  `portrait-secondary`, `landscape-primary`, `landscape-secondary`, `portrait` e
  `landscape`.
- **Se o arquivo não existir,** o `make:pwa` usa os padrões mostrados acima e
  exige `--name`. O pacote mantém uma cópia deste arquivo em
  `resources/pwa/config.php`. Em um projeto que instalou o framework com o
  Composer, ela fica em
  `vendor/fabioaacarneiro/sfphp-framework/resources/pwa/config.php`. Copie-a
  para `app/pwa/config.php`.

---

## Adicionando o PWA ao seu layout

O `make:pwa` não edita templates. Acrescente estas tags ao `<head>` do seu
layout, por exemplo `app/resources/views/layouts/base.sfht`:

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#007AFF">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<script src="/install-sw.js" defer></script>
```

- O valor de `<meta name="theme-color">` deve bater com o `theme_color`. O
  comando imprime este bloco já com a sua cor preenchida.
- O `install-sw.js` registra o `/service-worker.js` com escopo `/` e define o
  `window.pwa`. Sem ele, nenhum service worker é registrado.
- O iOS ignora os ícones do manifest para a tela inicial e usa o
  `apple-touch-icon`.

Coloque as tags em todo layout em que um usuário possa chegar. Uma página sem o
link do manifest não pode ser instalada.

---

## Estratégia de cache

O `service-worker.js` gerado trata as requisições assim:

| Requisição | Estratégia |
|---------|----------|
| Tudo o que não é `GET` (POST, PUT, PATCH, DELETE) | Não é interceptada. O navegador a envia normalmente. |
| `GET` cujo caminho termina em `.js`, `.css`, `.png`, `.jpg`, `.jpeg`, `.gif`, `.svg`, `.woff`, `.woff2`, `.ttf` ou `.eot` | **Cache-first.** É servida do cache quando está lá. Caso contrário, é buscada, guardada se a resposta for bem-sucedida (ou opaca, de outra origem) e devolvida. Se a rede falhar, o fallback offline é devolvido. |
| `GET` que casa com um padrão em `api_routes` | **Só rede, nunca cacheada.** Em caso de falha de rede, o fallback offline é devolvido. |
| Qualquer outro `GET`, incluindo toda página HTML | **Só rede, nunca cacheada.** Em caso de falha de rede, o fallback offline é devolvido. |

O que isso quer dizer na prática:

- **Arquivos estáticos são cacheados pela extensão, não pela lista
  `static_assets`.** A lista só é pré-cacheada na instalação, para que esses
  arquivos fiquem disponíveis offline antes da primeira visita. Qualquer URL
  `.css`/`.js`/de imagem/de fonte que o app carregue é cacheada na primeira vez
  em que é buscada, de qualquer origem, CDN incluída.
- **Cache-first nunca revalida.** Depois que o `sfcss.min.css` está no cache, o
  service worker serve essa cópia até o cache ser substituído, mesmo depois de
  você fazer deploy de uma nova. Veja
  [Nome do cache e versionamento](#nome-do-cache-e-versionamento).
- **Páginas e respostas de API nunca são guardadas**, então o HTML ou o JSON de
  um usuário logado nunca vai parar no cache. O `api_routes` declara quais
  caminhos são de API. Por enquanto eles são tratados do mesmo jeito que
  qualquer outra requisição não estática: só rede.
- **`.webp`, `.ico`, `.json`, `.mp4` e outras extensões não são estáticas** para
  este fim. Elas são só rede.
- **Um padrão de `api_routes` é ancorado e `*` casa com qualquer coisa**, então
  `/api/*` cobre `/api/users/7`. O resto do padrão é usado como expressão
  regular.
- **O pré-cache é tudo ou nada.** O `cache.addAll()` falha por inteiro se uma
  URL falhar, por exemplo com um 404. O service worker ainda é instalado, mas
  sem nada pré-cacheado, e o console registra `Some assets failed to cache`.
  Mantenha em `static_assets` só URLs que existem. `/offline.html` tem de ser
  uma delas.

---

## Nome do cache e versionamento

O cache se chama `<slug>-<version>`:

- `<slug>` é o nome do app em minúsculas, com os espaços trocados por hífens.
  `"My App"` vira `my-app`.
- `<version>` é o `service_worker.version` do config, `v1` por padrão.

Então `./sfphp make:pwa --name="My App"` com o config que acompanha o projeto
usa `my-app-v1`.

Quando um novo service worker é ativado, ele **apaga todo cache da origem cujo
nome não é o atual** e assume o controle das páginas abertas na hora
(`skipWaiting()` e `clients.claim()`). Isso inclui qualquer cache que o seu
próprio código tenha criado com a Cache API.

Para entregar novos arquivos estáticos a usuários que já os têm no cache:

1. Mude o `service_worker.version` em `app/pwa/config.php`, por exemplo para
   `'v2'`.
2. Rode `./sfphp make:pwa`.
3. Faça o deploy. Os navegadores veem que o `service-worker.js` mudou, o
   instalam, pré-cacheiam em `my-app-v2` e apagam o `my-app-v1`.

Mudar o nome do app também muda o nome do cache. Para um único arquivo, uma URL
versionada também funciona. `/assets/css/sfcss.min.css?v=2` é uma entrada de
cache diferente da URL sem versão.

---

## Suporte offline

Quando uma requisição que vai para a rede falha, o service worker responde com o
`offline_fallback` cacheado (`/offline.html`). Páginas nunca são cacheadas,
então um usuário que fica offline vê essa página em toda navegação, incluindo
páginas visitadas antes. Não há leitura offline de páginas que você já viu.

O `public/offline.html` é uma página autocontida, com CSS inline e nenhuma
requisição externa. Ela tem um botão **Retry** e um botão **Go Home**. Ela se
recarrega quando o navegador informa que voltou a ficar online, e confere o
`navigator.onLine` a cada 3 segundos. Se você for personalizá-la, edite o
`resources/pwa/offline-template.html`, ou edite o `public/offline.html` e pare
de rodar o `make:pwa`, que copia o template por cima dele.

Duas consequências que você precisa conhecer:

- **Um `fetch()` do seu JavaScript também recebe a página offline.** Um `GET`
  que falhou para um endpoint JSON resolve com o `offline.html` cacheado e status
  200. Confira o `navigator.onLine`, ou o `Content-Type` da resposta, antes de
  interpretá-la como JSON.
- **Se o `/offline.html` não foi pré-cacheado**, porque falta em
  `static_assets` ou porque o pré-cache falhou, uma requisição que falha termina
  no próprio erro de rede do navegador.

---

## Ícones e instalação

O `--logo` escreve quatro PNGs em `public/assets/icons/`:

| Arquivo | Tamanho | Usado por |
|------|------|---------|
| `icon-192x192.png` | 192×192 | manifest (tela inicial do Android) |
| `icon-512x512.png` | 512×512 | manifest (splash screen, diálogo de instalação) |
| `apple-touch-icon.png` | 180×180 | tela inicial do iOS, através da tag `<link rel="apple-touch-icon">` |
| `badge-72x72.png` | 72×72 | o `badge` padrão das notificações push |

Um logo que não é quadrado é encaixado dentro do quadrado e centralizado sobre
um fundo transparente, não esticado. Comece de uma imagem quadrada de pelo menos
512×512.

O manifest lista os ícones da chave `icons` do config, e por padrão isso são os
ícones de 192 e 512 com `purpose: "any"`. Nenhum ícone maskable é gerado. Se
você fizer um, com o logo dentro da zona segura central e um fundo opaco,
acrescente-o a `icons` com `'purpose' => 'maskable'`. O iOS desenha as áreas
transparentes do `apple-touch-icon.png` como preto, então um logo com fundo
opaco fica melhor ali.

**Quando o navegador oferece a instalação.** Navegadores baseados em Chromium
mostram o seu convite de instalação quando a página está em HTTPS (ou
localhost), aponta para um manifest com nome, um ícone de 192 e um de 512,
`start_url` e um `display` diferente de `browser`, e é controlada por um service
worker com um handler `fetch`. Tudo o que é gerado aqui atende a isso assim que
os ícones existem. No iOS o usuário instala pelo menu Compartilhar do Safari,
com **Adicionar à Tela de Início**. O Safari não mostra convite.

---

## Notificações push

O `--enable-push` (ou `'enable_push_notifications' => true`) acrescenta dois
handlers ao service worker, e nada mais:

- **`push`** lê o payload da mensagem como JSON e mostra uma notificação:

  ```json
  {
    "title": "New comment",
    "body": "Ana replied to your task",
    "icon": "/assets/icons/icon-192x192.png",
    "badge": "/assets/icons/badge-72x72.png",
    "tag": "comment-42",
    "data": { "url": "/tasks/42" }
  }
  ```

  Campos ausentes recaem no título `SFPHP Notification`, nos dois ícones
  mostrados acima e na tag `sfphp-notification`. O payload **tem de ser JSON**.
  Outro texto faz o handler lançar exceção, e nenhuma notificação é mostrada.
- **`notificationclick`** fecha a notificação e foca uma janela aberta cuja URL
  é exatamente `data.url`, ou abre `data.url`, `/` por padrão.

**O que o SFPHP não fornece:**

- **Nenhum código de inscrição.** Nem o `install-sw.js` nem o service worker
  chamam `pushManager.subscribe()`.
- **Nenhum remetente no servidor.** Enviar uma mensagem Web Push significa
  assinar um JWT VAPID (RFC 8292) e criptografar o payload (RFC 8291). Não há
  classe para isso no framework, e não há dependência que faça isso. Use a sua
  própria implementação ou um serviço de push externo.

Uma inscrição mínima no cliente, com a sua chave pública VAPID:

```javascript
async function subscribeToPush(vapidPublicKey) {
    if (!(await window.pwa.requestNotifications())) {
        return;
    }

    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: vapidPublicKey, // base64 seguro para URL ou um Uint8Array
    });

    await fetch('/api/push/subscriptions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription),
    });
}
```

E uma rota que a guarda:

```php
// app/routes/api.php
use SfphpProject\src\Router;

Router::post('/api/push/subscriptions', 'PushSubscriptionController', 'store');
```

```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

final class PushSubscriptionController
{
    public function store(Request $request): Response
    {
        $subscription = $request->json();

        if (!isset($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'])) {
            return Response::json(['error' => 'Invalid subscription'], 422);
        }

        // Guarde o endpoint + as chaves para o usuário atual, e então:
        logger()->info('Push subscription stored', ['endpoint' => $subscription['endpoint']]);

        return Response::json(['ok' => true], 201);
    }
}
```

Se a rota passa pela proteção CSRF, envie o token no header `X-CSRF-Token`. O
`csrf_meta()` o coloca na página para o seu script ler.

Para conferir que os handlers exibem corretamente sem um servidor, chame
`window.pwa.testNotification()` depois de conceder a permissão. Ele mostra uma
notificação local através do registro do service worker.

---

## Background Sync

O `--enable-sync` (ou `'enable_background_sync' => true`) acrescenta um handler
`sync` para a tag `sync-data`. **Do jeito que vem, ele não faz nada útil.**
Saiba exatamente por quê antes de contar com ele:

- **Nada enfileira as requisições que falharam.** O handler de fetch ignora toda
  requisição que não é GET, então um POST que falha offline simplesmente falha
  na sua página.
- **O handler não tem nada para reenviar.** Ele procura requisições POST e PUT
  na Cache API. A Cache API não consegue guardar requisições POST, então ele
  nunca encontra nenhuma.
- **Nada registra o sync.** O navegador só dispara o `sync` depois que você
  chama `registration.sync.register('sync-data')`.
- **A Background Sync API existe só no Chromium.** O Firefox e o Safari não
  disparam `sync` de jeito nenhum.

Para escritas offline de verdade, guarde você mesmo a requisição (o IndexedDB é
o lugar de costume), registre o sync e reenvie as requisições guardadas no
evento `sync`:

```javascript
// Na página, quando um salvamento falha porque o usuário está offline
await saveToIndexedDb({ url: '/api/tasks', body: task }); // o seu próprio armazenamento
const registration = await navigator.serviceWorker.ready;
if ('sync' in registration) {
    await registration.sync.register('sync-data');
}
```

O código de reenvio vai no `public/service-worker.js`, no lugar de
`syncOfflineData()`. **Rodar o `make:pwa` de novo sobrescreve esse arquivo**,
então mantenha a sua versão sob controle de versão e reaplique-a depois de
regenerar, ou pare de regenerar quando personalizar o service worker.

---

## A API window.pwa

O `install-sw.js` define o `window.pwa` **só em navegadores que suportam service
workers**, e retorna cedo nos outros. Confira se ele existe antes de usá-lo:
`if (window.pwa) { … }`.

| Membro | O que faz |
|--------|--------------|
| `pwa.unregister()` | Cancela o registro de todo service worker da origem. Assíncrono. |
| `pwa.clearCache()` | Apaga **todo** cache da origem. Assíncrono. |
| `pwa.requestNotifications()` | Pede permissão de notificação. Resolve com `true` se concedida, `false` se negada ou sem suporte. |
| `pwa.testNotification()` | Mostra uma notificação de teste local através do service worker. Precisa de permissão. |
| `pwa.getCacheInfo()` | Resolve com um objeto que mapeia cada nome de cache para uma string como `"12 items"`. |
| `pwa.isOnline()` | Devolve `navigator.onLine`. |
| `pwa.onOnline(callback)` | Acrescenta um listener para o evento `online` do navegador. |
| `pwa.onOffline(callback)` | Acrescenta um listener para o evento `offline` do navegador. |

Eventos disparados em `window`:

| Evento | Quando |
|-------|------|
| `pwa:update-available` | Um novo service worker terminou de ser instalado enquanto um mais antigo controlava a página. Se a permissão de notificação foi concedida, uma notificação "App Update Available" também é mostrada. |
| `pwa:online` | O navegador voltou a ficar online. |
| `pwa:offline` | O navegador ficou offline. |

O script também chama `registration.update()` a cada hora, para que uma aba
deixada aberta pegue um novo service worker.

```javascript
window.addEventListener('pwa:update-available', () => {
    // O novo worker já assumiu o controle (skipWaiting + clients.claim),
    // então recarregar basta para pegar os novos assets.
    if (confirm('A new version is available. Reload now?')) {
        location.reload();
    }
});

if (window.pwa) {
    window.pwa.getCacheInfo().then(info => console.table(info));
}
```

---

## Testes e depuração

No DevTools do Chrome ou do Edge, abra **Application**:

- **Manifest** mostra o `manifest.json` interpretado, os ícones e qualquer
  problema de instalabilidade.
- **Service workers** mostra o registro. **Update on reload** é útil enquanto
  você trabalha. **Offline** simula a perda da rede, e **Unregister** remove o
  worker.
- **Cache storage** lista o `<slug>-<version>` e as suas entradas.
- **Storage → Clear site data** começa do zero.

Coisas para experimentar:

1. Carregue uma página, marque **Offline** e recarregue. Você deve receber o
   `offline.html`.
2. Ainda offline, confira que o CSS e o JS já buscados vêm do service worker, na
   coluna *Size* do painel Network.
3. Mude o `service_worker.version`, rode `./sfphp make:pwa`, recarregue e
   confira que só o novo cache sobrou.
4. Rode o **Lighthouse** com a categoria PWA para ter um relatório de
   instalabilidade.

Pelo console: `await pwa.getCacheInfo()`, `await pwa.clearCache()`,
`await pwa.unregister()`.

---

## Deploy

- **Sirva por HTTPS.** Sem isso, nada é registrado.
- **Mantenha o `service-worker.js` na raiz do site.** O escopo dele é `/`, e um
  worker não consegue controlar URLs acima do seu próprio caminho.
- **Envie `Cache-Control: no-cache` para o `/service-worker.js`**, para que o
  navegador procure uma nova versão a cada visita. Os navegadores ignoram o
  cache HTTP para ele depois de 24 horas de qualquer forma, mas só depois disso.
- **Mude o `service_worker.version` em toda release que altera arquivos
  estáticos**, rode `./sfphp make:pwa` e faça o deploy do `service-worker.js`
  regenerado. Caso contrário, os usuários ficam com o CSS e o JS cacheados.
- **Faça commit dos arquivos gerados** (`public/manifest.json`,
  `public/service-worker.js`, `public/offline.html`, `public/install-sw.js`,
  `public/assets/icons/`), ou rode o `make:pwa` no seu build. O servidor não
  gera nada em tempo de execução.

---

## Solução de problemas

**`Error: --name is required when there is no app/pwa/config.php`**
Passe `--name="..."`, ou copie `resources/pwa/config.php` para
`app/pwa/config.php`.

**`⚠ Could not generate icons: The GD extension (ext-gd) is required to generate icons.`**
Instale ou habilite o GD (por exemplo `php8.3-gd` no Debian/Ubuntu), ou coloque
você mesmo os quatro PNGs em `public/assets/icons/`.

**`⚠ Could not generate icons: Unsupported image format...`**
O logo não é um arquivo PNG, JPEG, GIF ou WebP, ou o seu GD não consegue ler
WebP. Converta-o para PNG.

**`⚠ Skipping icon generation: path not found`**
O caminho do `--logo` é resolvido a partir do diretório em que você roda o
comando.

**Nenhum convite de instalação**
Confira o motivo em **Application → Manifest**. As causas habituais são ícones
faltando, já que sem `--logo` o manifest aponta para PNGs que não existem, uma
página que não aponta para o manifest, ou HTTP simples em um host que não é
localhost.

**O service worker não é registrado**
Confira que o `install-sw.js` está incluído e que o `/service-worker.js` carrega
no navegador. O console mostra `✗ Service Worker registration failed` com o
motivo.

**Os usuários ainda veem o CSS/JS antigo depois de um deploy**
Isso é o cache-first funcionando como foi projetado. Mude o
`service_worker.version`, regenere e faça o deploy. Veja
[Nome do cache e versionamento](#nome-do-cache-e-versionamento).

**As minhas edições no `service-worker.js` ou no `offline.html` sumiram**
O `make:pwa` sobrescreve os dois em toda execução.

**Uma mudança em `app/pwa/config.php` não tem efeito**
O arquivo só é lido pelo `make:pwa`. Rode o comando de novo.

**`Some assets failed to cache` no console**
Uma das URLs de `static_assets` não carrega, então nada foi pré-cacheado.
Corrija ou remova essa URL.

**As mensagens push chegam, mas nenhuma notificação aparece**
O service worker foi gerado sem `--enable-push`, ou o payload não é JSON.

**O evento `sync` nunca dispara**
Nada o registra por você, e só o Chromium tem suporte. Veja
[Background Sync](#background-sync).

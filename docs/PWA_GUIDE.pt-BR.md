# Guia Completo PWA - SFPHP

> **Leia em:** [English](PWA_GUIDE.md) · [Português](PWA_GUIDE.pt-BR.md) · [Español](PWA_GUIDE.es.md)

**Transforme seu app SFPHP em um Progressive Web App poderoso com suporte offline, notificações push e instalação na tela inicial.**

---

## Índice

1. [O que é PWA?](#o-que-é-pwa)
2. [Início Rápido (5 minutos)](#início-rápido)
3. [Componentes da PWA](#componentes-da-pwa)
4. [Configuração](#configuração)
5. [Estratégias de Cache](#estratégias-de-cache)
6. [Suporte Offline](#suporte-offline)
7. [Notificações Push](#notificações-push)
8. [Sincronização em Background](#sincronização-em-background)
9. [Ícones e Instalação](#ícones-e-instalação)
10. [Testes e Debug](#testes-e-debug)
11. [Deploy em Produção](#deploy-em-produção)
12. [Dicas de Performance](#dicas-de-performance)
13. [Resolvendo Problemas](#resolvendo-problemas)

---

## O que é PWA?

Uma **Progressive Web App (PWA)** é um aplicativo web que usa capacidades modernas para entregar uma experiência semelhante a um app nativo.

### Principais Benefícios

```
✅ Instala na tela inicial (sem app store)
✅ Funciona offline (conteúdo em cache)
✅ Carregamento rápido (cache de service worker)
✅ Notificações push (engage com usuários)
✅ Sincronização offline (salva dados, sincroniza online)
✅ Experiência full screen (sem UI do navegador)
✅ Funciona em todos os dispositivos (responsivo)
```

### Exemplos Reais

- Twitter PWA (64% menos uso de dados)
- Spotify PWA (instala da web)
- WhatsApp Web (mensagens offline)
- Uber Eats PWA (3x mais rápido, 75% menor)

---

## Início Rápido

### 1. Gerar Setup da PWA

```bash
./sfphp make:pwa \
  --name="Meu App" \
  --short="App" \
  --color="#007AFF" \
  --logo=caminho/para/logo.png
```

**O que isso cria:**
- ✅ `public/manifest.json` - Metadados da PWA
- ✅ `public/service-worker.js` - Suporte offline
- ✅ `public/offline.html` - Página offline customizada
- ✅ `public/icons/` - Ícones do app
- ✅ `public/install-sw.js` - Instalador do service worker
- ✅ `app/pwa/config.php` - Arquivo de configuração

### 2. Testar no Navegador

1. **Abra seu app:** `http://localhost:8000`
2. **Abra DevTools:** F12 → aba Aplicativo
3. **Verifique Manifest:** Seção "Manifest"
4. **Verifique Service Worker:** Seção "Service Workers"
5. **Instale:** Clique no prompt "Instalar" (ou menu)

### 3. Ativar Recursos (Opcional)

Edite `.env`:

```env
PWA_ENABLED=true
PWA_PUSH_NOTIFICATIONS=true
PWA_BACKGROUND_SYNC=true
```

Depois regenere:

```bash
./sfphp make:pwa --enable-push --enable-sync
```

---

## Componentes da PWA

### 1. Arquivo Manifest (`manifest.json`)

Define como sua PWA aparece na tela inicial, splash screen e instalação.

**Localização:** `public/manifest.json`

```json
{
  "name": "Meu App",
  "short_name": "App",
  "description": "Meu app incrível",
  "start_url": "/",
  "scope": "/",
  "display": "standalone",
  "theme_color": "#007AFF",
  "background_color": "#ffffff",
  "orientation": "portrait-primary",
  "icons": [
    {
      "src": "/icons/icon-192x192.png",
      "sizes": "192x192",
      "type": "image/png"
    },
    {
      "src": "/icons/icon-512x512.png",
      "sizes": "512x512",
      "type": "image/png"
    }
  ]
}
```

**Campos Principais:**

| Campo | Propósito | Exemplo |
|-------|-----------|---------|
| `name` | Nome completo do app | "Meu Aplicativo Incrível" |
| `short_name` | Nome na tela inicial (máx 12) | "MeuApp" |
| `description` | Descrição do app | "Rastreie tarefas e metas" |
| `start_url` | Página que abre na instalação | "/" |
| `display` | Modo de interface | "standalone", "fullscreen" |
| `theme_color` | Cor da barra de ferramentas | "#007AFF" |
| `background_color` | Cor do splash screen | "#ffffff" |
| `icons` | Ícones do app (múltiplos tamanhos) | Ver acima |

### 2. Service Worker (`service-worker.js`)

**Localização:** `public/service-worker.js`

Um arquivo JavaScript que roda em background, interceptando requisições de rede e gerenciando cache.

**Responsabilidades:**

```
┌─────────────────────────────────────┐
│    Service Worker                   │
├─────────────────────────────────────┤
│  Install → Cachear assets estáticos │
│  Activate → Limpar caches antigos   │
│  Fetch → Interceptar requisições    │
│  Push → Lidar com notificações      │
│  Sync → Sincronizar dados offline   │
└─────────────────────────────────────┘
```

### 3. Script de Instalação (`install-sw.js`)

**Localização:** `public/install-sw.js`

Registra o service worker e fornece API JavaScript da PWA.

```html
<!-- No seu layout base -->
<script src="/install-sw.js" defer></script>
```

---

## Configuração

### Arquivo de Config

Edite `app/pwa/config.php`:

```php
return [
    // Ativar PWA
    'enabled' => env('PWA_ENABLED', true),

    // Identificação do app
    'name' => 'Meu App',
    'short_name' => 'App',
    'description' => 'Meu app incrível',

    // Cores
    'theme_color' => '#007AFF',
    'background_color' => '#ffffff',

    // Instalação
    'display' => 'standalone',       // Como o app aparece
    'start_url' => '/',              // Página inicial
    'scope' => '/',                  // Escopo do app
    'orientation' => 'portrait-primary',

    // Estratégia de cache
    'cache' => [
        'name' => 'app-v1',          // Mude para invalidar cache

        'static_assets' => [         // Cache-first
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
            '/index.html',
        ],

        'api_routes' => [            // Network-first
            '/api/*',
        ],

        'offline_fallback' => '/offline.html',
    ],

    // Recursos
    'push_notifications' => env('PWA_PUSH_NOTIFICATIONS', false),
    'background_sync' => env('PWA_BACKGROUND_SYNC', false),
];
```

### Usar Config no App

```php
use SfphpProject\src\Pwa\PwaConfig;

$pwaConfig = PwaConfig::fromFile(__DIR__ . '/../app/pwa/config.php');

if ($pwaConfig->isEnabled()) {
    echo sprintf('<meta name="theme-color" content="%s">', 
        $pwaConfig->themeColor()
    );
}
```

---

## Estratégias de Cache

SFPHP PWA usa duas estratégias:

### 1. Cache-First (Assets Estáticos)

Para **imagens, CSS, JavaScript, fontes** que mudam raramente.

```
Requisição do usuário
    ↓
Verificar cache
    ↓
Encontrado? ✓ Retornar do cache
    ↓
Não encontrado? → Buscar da rede → Salvar cache → Retornar
```

**Benefícios:**
- ✅ Extremamente rápido (cacheado)
- ✅ Funciona offline
- ✅ Reduz bandwidth

**Desvantagem:**
- ❌ Atualizações exigem invalidar cache

### 2. Network-First (Chamadas API)

Para **endpoints de API** que precisam de dados frescos.

```
Requisição do usuário
    ↓
Tentar rede
    ↓
Sucesso? ✓ Salvar cache → Retornar
    ↓
Falhou? → Verificar cache → Retornar versão cacheada
```

**Benefícios:**
- ✅ Dados frescos quando online
- ✅ Funciona offline com resposta cacheada
- ✅ Experiência sem fricção

**Desvantagens:**
- ⚠️ Ligeiramente mais lento (espera rede)
- ⚠️ Mostra dados potencialmente antigos offline

### Customizar Estratégias

Edite `public/service-worker.js` → função `isStaticAsset()`:

```javascript
function isStaticAsset(pathname) {
  // Adicione mais tipos conforme necessário
  return /\.(js|css|png|jpg|jpeg|gif|svg|woff|woff2|ttf|eot)$/i.test(pathname);
}
```

### Invalidar Cache

Quando atualizar assets estáticos, mude o nome do cache:

```php
'cache' => [
    'name' => 'app-v2',  // Mude de v1
    // ...
],
```

Depois regenere:

```bash
./sfphp make:pwa
```

---

## Suporte Offline

### Como Funciona

1. **Install:** Service worker cacheia assets estáticos
2. **Offline:** Quando rede falha, sirva do cache
3. **Online:** Busque dados frescos e sincronize mudanças offline

### Página de Fallback Offline

Quando uma requisição falha e não está em cache, usuários veem `/offline.html`.

Edite `public/offline.html` para customizar:

```html
<!DOCTYPE html>
<html>
<head>
    <title>Offline</title>
    <style>
        body {
            font-family: sans-serif;
            text-align: center;
            padding: 40px;
        }
    </style>
</head>
<body>
    <h1>📡 Você está Offline</h1>
    <p>Sua conexão internet foi perdida.</p>
    <button onclick="location.reload()">Tentar Novamente</button>
    <button onclick="location.href='/'">Ir para Início</button>
</body>
</html>
```

### Testar Offline

**Em DevTools:**

1. Abra DevTools → aba Network
2. Marque checkbox "Offline"
3. Atualize página
4. Tente navegar

Você deve ver o fallback offline para páginas não cacheadas.

### Lidar com Offline em JavaScript

SFPHP PWA fornece API JavaScript:

```javascript
// Verificar status de conexão
if (pwa.isOnline()) {
    console.log('Online');
} else {
    console.log('Offline');
}

// Ouvir mudanças de conexão
pwa.onOnline(() => console.log('Voltamos online!'));
pwa.onOffline(() => console.log('Ficamos offline'));

// Ouvir eventos PWA customizados
window.addEventListener('pwa:online', () => {
    console.log('Conexão restaurada');
    location.reload(); // Atualizar dados
});

window.addEventListener('pwa:offline', () => {
    console.log('Conexão perdida');
    showNotification('Modo offline...');
});
```

### Persistir Dados Offline

Salve dados de formulários antes do usuário perder conexão:

```javascript
// Salvar dados do formulário quando offline
pwa.onOffline(() => {
    const formData = new FormData(document.querySelector('form'));
    localStorage.setItem('pending-form', JSON.stringify({
        timestamp: Date.now(),
        data: Object.fromEntries(formData),
    }));
});

// Restaurar e enviar quando online
pwa.onOnline(() => {
    const pending = localStorage.getItem('pending-form');
    if (pending) {
        const { data } = JSON.parse(pending);
        fetch('/api/submit', {
            method: 'POST',
            body: JSON.stringify(data),
            headers: { 'Content-Type': 'application/json' }
        }).then(() => {
            localStorage.removeItem('pending-form');
            showNotification('Dados salvos!');
        });
    }
});
```

---

## Notificações Push

### Ativar Notificações Push

1. **Em .env:**

```env
PWA_PUSH_NOTIFICATIONS=true
```

2. **Regenerar PWA:**

```bash
./sfphp make:pwa --enable-push
```

3. **Pedir Permissão:**

```javascript
// Usuário clica em botão para ativar
async function enableNotifications() {
    const granted = await pwa.requestNotifications();
    if (granted) {
        console.log('Notificações ativadas!');
        pwa.testNotification();
    }
}
```

### Enviar Push do Backend

**PHP:**

```php
use SfphpProject\src\Pwa\PushNotificationService;

$pushService = new PushNotificationService();

// Enviar para usuário
$pushService->sendToUser($userId, [
    'title' => 'Nova Mensagem',
    'body' => 'Você tem uma nova mensagem de João',
    'icon' => '/icon-192x192.png',
    'tag' => 'new-message',
    'data' => [
        'url' => '/messages/123',
    ],
]);
```

### Lidar com Click na Notificação

Em `service-worker.js` (já configurado):

```javascript
self.addEventListener('notificationclick', event => {
    event.notification.close();
    const url = event.notification.data.url || '/';
    
    // Abrir URL em janela
    event.waitUntil(clients.openWindow(url));
});
```

### Testar Notificações

```javascript
// Enviar notificação de teste
await pwa.testNotification();
```

---

## Sincronização em Background

### Ativar Background Sync

1. **Em .env:**

```env
PWA_BACKGROUND_SYNC=true
```

2. **Regenerar PWA:**

```bash
./sfphp make:pwa --enable-sync
```

### Como Funciona

1. **Usuário offline:** Requisições POST/PUT falham
2. **Service Worker:** Cacheia a requisição
3. **Usuário online:** Automaticamente retry das requisições cacheadas

### Exemplo: Envio de Formulário Offline

```javascript
// Envio de formulário
document.querySelector('form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    
    try {
        const response = await fetch('/api/submit', {
            method: 'POST',
            body: formData,
        });
        
        if (response.ok) {
            showNotification('✓ Salvo!');
        }
    } catch (error) {
        if (!pwa.isOnline()) {
            // Offline: vai retentar quando online
            showNotification('Salvo offline - sincronizará quando online');
            
            // Registrar background sync
            if ('serviceWorker' in navigator && 'SyncManager' in window) {
                const registration = await navigator.serviceWorker.ready;
                await registration.sync.register('sync-data');
            }
        }
    }
});
```

### Verificar Sync

DevTools → Aplicativo → Service Workers → Periodic Background Sync

---

## Ícones e Instalação

### Requisitos de Ícones

SFPHP gera tamanhos padrão:

| Tamanho | Propósito |
|---------|-----------|
| 192x192 | Tela inicial Android |
| 512x512 | Splash screen Android |
| 180x180 | Apple touch icon |

### Gerar Ícones

De uma imagem logo (PNG, JPG, WebP, GIF):

```bash
./sfphp make:pwa --logo=caminho/para/seu/logo.png
```

**Requisitos:**
- Imagem quadrada (proporção 1:1)
- Pelo menos 512×512 pixels
- PNG recomendado (fundo transparente)
- Extensão PHP GD necessária

### Ícones Manuais

Se não conseguir auto-gerar, crie você mesmo:

```
public/icons/
├─ icon-192x192.png
├─ icon-512x512.png
└─ apple-touch-icon.png (180x180)
```

### Prompts de Instalação

#### Android

1. Abra o app
2. Menu → "Instalar app"
3. Selecione "Instalar"

#### iOS

1. Abra o app no Safari
2. Ícone Compartilhar → "Adicionar à Tela de Início"
3. Nomeie e adicione

#### Web

Navegador mostra prompt automaticamente (se critérios PWA atendidos).

### Critérios para Install

Para o prompt aparecer:

```
✅ HTTPS ativado (obrigatório)
✅ Manifest.json presente
✅ Service worker registrado
✅ Ícones inclusos
✅ Cores tema definidas
✅ Start URL definida
```

Tudo automaticamente configurado por `make:pwa`!

---

## Testes e Debug

### Inspeção DevTools

**Aba Aplicativo:**

1. **Manifest:** Verifique todos os campos
2. **Service Workers:** Verifique status de registro
3. **Cache Storage:** Inspecione requisições cacheadas
4. **Local Storage:** Verifique dados offline

### Testar Offline

1. DevTools → aba Network
2. Marque checkbox "Offline"
3. Atualize página
4. App deve funcionar com conteúdo cacheado

### Testar Notificações Push

```javascript
// Enviar notificação de teste
await pwa.testNotification();
```

### Debug do Service Worker

```javascript
// Em seu app JavaScript
console.log('Status SW:', navigator.serviceWorker);

navigator.serviceWorker.addEventListener('message', (event) => {
    console.log('Mensagem do SW:', event.data);
});

// Obter instância do service worker
navigator.serviceWorker.ready.then((registration) => {
    console.log('SW registrado:', registration);
});
```

### Debug de Cache

```javascript
// Listar todos os caches
async function listCaches() {
    const cacheNames = await caches.keys();
    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const requests = await cache.keys();
        console.log(`Cache: ${name}`);
        requests.forEach(req => console.log(`  - ${req.url}`));
    }
}

// Ou use API PWA
console.log(await pwa.getCacheInfo());
```

### API PWA

SFPHP fornece API JavaScript:

```javascript
// Verificar status
pwa.isOnline()                    // true/false

// Notificações
await pwa.requestNotifications()  // Pedir permissão
await pwa.testNotification()      // Enviar teste

// Cache
await pwa.getCacheInfo()          // Listar caches
await pwa.clearCache()            // Limpar tudo

// Service Worker
await pwa.unregister()            // Remover SW

// Eventos
pwa.onOnline(callback)            // Conexão restaurada
pwa.onOffline(callback)           // Conexão perdida
```

---

## Deploy em Produção

### Requisitos

- **HTTPS:** Obrigatório para Service Workers
- **Certificado Válido:** Sem certificados auto-assinados
- **Todos PWA files:** manifest.json, service-worker.js, ícones

### Checklist de Deploy

```
✅ HTTPS ativado
✅ Verifique manifest.json acessível
✅ Verifique service-worker.js com caminhos corretos
✅ Ícones em /public/icons/
✅ offline.html acessível
✅ Teste em modo incógnito (instalação fresca)
✅ Teste modo offline
✅ Verifique se prompt de instalação aparece
✅ Verifique se notificações funcionam
```

### Otimização de Performance

1. **Comprimir manifest.json** (automático)
2. **Minificar service-worker.js** (automático)
3. **Otimizar ícones:**
   - Use JPEG/WebP para fotos
   - Use PNG para logos (transparência)
   - Comprima com TinyPNG
4. **Estratégia de versão de cache:**
   - Mude nome do cache por deploy
   - Use timestamp: `app-20240101`

### Monitoramento

Monitore uso e problemas de PWA:

```php
// Logs de erros do SW
Route::post('/api/pwa/error', function (Request $request) {
    Log::channel('pwa')->error('SW Error', $request->json());
    return response()->json(['ok' => true]);
});

// Rastrear instalações
Route::post('/api/pwa/install', function (Request $request) {
    Log::info('PWA instalado pelo usuário');
    return response()->json(['ok' => true]);
});
```

---

## Dicas de Performance

### 1. Gerenciamento de Versão de Cache

Mude nome do cache para forçar re-download:

```php
'cache' => [
    'name' => 'app-' . time(), // Force novo cache
],
```

Ou por deploy:

```php
'cache' => [
    'name' => env('PWA_CACHE_VERSION', 'app-v1'),
],
```

### 2. Cache Seletivo

Cachear apenas assets essenciais:

```php
'cache' => [
    'static_assets' => [
        '/css/sfcss.min.css',  // Crítico
        '/js/sfjs.min.js',     // Crítico
        '/index.html',         // Crítico
        // Não cachear imagens (muito grande)
    ],
],
```

### 3. Padrões de Rota API

Seja específico com rotas API:

```php
'api_routes' => [
    '/api/data/*',        // Cachear dados
    '/api/search/*',      // Cachear busca
    // Não incluir /api/upload/* (sempre fresco)
],
```

### 4. Expiração de Cache

Expire caches antigos manualmente:

```javascript
// Em service worker
const CACHE_EXPIRY = 7 * 24 * 60 * 60 * 1000; // 7 dias

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(names => {
            return Promise.all(
                names.map(name => {
                    // Deletar caches antigos
                    if (name !== CACHE_NAME) {
                        return caches.delete(name);
                    }
                })
            );
        })
    );
});
```

### 5. Monitorar Tamanho de Cache

```javascript
async function getCacheSize() {
    const cacheNames = await caches.keys();
    let totalSize = 0;

    for (const name of cacheNames) {
        const cache = await caches.open(name);
        const requests = await cache.keys();
        for (const request of requests) {
            const response = await cache.match(request);
            totalSize += response ? response.blob().then(b => b.size) : 0;
        }
    }

    return totalSize;
}
```

---

## Resolvendo Problemas

### Service Worker Não Registra

**Problema:** "Falha no registro do Service Worker"

**Soluções:**

1. **Verifique HTTPS:** Service Workers exigem HTTPS
```bash
# Desenvolvimento: localhost é exceção
http://localhost:8000
```

2. **Verifique arquivo:** Confirme `/public/service-worker.js`
3. **Verifique caminho:** Caminho correto em install script
4. **Verifique console:** DevTools → aba Console
5. **Limpar cache:** `Ctrl+Shift+Del` → "Imagens/arquivos cacheados"

### Prompt de Instalação Não Aparece

**Problema:** Botão "Instalar app" faltando

**Checklist:**

1. ✅ HTTPS ativado (ou localhost)
2. ✅ Manifest.json válido
3. ✅ Service Worker registrado
4. ✅ Ícones presentes
5. ✅ manifest.json linkado em HTML

**Testar validade do manifest:**

```bash
curl http://localhost:8000/manifest.json | jq .
```

### Página Offline Não Mostra

**Problema:** Offline, mas não vendo `/offline.html`

**Causas:**

1. `/offline.html` não acessível
2. Service Worker não instalado
3. Página nunca foi cacheada

**Corrigir:**

1. Verifique se arquivo existe: `public/offline.html`
2. Reinstale app: Limpe cache e atualize
3. Cachear manualmente página:

```php
'cache' => [
    'static_assets' => [
        // ...
        '/offline.html',  // Pré-cachear
    ],
],
```

### Notificações Não Funcionam

**Problema:** "Permissão negada" ou notificações silenciosas

**Soluções:**

1. **Verifique permissão:** DevTools → aba Segurança
2. **Pedir permissão explicitamente:**

```javascript
const permission = await Notification.requestPermission();
if (permission === 'granted') {
    pwa.testNotification();
}
```

3. **Verifique SW tem listener** (deve estar auto-incluído)
4. **Teste em site deployado** (alguns navegadores pulam em localhost)

### Cache com Dados Antigos

**Problema:** Conteúdo atualizado não aparece

**Solução:** Invalide cache mudando versão

```php
// app/pwa/config.php
'cache' => [
    'name' => 'app-' . date('Ymd'),  // Muda diariamente
],
```

Depois regenere:

```bash
./sfphp make:pwa
```

### Background Sync Não Funciona

**Problema:** Requisições offline não retentam

**Checklist:**

1. ✅ Background sync ativado (`PWA_BACKGROUND_SYNC=true`)
2. ✅ Service Worker tem listener de sync
3. ✅ Método de requisição é POST/PUT
4. ✅ Requisição não em exclusão "api_routes"

**Verifique:**

```javascript
// Quando online
navigator.serviceWorker.ready.then(async (reg) => {
    await reg.sync.register('sync-data');
    console.log('Sync registrado');
});
```

---

## Resumo

Você agora tem um PWA pronto para produção! 🎉

**O que você pode fazer:**

✅ Instalar na tela inicial
✅ Funciona offline
✅ Enviar notificações push
✅ Sincronizar dados offline
✅ Carregamento rápido (cacheado)
✅ Funciona em todos os dispositivos

**Próximos passos:**

1. Customizar cores e branding
2. Testar em diferentes dispositivos
3. Configurar backend de notificações
4. Monitorar performance
5. Deploy em produção

**Recursos:**

- [MDN Web Docs - PWA](https://developer.mozilla.org/pt-BR/docs/Web/Progressive_web_apps)
- [Google PWA Checklist](https://web.dev/pwa-checklist/)
- [web.dev aprendendo PWA](https://web.dev/pwa/)

---

## Dúvidas?

Junte-se à comunidade SFPHP:

- 📚 [Documentação](https://github.com/fabioaacarneiro/sfphp-project)
- 💬 [GitHub Discussions](https://github.com/fabioaacarneiro/sfphp-project/discussions)
- 🐛 [Reporte Issues](https://github.com/fabioaacarneiro/sfphp-project/issues)

Bom desenvolvimento! 🚀

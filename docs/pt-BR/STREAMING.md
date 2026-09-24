# Streaming HTTP

Envie e receba respostas HTTP em pedaços em vez de guardar o corpo inteiro na memória. Permite dados em tempo real, transferência de arquivos grandes e proxy servidor-para-cliente sem overhead de memória.

## Extensões PHP Necessárias

Antes de usar streaming, instale estas extensões:

### Essencial

```bash
# GD — Geração de imagens para ícones PWA
apt install php8.3-gd

# curl — Requisições HTTP
apt install php8.3-curl
```

Verifique a instalação:
```bash
php -m | grep -E 'gd|curl'
# Saída deve mostrar: curl, gd
```

### Opcional (Recomendado)

```bash
# mbstring — Melhor suporte UTF-8
apt install php8.3-mbstring
```

Se `mbstring` não estiver instalado, streaming funciona mas borda cases de UTF-8 são menos robustos. O framework funciona sem (PHP 8.1+).

### Verificar seu servidor

```bash
php -r "
echo 'Extensões:\n';
echo '  curl: ' . (extension_loaded('curl') ? '✓ instalada' : '✗ FALTANDO') . '\n';
echo '  gd: ' . (extension_loaded('gd') ? '✓ instalada' : '✗ FALTANDO') . '\n';
echo '  mbstring: ' . (extension_loaded('mbstring') ? '✓ instalada' : '○ opcional') . '\n';
"
```

---

## Streaming Servidor → Cliente

Envie o corpo da resposta em pedaços enquanto os dados são gerados. O cliente recebe e processa cada parte imediatamente, sem esperar a resposta inteira.

### Casos de uso

- **Saída de LLM** — Envie tokens conforme são gerados por um modelo de IA
- **Exportações grandes** — Faça stream de CSV, NDJSON ou JSON sem guardar na memória
- **Atualizações em tempo real** — Relatórios de progresso, logs ao vivo, dados em tempo real
- **Server-Sent Events** — Navegador se inscreve em um fluxo de eventos
- **Proxy de streams** — Receba de serviço upstream, encaminhe para o cliente

### Uso básico

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(function(StreamWriter $out) {
    for ($i = 1; $i <= 100; $i++) {
        // Verifica se cliente desconectou
        if ($out->aborted()) {
            break;
        }

        $out->write("Item $i\n");
        
        // Simula geração lenta
        usleep(100000); // 100ms
    }
}, status: 200, headers: [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

### Como funciona

1. **Requisição chega** → despachante cria Response::stream()
2. **Middleware roda** → pode modificar headers/status antes da emissão
3. **Emitter envia headers** → cliente recebe status 200, Content-Type, etc.
4. **Produtor executa** → seu callable começa a rodar
5. **Cada write() faz flush** → dados chegam ao cliente imediatamente
6. **Cliente processa** → pode agir nos pedaços conforme chegam
7. **aborted() retorna true** → se cliente desconectou

### API do StreamWriter

```php
interface StreamWriter {
    /**
     * Escreve um pedaço para o cliente.
     * Retorna false se cliente desconectou (você deve parar).
     */
    public function write(string $chunk): bool;

    /**
     * Verifica se cliente ainda está conectado.
     */
    public function aborted(): bool;

    /**
     * Faz flush de todos os níveis de output buffer.
     */
    public function flush(): void;
}
```

### Headers Enviados Automaticamente

```http
Cache-Control: no-cache
X-Accel-Buffering: no
Content-Type: text/plain; charset=utf-8
```

Estes impedem buffer em proxies e cache. Se precisa de cache diferente:

```php
return Response::stream($producer)
    ->withHeader('Cache-Control', 'private, max-age=0')
    ->withHeader('X-Accel-Buffering', 'no');
```

### Requisições HEAD

Enviar HEAD para um endpoint de streaming retorna apenas headers:

```bash
curl -I http://example.com/stream
# HTTP/1.1 200 OK
# Cache-Control: no-cache
# Content-Type: text/plain
# (nenhum body, produtor não roda)
```

### Gerenciamento de Sessão

A sessão é automaticamente fechada antes do streaming iniciar, evitando locks:

```php
return Response::stream(function(StreamWriter $out) {
    // Sessão já está fechada aqui
    // Escrever em $_SESSION depois disso não tem efeito
    
    $out->write("Dados em streaming...");
});
```

Se precisa guardar dados de sessão, faça antes de retornar a Response:

```php
$_SESSION['stream_iniciado'] = true;
session_write_close(); // Explícito (já feito automaticamente)

return Response::stream(function(StreamWriter $out) {
    // Seguro fazer streaming agora
    $out->write("Dados...");
});
```

---

## Server-Sent Events (SSE)

Eventos em tempo real do servidor para o navegador. O navegador abre conexão persistente e recebe eventos conforme são enviados.

### Helper ServerSentEvent

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\ServerSentEvent;

return Response::stream(
    function(StreamWriter $out) {
        $sse = new ServerSentEvent($out);

        // Envia um evento
        $sse->send('Processando...', event: 'status', id: '1');

        // Com intervalo de retry (milissegundos)
        $sse->send('Chunk de dados', event: 'dados', id: '2', retry: 5000);

        // Heartbeat para manter conexão viva
        $sse->heartbeat();

        // Dados multi-linha (cada linha prefixada com 'data:')
        $sse->send("Linha 1\nLinha 2\nLinha 3");
    },
    headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
);
```

### Consumo no navegador

```javascript
const es = new EventSource('/stream/sse');

es.addEventListener('status', (event) => {
    console.log('Status:', event.data);
});

es.addEventListener('dados', (event) => {
    console.log('Dados:', event.data);
});

es.addEventListener('error', () => {
    console.log('Conexão perdida');
    es.close();
});
```

### Formato SSE

Cada evento é formatado como:

```
event: status
id: 1
data: Processando...

```

Dados multi-linha:

```
event: dados
data: Linha 1
data: Linha 2
data: Linha 3

```

Heartbeat (mantém vivo, navegador ignora):

```
: heartbeat

```

---

## Streaming no Cliente (HTTP → PHP)

Receba respostas grandes de serviços upstream em pedaços, sem guardar o corpo inteiro.

### Casos de uso

- **Download de arquivo grande** — Faça stream para disco sem carregar na memória
- **Dados em tempo real** — Processe conforme chega de uma API
- **Proxy de stream** — Receba de upstream, encaminhe para cliente (combine com streaming do servidor)
- **Monitoramento** — Escute stream de logs ou eventos

### Uso básico

```php
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\ClientStreamListener;

Http::stream('/api/export.csv', new class implements ClientStreamListener {
    public function onChunk(string $chunk): bool {
        // Processa cada pedaço conforme chega
        echo $chunk; // ou file_put_contents, acumula, etc.
        
        // Retorna false para abortar transferência
        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        // Chamado após transferência completa ou falha
        if ($statusCode !== 200) {
            error_log("Export falhou: HTTP $statusCode");
        }
    }
});
```

### API ClientStreamListener

```php
interface ClientStreamListener {
    /**
     * Chamado repetidamente conforme pedaços chegam.
     * @param string $chunk Os dados recebidos
     * @return bool True para continuar, false para abortar
     */
    public function onChunk(string $chunk): bool;

    /**
     * Chamado após último pedaço ou se erro ocorre.
     * @param int $statusCode O código HTTP de status
     * @param array<string, string> $headers Headers da resposta
     */
    public function onComplete(int $statusCode, array $headers): void;
}
```

### Status Antes do Primeiro Pedaço

Headers e status estão disponíveis antes do streaming iniciar:

```php
Http::stream('/api/export', new class implements ClientStreamListener {
    public function onChunk(string $chunk): bool {
        // Isto é chamado após status/headers serem conhecidos
        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        // Decisão feita ANTES do streaming?
        if ($statusCode === 401) {
            // Muito tarde; você já recebeu pedaços
            // Verifique status em onChunk() e retorne false para parar
        }
    }
});
```

Melhor: retorne false do onChunk() para abortar:

```php
Http::stream('/api/dados', new class implements ClientStreamListener {
    private int $totalBytes = 0;
    private const MAX_SIZE = 1024 * 1024 * 100; // 100MB

    public function onChunk(string $chunk): bool {
        $this->totalBytes += strlen($chunk);
        
        if ($this->totalBytes > self::MAX_SIZE) {
            error_log('Stream excedeu limite de tamanho');
            return false; // Para de receber
        }

        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        echo "Pronto: $this->totalBytes bytes\n";
    }
});
```

### Segurança UTF-8

Sequências multi-byte UTF-8 podem se dividir entre pedaços. O manipulador do cliente garante nunca serem despedaçadas:

```php
// Entrada: bytes [E2, 98, 83] = ☃ (boneco de neve, 3 bytes)
// Se pedaço termina após byte 1: [E2, 98]
// Próximo pedaço: [83, ...]

// ClientStream segura a sequência incompleta [E2, 98]
// onChunk() NÃO é chamado com [E2, 98]
// Próximo pedaço chega [83, ...], combinado para [E2, 98, 83]
// onChunk() chamado com ☃ completo
```

Isto previne que bibliotecas de processamento engasguem em UTF-8 parcial.

### Encadeando: Proxy de Streams

Receba de upstream, envie para cliente de uma vez:

```php
// GET /download/report
return Response::stream(function(StreamWriter $out) {
    Http::stream('/api/generate-report', new class($out) implements ClientStreamListener {
        public function __construct(private StreamWriter $out) {}

        public function onChunk(string $chunk): bool {
            // Encaminha cada pedaço para cliente imediatamente
            return $this->out->write($chunk);
        }

        public function onComplete(int $statusCode, array $headers): void {
            if ($statusCode !== 200) {
                error_log("Upstream falhou: $statusCode");
            }
        }
    });
});
```

---

## Configuração de Servidor

Streaming requer configuração adequada do servidor para funcionar confiável.

### Servidor Embutido do PHP (`php -S`)

Funciona de imediato:

```bash
./sfphp serve
curl -N http://localhost:8000/stream
```

A flag `-N` desabilita buffer do curl, então você vê pedaços chegarem ao longo do tempo.

### PHP-FPM + Nginx

Desabilite buffer de FastCGI para permitir dados em tempo real:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    
    # Crítico: desabilitar buffer
    fastcgi_buffering off;
    fastcgi_request_buffering off;
    
    # Ou use o header do PHP
    # fastcgi_buffering on;
    # fastcgi_ignore_headers X-Accel-Buffering;
}
```

Ou deixe o app enviar o header:

```nginx
fastcgi_buffering on;
# PHP envia X-Accel-Buffering: no, que tem precedência
```

### Apache + mod_php

Garanta que output buffering está desabilitado:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    
    # Desabilitar proxy buffering
    SetEnv proxy-nokeepalive 1
    SetEnv proxy-initial-not-pooled 1
    SetEnv proxy-sendcl 1
</FilesMatch>
```

Ou com mod_deflate (compressão):

```apache
<IfModule mod_deflate.c>
    # Não comprima respostas de streaming
    SetEnvIfNoCase Request_URI "^/stream" no-gzip
</IfModule>
```

### Caddy

Desabilite buffer para respostas de streaming:

```caddyfile
example.com {
    reverse_proxy localhost:9000 {
        # Desabilitar buffer
        flush_interval -1
    }
}
```

### Load Balancers e Proxies

Se o tráfego passa por um load balancer (HAProxy, Cloudflare, etc.), garanta que não buffer:

- **HAProxy:** `option http-server-close` + não defina `timeout tunnel`
- **Cloudflare:** Desabilite "Rocket Loader" e "Auto Minify" para endpoints de streaming
- **AWS ALB:** Streaming deve funcionar transparentemente

---

## Solução de Problemas

### Dados chegam todos de uma vez, não gradualmente

**Problema:** Pedaços são bufferizados e enviados juntos.

**Solução:**
1. Verifique flag `-N` no curl: `curl -N http://localhost:8000/stream`
2. Verifique output buffering do PHP:
   ```php
   while (ob_get_level() > 0) ob_end_clean();
   ```
3. Verifique configuração de servidor (nginx: `fastcgi_buffering off`)

### Stream nunca inicia

**Problema:** Nenhum dado aparece, conexão fica pendente.

**Solução:**
1. Verifique extensões: `php -m | grep curl`
2. Verifique headers enviados: procure por "200 OK"
3. Verifique se sessão está travando: streaming auto-fecha, mas verifique logs

### Caracteres UTF-8 parciais aparecem

**Problema:** Texto parece corrompido com caracteres quebrados.

**Solução:**
1. Garanta que mbstring está instalado: `apt install php8.3-mbstring`
2. Defina Content-Type correto: `'Content-Type' => 'text/plain; charset=utf-8'`
3. Streaming já manipula limites; se corrompido, verifique dados de origem

### Navegador mostra resposta incompleta

**Problema:** Conexão SSE fecha cedo ou não mostra todos os eventos.

**Solução:**
1. Verifique console do navegador por erros
2. Garanta que URL do EventSource está correta
3. Verifique que servidor envia eventos completos (termina com `\n\n`)
4. Verifique que `onComplete()` não está fechando prematuramente

---

## Considerações de Performance

### Memória

Streaming usa memória mínima comparado a respostas bufferizadas:

```
Bufferizado (1GB arquivo): ~1GB RAM
Stream (1GB arquivo): ~1MB RAM (tamanho do pedaço)
```

### Timeouts

Defina timeouts razoáveis:

```php
// Padrão: 15 segundos total
$http = Http::timeout(30);

// Timeout de conexão: quanto tempo esperar para conectar
$http = Http::connectTimeout(5);

// Para streams longos, considere timeout mais longo ou nenhum
// (Ainda não implementado; usa php.ini max_execution_time)
```

### CPU

Streaming não adiciona overhead de CPU vs. buffering. Ambos processam os mesmos dados.

---

## Ainda não implementado

### Atributo @stream do SFJS

Streaming no navegador é planejado para futura release. Por enquanto:

```html
<!-- Ainda não disponível -->
<div @stream="/api/stream" @target="#output">Carregando...</div>

<!-- Contorno: use JavaScript -->
<script>
const es = new EventSource('/api/stream');
es.onmessage = (e) => {
    document.getElementById('output').textContent += e.data;
};
</script>
```

### Gerenciamento de Timeout

Atualmente usa `php.ini max_execution_time`. Features planejadas:
- Timeout de inatividade por stream
- Timeout de conexão vs. total separados

### Callbacks de Progresso

Streaming do cliente HTTP ainda não relata bytes/percentual. Pode rastrear manualmente:

```php
$totalBytes = 0;
Http::stream('/api/dados', new class($totalBytes) implements ClientStreamListener {
    // ...
    public function onChunk(string $chunk): bool {
        $this->totalBytes += strlen($chunk);
        printf("%.1f MB\n", $this->totalBytes / 1024 / 1024);
        return true;
    }
});
```

---

## Exemplos

### Exemplo 1: Stream de arquivo de log

```php
return Response::stream(function(StreamWriter $out) {
    $file = fopen('/var/log/app.log', 'r');
    fseek($file, -10000, SEEK_END); // Últimos 10KB
    
    while (!feof($file) && !$out->aborted()) {
        $line = fgets($file);
        $out->write($line);
    }
    
    fclose($file);
}, headers: ['Content-Type' => 'text/plain']);
```

### Exemplo 2: Stream de registros de banco de dados

```php
return Response::stream(function(StreamWriter $out) {
    $query = 'SELECT id, name FROM users';
    $stmt = db()->query($query);
    
    $out->write("id,name\n");
    
    foreach ($stmt->fetchAll() as $row) {
        if ($out->aborted()) break;
        $out->write($row['id'] . ',' . $row['name'] . "\n");
    }
}, headers: ['Content-Type' => 'text/csv']);
```

### Exemplo 3: Proxy de streaming upstream

```php
return Response::stream(function(StreamWriter $out) {
    $client = Http::base('https://api.example.com');
    
    $client->stream('/large-file', new class($out) implements ClientStreamListener {
        public function __construct(private StreamWriter $out) {}
        
        public function onChunk(string $chunk): bool {
            return $this->out->write($chunk);
        }
        
        public function onComplete(int $statusCode, array $headers): void {
            if ($statusCode !== 200) {
                $this->out->write("\n[Erro upstream: $statusCode]\n");
            }
        }
    });
}, headers: ['Content-Type' => 'application/octet-stream']);
```

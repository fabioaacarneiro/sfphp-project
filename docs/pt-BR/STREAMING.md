# Streaming HTTP

> **Leia em:** [English](../en/STREAMING.md) · [Português](STREAMING.md) · [Español](../es/STREAMING.md)

Envie e receba corpos HTTP em pedaços, em vez de guardá-los inteiros em buffer.
Uma resposta pode começar a chegar ao navegador antes de terminar de ser
produzida, e uma resposta de outro serviço pode ser processada enquanto ainda
está chegando, então nenhum dos dois lados precisa segurar o corpo inteiro na
memória.

Este guia cobre as três peças:

- **`Response::stream()`** — um controller responde em pedaços, inclusive com
  Server-Sent Events;
- **SFJS `@stream`** — o navegador mostra um stream em um elemento, sem nenhum
  JavaScript seu;
- **`Client::stream()`** — o PHP lê uma resposta de outro serviço conforme ela
  chega.

A referência do framework cobre o restante da camada HTTP:
[Cliente HTTP](DOCUMENTATION.md#cliente-http) e [SFJS](DOCUMENTATION.md#sfjs).

## Extensões PHP necessárias

| Parte | Precisa de |
|---|---|
| Streaming no servidor e SSE (`Response::stream`, `ServerSentEvent`) | Nada além do próprio PHP |
| Streaming no navegador (`@stream`) | Nada no servidor; o SFJS já está em `sfjs.min.js` |
| Streaming no cliente (`Client::stream`, `Client::streamRequest`) | `ext-curl` |

O cliente HTTP é construído sobre o curl. Sem a extensão, uma requisição de
stream lança `ClientException` com *"The curl extension is required to stream
HTTP responses"*, em vez de se degradar. O `mbstring` não tem papel aqui: o
cliente mantém inteiros os caracteres multibyte com uma aritmética de bytes
própria.

```bash
php -m | grep curl          # imprime "curl" quando está instalada
apt install php8.3-curl     # Debian e Ubuntu; o nome do pacote segue a sua versão do PHP
```

---

## Streaming do servidor para o cliente

Envie o corpo da resposta em pedaços conforme ele é gerado. O cliente recebe e
processa cada parte assim que ela chega, sem esperar a resposta inteira.

### Casos de uso

- **Saída de LLM** — envie tokens conforme um modelo os gera
- **Exportações grandes** — arquivos CSV, NDJSON ou de log sem montá-los na memória
- **Progresso** — informe as etapas de uma tarefa longa enquanto ela roda
- **Server-Sent Events** — o navegador se inscreve em um fluxo de eventos
- **Proxy de streams** — leia de um serviço upstream e encaminhe para o cliente

### Uso básico

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(function (StreamWriter $out): void {
    for ($i = 1; $i <= 100; $i++) {
        // O visitante fechou a aba: pare de produzir.
        if ($out->aborted()) {
            break;
        }

        $out->write("Item $i\n");

        usleep(100000); // 100 ms, no lugar de um trabalho real
    }
}, status: 200, headers: [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

`Response::stream(callable $producer, int $status = 200, array $headers = [])`
devolve uma `Response` comum, então ela passa pelo middleware como qualquer
outra. O produtor só roda quando a resposta é emitida, depois que todo
middleware terminou com ela.

### Como funciona

1. **A action retorna** `Response::stream($producer)`. Nada rodou ainda.
2. **O middleware roda** e pode alterar o status e os headers, como em qualquer
   resposta.
3. **O emitter envia o status e os headers.** Um header `Content-Length` é
   descartado, porque o tamanho não é conhecido de antemão.
4. **A sessão é fechada**, para que um stream longo não trave as outras
   requisições do visitante.
5. **Os buffers de saída são esvaziados** e o `zlib.output_compression` é
   desligado, para que um pedaço não fique retido à espera de compressão.
6. **O produtor roda.** Cada `write()` envia o seu pedaço e faz o flush na hora.
7. **Um cliente desconectado** faz o `write()` retornar `false` e o `aborted()`
   retornar `true`. O produtor continua rodando até verificar um dos dois — o
   PHP não o interrompe —, então verifique-os em qualquer laço.

### API do StreamWriter

```php
interface StreamWriter
{
    /** Envia um pedaço e faz o flush. False quando o cliente se desconectou. */
    public function write(string $chunk): bool;

    /** Se o cliente se desconectou. */
    public function aborted(): bool;

    /** Faz o flush de todos os níveis de buffer de saída. O write() já faz isso. */
    public function flush(): void;
}
```

### Headers

O emitter acrescenta dois headers a toda resposta de streaming:

```http
Cache-Control: no-cache
X-Accel-Buffering: no
```

O `X-Accel-Buffering: no` diz ao nginx para não guardar esta resposta em buffer
(veja [Configuração do servidor](#configuração-do-servidor)). Os dois são
definidos **depois** dos seus próprios headers, então hoje não podem ser
sobrescritos: um `Cache-Control` passado em `headers` ou com `withHeader()` é
substituído por `no-cache`.

**Defina o `Content-Type` você mesmo.** Nada escolhe um para um stream, e sem
ele o PHP envia o seu padrão, `text/html; charset=UTF-8`:

```php
headers: ['Content-Type' => 'text/plain; charset=utf-8']        // texto
headers: ['Content-Type' => 'text/event-stream; charset=utf-8'] // Server-Sent Events
headers: ['Content-Type' => 'application/x-ndjson']             // um valor JSON por linha
```

### Requisições HEAD

Uma rota registrada com `Router::get()` responde apenas a `GET`. Uma requisição
`HEAD` para ela recebe `405 Method Not Allowed` com `Allow: GET`. Para responder
a `HEAD`, registre a mesma action para ele:

```php
Router::get('/stream', [StreamController::class, 'text']);
Router::head('/stream', [StreamController::class, 'text']);
```

Em uma resposta de streaming a um `HEAD`, o emitter envia o status e os headers
e **não roda o produtor**:

```bash
curl -I http://localhost:8000/stream
# HTTP/1.1 200 OK
# Content-Type: text/plain; charset=utf-8
# Cache-Control: no-cache
# X-Accel-Buffering: no
```

### Gerenciamento de sessão

A sessão é fechada antes de o produtor rodar. O handler de sessão em arquivo do
PHP trava a sessão enquanto ela estiver aberta, e um stream que a mantivesse
aberta bloquearia todas as outras requisições do mesmo visitante até terminar.

```php
return Response::stream(function (StreamWriter $out): void {
    // A sessão já está fechada aqui: uma alteração em $_SESSION não é salva.
    $out->write("Streaming data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

O que a sessão precisar lembrar é gravado na action, antes de ela retornar a
resposta:

```php
$_SESSION['export_started'] = time();

return Response::stream(function (StreamWriter $out): void {
    $out->write("Data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

---

## Server-Sent Events (SSE)

Server-Sent Events são um formato de texto para um fluxo de eventos nomeados do
servidor para o navegador. O navegador mantém a conexão aberta, lê cada evento
assim que ele chega e se reconecta sozinho quando a conexão cai.

### Helper ServerSentEvent

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\ServerSentEvent;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(
    function (StreamWriter $out): void {
        $sse = new ServerSentEvent($out);

        $sse->send('Processing...', event: 'status', id: 1);

        for ($step = 1; $step <= 3; $step++) {
            if ($out->aborted()) {
                return;
            }

            $sse->send("Step $step of 3", event: 'progress', id: $step + 1);
            usleep(200000);
        }

        // Uma linha de comentário: mantém a conexão viva, e o navegador a ignora.
        $sse->heartbeat();

        // Dados em várias linhas: cada linha recebe o seu próprio prefixo "data:".
        $sse->send("Line 1\nLine 2");

        // O evento final. Sem ele, o navegador se reconecta e começa de novo.
        $sse->send('All done', event: 'complete');
    },
    headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
);
```

```php
public function send(
    string $data,
    ?string $event = null,        // o tipo do evento; o navegador chama um sem nome de "message"
    string|int|null $id = null,   // devolvido pelo navegador como Last-Event-ID ao se reconectar
    ?int $retry = null,           // milissegundos que o navegador espera antes de se reconectar
    ?string $comment = null       // uma linha ": comentário" antes do evento
): bool;

public function heartbeat(): bool;
```

Os dois retornam `false` quando o cliente se desconectou, como o
`StreamWriter::write()`.

### Encerrando o stream

O `EventSource`, o cliente SSE do navegador, não consegue distinguir um stream
que terminou de uma conexão que caiu. Quando o servidor fecha a conexão, o
navegador espera o intervalo de `retry` (alguns segundos, por padrão) e
requisita a URL de novo — então um stream finito sem um encerramento próprio se
repete para sempre.

Encerre um stream finito de uma de duas formas:

- **envie um evento final** — o SFJS fecha a conexão em um evento chamado
  `done` ou `complete`, ou nos nomes dados em `@done` (veja
  [Atributos](#atributos)). Um código seu chama `es.close()` quando vê o
  evento;
- **responda à reconexão com `204 No Content`** — o padrão SSE diz ao navegador
  para parar de tentar, e o `Response::noContent()` a devolve.

### Consumo no navegador

Com o SFJS, o `@stream` e o `@sse` fazem isso por você (veja
[Streaming no navegador](#streaming-no-navegador-sfjs-stream)). Com o
`EventSource` diretamente:

```javascript
const es = new EventSource('/stream/sse');

es.addEventListener('status', (event) => console.log('Status:', event.data));
es.addEventListener('progress', (event) => console.log('Progress:', event.data));
es.addEventListener('message', (event) => console.log('Message:', event.data));

// O evento final: feche, ou o navegador se reconecta.
es.addEventListener('complete', (event) => {
    console.log('Done:', event.data);
    es.close();
});

es.addEventListener('error', () => {
    // CONNECTING significa que o navegador está tentando de novo; CLOSED, que ele desistiu.
    if (es.readyState === EventSource.CLOSED) console.log('Connection closed');
});
```

### Formato SSE

O exemplo acima envia exatamente isto. Cada evento termina com uma linha em
branco:

```
event: status
id: 1
data: Processing...

event: progress
id: 2
data: Step 1 of 3

```

Dados em várias linhas são uma linha `data:` por linha, e o navegador as junta
com `\n` — uma linha termina em `\n`, `\r\n` ou em um `\r` sozinho, como a
especificação a lê. O nome do evento, o id e um comentário são uma linha cada:
uma quebra de linha em qualquer um deles escreveria campos próprios, então o
`send()` a recusa com uma `InvalidArgumentException`. Um evento sem `event:` é
um `message`:

```
data: Line 1
data: Line 2

```

O `retry` e o `comment` acrescentam as suas próprias linhas, antes dos dados:

```php
$sse->send('Data chunk', event: 'data', id: 2, retry: 5000, comment: 'first batch');
```

```
: first batch
event: data
id: 2
retry: 5000
data: Data chunk

```

O heartbeat é uma única linha de comentário, sem linha em branco depois. Ele não
é um evento, e o navegador o ignora:

```
: heartbeat
```

---

## Streaming no navegador (SFJS `@stream`)

O SFJS lê uma resposta em stream para dentro de um elemento da página sem
nenhum JavaScript seu. O streaming faz parte do pacote único do SFJS, então a
única tag de script basta — não existe um arquivo separado de streaming:

```html
<script src="{{ asset('js/sfjs.min.js') }}"></script>
```

### Streams de texto

```html
<pre id="output">Output appears here...</pre>

<button @stream="/stream" @target="#output">Start streaming</button>
```

Cada pedaço é anexado ao alvo conforme chega. O alvo recebe os pedaços como
**texto**, não HTML, então o endpoint deve responder com texto puro
(`Content-Type: text/plain`). Uma marcação enviada pelo stream apareceria tag
por tag. Uma resposta que não seja 2xx mostra `Error: HTTP 500` (a mensagem
`streamError`) no lugar.

### Server-Sent Events

Acrescente `@sse` para ler o endpoint como um fluxo de eventos:

```html
<button @stream="/stream/sse" @sse @events="status,progress" @target="#events">
    Connect
</button>
<pre id="events"></pre>
```

Cada evento é anexado como uma linha: um `message` só com os seus dados,
qualquer outro tipo como `[tipo] dados`. Com o endpoint de
[Helper ServerSentEvent](#helper-serversentevent), o alvo termina contendo:

```
[status] Processing...
[progress] Step 1 of 3
[progress] Step 2 of 3
[progress] Step 3 of 3
Line 1
Line 2


[Connection closed]
```

O evento `complete` fechou a conexão sem ser mostrado, porque não está listado
em `@events`. `[Connection closed]` é a mensagem `streamClosed`.

A forma de ler o stream depende da requisição, e o `@events` significa algo um
pouco diferente em cada caso:

| Requisição | Lida com | `@events` |
|---|---|---|
| `GET` sem `@body` | `EventSource` | Os tipos a mostrar **além de** `message`, que é sempre mostrado. Sem `@events`, só `message` é mostrado |
| Qualquer outro método, ou com `@body` | `fetch` | Um **filtro estrito**: só os tipos listados são mostrados, então liste `message` para mantê-lo. Sem `@events`, todo evento é mostrado |

Os dois caminhos também terminam de formas diferentes. O `EventSource` se
reconecta quando o servidor fecha, e só para em um evento final (`@done`) ou em
um `204`. O caminho do `fetch` não se reconecta: termina quando o servidor fecha
a conexão, e o `@done` não tem efeito sobre ele.

### Atributos

| Atributo | Significado |
|---|---|
| `@stream` | A URL de onde fazer o stream |
| `@target` | Seletor do elemento que recebe a saída (padrão: o próprio elemento) |
| `@sse` | Lê a resposta como Server-Sent Events |
| `@events` | Tipos de evento SSE a mostrar, separados por vírgula (veja a tabela acima) |
| `@done` | Tipos de evento SSE que encerram o stream, separados por vírgula. Padrão `done,complete`. Sem um evento final, o `EventSource` se reconecta quando o servidor fecha e o stream recomeça |
| `@method` | Método HTTP (padrão `GET`) |
| `@body` | Um corpo de requisição em JSON, enviado só com `POST`, `PUT` ou `PATCH` |
| `@trigger` | Quando o stream começa: a mesma lista que o núcleo lê — `click`, `submit`, `load`, `load delay:1s`, `load, every:30s` |
| `@abort` | Seletor de um elemento cujo clique interrompe o stream |

```html
<!-- Começa sozinho, um segundo depois de a página estar pronta -->
<pre @stream="/stream/sse" @sse @trigger="load delay:1s"></pre>

<!-- Um evento final com nome próprio -->
<pre @stream="/import/progress" @sse @events="status" @done="finished"></pre>

<!-- POST com corpo JSON, lido como texto -->
<button @stream="/chat" @method="POST" @body='{"prompt": "Hello"}' @target="#reply">Ask</button>
<pre id="reply"></pre>

<!-- Um botão de parar -->
<button @stream="/stream" @target="#log" @abort="#stop">Start</button>
<button id="stop">Stop</button>
<pre id="log"></pre>
```

O `delay:` aceita `ms`, `s` ou `m` (`300ms`, `2s`, `1m`); um número sozinho é
em segundos. No `load`, ele espera esse tempo antes de começar. Em qualquer
outro evento, espera até que o evento pare de disparar por esse tempo, igual ao
`@trigger` no restante do SFJS.

### Quando um stream começa

Sem `@trigger`, a regra é a mesma do restante do SFJS: **um clique inicia um
botão ou link, um submit inicia um formulário**. Qualquer outro elemento começa
o stream sozinho quando a página está pronta. Um botão dentro de um formulário
faz o stream no clique e envia os campos do formulário como corpo quando o
método é `POST`, `PUT` ou `PATCH`; um campo que se repete é enviado como lista.

Começar de novo substitui a execução em andamento. Um segundo clique interrompe
o stream que ainda está chegando, limpa o alvo e faz o stream desde o início,
então a saída de duas execuções nunca se mistura em uma caixa. O `@abort`
interrompe a execução atual e deixa no lugar o que já chegou.

### Quando um stream para

Um stream termina quando o servidor o encerra (veja a tabela acima), quando o
`@abort` é clicado, ou quando **o seu elemento é removido da página** — por um
swap, por exemplo. A conexão de um elemento removido é fechada, então o servidor
vê o cliente se desconectar e o `aborted()` passa a ser `true`. Elementos
acrescentados à página depois, por um swap ou por um código seu, são vinculados
conforme chegam.

### Acessibilidade e mensagens

O alvo recebe `aria-live="polite"`, a não ser que já tenha um `aria-live`, e
`aria-busy="true"` enquanto os dados estão chegando, para que um leitor de tela
anuncie o resultado uma vez, e não a cada pedaço.

`[Connection closed]` e `Error: …` são as entradas `streamClosed` e
`streamError` de `sf.messages`, e são traduzidas como as demais mensagens do
SFJS.

### CSRF

Os métodos que alteram estado (`POST`, `PUT`, `PATCH`, `DELETE`) enviam o
`<meta name="csrf-token">` da página — que o `csrf_meta()` escreve — como
`X-CSRF-Token`, o header que o `VerifyCsrfToken` aceita.

---

## Streaming no cliente (HTTP → PHP)

Leia uma resposta de outro serviço em pedaços, sem guardar o corpo inteiro em
buffer.

### Casos de uso

- **Downloads grandes** — grave em disco sem carregar o arquivo na memória
- **Dados em tempo real** — processe a saída de uma API conforme ela chega
- **Proxy de streams** — leia do upstream e encaminhe para o cliente (combinado
  com o streaming no servidor)
- **Monitoramento** — acompanhe um stream de log ou um feed de eventos

### Uso básico

O `stream()` fica em um cliente, não na fachada `Http`. Comece do
`Http::base()` para um serviço que você chama por caminho, ou do
`Http::client()` com uma URL absoluta:

```php
use SfphpProject\src\Http\AbstractClientStreamListener;
use SfphpProject\src\Http\Http;

$file = fopen(sys_get_temp_dir() . '/report.csv', 'wb');

Http::base('https://api.example.com')->stream('/export.csv', new class ($file) extends AbstractClientStreamListener {
    /** @param resource $file */
    public function __construct(private $file)
    {
    }

    public function onChunk(string $chunk): bool
    {
        // Cada pedaço conforme chega. Retornar false interrompe a transferência.
        return fwrite($this->file, $chunk) !== false;
    }
});

fclose($file);
```

```php
Http::client()->stream('https://api.example.com/export.csv', $listener);
```

Um caminho relativo sem URL base é passado ao curl como está, e falha.

O `stream()` envia um `GET`. Para qualquer outro método, ou para enviar um
corpo, use o `streamRequest()`:

```php
Http::base('https://api.example.com')
    ->token($apiKey)
    ->streamRequest('POST', '/v1/generate', $listener, ['prompt' => 'Olá']);
```

```php
public function stream(string $url, ClientStreamListener $listener): void;

public function streamRequest(
    string $method,
    string $url,
    ClientStreamListener $listener,
    array|string|null $body = null
): void;
```

O corpo segue as mesmas regras do restante do cliente: um array é enviado como
JSON (ou como formulário depois de `->asForm()`), e uma string é enviada como
está. Os headers, o token, as verificações de certificado e as regras de
redirecionamento do cliente valem também para os streams.

### API do ClientStreamListener

```php
interface ClientStreamListener
{
    /**
     * O status e os headers finais, antes do primeiro onChunk().
     * Retorne false para abortar a transferência antes de ler qualquer corpo.
     */
    public function onStatus(int $statusCode, array $headers): bool;

    /**
     * Um pedaço do corpo. Retorne false para abortar a transferência.
     */
    public function onChunk(string $chunk): bool;

    /**
     * A transferência acabou: o corpo terminou, ou o listener a interrompeu.
     */
    public function onComplete(int $statusCode, array $headers): void;
}
```

Um listener precisa implementar os três. O **`AbstractClientStreamListener`**
implementa o `onStatus()` (continuar) e o `onComplete()` (não fazer nada), então
uma classe que o estende só escreve o `onChunk()` e sobrescreve o que precisar.
Uma classe anônima que implementa a interface e deixa de fora o `onStatus()` é
um erro fatal.

As chamadas vêm nesta ordem:

1. `onStatus()` — uma vez, quando os headers da resposta chegaram, antes de
   qualquer corpo;
2. `onChunk()` — uma vez por pedaço, enquanto retornar `true`;
3. `onComplete()` — uma vez, depois do último pedaço, e também depois de o
   `onStatus()` ou o `onChunk()` retornar `false`.

**Uma falha de transporte não é uma conclusão.** Uma conexão recusada, um host
que não resolve, um certificado que falha, um timeout: cada um lança
`ClientException`, e o `onComplete()` **não** é chamado. Um status de erro HTTP
é uma conclusão — um 500 é uma resposta — e passa pelo `onStatus()` e pelo
`onComplete()` como um 200.

```php
use SfphpProject\src\Http\ClientException;

try {
    Http::base('https://api.example.com')->stream('/export.csv', $listener);
} catch (ClientException $e) {
    logger()->error('export stream failed', ['detail' => $e->getMessage()]);
}
```

### Status antes do primeiro pedaço

Sobrescreva o `onStatus()` para decidir com base no status antes de ler
qualquer corpo. Retornar `false` aborta a transferência: nenhum pedaço é
entregue, e o `onComplete()` é chamado com o status.

```php
Http::base('https://api.example.com')->stream('/export', new class extends AbstractClientStreamListener {
    public function onStatus(int $statusCode, array $headers): bool
    {
        if ($statusCode >= 400) {
            error_log("Export refused: HTTP $statusCode");

            return false; // nada do corpo é lido
        }

        return true;
    }

    public function onChunk(string $chunk): bool
    {
        echo $chunk;

        return true;
    }
});
```

> **Redirecionamentos.** O `onStatus()` ouve só a resposta final. Um
> redirecionamento que o cliente está prestes a seguir — um `3xx` com um
> `Location` — e um `1xx` não são informados, então um listener que recusa tudo
> o que não seja `200` vê o `200` no fim da cadeia. Antes ele ouvia o `302`
> primeiro e abortava nele.

Para parar no meio, retorne `false` do `onChunk()`:

```php
Http::base('https://api.example.com')->stream('/data', new class extends AbstractClientStreamListener {
    private const LIMIT = 100 * 1024 * 1024; // 100 MB

    private int $bytes = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        return $this->bytes <= self::LIMIT; // false interrompe a transferência
    }

    public function onComplete(int $statusCode, array $headers): void
    {
        echo "Stopped after {$this->bytes} bytes\n";
    }
});
```

### Segurança UTF-8

Um caractere UTF-8 multibyte pode ser dividido entre duas leituras de rede. O
cliente nunca entrega meio caractere ao `onChunk()`: os bytes incompletos no fim
de uma leitura são retidos e entregues com a próxima.

```php
// O servidor envia "a☃b" em duas escritas: "a\xE2" e "\x98\x83b".
// O onChunk() recebe "a", depois "☃b" — nunca um "\xE2" sozinho.
```

Isso vale para o cliente PHP, e o SFJS faz o mesmo no navegador. O lado do
servidor escreve os bytes exatamente como você os entrega ao `write()`, então um
produtor que corta a própria string em um offset de bytes ainda pode enviar meio
caractere.

### Encadeamento: proxy de streams

Leia do upstream e encaminhe para o seu próprio cliente conforme chega:

```php
use SfphpProject\src\Http\AbstractClientStreamListener;
use SfphpProject\src\Http\ClientException;
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

// GET /download/report
return Response::stream(function (StreamWriter $out): void {
    $listener = new class ($out) extends AbstractClientStreamListener {
        public function __construct(private StreamWriter $out)
        {
        }

        public function onChunk(string $chunk): bool
        {
            // O write() é false quando o nosso próprio cliente foi embora, o que interrompe também a transferência do upstream.
            return $this->out->write($chunk);
        }
    };

    try {
        Http::base('https://reports.internal')->stream('/generate', $listener);
    } catch (ClientException $e) {
        logger()->error('upstream stream failed', ['detail' => $e->getMessage()]);
        $out->write("\n[The report service is unavailable]\n");
    }
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

O status e os headers da resposta são enviados antes de o produtor rodar, então
quando o upstream responde eles já não podem ser alterados. Uma falha do
upstream tem de ser informada no corpo, como acima.

### Timeouts

Um stream tem timeouts diferentes dos de uma requisição comum:

| | Requisição comum | Stream |
|---|---|---|
| Conexão | 5 s | 5 s |
| A troca inteira | 15 s | **Sem limite**, a não ser que você defina um |
| Sem receber nenhum dado | — | **30 s** (`idleTimeout()`) |

Um stream que continua enviando nunca é cortado por ser longo; um que fica em
silêncio por mais tempo que o timeout de ociosidade é abortado com
`ClientException`. O silêncio é medido como menos de um byte por segundo, e o
curl o verifica em janelas de alguns segundos, então o aborto pode vir alguns
segundos depois do limite.

```php
$client = Http::base('https://api.example.com')
    ->timeout(300, 5)     // no máximo 300 s no total, 5 s para conectar
    ->idleTimeout(60);    // aborta depois de 60 s sem dados; 0 desativa

Http::timeout(300, connect: 5); // o mesmo, pela fachada
```

Um timeout total passado ao `timeout()` vale também para os streams — exceto
`15`, o valor padrão, que um stream entende como "não definido" e substitui por
sem limite.

---

## Configuração do servidor

Cada camada entre o PHP e o navegador pode guardar uma resposta em buffer, e um
stream em buffer chega todo de uma vez, no fim. O emitter faz a sua parte (ele
esvazia os buffers de saída do PHP, desliga o `zlib.output_compression` e envia
`X-Accel-Buffering: no`); o servidor web e o que estiver na frente dele precisam
fazer a deles.

### Servidor embutido do PHP (`php -S`)

Funciona sem configuração:

```bash
./sfphp serve
curl -N http://localhost:8000/stream
```

A flag `-N` desliga o buffer do próprio curl, para você ver os pedaços chegarem
ao longo do tempo. O servidor embutido atende uma requisição por vez, a não ser
que `PHP_CLI_SERVER_WORKERS` esteja definida, então uma página que abre um
stream e depois faz outra requisição espera o stream terminar. Ele serve apenas
para desenvolvimento.

### PHP-FPM + Nginx

O nginx guarda as respostas FastCGI em buffer por padrão, e respeita o header
`X-Accel-Buffering: no` que o emitter envia: uma resposta de streaming não vai
para o buffer, e todas as outras continuam indo. Nenhuma diretiva de buffer é
necessária.

```nginx
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;

    # Quanto tempo o nginx espera entre duas leituras do PHP. Um stream que
    # fica em silêncio por mais tempo é cortado; envie heartbeats com mais frequência que isso.
    fastcgi_read_timeout 300s;
}
```

Para desligar o buffer de todas as respostas, use `fastcgi_buffering off;`.
**Não** liste `X-Accel-Buffering` em `fastcgi_ignore_headers`: isso faz o nginx
ignorar o header e voltar a guardar o stream em buffer.

A compressão também retém os pedaços. O nginx só comprime `text/html`, a não
ser que `gzip_types` diga o contrário, então deixe `text/event-stream` (e o tipo
de qualquer outro stream) fora de `gzip_types`, ou defina `gzip off;` na
location de streaming.

### Apache + PHP-FPM (mod_proxy_fcgi)

Diga ao `mod_proxy_fcgi` para repassar cada pacote conforme chega, com
`flushpackets=on` no worker FastCGI:

```apache
<Proxy "fcgi://localhost/" enablereuse=on flushpackets=on>
</Proxy>

<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
</FilesMatch>

# O mod_deflate guarda em buffer para comprimir: mantenha-o longe das URLs de streaming.
<IfModule mod_deflate.c>
    SetEnvIfNoCase Request_URI "^/stream" no-gzip
</IfModule>
```

Ajuste o padrão de `Request_URI` às suas rotas de streaming. Sob o PHP-FPM, a
aplicação não consegue desligar a compressão pelo PHP, então é essa linha que
faz isso.

### Apache + mod_php

O emitter chama `apache_setenv('no-gzip', 1)` para toda resposta de streaming,
o que impede o `mod_deflate` de comprimi-la, e a saída do PHP vai direto para o
Apache. Nenhuma configuração é necessária.

### Caddy

O `php_fastcgi` é o front end FastCGI do Caddy, e aceita as mesmas opções que o
`reverse_proxy`. O Caddy já faz o flush de uma resposta sem `Content-Length`,
que um stream nunca tem; o `flush_interval -1` deixa isso explícito:

```caddyfile
example.com {
    root * /var/www/app/public

    php_fastcgi unix//run/php/php8.3-fpm.sock {
        flush_interval -1
    }

    file_server
}
```

### Load balancers e proxies

Qualquer coisa na frente do servidor web fecha uma conexão que fica em silêncio
por tempo demais — **60 segundos** por padrão em um Application Load Balancer da
AWS, cerca de **100 segundos** no Cloudflare. Um stream que pausa por mais tempo
que isso é cortado, e, no caso do SSE, o navegador então se reconecta.

- Não deixe o stream ficar em silêncio: envie `$sse->heartbeat()` (ou qualquer
  dado) com mais frequência que o menor timeout de ociosidade do caminho — a
  cada 15 a 30 segundos é uma escolha comum.
- Ou aumente o limite: o idle timeout em um ALB, `timeout server` (e
  `timeout tunnel`) no HAProxy, `proxy_read_timeout` em um nginx que faz proxy
  para outro nginx.
- Um proxy que guarda em buffer ou comprime as respostas precisa deixar os
  streams em paz, do mesmo jeito que os servidores acima.

---

## Solução de problemas

### Os dados chegam todos de uma vez, não aos poucos

**Problema:** os pedaços ficam em buffer e são entregues juntos, no fim.

**Solução:**
1. Teste com `curl -N`, que não usa buffer: `curl -N http://localhost:8000/stream`.
   Se ali os pedaços chegam aos poucos, o buffer está no caminho até o
   navegador, não no PHP.
2. Verifique o servidor web: o nginx não pode ignorar o `X-Accel-Buffering`, e o
   Apache com PHP-FPM precisa de `flushpackets=on` (veja
   [Configuração do servidor](#configuração-do-servidor)).
3. Verifique a compressão: o gzip no servidor web, em uma CDN ou em um proxy
   guarda em buffer para comprimir.
4. Verifique o `Content-Type`: `text/html` (o que o PHP envia quando você não
   define nenhum) é o tipo que a maioria dos servidores comprime.

### O stream nunca começa

**Problema:** nada chega, ou a requisição falha antes do primeiro pedaço.

**Solução:**
1. `Cannot emit the response: output already started at …` significa que algo
   imprimiu saída antes de a resposta ser emitida — um `echo`, um `var_dump`,
   ou bytes fora de uma tag PHP. A mensagem indica o arquivo e a linha.
2. Uma requisição `HEAD` precisa de `Router::head()`; uma rota `GET` responde a
   ela com 405.
3. No streaming no cliente, `ClientException` com *"The curl extension is
   required"* significa que falta o `ext-curl`: `php -m | grep curl`.

### Outras requisições travam enquanto um stream está aberto

**Problema:** o resto do site para de responder para o visitante que abriu o
stream.

**Solução:**
1. Sob `php -S`, é o servidor embutido atendendo uma requisição por vez: defina
   `PHP_CLI_SERVER_WORKERS=4`, ou use o PHP-FPM.
2. Sob o PHP-FPM, cada stream aberto ocupa um worker. Quando todos os
   `pm.max_children` workers estão fazendo stream, as novas requisições esperam
   — veja [Workers](#workers).
3. A sessão é fechada antes de o produtor rodar, então não é a trava da sessão
   — a não ser que algo reabra a sessão dentro do produtor.

### Aparecem caracteres UTF-8 partidos

**Problema:** o texto mostra caracteres quebrados.

**Solução:**
1. Declare o charset: `'Content-Type' => 'text/plain; charset=utf-8'`.
2. Verifique o produtor: o servidor escreve os bytes exatamente como recebe,
   então uma string cortada com `substr()` em um offset de bytes envia meio
   caractere. Corte com `mb_substr()`, ou envie linhas inteiras.
3. O cliente PHP e o SFJS remontam os caracteres divididos entre leituras. Se o
   texto também está quebrado ali, os bytes já vieram quebrados da origem.

### O navegador repete um stream SSE

**Problema:** os eventos aparecem de novo e de novo, ou o stream nunca termina.

**Solução:** o servidor fechou a conexão sem um evento final, e o `EventSource`
se reconectou. Encerre o stream com um evento chamado `done` ou `complete` (ou
os nomes em `@done`), ou responda à reconexão com `204` (veja
[Encerrando o stream](#encerrando-o-stream)).

### O navegador mostra eventos SSE incompletos

**Problema:** alguns eventos nunca aparecem.

**Solução:**
1. Verifique erros no console do navegador.
2. Verifique os tipos de evento: em um `GET`, o SFJS mostra `message` mais os
   tipos em `@events`; pelo `fetch` (outro método, ou `@body`), só os tipos em
   `@events`.
3. Verifique se o `Content-Type` da resposta é `text/event-stream`; o
   `EventSource` recusa qualquer outro.
4. Todo evento precisa terminar com uma linha em branco. O
   `ServerSentEvent::send()` faz isso; um evento escrito à mão com `write()`
   também precisa fazer.

---

## Considerações de desempenho

### Memória

Um stream segura um pedaço por vez, em vez do corpo inteiro:

```
Buffered (1 GB file): about 1 GB of memory
Streamed (1 GB file): about the size of one chunk
```

Isso só vale se o produtor não montar o corpo inteiro antes. Um produtor que
chama `fetchAll()` e depois escreve linha por linha já gastou a memória — veja o
[Exemplo 2](#exemplo-2-stream-de-registros-do-banco-de-dados).

### Workers

Sob o PHP-FPM, um stream ocupa um worker enquanto estiver aberto. Mil
visitantes, cada um com uma conexão SSE, precisam de mil workers, e as
requisições que chegam depois de atingido o `pm.max_children` esperam um deles
terminar. Dimensione o pool para os streams que você espera, mantenha os streams
curtos, e não use SSE para algo que uma requisição periódica faria igualmente
bem.

O `request_terminate_timeout` do pool do FPM encerra uma requisição que roda
por mais tempo que ele permite, stream ou não, e o `max_execution_time` continua
valendo para o produtor (no Linux ele costuma contar tempo de CPU, não tempo
gasto esperando). Aumente-os, ou chame `set_time_limit()` no produtor, para um
stream que deve rodar por muito tempo.

### CPU

O streaming não acrescenta trabalho de CPU em comparação com o buffer: os mesmos
bytes são produzidos e enviados, só que mais cedo.

---

## Ainda não implementado

### Callbacks de progresso

O cliente HTTP não informa o progresso (bytes ou porcentagem) por conta própria.
Conte no listener:

```php
Http::base('https://files.example.com')->stream('/backup.tar', new class extends AbstractClientStreamListener {
    private int $bytes = 0;

    private int $reported = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        // Uma vez por megabyte, não uma vez por pedaço.
        if ($this->bytes - $this->reported >= 1024 * 1024) {
            $this->reported = $this->bytes;
            printf("%.1f MB received\n", $this->bytes / 1024 / 1024);
        }

        return true;
    }

    public function onComplete(int $statusCode, array $headers): void
    {
        printf("Done: %.1f MB, HTTP %d\n", $this->bytes / 1024 / 1024, $statusCode);
    }
});
```

Para uma porcentagem, leia o header `Content-Length` no `onStatus()`, quando o
servidor enviar um.

### Sobrescrever os headers de streaming

`Cache-Control: no-cache` e `X-Accel-Buffering: no` são sempre enviados e
substituem um valor seu (veja [Headers](#headers)).

---

## Exemplos

### Exemplo 1: stream de um arquivo de log

```php
return Response::stream(function (StreamWriter $out): void {
    $path = '/var/log/app/app.log';
    $file = fopen($path, 'rb');

    if ($file === false) {
        $out->write("The log could not be opened.\n");

        return;
    }

    // Os últimos 10 KB, ou o arquivo inteiro se for menor.
    fseek($file, -min(10240, filesize($path)), SEEK_END);

    while (($line = fgets($file)) !== false) {
        if (!$out->write($line)) {
            break; // o cliente foi embora
        }
    }

    fclose($file);
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

### Exemplo 2: stream de registros do banco de dados

```php
use SfphpProject\src\Database;

return Response::stream(function (StreamWriter $out): void {
    // O execute() devolve o PDOStatement, que é lido uma linha por vez.
    $rows = Database::query('SELECT id, name FROM users ORDER BY id')->execute();
    $line = fopen('php://temp', 'r+');

    $out->write("id,name\n");

    foreach ($rows as $row) {
        // O fputcsv põe entre aspas um nome que contém vírgula ou aspas.
        rewind($line);
        ftruncate($line, 0);
        fputcsv($line, [$row['id'], $row['name']], escape: '');
        rewind($line);

        if (!$out->write(stream_get_contents($line))) {
            break;
        }
    }
}, headers: [
    'Content-Type' => 'text/csv; charset=utf-8',
    'Content-Disposition' => 'attachment; filename="users.csv"',
]);
```

Iterar o statement, em vez de chamar `fetchAll()` ou `->get()`, mantém as
linhas fora da memória do PHP até onde o driver permite. No MySQL, o PDO guarda
o resultado inteiro em buffer no cliente por padrão; para uma exportação muito
grande, desligue isso na consulta com
`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`.

### Exemplo 3: proxy de streaming upstream

```php
return Response::stream(function (StreamWriter $out): void {
    $client = Http::base('https://api.example.com')->idleTimeout(60);

    $client->stream('/large-file', new class ($out) extends AbstractClientStreamListener {
        public function __construct(private StreamWriter $out)
        {
        }

        public function onStatus(int $statusCode, array $headers): bool
        {
            if ($statusCode >= 400) {
                $this->out->write("[Upstream error: HTTP $statusCode]\n");

                return false;
            }

            return true;
        }

        public function onChunk(string $chunk): bool
        {
            return $this->out->write($chunk);
        }
    });
}, headers: ['Content-Type' => 'application/octet-stream']);
```

Uma falha de transporte lança `ClientException` para fora do produtor; capture-a,
como em [Encadeamento](#encadeamento-proxy-de-streams), para escrever algo que o
cliente consiga ler.

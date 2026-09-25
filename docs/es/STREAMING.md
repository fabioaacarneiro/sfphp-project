# Streaming HTTP

> **Lee en:** [English](../en/STREAMING.md) · [Português](../pt-BR/STREAMING.md) · [Español](STREAMING.md)

Envía y recibe cuerpos HTTP en fragmentos, en lugar de guardarlos enteros en
buffer. Una respuesta puede empezar a llegar al navegador antes de terminar de
producirse, y una respuesta de otro servicio puede procesarse mientras todavía
está llegando, así que ninguno de los dos lados tiene que retener el cuerpo
entero en memoria.

Esta guía cubre las tres piezas:

- **`Response::stream()`** — un controller responde en fragmentos, incluso con
  Server-Sent Events;
- **SFJS `@stream`** — el navegador muestra un stream en un elemento, sin
  JavaScript propio;
- **`Client::stream()`** — PHP lee una respuesta de otro servicio a medida que
  llega.

La referencia del framework cubre el resto de la capa HTTP:
[Cliente HTTP](DOCUMENTATION.md#cliente-http) y [SFJS](DOCUMENTATION.md#sfjs).

## Extensiones PHP necesarias

| Parte | Necesita |
|---|---|
| Streaming en el servidor y SSE (`Response::stream`, `ServerSentEvent`) | Nada más allá del propio PHP |
| Streaming en el navegador (`@stream`) | Nada en el servidor; SFJS ya está en `sfjs.min.js` |
| Streaming en el cliente (`Client::stream`, `Client::streamRequest`) | `ext-curl` |

El cliente HTTP está construido sobre curl. Sin la extensión, una petición de
stream lanza `ClientException` con *"The curl extension is required to stream
HTTP responses"*, en lugar de degradarse. `mbstring` no tiene ningún papel
aquí: el cliente mantiene enteros los caracteres multibyte con una aritmética
de bytes propia.

```bash
php -m | grep curl          # imprime "curl" cuando está instalada
apt install php8.3-curl     # Debian y Ubuntu; el nombre del paquete sigue tu versión de PHP
```

---

## Streaming del servidor al cliente

Envía el cuerpo de la respuesta en fragmentos a medida que se genera. El
cliente recibe y procesa cada parte en cuanto llega, sin esperar la respuesta
entera.

### Casos de uso

- **Salida de LLM** — envía tokens a medida que un modelo los genera
- **Exportaciones grandes** — archivos CSV, NDJSON o de log sin armarlos en memoria
- **Progreso** — informa de los pasos de una tarea larga mientras se ejecuta
- **Server-Sent Events** — el navegador se suscribe a un flujo de eventos
- **Proxy de streams** — lee de un servicio upstream y reenvía al cliente

### Uso básico

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(function (StreamWriter $out): void {
    for ($i = 1; $i <= 100; $i++) {
        // El visitante cerró la pestaña: deja de producir.
        if ($out->aborted()) {
            break;
        }

        $out->write("Item $i\n");

        usleep(100000); // 100 ms, en lugar de un trabajo real
    }
}, status: 200, headers: [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

`Response::stream(callable $producer, int $status = 200, array $headers = [])`
devuelve una `Response` normal, así que pasa por el middleware como cualquier
otra. El productor solo se ejecuta cuando la respuesta se emite, después de que
todo middleware haya terminado con ella.

### Cómo funciona

1. **La acción devuelve** `Response::stream($producer)`. Todavía no se ha
   ejecutado nada.
2. **El middleware se ejecuta** y puede cambiar el status y las cabeceras, como
   en cualquier respuesta.
3. **El emitter envía el status y las cabeceras.** Una cabecera
   `Content-Length` se descarta, porque la longitud no se conoce de antemano.
4. **La sesión se cierra**, para que un stream largo no bloquee las demás
   peticiones del visitante.
5. **Los buffers de salida se vacían** y `zlib.output_compression` se desactiva,
   para que un fragmento no quede retenido a la espera de ser comprimido.
6. **El productor se ejecuta.** Cada `write()` envía su fragmento y hace el
   flush en el acto.
7. **Un cliente desconectado** hace que `write()` devuelva `false` y `aborted()`
   devuelva `true`. El productor sigue ejecutándose hasta que comprueba uno de
   los dos — PHP no lo detiene —, así que compruébalos en cualquier bucle.

### API de StreamWriter

```php
interface StreamWriter
{
    /** Envía un fragmento y hace el flush. False cuando el cliente se ha desconectado. */
    public function write(string $chunk): bool;

    /** Si el cliente se ha desconectado. */
    public function aborted(): bool;

    /** Hace el flush de todos los niveles de buffer de salida. write() ya lo hace. */
    public function flush(): void;
}
```

### Cabeceras

El emitter añade dos cabeceras a toda respuesta de streaming:

```http
Cache-Control: no-cache
X-Accel-Buffering: no
```

`X-Accel-Buffering: no` le dice a nginx que no guarde esta respuesta en buffer
(consulta [Configuración del servidor](#configuración-del-servidor)). Las dos se
definen **después** de tus propias cabeceras, así que hoy no se pueden
sobrescribir: un `Cache-Control` pasado en `headers` o con `withHeader()` se
reemplaza por `no-cache`.

**Define el `Content-Type` tú mismo.** Nada elige uno para un stream, y sin él
PHP envía el suyo por defecto, `text/html; charset=UTF-8`:

```php
headers: ['Content-Type' => 'text/plain; charset=utf-8']        // texto
headers: ['Content-Type' => 'text/event-stream; charset=utf-8'] // Server-Sent Events
headers: ['Content-Type' => 'application/x-ndjson']             // un valor JSON por línea
```

### Peticiones HEAD

Una ruta registrada con `Router::get()` responde solo a `GET`. Una petición
`HEAD` a ella recibe `405 Method Not Allowed` con `Allow: GET`. Para responder
a `HEAD`, registra la misma acción para él:

```php
Router::get('/stream', [StreamController::class, 'text']);
Router::head('/stream', [StreamController::class, 'text']);
```

En una respuesta de streaming a un `HEAD`, el emitter envía el status y las
cabeceras y **no ejecuta el productor**:

```bash
curl -I http://localhost:8000/stream
# HTTP/1.1 200 OK
# Content-Type: text/plain; charset=utf-8
# Cache-Control: no-cache
# X-Accel-Buffering: no
```

### Gestión de la sesión

La sesión se cierra antes de que se ejecute el productor. El handler de sesión
en archivos de PHP bloquea la sesión mientras está abierta, y un stream que la
mantuviera abierta bloquearía todas las demás peticiones del mismo visitante
hasta terminar.

```php
return Response::stream(function (StreamWriter $out): void {
    // La sesión ya está cerrada aquí: un cambio en $_SESSION no se guarda.
    $out->write("Streaming data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

Lo que la sesión tenga que recordar se escribe en la acción, antes de que
devuelva la respuesta:

```php
$_SESSION['export_started'] = time();

return Response::stream(function (StreamWriter $out): void {
    $out->write("Data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

---

## Server-Sent Events (SSE)

Server-Sent Events es un formato de texto para un flujo de eventos con nombre
del servidor al navegador. El navegador mantiene la conexión abierta, lee cada
evento en cuanto llega y se reconecta solo cuando la conexión se cae.

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

        // Una línea de comentario: mantiene viva la conexión, y el navegador la ignora.
        $sse->heartbeat();

        // Datos en varias líneas: cada línea recibe su propio prefijo "data:".
        $sse->send("Line 1\nLine 2");

        // El evento final. Sin él, el navegador se reconecta y empieza de nuevo.
        $sse->send('All done', event: 'complete');
    },
    headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
);
```

```php
public function send(
    string $data,
    ?string $event = null,        // el tipo de evento; el navegador llama "message" a uno sin nombre
    string|int|null $id = null,   // el navegador lo devuelve como Last-Event-ID al reconectarse
    ?int $retry = null,           // milisegundos que el navegador espera antes de reconectarse
    ?string $comment = null       // una línea ": comentario" antes del evento
): bool;

public function heartbeat(): bool;
```

Los dos devuelven `false` cuando el cliente se ha desconectado, como
`StreamWriter::write()`.

### Terminar el stream

`EventSource`, el cliente SSE del navegador, no puede distinguir un stream que
terminó de una conexión que se cayó. Cuando el servidor cierra la conexión, el
navegador espera el intervalo de `retry` (unos segundos, por defecto) y vuelve
a pedir la URL — así que un stream finito sin un final propio se repite para
siempre.

Termina un stream finito de una de estas dos formas:

- **envía un evento final** — SFJS cierra la conexión con un evento llamado
  `done` o `complete`, o con los nombres dados en `@done` (consulta
  [Atributos](#atributos)). Tu propio código llama a `es.close()` cuando ve el
  evento;
- **responde a la reconexión con `204 No Content`** — el estándar SSE le dice
  al navegador que deje de reintentar, y `Response::noContent()` la devuelve.

### Consumo en el navegador

Con SFJS, `@stream` y `@sse` lo hacen por ti (consulta
[Streaming en el navegador](#streaming-en-el-navegador-sfjs-stream)). Con
`EventSource` directamente:

```javascript
const es = new EventSource('/stream/sse');

es.addEventListener('status', (event) => console.log('Status:', event.data));
es.addEventListener('progress', (event) => console.log('Progress:', event.data));
es.addEventListener('message', (event) => console.log('Message:', event.data));

// El evento final: cierra, o el navegador se reconecta.
es.addEventListener('complete', (event) => {
    console.log('Done:', event.data);
    es.close();
});

es.addEventListener('error', () => {
    // CONNECTING significa que el navegador está reintentando; CLOSED, que se rindió.
    if (es.readyState === EventSource.CLOSED) console.log('Connection closed');
});
```

### Formato SSE

El ejemplo anterior envía exactamente esto. Cada evento termina con una línea
en blanco:

```
event: status
id: 1
data: Processing...

event: progress
id: 2
data: Step 1 of 3

```

Los datos en varias líneas son una línea `data:` por línea, y el navegador las
une con `\n` — una línea termina en `\n`, `\r\n` o un `\r` suelto, tal como lo lee la
especificación. El nombre del evento, el id y un comentario ocupan una línea cada uno: un salto
de línea en cualquiera de ellos escribiría campos propios, así que `send()` lo rechaza con una
`InvalidArgumentException`. Un evento sin `event:` es un `message`:

```
data: Line 1
data: Line 2

```

`retry` y `comment` añaden sus propias líneas, antes de los datos:

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

El heartbeat es una única línea de comentario, sin línea en blanco después. No
es un evento, y el navegador lo ignora:

```
: heartbeat
```

---

## Streaming en el navegador (SFJS `@stream`)

SFJS lee una respuesta en stream dentro de un elemento de la página sin
JavaScript propio. El streaming forma parte del paquete único de SFJS, así que
basta con la única etiqueta de script — no hay un archivo de streaming aparte:

```html
<script src="{{ asset('js/sfjs.min.js') }}"></script>
```

### Streams de texto

```html
<pre id="output">Output appears here...</pre>

<button @stream="/stream" @target="#output">Start streaming</button>
```

Cada fragmento se añade al objetivo a medida que llega. El objetivo recibe los
fragmentos como **texto**, no como HTML, así que el endpoint debe responder con
texto plano (`Content-Type: text/plain`). Un marcado enviado por el stream
aparecería etiqueta por etiqueta. Una respuesta que no sea 2xx muestra
`Error: HTTP 500` (el mensaje `streamError`) en su lugar.

### Server-Sent Events

Añade `@sse` para leer el endpoint como un flujo de eventos:

```html
<button @stream="/stream/sse" @sse @events="status,progress" @target="#events">
    Connect
</button>
<pre id="events"></pre>
```

Cada evento se añade como una línea: un `message` solo con sus datos, cualquier
otro tipo como `[tipo] datos`. Con el endpoint de
[Helper ServerSentEvent](#helper-serversentevent), el objetivo acaba
conteniendo:

```
[status] Processing...
[progress] Step 1 of 3
[progress] Step 2 of 3
[progress] Step 3 of 3
Line 1
Line 2


[Connection closed]
```

El evento `complete` cerró la conexión sin mostrarse, porque no está en la
lista de `@events`. `[Connection closed]` es el mensaje `streamClosed`.

La forma de leer el stream depende de la petición, y `@events` significa algo
ligeramente distinto en cada caso:

| Petición | Se lee con | `@events` |
|---|---|---|
| `GET` sin `@body` | `EventSource` | Los tipos a mostrar **además de** `message`, que siempre se muestra. Sin `@events`, solo se muestra `message` |
| Cualquier otro método, o con `@body` | `fetch` | Un **filtro estricto**: solo se muestran los tipos listados, así que incluye `message` para conservarlo. Sin `@events`, se muestran todos los eventos |

Los dos caminos también terminan de forma distinta. `EventSource` se reconecta
cuando el servidor cierra, y solo se detiene con un evento final (`@done`) o un
`204`. El camino de `fetch` no se reconecta: termina cuando el servidor cierra
la conexión, y `@done` no tiene efecto en él.

### Atributos

| Atributo | Significado |
|---|---|
| `@stream` | La URL de la que hacer el stream |
| `@target` | Selector del elemento que recibe la salida (por defecto: el propio elemento) |
| `@sse` | Lee la respuesta como Server-Sent Events |
| `@events` | Tipos de evento SSE a mostrar, separados por comas (consulta la tabla anterior) |
| `@done` | Tipos de evento SSE que terminan el stream, separados por comas. Por defecto `done,complete`. Sin un evento final, `EventSource` se reconecta cuando el servidor cierra y el stream empieza de nuevo |
| `@method` | Método HTTP (por defecto `GET`) |
| `@body` | Un cuerpo de petición en JSON, enviado solo con `POST`, `PUT` o `PATCH` |
| `@trigger` | Cuándo empieza el stream: la misma lista que lee el núcleo — `click`, `submit`, `load`, `load delay:1s`, `load, every:30s` |
| `@abort` | Selector de un elemento cuyo clic detiene el stream |

```html
<!-- Empieza solo, un segundo después de que la página esté lista -->
<pre @stream="/stream/sse" @sse @trigger="load delay:1s"></pre>

<!-- Un evento final con nombre propio -->
<pre @stream="/import/progress" @sse @events="status" @done="finished"></pre>

<!-- POST con cuerpo JSON, leído como texto -->
<button @stream="/chat" @method="POST" @body='{"prompt": "Hello"}' @target="#reply">Ask</button>
<pre id="reply"></pre>

<!-- Un botón de detener -->
<button @stream="/stream" @target="#log" @abort="#stop">Start</button>
<button id="stop">Stop</button>
<pre id="log"></pre>
```

`delay:` acepta `ms`, `s` o `m` (`300ms`, `2s`, `1m`); un número solo son
segundos. En `load`, espera ese tiempo antes de empezar. En cualquier otro
evento, espera hasta que el evento haya dejado de dispararse durante ese
tiempo, igual que `@trigger` en el resto de SFJS.

### Cuándo empieza un stream

Sin `@trigger`, la regla es la misma que en el resto de SFJS: **un clic inicia
un botón o un enlace, un submit inicia un formulario**. Cualquier otro elemento
empieza el stream por sí solo cuando la página está lista. Un botón dentro de un
formulario hace el stream al hacer clic y envía los campos del formulario como
cuerpo cuando el método es `POST`, `PUT` o `PATCH`; un campo que se repite se
envía como lista.

Empezar de nuevo reemplaza la ejecución en curso. Un segundo clic detiene el
stream que todavía está llegando, vacía el objetivo y hace el stream desde el
principio, así que la salida de dos ejecuciones nunca se mezcla en una caja.
`@abort` detiene la ejecución actual y deja en su sitio lo que ya llegó.

### Cuándo se detiene un stream

Un stream termina cuando el servidor lo termina (consulta la tabla anterior),
cuando se hace clic en `@abort`, o cuando **su elemento se quita de la
página** — por un swap, por ejemplo. La conexión de un elemento quitado se
cierra, así que el servidor ve que el cliente se desconecta y `aborted()` pasa
a ser `true`. Los elementos que se añaden a la página después, por un swap o
por tu propio código, se vinculan a medida que llegan.

### Accesibilidad y mensajes

El objetivo recibe `aria-live="polite"`, salvo que ya tenga un `aria-live`, y
`aria-busy="true"` mientras llegan datos, para que un lector de pantalla anuncie
el resultado una vez y no con cada fragmento.

`[Connection closed]` y `Error: …` son las entradas `streamClosed` y
`streamError` de `sf.messages`, y se traducen como el resto de los mensajes de
SFJS.

### CSRF

Los métodos que cambian estado (`POST`, `PUT`, `PATCH`, `DELETE`) envían el
`<meta name="csrf-token">` de la página — que escribe `csrf_meta()` — como
`X-CSRF-Token`, la cabecera que acepta `VerifyCsrfToken`.

---

## Streaming en el cliente (HTTP → PHP)

Lee una respuesta de otro servicio en fragmentos, sin guardar el cuerpo entero
en buffer.

### Casos de uso

- **Descargas grandes** — escribe en disco sin cargar el archivo en memoria
- **Datos en tiempo real** — procesa la salida de una API a medida que llega
- **Proxy de streams** — lee del upstream y reenvía al cliente (combinado con
  el streaming en el servidor)
- **Monitorización** — sigue un stream de log o un feed de eventos

### Uso básico

`stream()` vive en un cliente, no en la fachada `Http`. Parte de
`Http::base()` para un servicio al que llamas por ruta, o de `Http::client()`
con una URL absoluta:

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
        // Cada fragmento a medida que llega. Devolver false detiene la transferencia.
        return fwrite($this->file, $chunk) !== false;
    }
});

fclose($file);
```

```php
Http::client()->stream('https://api.example.com/export.csv', $listener);
```

Una ruta relativa sin URL base se pasa a curl tal cual, y falla.

`stream()` envía un `GET`. Para cualquier otro método, o para enviar un cuerpo,
usa `streamRequest()`:

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

El cuerpo sigue las mismas reglas que el resto del cliente: un array se envía
como JSON (o como formulario después de `->asForm()`), y una cadena se envía
tal cual. Las cabeceras, el token, las comprobaciones de certificado y las
reglas de redirección del cliente también se aplican a los streams.

### API de ClientStreamListener

```php
interface ClientStreamListener
{
    /**
     * El status y las cabeceras finales, antes del primer onChunk().
     * Devuelve false para abortar la transferencia antes de leer cualquier cuerpo.
     */
    public function onStatus(int $statusCode, array $headers): bool;

    /**
     * Un fragmento del cuerpo. Devuelve false para abortar la transferencia.
     */
    public function onChunk(string $chunk): bool;

    /**
     * La transferencia ha terminado: el cuerpo acabó, o el listener la detuvo.
     */
    public function onComplete(int $statusCode, array $headers): void;
}
```

Un listener tiene que implementar los tres. **`AbstractClientStreamListener`**
implementa `onStatus()` (continuar) y `onComplete()` (no hacer nada), así que
una clase que lo extiende solo escribe `onChunk()` y sobrescribe lo que
necesite. Una clase anónima que implementa la interfaz y deja fuera
`onStatus()` es un error fatal.

Las llamadas llegan en este orden:

1. `onStatus()` — una vez, cuando han llegado las cabeceras de la respuesta,
   antes de cualquier cuerpo;
2. `onChunk()` — una vez por fragmento, mientras devuelva `true`;
3. `onComplete()` — una vez, después del último fragmento, y también después de
   que `onStatus()` u `onChunk()` devolvieran `false`.

**Un fallo de transporte no es una finalización.** Una conexión rechazada, un
host que no resuelve, un certificado que falla, un timeout: cada uno lanza
`ClientException`, y `onComplete()` **no** se llama. Un status de error HTTP es
una finalización — un 500 es una respuesta — y pasa por `onStatus()` y
`onComplete()` como un 200.

```php
use SfphpProject\src\Http\ClientException;

try {
    Http::base('https://api.example.com')->stream('/export.csv', $listener);
} catch (ClientException $e) {
    logger()->error('export stream failed', ['detail' => $e->getMessage()]);
}
```

### Status antes del primer fragmento

Sobrescribe `onStatus()` para decidir según el status antes de leer cualquier
cuerpo. Devolver `false` aborta la transferencia: no se entrega ningún
fragmento, y `onComplete()` se llama con el status.

```php
Http::base('https://api.example.com')->stream('/export', new class extends AbstractClientStreamListener {
    public function onStatus(int $statusCode, array $headers): bool
    {
        if ($statusCode >= 400) {
            error_log("Export refused: HTTP $statusCode");

            return false; // no se lee nada del cuerpo
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

> **Redirecciones.** `onStatus()` solo oye la respuesta final. Una redirección que el
> cliente está a punto de seguir — un `3xx` con `Location` — y un `1xx` no se
> notifican, así que un listener que rechaza todo lo que no sea `200` ve el
> `200` al final de la cadena. Antes oía primero el `302` y abortaba en
> él.

Para detenerte a la mitad, devuelve `false` desde `onChunk()`:

```php
Http::base('https://api.example.com')->stream('/data', new class extends AbstractClientStreamListener {
    private const LIMIT = 100 * 1024 * 1024; // 100 MB

    private int $bytes = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        return $this->bytes <= self::LIMIT; // false detiene la transferencia
    }

    public function onComplete(int $statusCode, array $headers): void
    {
        echo "Stopped after {$this->bytes} bytes\n";
    }
});
```

### Seguridad UTF-8

Un carácter UTF-8 multibyte puede quedar dividido entre dos lecturas de red. El
cliente nunca le entrega medio carácter a `onChunk()`: los bytes incompletos al
final de una lectura se retienen y se entregan con la siguiente.

```php
// El servidor envía "a☃b" en dos escrituras: "a\xE2" y "\x98\x83b".
// onChunk() recibe "a", y luego "☃b" — nunca un "\xE2" suelto.
```

Esto se aplica al cliente PHP, y SFJS hace lo mismo en el navegador. El lado
del servidor escribe los bytes exactamente como se los das a `write()`, así que
un productor que corta su propia cadena en un offset de bytes todavía puede
enviar medio carácter.

### Encadenamiento: proxy de streams

Lee del upstream y reenvía a tu propio cliente a medida que llega:

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
            // write() es false cuando nuestro propio cliente se ha ido, lo que detiene también la transferencia del upstream.
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

El status y las cabeceras de la respuesta se envían antes de que se ejecute el
productor, así que, para cuando el upstream responde, ya no se pueden cambiar.
Un fallo del upstream tiene que informarse en el cuerpo, como arriba.

### Timeouts

Un stream tiene tiempos de espera distintos de los de una petición normal:

| | Petición normal | Stream |
|---|---|---|
| Conexión | 5 s | 5 s |
| El intercambio entero | 15 s | **Sin límite**, salvo que definas uno |
| Sin recibir ningún dato | — | **30 s** (`idleTimeout()`) |

Un stream que sigue enviando nunca se corta por ser largo; uno que se queda en
silencio más tiempo que el timeout de inactividad se aborta con
`ClientException`. El silencio se mide como menos de un byte por segundo, y
curl lo comprueba en ventanas de unos segundos, así que el aborto puede llegar
unos segundos después del límite.

```php
$client = Http::base('https://api.example.com')
    ->timeout(300, 5)     // como máximo 300 s en total, 5 s para conectar
    ->idleTimeout(60);    // aborta tras 60 s sin datos; 0 lo desactiva

Http::timeout(300, connect: 5); // lo mismo, desde la fachada
```

Un timeout total pasado a `timeout()` también se aplica a los streams — salvo
`15`, el valor por defecto, que un stream interpreta como "no definido" y
reemplaza por sin límite.

---

## Configuración del servidor

Cada capa entre PHP y el navegador puede guardar una respuesta en buffer, y un
stream en buffer llega todo de golpe, al final. El emitter hace su parte (vacía
los buffers de salida de PHP, desactiva `zlib.output_compression` y envía
`X-Accel-Buffering: no`); el servidor web y lo que tenga delante tienen que
hacer la suya.

### Servidor integrado de PHP (`php -S`)

Funciona sin configuración:

```bash
./sfphp serve
curl -N http://localhost:8000/stream
```

La opción `-N` desactiva el buffer propio de curl, para que veas llegar los
fragmentos a lo largo del tiempo. El servidor integrado atiende una petición a
la vez, salvo que `PHP_CLI_SERVER_WORKERS` esté definida, así que una página
que abre un stream y luego hace otra petición espera a que el stream termine.
Es solo para desarrollo.

### PHP-FPM + Nginx

nginx guarda en buffer las respuestas FastCGI por defecto, y respeta la
cabecera `X-Accel-Buffering: no` que envía el emitter: una respuesta de
streaming no va al buffer, y todas las demás siguen yendo. No hace falta
ninguna directiva de buffer.

```nginx
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;

    # Cuánto espera nginx entre dos lecturas de PHP. Un stream que se queda
    # en silencio más tiempo se corta; envía heartbeats con más frecuencia que esto.
    fastcgi_read_timeout 300s;
}
```

Para desactivar el buffer en todas las respuestas, usa `fastcgi_buffering off;`.
**No** incluyas `X-Accel-Buffering` en `fastcgi_ignore_headers`: eso hace que
nginx ignore la cabecera y vuelva a guardar el stream en buffer.

La compresión también retiene los fragmentos. nginx solo comprime `text/html`,
salvo que `gzip_types` diga lo contrario, así que deja `text/event-stream` (y
el tipo de cualquier otro stream) fuera de `gzip_types`, o define `gzip off;`
en la location de streaming.

### Apache + PHP-FPM (mod_proxy_fcgi)

Dile a `mod_proxy_fcgi` que pase cada paquete a medida que llega, con
`flushpackets=on` en el worker FastCGI:

```apache
<Proxy "fcgi://localhost/" enablereuse=on flushpackets=on>
</Proxy>

<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
</FilesMatch>

# mod_deflate guarda en buffer para comprimir: mantenlo lejos de las URLs de streaming.
<IfModule mod_deflate.c>
    SetEnvIfNoCase Request_URI "^/stream" no-gzip
</IfModule>
```

Ajusta el patrón de `Request_URI` a tus rutas de streaming. Con PHP-FPM, la
aplicación no puede desactivar la compresión desde PHP, así que es esta línea
la que lo hace.

### Apache + mod_php

El emitter llama a `apache_setenv('no-gzip', 1)` en toda respuesta de
streaming, lo que impide que `mod_deflate` la comprima, y la salida de PHP va
directamente a Apache. No hace falta ninguna configuración.

### Caddy

`php_fastcgi` es el front end FastCGI de Caddy, y acepta las mismas opciones
que `reverse_proxy`. Caddy ya hace el flush de una respuesta sin
`Content-Length`, que un stream nunca tiene; `flush_interval -1` lo deja
explícito:

```caddyfile
example.com {
    root * /var/www/app/public

    php_fastcgi unix//run/php/php8.3-fpm.sock {
        flush_interval -1
    }

    file_server
}
```

### Balanceadores de carga y proxies

Cualquier cosa delante del servidor web cierra una conexión que se queda en
silencio demasiado tiempo — **60 segundos** por defecto en un Application Load
Balancer de AWS, unos **100 segundos** en Cloudflare. Un stream que se pausa
más que eso se corta, y, en el caso de SSE, el navegador entonces se reconecta.

- Evita que el stream se quede en silencio: envía `$sse->heartbeat()` (o
  cualquier dato) con más frecuencia que el timeout de inactividad más corto
  del camino — cada 15 a 30 segundos es una elección habitual.
- O sube el límite: el idle timeout en un ALB, `timeout server` (y
  `timeout tunnel`) en HAProxy, `proxy_read_timeout` en un nginx que hace de
  proxy hacia otro nginx.
- Un proxy que guarda en buffer o comprime las respuestas tiene que dejar en
  paz los streams, igual que los servidores anteriores.

---

## Solución de problemas

### Los datos llegan todos de golpe, no poco a poco

**Problema:** los fragmentos se guardan en buffer y se entregan juntos al final.

**Solución:**
1. Prueba con `curl -N`, que no usa buffer: `curl -N http://localhost:8000/stream`.
   Si ahí los fragmentos llegan poco a poco, el buffer está en el camino hacia
   el navegador, no en PHP.
2. Revisa el servidor web: nginx no debe ignorar `X-Accel-Buffering`, y Apache
   con PHP-FPM necesita `flushpackets=on` (consulta
   [Configuración del servidor](#configuración-del-servidor)).
3. Revisa la compresión: gzip en el servidor web, en una CDN o en un proxy
   guarda en buffer para comprimir.
4. Revisa el `Content-Type`: `text/html` (lo que PHP envía cuando no das
   ninguno) es el tipo que la mayoría de los servidores comprime.

### El stream nunca empieza

**Problema:** no llega nada, o la petición falla antes del primer fragmento.

**Solución:**
1. `Cannot emit the response: output already started at …` significa que algo
   imprimió salida antes de que se emitiera la respuesta — un `echo`, un
   `var_dump`, o bytes fuera de una etiqueta PHP. El mensaje indica el archivo
   y la línea.
2. Una petición `HEAD` necesita `Router::head()`; una ruta `GET` le responde
   con 405.
3. En el streaming en el cliente, `ClientException` con *"The curl extension is
   required"* significa que falta `ext-curl`: `php -m | grep curl`.

### Otras peticiones se cuelgan mientras un stream está abierto

**Problema:** el resto del sitio deja de responder al visitante que abrió el
stream.

**Solución:**
1. Con `php -S`, es el servidor integrado atendiendo una petición a la vez:
   define `PHP_CLI_SERVER_WORKERS=4`, o usa PHP-FPM.
2. Con PHP-FPM, cada stream abierto ocupa un worker. Cuando todos los
   `pm.max_children` workers están haciendo stream, las peticiones nuevas
   esperan — consulta [Workers](#workers).
3. La sesión se cierra antes de que se ejecute el productor, así que no es el
   bloqueo de la sesión — salvo que algo vuelva a abrir la sesión dentro del
   productor.

### Aparecen caracteres UTF-8 partidos

**Problema:** el texto muestra caracteres rotos.

**Solución:**
1. Declara el charset: `'Content-Type' => 'text/plain; charset=utf-8'`.
2. Revisa el productor: el servidor escribe los bytes exactamente como los
   recibe, así que una cadena cortada con `substr()` en un offset de bytes
   envía medio carácter. Corta con `mb_substr()`, o envía líneas enteras.
3. El cliente PHP y SFJS vuelven a unir los caracteres divididos entre
   lecturas. Si el texto también está roto ahí, los bytes ya venían rotos del
   origen.

### El navegador repite un stream SSE

**Problema:** los eventos aparecen una y otra vez, o el stream nunca termina.

**Solución:** el servidor cerró la conexión sin un evento final, y
`EventSource` se reconectó. Termina el stream con un evento llamado `done` o
`complete` (o los nombres de `@done`), o responde a la reconexión con `204`
(consulta [Terminar el stream](#terminar-el-stream)).

### El navegador muestra eventos SSE incompletos

**Problema:** algunos eventos nunca aparecen.

**Solución:**
1. Revisa si hay errores en la consola del navegador.
2. Revisa los tipos de evento: en un `GET`, SFJS muestra `message` más los
   tipos de `@events`; a través de `fetch` (otro método, o `@body`), solo los
   tipos de `@events`.
3. Comprueba que el `Content-Type` de la respuesta sea `text/event-stream`;
   `EventSource` rechaza cualquier otro.
4. Todo evento tiene que terminar con una línea en blanco.
   `ServerSentEvent::send()` lo hace; un evento escrito a mano con `write()`
   también tiene que hacerlo.

---

## Consideraciones de rendimiento

### Memoria

Un stream retiene un fragmento a la vez en lugar del cuerpo entero:

```
Buffered (1 GB file): about 1 GB of memory
Streamed (1 GB file): about the size of one chunk
```

Eso solo es cierto si el productor no arma antes el cuerpo entero. Un productor
que llama a `fetchAll()` y luego escribe fila por fila ya ha gastado la
memoria — consulta el
[Ejemplo 2](#ejemplo-2-stream-de-registros-de-la-base-de-datos).

### Workers

Con PHP-FPM, un stream ocupa un worker mientras está abierto. Mil visitantes,
cada uno con una conexión SSE, necesitan mil workers, y las peticiones que
llegan una vez alcanzado `pm.max_children` esperan a que uno termine.
Dimensiona el pool para los streams que esperas, mantén los streams cortos, y
no uses SSE para algo que una petición periódica haría igual de bien.

`request_terminate_timeout` en el pool de FPM termina una petición que dura más
de lo que permite, sea un stream o no, y `max_execution_time` sigue aplicándose
al productor (en Linux suele contar tiempo de CPU, no tiempo pasado esperando).
Súbelos, o llama a `set_time_limit()` en el productor, para un stream pensado
para durar mucho.

### CPU

El streaming no añade trabajo de CPU en comparación con el buffer: se producen
y envían los mismos bytes, solo que antes.

---

## Aún no implementado

### Callbacks de progreso

El cliente HTTP no informa del progreso (bytes o porcentaje) por sí solo.
Cuenta en el listener:

```php
Http::base('https://files.example.com')->stream('/backup.tar', new class extends AbstractClientStreamListener {
    private int $bytes = 0;

    private int $reported = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        // Una vez por megabyte, no una vez por fragmento.
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

Para un porcentaje, lee la cabecera `Content-Length` en `onStatus()`, cuando el
servidor envíe una.

### Sobrescribir las cabeceras de streaming

`Cache-Control: no-cache` y `X-Accel-Buffering: no` se envían siempre y
reemplazan un valor tuyo (consulta [Cabeceras](#cabeceras)).

---

## Ejemplos

### Ejemplo 1: stream de un archivo de log

```php
return Response::stream(function (StreamWriter $out): void {
    $path = '/var/log/app/app.log';
    $file = fopen($path, 'rb');

    if ($file === false) {
        $out->write("The log could not be opened.\n");

        return;
    }

    // Los últimos 10 KB, o el archivo entero si es más pequeño.
    fseek($file, -min(10240, filesize($path)), SEEK_END);

    while (($line = fgets($file)) !== false) {
        if (!$out->write($line)) {
            break; // el cliente se ha ido
        }
    }

    fclose($file);
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

### Ejemplo 2: stream de registros de la base de datos

```php
use SfphpProject\src\Database;

return Response::stream(function (StreamWriter $out): void {
    // execute() devuelve el PDOStatement, que se lee fila a fila.
    $rows = Database::query('SELECT id, name FROM users ORDER BY id')->execute();
    $line = fopen('php://temp', 'r+');

    $out->write("id,name\n");

    foreach ($rows as $row) {
        // fputcsv pone entre comillas un nombre que contiene una coma o una comilla.
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

Iterar el statement, en lugar de llamar a `fetchAll()` o `->get()`, mantiene
las filas fuera de la memoria de PHP hasta donde el driver lo permite. En
MySQL, PDO guarda en buffer el resultado entero en el cliente por defecto; para
una exportación muy grande, desactívalo en la consulta con
`PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`.

### Ejemplo 3: proxy de streaming upstream

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

Un fallo de transporte lanza `ClientException` fuera del productor; captúralo,
como en [Encadenamiento](#encadenamiento-proxy-de-streams), para escribir algo
que el cliente pueda leer.

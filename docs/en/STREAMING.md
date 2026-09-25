# HTTP Streaming

> **Read in:** [English](STREAMING.md) · [Português](../pt-BR/STREAMING.md) · [Español](../es/STREAMING.md)

Send and receive HTTP bodies in chunks instead of buffering them whole. A
response can start reaching the browser before it has finished being produced,
and a response from another service can be processed while it is still
arriving, so neither side has to hold the whole body in memory.

This guide covers the three pieces:

- **`Response::stream()`** — a controller answers in chunks, including
  Server-Sent Events;
- **SFJS `@stream`** — the browser shows a stream in an element, with no
  JavaScript of your own;
- **`Client::stream()`** — PHP reads a response from another service as it
  arrives.

The framework reference covers the rest of the HTTP layer:
[HTTP client](DOCUMENTATION.md#http-client) and [SFJS](DOCUMENTATION.md#sfjs).

## Required PHP Extensions

| Part | Needs |
|---|---|
| Server streaming and SSE (`Response::stream`, `ServerSentEvent`) | Nothing beyond PHP itself |
| Browser streaming (`@stream`) | Nothing on the server; SFJS is already in `sfjs.min.js` |
| Client streaming (`Client::stream`, `Client::streamRequest`) | `ext-curl` |

The HTTP client is built on curl. Without the extension, a stream request throws
`ClientException` with *"The curl extension is required to stream HTTP
responses"* rather than degrading. `mbstring` plays no part here: the client
keeps multi-byte characters whole with byte arithmetic of its own.

```bash
php -m | grep curl          # prints "curl" when it is installed
apt install php8.3-curl     # Debian and Ubuntu; the package name follows your PHP version
```

---

## Server-to-Client Streaming

Send the response body in chunks as it is generated. The client receives and
processes each piece as it arrives, without waiting for the whole response.

### Use Cases

- **LLM output** — send tokens as a model generates them
- **Large exports** — CSV, NDJSON or log files without building them in memory
- **Progress** — report the steps of a long task while it runs
- **Server-Sent Events** — the browser subscribes to a stream of events
- **Proxy streams** — read from an upstream service and forward to the client

### Basic Usage

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(function (StreamWriter $out): void {
    for ($i = 1; $i <= 100; $i++) {
        // The visitor closed the tab: stop producing.
        if ($out->aborted()) {
            break;
        }

        $out->write("Item $i\n");

        usleep(100000); // 100 ms, standing in for real work
    }
}, status: 200, headers: [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

`Response::stream(callable $producer, int $status = 200, array $headers = [])`
returns an ordinary `Response`, so it goes through middleware like any other.
The producer runs only when the response is emitted, after every middleware has
finished with it.

### How It Works

1. **The action returns** `Response::stream($producer)`. Nothing has run yet.
2. **Middleware runs** and can change the status and headers, as for any
   response.
3. **The emitter sends the status and headers.** A `Content-Length` header is
   dropped, because the length is not known in advance.
4. **The session is closed**, so a long stream does not lock the visitor's other
   requests.
5. **Output buffers are cleared** and `zlib.output_compression` is turned off,
   so a chunk is not held back to be compressed.
6. **The producer runs.** Each `write()` sends its chunk and flushes it at once.
7. **A disconnected client** makes `write()` return `false` and `aborted()`
   return `true`. The producer keeps running until it checks one of them —
   PHP does not stop it — so check them in any loop.

### StreamWriter API

```php
interface StreamWriter
{
    /** Send a chunk and flush it. False when the client has disconnected. */
    public function write(string $chunk): bool;

    /** Whether the client has disconnected. */
    public function aborted(): bool;

    /** Flush every output buffer level. write() already does this. */
    public function flush(): void;
}
```

### Headers

The emitter adds two headers to every streaming response:

```http
Cache-Control: no-cache
X-Accel-Buffering: no
```

`X-Accel-Buffering: no` tells nginx not to buffer this response (see
[Server Configuration](#server-configuration)). Both are set **after** your own
headers, so they cannot currently be overridden: a `Cache-Control` passed in
`headers` or with `withHeader()` is replaced by `no-cache`.

**Set `Content-Type` yourself.** Nothing picks one for a stream, and without it
PHP sends its default, `text/html; charset=UTF-8`:

```php
headers: ['Content-Type' => 'text/plain; charset=utf-8']        // text
headers: ['Content-Type' => 'text/event-stream; charset=utf-8'] // Server-Sent Events
headers: ['Content-Type' => 'application/x-ndjson']             // one JSON value per line
```

### HEAD Requests

A route registered with `Router::get()` answers only `GET`. A `HEAD` request to
it is answered with `405 Method Not Allowed` and `Allow: GET`. To answer `HEAD`,
register the same action for it:

```php
Router::get('/stream', [StreamController::class, 'text']);
Router::head('/stream', [StreamController::class, 'text']);
```

For a streaming response to `HEAD`, the emitter sends the status and headers and
**does not run the producer**:

```bash
curl -I http://localhost:8000/stream
# HTTP/1.1 200 OK
# Content-Type: text/plain; charset=utf-8
# Cache-Control: no-cache
# X-Accel-Buffering: no
```

### Session Management

The session is closed before the producer runs. PHP's file session handler
locks the session for as long as it is open, and a stream that kept it open
would block every other request from the same visitor until it finished.

```php
return Response::stream(function (StreamWriter $out): void {
    // The session is already closed here: a change to $_SESSION is not saved.
    $out->write("Streaming data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

Anything the session must remember is written in the action, before it returns
the response:

```php
$_SESSION['export_started'] = time();

return Response::stream(function (StreamWriter $out): void {
    $out->write("Data...\n");
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

---

## Server-Sent Events (SSE)

Server-Sent Events are a text format for a stream of named events from the
server to the browser. The browser keeps the connection open, reads each event
as it arrives, and reconnects by itself when the connection drops.

### ServerSentEvent Helper

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

        // A comment line: keeps the connection alive, and the browser ignores it.
        $sse->heartbeat();

        // Multi-line data: each line gets its own "data:" prefix.
        $sse->send("Line 1\nLine 2");

        // The final event. Without it, the browser reconnects and starts over.
        $sse->send('All done', event: 'complete');
    },
    headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
);
```

```php
public function send(
    string $data,
    ?string $event = null,        // the event type; the browser calls an unnamed one "message"
    string|int|null $id = null,   // sent back by the browser as Last-Event-ID when it reconnects
    ?int $retry = null,           // milliseconds the browser waits before reconnecting
    ?string $comment = null       // a ": comment" line before the event
): bool;

public function heartbeat(): bool;
```

Both return `false` when the client has disconnected, like `StreamWriter::write()`.

### Ending the Stream

`EventSource`, the browser's SSE client, cannot tell a stream that finished from
a connection that dropped. When the server closes the connection, the browser
waits the `retry` interval (a few seconds by default) and requests the URL
again — so a finite stream with no ending of its own replays forever.

End a finite stream in one of two ways:

- **send a final event** — SFJS closes the connection on an event named `done`
  or `complete`, or on the names given in `@done` (see
  [Attributes](#attributes)). Code of your own calls `es.close()` when it sees
  the event;
- **answer the reconnection with `204 No Content`** — the SSE standard tells
  the browser to stop retrying, and `Response::noContent()` returns it.

### Browser Consumption

With SFJS, `@stream` and `@sse` do this for you (see
[Streaming in the Browser](#streaming-in-the-browser-sfjs-stream)). With
`EventSource` directly:

```javascript
const es = new EventSource('/stream/sse');

es.addEventListener('status', (event) => console.log('Status:', event.data));
es.addEventListener('progress', (event) => console.log('Progress:', event.data));
es.addEventListener('message', (event) => console.log('Message:', event.data));

// The final event: close, or the browser reconnects.
es.addEventListener('complete', (event) => {
    console.log('Done:', event.data);
    es.close();
});

es.addEventListener('error', () => {
    // CONNECTING means the browser is retrying; CLOSED means it gave up.
    if (es.readyState === EventSource.CLOSED) console.log('Connection closed');
});
```

### SSE Format

The example above sends exactly this. Each event ends with a blank line:

```
event: status
id: 1
data: Processing...

event: progress
id: 2
data: Step 1 of 3

```

Multi-line data is one `data:` line per line, and the browser joins them with
`\n` — a line ends at `\n`, `\r\n` or a lone `\r`, as the specification
reads it. The event name, the id and a comment are one line each: a line break
in any of them would write fields of its own, so `send()` refuses it with an
`InvalidArgumentException`. An event without `event:` is a `message`:

```
data: Line 1
data: Line 2

```

`retry` and `comment` add their own lines, before the data:

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

The heartbeat is a single comment line, with no blank line after it. It is not
an event, and the browser ignores it:

```
: heartbeat
```

---

## Streaming in the Browser (SFJS `@stream`)

SFJS reads a streamed response into a page element without any JavaScript of
your own. Streaming is part of the single SFJS bundle, so the one script tag is
all it takes — there is no separate streaming file:

```html
<script src="{{ asset('js/sfjs.min.js') }}"></script>
```

### Text streams

```html
<pre id="output">Output appears here...</pre>

<button @stream="/stream" @target="#output">Start streaming</button>
```

Each chunk is appended to the target as it arrives. The target receives the
chunks as **text**, not HTML, so the endpoint should answer with plain text
(`Content-Type: text/plain`). Markup sent down the stream would show up tag by
tag. A response that is not 2xx shows `Error: HTTP 500` (the `streamError`
message) instead.

### Server-Sent Events

Add `@sse` to read the endpoint as an event stream:

```html
<button @stream="/stream/sse" @sse @events="status,progress" @target="#events">
    Connect
</button>
<pre id="events"></pre>
```

Each event is appended as a line: a `message` as its data alone, any other type
as `[type] data`. With the endpoint from [ServerSentEvent Helper](#serversentevent-helper),
the target ends up holding:

```
[status] Processing...
[progress] Step 1 of 3
[progress] Step 2 of 3
[progress] Step 3 of 3
Line 1
Line 2


[Connection closed]
```

The `complete` event closed the connection without being shown, because it is
not listed in `@events`. `[Connection closed]` is the `streamClosed` message.

How the stream is read depends on the request, and `@events` means something
slightly different in each case:

| Request | Read with | `@events` |
|---|---|---|
| `GET` without `@body` | `EventSource` | The types to show **besides** `message`, which is always shown. Without `@events`, only `message` is shown |
| Any other method, or with `@body` | `fetch` | A **strict filter**: only the listed types are shown, so list `message` to keep it. Without `@events`, every event is shown |

The two paths also end differently. `EventSource` reconnects when the server
closes, and stops only on a final event (`@done`) or a `204`. The `fetch` path
does not reconnect: it ends when the server closes the connection, and `@done`
has no effect on it.

### Attributes

| Attribute | Meaning |
|---|---|
| `@stream` | The URL to stream from |
| `@target` | Selector of the element that receives the output (default: the element itself) |
| `@sse` | Read the response as Server-Sent Events |
| `@events` | Comma-separated SSE event types to show (see the table above) |
| `@done` | Comma-separated SSE event types that end the stream. Default `done,complete`. Without a final event, `EventSource` reconnects when the server closes and the stream starts over |
| `@method` | HTTP method (default `GET`) |
| `@body` | A JSON request body, sent only with `POST`, `PUT` or `PATCH` |
| `@trigger` | When the stream starts: the same list the core reads — `click`, `submit`, `load`, `load delay:1s`, `load, every:30s` |
| `@abort` | Selector of an element whose click stops the stream |

```html
<!-- Starts by itself, one second after the page is ready -->
<pre @stream="/stream/sse" @sse @trigger="load delay:1s"></pre>

<!-- A final event with a name of its own -->
<pre @stream="/import/progress" @sse @events="status" @done="finished"></pre>

<!-- POST with a JSON body, read as text -->
<button @stream="/chat" @method="POST" @body='{"prompt": "Hello"}' @target="#reply">Ask</button>
<pre id="reply"></pre>

<!-- A stop button -->
<button @stream="/stream" @target="#log" @abort="#stop">Start</button>
<button id="stop">Stop</button>
<pre id="log"></pre>
```

`delay:` takes `ms`, `s` or `m` (`300ms`, `2s`, `1m`); a bare number is seconds.
On `load`, it waits that long before starting. On any other event, it waits
until the event has stopped firing for that long, the same as `@trigger`
elsewhere in SFJS.

### When a stream starts

Without `@trigger`, the rule is the same as the rest of SFJS: **a click starts
a button or link, a submit starts a form**. Any other element starts streaming
on its own when the page is ready. A button inside a form streams on click and
sends the form's fields as the body when the method is `POST`, `PUT` or
`PATCH`; a field that repeats is sent as a list.

Starting again replaces the run in progress. A second click stops the stream
that is still arriving, clears the target and streams from the beginning, so
the output of two runs never mixes in one box. `@abort` stops the current run
and leaves what already arrived in place.

### When a stream stops

A stream ends when the server finishes it (see the table above), when `@abort`
is clicked, or when **its element is removed from the page** — by a swap, for
example. A removed element's connection is closed, so the server sees the
client disconnect and `aborted()` becomes `true`. Elements added to the page
later, by a swap or by your own code, are bound as they arrive.

### Accessibility and messages

The target gets `aria-live="polite"`, unless it already has an `aria-live`, and
`aria-busy="true"` while data is arriving, so a screen reader announces the
result once rather than every chunk.

`[Connection closed]` and `Error: …` are the `streamClosed` and `streamError`
entries of `sf.messages`, and are translated like the rest of SFJS's messages.

### CSRF

State-changing methods (`POST`, `PUT`, `PATCH`, `DELETE`) send the page's
`<meta name="csrf-token">` — which `csrf_meta()` writes — as `X-CSRF-Token`,
the header `VerifyCsrfToken` accepts.

---

## Client-Side Streaming (HTTP → PHP)

Read a response from another service in chunks, without buffering the whole
body.

### Use Cases

- **Large downloads** — write to disk without loading the file into memory
- **Real-time data** — process an API's output as it arrives
- **Proxy streams** — read from upstream and forward to the client (combined
  with server streaming)
- **Monitoring** — follow a log stream or an event feed

### Basic Usage

`stream()` lives on a client, not on the `Http` facade. Start from
`Http::base()` for a service you call by path, or from `Http::client()` with an
absolute URL:

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
        // Each chunk as it arrives. Returning false stops the transfer.
        return fwrite($this->file, $chunk) !== false;
    }
});

fclose($file);
```

```php
Http::client()->stream('https://api.example.com/export.csv', $listener);
```

A relative path with no base URL is passed to curl as it stands, and fails.

`stream()` sends a `GET`. For any other method, or to send a body, use
`streamRequest()`:

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

The body follows the same rules as the rest of the client: an array is sent as
JSON (or as a form after `->asForm()`), and a string is sent as it is. The
client's headers, token, certificate checks and redirect rules apply to streams
too.

### ClientStreamListener API

```php
interface ClientStreamListener
{
    /**
     * The final status and headers, before the first onChunk().
     * Return false to abort the transfer before any body is read.
     */
    public function onStatus(int $statusCode, array $headers): bool;

    /**
     * A chunk of the body. Return false to abort the transfer.
     */
    public function onChunk(string $chunk): bool;

    /**
     * The transfer is over: the body ended, or the listener stopped it.
     */
    public function onComplete(int $statusCode, array $headers): void;
}
```

A listener must implement all three. **`AbstractClientStreamListener`**
implements `onStatus()` (continue) and `onComplete()` (do nothing), so a class
that extends it only writes `onChunk()` and overrides what it needs. An
anonymous class that implements the interface and leaves out `onStatus()` is a
fatal error.

The calls come in this order:

1. `onStatus()` — once the response's headers have arrived, before any body;
2. `onChunk()` — once per chunk, as long as it returns `true`;
3. `onComplete()` — once, after the last chunk, and also after `onStatus()` or
   `onChunk()` returned `false`.

**A transport failure is not a completion.** A connection that is refused, a
host that does not resolve, a certificate that fails, a timeout: each throws
`ClientException`, and `onComplete()` is **not** called. An HTTP error status is
a completion — a 500 is an answer — and goes through `onStatus()` and
`onComplete()` like a 200.

```php
use SfphpProject\src\Http\ClientException;

try {
    Http::base('https://api.example.com')->stream('/export.csv', $listener);
} catch (ClientException $e) {
    logger()->error('export stream failed', ['detail' => $e->getMessage()]);
}
```

### Status Before First Chunk

Override `onStatus()` to decide on the status before any body is read.
Returning `false` aborts the transfer: no chunk is delivered, and
`onComplete()` is called with the status.

```php
Http::base('https://api.example.com')->stream('/export', new class extends AbstractClientStreamListener {
    public function onStatus(int $statusCode, array $headers): bool
    {
        if ($statusCode >= 400) {
            error_log("Export refused: HTTP $statusCode");

            return false; // nothing of the body is read
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

> **Redirects.** `onStatus()` hears the final response only. A redirect the
> client is about to follow — a `3xx` with a `Location` — and a `1xx` are not
> reported, so a listener that refuses everything other than `200` sees the
> `200` at the end of the chain. It used to hear the `302` first and abort on
> it.

To stop in the middle, return `false` from `onChunk()`:

```php
Http::base('https://api.example.com')->stream('/data', new class extends AbstractClientStreamListener {
    private const LIMIT = 100 * 1024 * 1024; // 100 MB

    private int $bytes = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        return $this->bytes <= self::LIMIT; // false stops the transfer
    }

    public function onComplete(int $statusCode, array $headers): void
    {
        echo "Stopped after {$this->bytes} bytes\n";
    }
});
```

### UTF-8 Safety

A multi-byte UTF-8 character may be split between two network reads. The client
never hands `onChunk()` half a character: the incomplete bytes at the end of a
read are held back and delivered with the next one.

```php
// The server sends "a☃b" in two writes: "a\xE2" and "\x98\x83b".
// onChunk() receives "a", then "☃b" — never a lone "\xE2".
```

This applies to the PHP client, and SFJS does the same in the browser. The
server side writes bytes exactly as you give them to `write()`, so a producer
that cuts its own string at a byte offset can still send half a character.

### Chaining: Proxy Streams

Read from upstream and forward to your own client as it arrives:

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
            // write() is false once our own client has gone, which stops the upstream transfer too.
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

The status and headers of the response are sent before the producer runs, so
by the time the upstream answers they can no longer be changed. An upstream
failure has to be reported in the body, as above.

### Timeouts

A stream is timed differently from an ordinary request:

| | Ordinary request | Stream |
|---|---|---|
| Connecting | 5 s | 5 s |
| The whole exchange | 15 s | **No limit**, unless you set one |
| Without receiving any data | — | **30 s** (`idleTimeout()`) |

A stream that keeps sending is never cut off for being long; one that goes
quiet for longer than the idle timeout is aborted with `ClientException`.
Silence is measured as less than one byte per second, and curl checks it in
windows of a few seconds, so the abort can come a few seconds after the limit.

```php
$client = Http::base('https://api.example.com')
    ->timeout(300, 5)     // at most 300 s in total, 5 s to connect
    ->idleTimeout(60);    // abort after 60 s without data; 0 disables it

Http::timeout(300, connect: 5); // the same, from the facade
```

A total timeout given to `timeout()` applies to streams too — except `15`, the
default value, which a stream reads as "not set" and replaces with no limit.

---

## Server Configuration

Each layer between PHP and the browser can buffer a response, and a buffered
stream arrives all at once at the end. The emitter does its part (it clears
PHP's output buffers, turns off `zlib.output_compression`, and sends
`X-Accel-Buffering: no`); the web server and anything in front of it have to
do theirs.

### PHP Built-In Server (`php -S`)

Works out of the box:

```bash
./sfphp serve
curl -N http://localhost:8000/stream
```

The `-N` flag turns off curl's own buffering, so you see chunks arrive over
time. The built-in server handles one request at a time unless
`PHP_CLI_SERVER_WORKERS` is set, so a page that opens a stream and then makes
another request waits for the stream to finish. It is for development only.

### PHP-FPM + Nginx

nginx buffers FastCGI responses by default, and honours the
`X-Accel-Buffering: no` header the emitter sends: a streaming response is not
buffered, and every other response still is. No buffering directive is needed.

```nginx
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;

    # How long nginx waits between two reads from PHP. A stream that is
    # silent for longer is cut; send heartbeats more often than this.
    fastcgi_read_timeout 300s;
}
```

To turn buffering off for every response instead, use `fastcgi_buffering off;`.
Do **not** list `X-Accel-Buffering` in `fastcgi_ignore_headers`: that makes
nginx ignore the header and buffer the stream again.

Compression also holds chunks back. nginx compresses only `text/html` unless
`gzip_types` says otherwise, so leave `text/event-stream` (and the type of any
other stream) out of `gzip_types`, or set `gzip off;` in the streaming
location.

### Apache + PHP-FPM (mod_proxy_fcgi)

Tell `mod_proxy_fcgi` to pass each packet on as it arrives, with
`flushpackets=on` on the FastCGI worker:

```apache
<Proxy "fcgi://localhost/" enablereuse=on flushpackets=on>
</Proxy>

<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
</FilesMatch>

# mod_deflate buffers to compress: keep it away from streaming URLs.
<IfModule mod_deflate.c>
    SetEnvIfNoCase Request_URI "^/stream" no-gzip
</IfModule>
```

Adjust the `Request_URI` pattern to your streaming routes. Under PHP-FPM the
application cannot switch compression off from PHP, so this line is what does
it.

### Apache + mod_php

The emitter calls `apache_setenv('no-gzip', 1)` for every streaming response,
which keeps `mod_deflate` from compressing it, and PHP's output goes straight to
Apache. No configuration is needed.

### Caddy

`php_fastcgi` is Caddy's FastCGI front end, and takes the same options as
`reverse_proxy`. Caddy already flushes a response without a `Content-Length`,
which a stream never has; `flush_interval -1` makes it explicit:

```caddyfile
example.com {
    root * /var/www/app/public

    php_fastcgi unix//run/php/php8.3-fpm.sock {
        flush_interval -1
    }

    file_server
}
```

### Load Balancers & Proxies

Anything in front of the web server closes a connection that stays silent for
too long — **60 seconds** by default on an AWS Application Load Balancer, about
**100 seconds** on Cloudflare. A stream that pauses longer than that is cut, and
for SSE the browser then reconnects.

- Keep the stream from going quiet: send `$sse->heartbeat()` (or any data)
  more often than the shortest idle timeout on the path — every 15 to 30
  seconds is a common choice.
- Or raise the limit: the idle timeout on an ALB, `timeout server` (and
  `timeout tunnel`) on HAProxy, `proxy_read_timeout` on an nginx that proxies
  to another nginx.
- A proxy that buffers or compresses responses has to leave streams alone, in
  the same way as the servers above.

---

## Troubleshooting

### Data arrives all at once, not gradually

**Problem:** the chunks are buffered and delivered together at the end.

**Solution:**
1. Test with `curl -N`, which does not buffer: `curl -N http://localhost:8000/stream`.
   If the chunks arrive gradually there, the buffering is in the browser's
   path, not in PHP.
2. Check the web server: nginx must not ignore `X-Accel-Buffering`, and Apache
   with PHP-FPM needs `flushpackets=on` (see [Server Configuration](#server-configuration)).
3. Check compression: gzip in the web server, a CDN or a proxy buffers to
   compress.
4. Check the `Content-Type`: `text/html` (what PHP sends when you give none) is
   the type most servers compress.

### Stream never starts

**Problem:** nothing arrives, or the request fails before the first chunk.

**Solution:**
1. `Cannot emit the response: output already started at …` means something
   printed output before the response was emitted — an `echo`, a `var_dump`,
   or bytes outside a PHP tag. The message names the file and line.
2. A `HEAD` request needs `Router::head()`; a `GET` route answers it with 405.
3. For client streaming, `ClientException` with *"The curl extension is
   required"* means `ext-curl` is missing: `php -m | grep curl`.

### Other requests hang while a stream is open

**Problem:** the rest of the site stops answering for the visitor who opened
the stream.

**Solution:**
1. Under `php -S`, this is the built-in server serving one request at a time:
   set `PHP_CLI_SERVER_WORKERS=4`, or use PHP-FPM.
2. Under PHP-FPM, every open stream holds a worker. When all
   `pm.max_children` workers are streaming, new requests wait — see
   [Workers](#workers).
3. The session is closed before the producer runs, so it is not the session
   lock — unless something reopens the session inside the producer.

### Partial UTF-8 characters appear

**Problem:** text shows broken characters.

**Solution:**
1. Declare the charset: `'Content-Type' => 'text/plain; charset=utf-8'`.
2. Check the producer: the server writes bytes exactly as given, so a string
   cut with `substr()` at a byte offset sends half a character. Cut with
   `mb_substr()`, or send whole lines.
3. The PHP client and SFJS both reassemble characters split between reads.
   If the text is broken there too, the bytes were broken at the source.

### The browser replays an SSE stream

**Problem:** the events show up again and again, or the stream never ends.

**Solution:** the server closed the connection without a final event, and
`EventSource` reconnected. End the stream with an event named `done` or
`complete` (or the names in `@done`), or answer the reconnection with `204`
(see [Ending the Stream](#ending-the-stream)).

### Browser shows incomplete SSE events

**Problem:** some events never appear.

**Solution:**
1. Check the browser console for errors.
2. Check the event types: on a `GET`, SFJS shows `message` plus the types in
   `@events`; through `fetch` (another method, or `@body`), only the types in
   `@events`.
3. Check the response's `Content-Type` is `text/event-stream`; `EventSource`
   refuses anything else.
4. Every event must end with a blank line. `ServerSentEvent::send()` does this;
   an event written by hand with `write()` has to as well.

---

## Performance Considerations

### Memory

A stream holds one chunk at a time instead of the whole body:

```
Buffered (1 GB file): about 1 GB of memory
Streamed (1 GB file): about the size of one chunk
```

That holds only if the producer does not build the whole body first. A producer
that calls `fetchAll()` and then writes row by row has already spent the memory
— see [Example 2](#example-2-stream-database-records).

### Workers

Under PHP-FPM, a stream occupies one worker for as long as it is open. A
thousand visitors each holding an SSE connection need a thousand workers, and
the requests that arrive once `pm.max_children` is reached wait for one to
finish. Size the pool for the streams you expect, keep streams short, and do
not use SSE for something a periodic request would do as well.

`request_terminate_timeout` in the FPM pool ends a request that runs longer
than it allows, stream or not, and `max_execution_time` still applies to the
producer (on Linux it usually counts CPU time, not time spent waiting). Raise them, or call
`set_time_limit()` in the producer, for a stream that is meant to run long.

### CPU

Streaming does not add CPU work compared to buffering: the same bytes are
produced and sent, only sooner.

---

## Not Yet Implemented

### Progress Callbacks

The HTTP client does not report progress (bytes or percentage) by itself.
Count in the listener:

```php
Http::base('https://files.example.com')->stream('/backup.tar', new class extends AbstractClientStreamListener {
    private int $bytes = 0;

    private int $reported = 0;

    public function onChunk(string $chunk): bool
    {
        $this->bytes += strlen($chunk);

        // Once per megabyte, not once per chunk.
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

For a percentage, read the `Content-Length` header in `onStatus()`, when the
server sends one.

### Overriding the Streaming Headers

`Cache-Control: no-cache` and `X-Accel-Buffering: no` are always sent and
replace a value of your own (see [Headers](#headers)).

---

## Examples

### Example 1: Stream Log File

```php
return Response::stream(function (StreamWriter $out): void {
    $path = '/var/log/app/app.log';
    $file = fopen($path, 'rb');

    if ($file === false) {
        $out->write("The log could not be opened.\n");

        return;
    }

    // The last 10 KB, or the whole file if it is smaller.
    fseek($file, -min(10240, filesize($path)), SEEK_END);

    while (($line = fgets($file)) !== false) {
        if (!$out->write($line)) {
            break; // the client has gone
        }
    }

    fclose($file);
}, headers: ['Content-Type' => 'text/plain; charset=utf-8']);
```

### Example 2: Stream Database Records

```php
use SfphpProject\src\Database;

return Response::stream(function (StreamWriter $out): void {
    // execute() returns the PDOStatement, which is read one row at a time.
    $rows = Database::query('SELECT id, name FROM users ORDER BY id')->execute();
    $line = fopen('php://temp', 'r+');

    $out->write("id,name\n");

    foreach ($rows as $row) {
        // fputcsv quotes a name that contains a comma or a quote.
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

Iterating the statement, rather than calling `fetchAll()` or `->get()`, keeps
the rows out of PHP's memory as far as the driver allows. On MySQL, PDO buffers
the whole result on the client by default; for a very large export, turn that
off for the query with `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false`.

### Example 3: Proxy Upstream Streaming

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

A transport failure throws `ClientException` out of the producer; catch it, as
in [Chaining](#chaining-proxy-streams), to write something the client can read.

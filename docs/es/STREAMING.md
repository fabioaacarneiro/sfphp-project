# Streaming HTTP

Envíe y reciba respuestas HTTP en fragmentos en lugar de almacenar todo el cuerpo en la memoria. Habilita datos en tiempo real, transferencias de archivos grandes y proxy servidor-a-cliente sin sobrecarga de memoria.

## Extensiones PHP Necesarias

Antes de usar streaming, instale estas extensiones:

### Esencial

```bash
# GD — Generación de imágenes para iconos PWA
apt install php8.3-gd

# curl — Solicitudes HTTP
apt install php8.3-curl
```

Verifique la instalación:
```bash
php -m | grep -E 'gd|curl'
# La salida debe mostrar: curl, gd
```

### Opcional (Recomendado)

```bash
# mbstring — Mejor soporte UTF-8
apt install php8.3-mbstring
```

Si `mbstring` no está instalado, streaming funciona pero los casos límite UTF-8 son menos robustos. El framework funciona sin (PHP 8.1+).

### Verificar su servidor

```bash
php -r "
echo 'Extensiones:\n';
echo '  curl: ' . (extension_loaded('curl') ? '✓ instalada' : '✗ FALTANTE') . '\n';
echo '  gd: ' . (extension_loaded('gd') ? '✓ instalada' : '✗ FALTANTE') . '\n';
echo '  mbstring: ' . (extension_loaded('mbstring') ? '✓ instalada' : '○ opcional') . '\n';
"
```

---

## Streaming Servidor → Cliente

Envíe el cuerpo de la respuesta en fragmentos conforme se generan los datos. El cliente recibe y procesa cada parte inmediatamente, sin esperar la respuesta completa.

### Casos de uso

- **Salida de LLM** — Envíe tokens conforme se generan por un modelo de IA
- **Exportaciones grandes** — Haga stream de CSV, NDJSON o JSON sin usar memoria
- **Actualizaciones en tiempo real** — Informes de progreso, registros en vivo, datos en tiempo real
- **Server-Sent Events** — El navegador se suscribe a un flujo de eventos
- **Proxy de streams** — Reciba de servicio upstream, reenvíe al cliente

### Uso básico

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\StreamWriter;

return Response::stream(function(StreamWriter $out) {
    for ($i = 1; $i <= 100; $i++) {
        // Verifica si el cliente se desconectó
        if ($out->aborted()) {
            break;
        }

        $out->write("Elemento $i\n");
        
        // Simula generación lenta
        usleep(100000); // 100ms
    }
}, status: 200, headers: [
    'Content-Type' => 'text/plain; charset=utf-8',
]);
```

### Cómo funciona

1. **Solicitud llega** → despachador crea Response::stream()
2. **Middleware se ejecuta** → puede modificar headers/status antes de emitir
3. **Emitter envía headers** → cliente recibe estado 200, Content-Type, etc.
4. **Productor se ejecuta** → su callable comienza a ejecutarse
5. **Cada write() hace flush** → datos llegan al cliente inmediatamente
6. **Cliente procesa** → puede actuar en fragmentos conforme llegan
7. **aborted() devuelve true** → si cliente se desconectó

### API StreamWriter

```php
interface StreamWriter {
    /**
     * Escribe un fragmento al cliente.
     * Devuelve false si el cliente se desconectó (debe parar).
     */
    public function write(string $chunk): bool;

    /**
     * Verifica si el cliente sigue conectado.
     */
    public function aborted(): bool;

    /**
     * Hace flush de todos los niveles de buffer de salida.
     */
    public function flush(): void;
}
```

### Headers Enviados Automáticamente

```http
Cache-Control: no-cache
X-Accel-Buffering: no
Content-Type: text/plain; charset=utf-8
```

Estos previenen buffering en proxies y cache. Si necesita caché diferente:

```php
return Response::stream($producer)
    ->withHeader('Cache-Control', 'private, max-age=0')
    ->withHeader('X-Accel-Buffering', 'no');
```

### Solicitudes HEAD

Enviar HEAD a un endpoint de streaming retorna solo headers:

```bash
curl -I http://example.com/stream
# HTTP/1.1 200 OK
# Cache-Control: no-cache
# Content-Type: text/plain
# (sin cuerpo, productor no se ejecuta)
```

### Gestión de Sesión

La sesión se cierra automáticamente antes de que comience el streaming, evitando bloqueos:

```php
return Response::stream(function(StreamWriter $out) {
    // La sesión ya está cerrada aquí
    // Escribir en $_SESSION después no tiene efecto
    
    $out->write("Datos en streaming...");
});
```

Si necesita guardar datos de sesión, hágalo antes de devolver la Response:

```php
$_SESSION['stream_iniciado'] = true;
session_write_close(); // Explícito (ya hecho automáticamente)

return Response::stream(function(StreamWriter $out) {
    // Seguro hacer streaming ahora
    $out->write("Datos...");
});
```

---

## Server-Sent Events (SSE)

Eventos en tiempo real del servidor al navegador. El navegador abre conexión persistente y recibe eventos conforme se envían.

### Helper ServerSentEvent

```php
use SfphpProject\src\Http\Response;
use SfphpProject\src\Http\ServerSentEvent;

return Response::stream(
    function(StreamWriter $out) {
        $sse = new ServerSentEvent($out);

        // Envía un evento
        $sse->send('Procesando...', event: 'status', id: '1');

        // Con intervalo de reintento (milisegundos)
        $sse->send('Fragmento de datos', event: 'datos', id: '2', retry: 5000);

        // Heartbeat para mantener conexión viva
        $sse->heartbeat();

        // Datos multi-línea (cada línea prefijada con 'data:')
        $sse->send("Línea 1\nLínea 2\nLínea 3");
    },
    headers: ['Content-Type' => 'text/event-stream; charset=utf-8']
);
```

### Consumo en navegador

```javascript
const es = new EventSource('/stream/sse');

es.addEventListener('status', (event) => {
    console.log('Status:', event.data);
});

es.addEventListener('datos', (event) => {
    console.log('Datos:', event.data);
});

es.addEventListener('error', () => {
    console.log('Conexión perdida');
    es.close();
});
```

### Formato SSE

Cada evento está formateado como:

```
event: status
id: 1
data: Procesando...

```

Datos multi-línea:

```
event: datos
data: Línea 1
data: Línea 2
data: Línea 3

```

Heartbeat (mantiene vivo, navegador ignora):

```
: heartbeat

```

---

## Streaming en Cliente (HTTP → PHP)

Reciba respuestas grandes de servicios upstream en fragmentos, sin almacenar el cuerpo completo.

### Casos de uso

- **Descarga de archivo grande** — Haga stream a disco sin cargar en memoria
- **Datos en tiempo real** — Procese conforme llega de una API
- **Proxy de stream** — Reciba de upstream, reenvíe a cliente (combine con streaming del servidor)
- **Monitoreo** — Escuche stream de registros o eventos

### Uso básico

```php
use SfphpProject\src\Http\Http;
use SfphpProject\src\Http\ClientStreamListener;

Http::stream('/api/export.csv', new class implements ClientStreamListener {
    public function onChunk(string $chunk): bool {
        // Procesa cada fragmento conforme llega
        echo $chunk; // o file_put_contents, acumula, etc.
        
        // Retorna false para abortar transferencia
        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        // Llamado después de transferencia completa o falla
        if ($statusCode !== 200) {
            error_log("Exportación falló: HTTP $statusCode");
        }
    }
});
```

### API ClientStreamListener

```php
interface ClientStreamListener {
    /**
     * Llamado repetidamente conforme llegan fragmentos.
     * @param string $chunk Los datos recibidos
     * @return bool True para continuar, false para abortar
     */
    public function onChunk(string $chunk): bool;

    /**
     * Llamado después del último fragmento o si ocurre error.
     * @param int $statusCode El código de estado HTTP
     * @param array<string, string> $headers Headers de la respuesta
     */
    public function onComplete(int $statusCode, array $headers): void;
}
```

### Status Antes del Primer Fragmento

Headers y status están disponibles antes de que comience el streaming:

```php
Http::stream('/api/export', new class implements ClientStreamListener {
    public function onChunk(string $chunk): bool {
        // Esto se llama después de que status/headers se conocen
        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        // ¿Decisión hecha ANTES del streaming?
        if ($statusCode === 401) {
            // Demasiado tarde; ya recibió fragmentos
            // Verifique status en onChunk() y retorne false para parar
        }
    }
});
```

Mejor: retorne false de onChunk() para abortar:

```php
Http::stream('/api/datos', new class implements ClientStreamListener {
    private int $totalBytes = 0;
    private const MAX_SIZE = 1024 * 1024 * 100; // 100MB

    public function onChunk(string $chunk): bool {
        $this->totalBytes += strlen($chunk);
        
        if ($this->totalBytes > self::MAX_SIZE) {
            error_log('Stream excedió límite de tamaño');
            return false; // Deja de recibir
        }

        return true;
    }

    public function onComplete(int $statusCode, array $headers): void {
        echo "Listo: $this->totalBytes bytes\n";
    }
});
```

### Seguridad UTF-8

Las secuencias UTF-8 multi-byte pueden dividirse entre fragmentos. El manejador del cliente garantiza que nunca se corten:

```php
// Entrada: bytes [E2, 98, 83] = ☃ (muñeco de nieve, 3 bytes)
// Si fragmento termina después del byte 1: [E2, 98]
// Siguiente fragmento: [83, ...]

// ClientStream sostiene la secuencia incompleta [E2, 98]
// onChunk() NO se llama con [E2, 98]
// Siguiente fragmento llega [83, ...], combinado a [E2, 98, 83]
// onChunk() llamado con ☃ completo
```

Esto previene que bibliotecas de procesamiento se ahoguen en UTF-8 parcial.

---

## Configuración de Servidor

El streaming requiere configuración adecuada del servidor para funcionar confiable.

### Servidor Integrado de PHP (`php -S`)

Funciona de inmediato:

```bash
./sfphp serve
curl -N http://localhost:8000/stream
```

El flag `-N` desabilita buffering en curl, así que ve fragmentos llegar con el tiempo.

### PHP-FPM + Nginx

Desabilite buffering de FastCGI para permitir datos en tiempo real:

```nginx
location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    
    # Crítico: deshabilitar buffering
    fastcgi_buffering off;
    fastcgi_request_buffering off;
}
```

### Apache + mod_php

Asegúrese de que output buffering está deshabilitado:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    
    # Deshabilitar proxy buffering
    SetEnv proxy-nokeepalive 1
    SetEnv proxy-initial-not-pooled 1
    SetEnv proxy-sendcl 1
</FilesMatch>
```

### Caddy

Desabilite buffering para respuestas de streaming:

```caddyfile
example.com {
    reverse_proxy localhost:9000 {
        # Deshabilitar buffering
        flush_interval -1
    }
}
```

---

## Ejemplos

### Ejemplo 1: Stream de archivo de registro

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

### Ejemplo 2: Stream de registros de base de datos

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

### Ejemplo 3: Proxy de streaming upstream

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
                $this->out->write("\n[Error upstream: $statusCode]\n");
            }
        }
    });
}, headers: ['Content-Type' => 'application/octet-stream']);
```

# Guía del runtime async

> **Lee en:** [English](../en/ASYNC.md) · [Português](../pt-BR/ASYNC.md) · [Español](ASYNC.md)

Esta guía cubre a fondo el runtime async de SFPHP: qué hace cada función y cada
clase, qué no hace y dónde espera realmente el proceso. Añade detalle a la
[sección Async de la documentación principal](DOCUMENTATION.md#async) y no la
sustituye.

Todo ejemplo que empieza con `<?php` se ejecuta tal cual, suponiendo que el
autoloader de Composer está cargado (dentro de una aplicación SFPHP siempre lo
está). Los ejemplos llaman a `https://api.example.com`. Apúntalos a un servidor
que controles para probarlos. Los ejemplos de base de datos necesitan además una
conexión configurada.

## Contenido

- [Dos significados de async](#dos-significados-de-async)
- [Importar las funciones](#importar-las-funciones)
- [async() y await()](#async-y-await)
- [delay()](#delay)
- [Varios a la vez: awaitAll() y CompositeFuture](#varios-a-la-vez-awaitall-y-compositefuture)
- [syncRun()](#syncrun)
- [El contrato de Future](#el-contrato-de-future)
- [Tareas](#tareas)
- [Plazos y cancelación](#plazos-y-cancelación)
- [Peticiones HTTP](#peticiones-http)
- [Consultas a la base de datos](#consultas-a-la-base-de-datos)
- [Componentes](#componentes)
- [Un planificador por petición: EnableAsync](#un-planificador-por-petición-enableasync)
- [Async en tus propias clases: AsyncAware](#async-en-tus-propias-clases-asyncaware)
- [Errores](#errores)
- [Adaptadores bloqueantes](#adaptadores-bloqueantes)
  - [FileFuture](#filefuture)
  - [CacheFuture](#cachefuture)
  - [CacheInvalidator](#cacheinvalidator)
  - [ComponentFuture](#componentfuture)
  - [StreamFuture](#streamfuture)
- [ReactiveState](#reactivestate)
- [EventBroadcaster](#eventbroadcaster)
- [WebSocketFuture (experimental)](#websocketfuture-experimental)
- [Bajo el capó](#bajo-el-capó)
- [Cifras medidas](#cifras-medidas)
- [Errores comunes](#errores-comunes)

---

## Dos significados de async

La palabra cubre dos cosas distintas, y la diferencia decide cuánto tarda tu
código:

- **Planificación asíncrona.** Una operación es un valor (un *Future*) que el
  runtime puede sostener, pasar, combinar y esperar. Todo lo de esta guía tiene
  esto.
- **I/O no bloqueante.** Mientras la operación espera, el proceso hace otro
  trabajo. Solo algunas operaciones tienen esto.

| Operación | Tipo de Future | ¿Se solapa con otro trabajo? |
|---|---|---|
| Petición HTTP (`Http::getAsync()` y las demás) | `HttpFuture` | **Sí.** Las peticiones corren juntas en un único curl multi handle. |
| Temporizador (`delay()`) | `TimerFuture` | **Sí.** Es un plazo por el que el event loop despierta. |
| Tu propio código (`async()`) | `Task` | Solo mientras espera algo que se solapa. |
| Consulta a la base de datos (`getAsync()`, `firstAsync()`, …) | `QueryFuture` | **No.** PDO bloquea hasta que el servidor responde. |
| Adaptadores de archivo, caché, componente y stream | `FileFuture`, `CacheFuture`, … | **No.** Se ejecutan, bloqueando, cuando se esperan. |

En PHP puro (PHP-FPM, `php -S`, la CLI) esto es literalmente cierto. SFPHP no
necesita ninguna extensión como Swoole o RoadRunner para que las peticiones HTTP
y los temporizadores se solapen. Tampoco convierte una llamada bloqueante en no
bloqueante por ejecutarla dentro de una Fiber: tres consultas esperadas juntas
tardan lo mismo que las tres sumadas.

El runtime tiene un solo hilo. Nunca ejecuta dos fragmentos de PHP en el mismo
instante. Lo que se solapa es la *espera*: mientras una petición espera su
respuesta, pueden llegar los bytes de otra.

## Importar las funciones

Los cuatro helpers son funciones con namespace. Impórtalos con `use function`:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, awaitAll};
```

`syncRun` vive en el mismo namespace, y puedes añadirlo a la misma lista. Las
clases se importan de la forma habitual:

```php
<?php

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;
```

## async() y await()

`await($future)` espera un Future y devuelve su valor, o lanza aquello con lo
que fue rechazado. `async($callable)` ejecuta tu callable como una **tarea**
(`Task`) junto a lo que esté corriendo, y devuelve la tarea, que es a su vez un
Future.

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

$task = async(function (): ?array {
    $response = await(Http::getAsync('https://api.example.com/users/1'));

    return $response->json();
});

$user = await($task);
```

Cómo espera `await()` depende de dónde se llame:

- **Dentro de una tarea** aparca la Fiber de la tarea. El planificador la
  reanuda cuando el Future esperado ha concluido, y mientras tanto ejecuta otras
  tareas.
- **Fuera de una tarea** (en un controlador, un comando o una prueba) conduce él
  mismo el event loop hasta que el Future concluye. Todas las demás operaciones
  pendientes siguen avanzando mientras espera.

En ambos casos el proceso nunca consulta en bucle. Cuando no hay nada listo para
ejecutarse, espera en un único `select()` sobre todas las transferencias HTTP
abiertas y el temporizador más cercano.

`await()` funciona en cualquier sitio. No hace falta configuración ni
middleware, porque el runtime crea un planificador para el proceso la primera
vez que se necesita uno (consulta
[EnableAsync](#un-planificador-por-petición-enableasync) para tener uno por
petición).

**Una tarea empieza cuando algo conduce el planificador, no al crearla.**
`async()` encola la tarea. La tarea se ejecuta la próxima vez que cualquier
`await()` (de lo que sea) le da un turno al planificador. Una tarea que nadie
espera nunca, en un programa que nunca espera nada, nunca se ejecuta.

**El valor de una tarea es lo que devuelve el callable, tal cual.** Si el
callable devuelve un Future, la tarea se resuelve con ese Future y no con su
valor. Espéralo dentro del callable:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

// Mal: $wrong es un HttpFuture, no una respuesta.
$wrong = await(async(fn () => Http::getAsync('https://api.example.com/a')));

// Bien: la tarea espera la petición y devuelve la respuesta.
$right = await(async(fn () => await(Http::getAsync('https://api.example.com/a'))));
```

En la mayoría de los casos no necesitas `async()` en absoluto. Un Future HTTP ya
está en marcha, así que esperarlo directamente basta. Usa `async()` cuando
tengas varios pasos propios que deban ejecutarse junto a otro trabajo, como
«busca, y luego vuelve a buscar con el resultado».

## delay()

`delay($milliseconds, $value = null)` devuelve un Future que se resuelve con
`$value` tras la espera. Es un temporizador del event loop, no `usleep()`: las
demás tareas y transferencias HTTP siguen avanzando mientras corre.

```php
<?php

use SfphpProject\src\Async\CompositeFuture;

use function SfphpProject\src\Async\{await, delay};

$started = microtime(true);

// Tres esperas de 250 ms aguardadas juntas tardan unos 250 ms, no 750.
await(CompositeFuture::all(delay(250), delay(250), delay(250)));

$value = await(delay(10, 'done')); // 'done'

printf("%.0f ms\n", (microtime(true) - $started) * 1000);
```

## Varios a la vez: awaitAll() y CompositeFuture

`CompositeFuture` combina Futures. Tiene exactamente dos modos:

- `CompositeFuture::all(...$futures)` se resuelve cuando todas las partes lo
  han hecho, con los valores en el orden dado. Se rechaza en cuanto una parte
  falla, con la excepción de esa parte.
- `CompositeFuture::race(...$futures)` concluye con la primera parte que
  concluya. Si esa parte falló, la carrera se rechaza.

`awaitAll(...$futures)` es una abreviatura de `await(CompositeFuture::all(...))`.
Las partes pueden tener nombre: `awaitAll(...['user' => $a, 'posts' => $b])` se resuelve en
`['user' => …, 'posts' => …]`, en el orden dado. Una parte con nombre solía ser un
`TypeError` dentro del loop, que además hacía fallar el siguiente `await()`.

```php
<?php

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

[$invoice, $product, $stock] = awaitAll(
    Http::getAsync('https://api.example.com/invoices/7'),
    Http::getAsync('https://api.example.com/products/42'),
    Http::getAsync('https://api.example.com/stock/42'),
);

$fastest = await(CompositeFuture::race(
    Http::getAsync('https://api.example.com/primary'),
    Http::getAsync('https://api.example.com/mirror'),
));

echo $invoice->status(), ' ', $fastest->status(), PHP_EOL;
```

Las tres peticiones se solapan, así que esto tarda más o menos lo que la más
lenta.

Si algo se solapa lo deciden las partes. `CompositeFuture` solo escucha. No
inicia ni conduce nada. Tres llamadas a `Http::getAsync()` se solapan porque
cada una ya estaba en el event loop. Tres consultas pasadas a `all()` siguen
ejecutándose una tras otra.

Cuando `all()` se rechaza o `race()` concluye, las demás partes **no** se
cancelan. Pertenecen a quien las creó, y una petición HTTP que perdió una
carrera sigue ejecutándose hasta el final en el loop. Para detener las demás
partes, cancela el propio compuesto. `CompositeFuture::cancel()` cancela todas
las partes que sigan pendientes y se puedan cancelar.

`all()` sin partes se resuelve al instante con `[]`.

## syncRun()

`syncRun($future)` espera un Future en un planificador nuevo, propio, y luego
vuelve a retirar ese planificador. Le da a un punto de entrada, como un comando
de consola o un trabajo de cola, un planificador limpio que nada más comparte:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, syncRun};

$result = syncRun(async(function (): string {
    await(delay(50));

    return 'finished';
}));
```

Un Future creado *antes* de la llamada queda ligado al event loop que estaba
vigente cuando se creó. Crea el trabajo dentro del callable, como arriba, para
que pertenezca al planificador que conduce `syncRun()`.

## El contrato de Future

Todo Future implementa `SfphpProject\src\Async\Future`:

| Método | Significado |
|---|---|
| `isPending()` | Todavía no ha concluido. |
| `isResolved()` | Concluyó con un valor. |
| `isRejected()` | Concluyó con una excepción. |
| `getValue()` | El valor. Lanza la excepción si fue rechazado. |
| `getException()` | La excepción, o `null`. |
| `onResolve(callable $cb)` | Llama a `$cb($future)` cuando concluye, o al instante si ya lo ha hecho. |

Los Futures propios del runtime (`Task`, `HttpFuture`, `TimerFuture`,
`CompositeFuture`, `QueryFuture`) extienden `Pending`, que añade:

| Método | Significado |
|---|---|
| `isCancelled()` | Fue cancelado. Un Future cancelado no está «rechazado». |
| `isSettled()` | Resuelto, rechazado o cancelado. |
| `state()` | `'pending'`, `'running'`, `'resolved'`, `'rejected'` o `'cancelled'`. |

Tres reglas:

1. **Concluido es definitivo.** Una vez que un Future ha concluido, nunca
   vuelve a cambiar.
2. **Leer demasiado pronto lanza una excepción.** `getValue()` sobre un
   `Pending` que no ha concluido lanza `AsyncException` ("This operation has
   not finished. Await it before reading its value."). No devuelve `null`. Usa
   `await()`.
3. **Un Future cancelado lanza al leerlo.** `getValue()` lanza el motivo de la
   cancelación: una `CancelledException`, o el motivo que se haya dado.

```php
<?php

use SfphpProject\src\Async\AsyncException;

use function SfphpProject\src\Async\{async, await};

$task = async(fn (): int => 42);

try {
    $task->getValue();                 // Todavía no se ha ejecutado
} catch (AsyncException $e) {
    echo $e->getMessage(), PHP_EOL;
}

echo await($task), PHP_EOL;            // 42
echo $task->getValue(), PHP_EOL;       // 42, ahora que ha concluido
```

Los callbacks pasados a `onResolve()` se ejecutan de forma síncrona cuando el
Future concluye. Una excepción lanzada en uno de ellos no se silencia. Se
propaga, porque ocultarla dejaría una tarea que nunca se reanuda.

## Tareas

Una `Task` envuelve una Fiber. Está `pending` hasta que el planificador la
alcanza por primera vez, `running` mientras su Fiber está viva, y después
`resolved`, `rejected` o `cancelled`.

- Una excepción lanzada dentro del callable rechaza la tarea. `await()` la
  vuelve a lanzar.
- `Fiber::suspend()` dentro de una tarea (sin esperar nada) cede el turno a las
  demás tareas listas. La tarea pasa al final de la cola.
- `await()` sobre una tarea la planifica si todavía no lo está.

```php
<?php

use function SfphpProject\src\Async\{async, await};

$outer = async(function (): int {
    $inner = async(fn (): int => 20);

    return await($inner) + 1;
});

echo await($outer), PHP_EOL; // 21
```

## Plazos y cancelación

`await()` acepta un plazo en milisegundos:

```php
<?php

use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

try {
    $response = await(Http::getAsync('https://api.example.com/slow'), timeout: 2000);
} catch (TimeoutException $e) {
    echo $e->getMessage(), PHP_EOL; // "The operation did not finish within 2000 ms."
}
```

El plazo es un temporizador del event loop. Cuando vence, el Future esperado se
**cancela** y `await()` lanza `TimeoutException`.

**Solo los Futures que implementan `Cancellable` respetan un plazo.** Son estos:

| Future | Qué hace la cancelación |
|---|---|
| `HttpFuture` | Retira la transferencia del curl multi handle y cierra la conexión. |
| `TimerFuture` (`delay()`) | Retira el temporizador. |
| `Task` | Da la tarea por cancelada. Su Fiber nunca se reanuda, así que su código se detiene en el `await()` en el que está aparcada. |
| `CompositeFuture` | Cancela todas las partes que sigan pendientes y sean cancelables, y después a sí mismo. |

Un Future que no es `Cancellable` ignora el plazo. Esto incluye `QueryFuture` y
los [adaptadores bloqueantes](#adaptadores-bloqueantes). `await()` espera a que
concluya, exactamente como lo haría sin plazo, y no se lanza ninguna
`TimeoutException`. Para una consulta no puede ser de otra forma: una vez que
PDO la ha enviado, el proceso queda bloqueado hasta que el servidor responde.

Un plazo sobre una `Task` cancela la tarea, no el trabajo que hay dentro. Si una
tarea está aparcada en una petición HTTP cuando vence su plazo, la petición
sigue ejecutándose en el loop. Para acotar la propia petición, pon el plazo en
la petición:

```php
<?php

use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

$task = async(function (): string {
    try {
        return await(Http::getAsync('https://api.example.com/slow'), 200)->body();
    } catch (TimeoutException) {
        return 'fallback';
    }
});

echo await($task), PHP_EOL;
```

También puedes cancelar de forma explícita. El argumento opcional es el motivo
que recibe el código que espera:

```php
<?php

use SfphpProject\src\Async\CancelledException;

use function SfphpProject\src\Async\{async, await, delay};

$task = async(function (): string {
    await(delay(1000));

    return 'never returned';
});

$task->cancel(new CancelledException('The user left.'));

try {
    await($task);
} catch (CancelledException $e) {
    echo $e->getMessage(), PHP_EOL;           // "The user left."
}

var_dump($task->isCancelled());               // bool(true)
```

## Peticiones HTTP

HTTP es donde el runtime solapa trabajo real. Cada llamada entrega una
transferencia al curl multi handle del event loop y devuelve un `HttpFuture` al
instante.

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

$get    = Http::getAsync('https://api.example.com/users', ['page' => 2], ['Accept' => 'application/json']);
$post   = Http::postAsync('https://api.example.com/users', ['name' => 'Ana']);
$put    = Http::putAsync('https://api.example.com/users/1', ['name' => 'Ana Maria']);
$patch  = Http::patchAsync('https://api.example.com/users/1', ['active' => true]);
$delete = Http::deleteAsync('https://api.example.com/users/1');

foreach ([$get, $post, $put, $patch, $delete] as $future) {
    echo await($future)->status(), PHP_EOL;
}
```

Las firmas son:

| Método | Argumentos |
|---|---|
| `Http::getAsync` | `string $url, array $query = [], array $headers = []` |
| `Http::postAsync` / `putAsync` / `patchAsync` | `string $url, array\|string\|null $body = null, array $headers = []` |
| `Http::deleteAsync` | `string $url, array $headers = []` |

Un cuerpo en forma de array se envía como JSON con
`Content-Type: application/json`. Un cuerpo en forma de cadena se envía tal
cual. Tus propias cabeceras prevalecen sobre esos valores por defecto.

`HttpFuture` tiene constructores estáticos equivalentes: `HttpFuture::get($url,
$headers, $options)`, `post($url, $body, $headers, $options)`, y `put`,
`patch`, `delete`. El último argumento acepta opciones de cURL en crudo
(`CURLOPT_*`), que prevalecen sobre los valores por defecto.

**Lo que el cliente async no comparte con el síncrono.** Los métodos `*Async`
crean una petición nueva cada vez. No usan `Http::base()`, `withToken()` ni el
resto de la configuración de `Http::client()`. Así que:

- **Usa URLs absolutas.** `Http::getAsync('/users')` no tiene host y se rechaza
  con `ClientException` ("URL rejected: No host part in the URL").
- **Pasa la autenticación como cabecera:** `['Authorization' => 'Bearer ' . $token]`.
- Los valores por defecto son fijos: 30 s en total, 10 s para conectar, hasta 5
  redirecciones, redirecciones solo por HTTPS (se rechaza una redirección de
  `https://` a `http://`). Cámbialos por petición mediante `$options` en
  `HttpFuture`, o acota la espera con `await(..., timeout: $ms)`.

**Cuándo avanza la petición.** La transferencia se registra cuando se crea el
Future. Avanza siempre que el proceso espera en el event loop, es decir, dentro
de cualquier `await()`. El trabajo bloqueante hecho entre crear el Future y
esperarlo (una consulta, un `usleep()`, un cálculo pesado) no se solapa con la
petición. Inicia primero las peticiones y luego espera:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\awaitAll;

// Las tres se registran antes de que nada espere, así que se solapan.
$futures = [];

foreach ([1, 2, 3] as $id) {
    $futures[] = Http::getAsync('https://api.example.com/users/' . $id);
}

$responses = awaitAll(...$futures);
```

**La respuesta.** Un `HttpFuture` se resuelve con una `ClientResponse`, la misma
clase que devuelve el cliente síncrono:

| Método | Devuelve |
|---|---|
| `status()` | El código de estado (`int`). |
| `ok()` | Verdadero para 2xx. |
| `failed()` | Verdadero para 4xx y 5xx. |
| `clientError()` / `serverError()` | Verdadero para 4xx / 5xx. |
| `body()` | El cuerpo en crudo (`string`). |
| `json(bool $strict = false)` | El cuerpo decodificado como array, o `null` cuando no es JSON. Con `true`, lanza `ClientException` en lugar de devolver `null`. |
| `header(string $name)` | Una cabecera, buscada sin distinguir mayúsculas de minúsculas, o `null`. |
| `headers()` | Todas las cabeceras de la respuesta final — no las de las redirecciones anteriores. |
| `url()` | La URL que respondió, tras las redirecciones. |
| `throw()` | Lanza `ClientException` para 4xx/5xx; en caso contrario devuelve la respuesta. |

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

$response = await(Http::getAsync('https://api.example.com/users/1'));

if ($response->ok()) {
    $user = $response->json();
    $type = $response->header('Content-Type');
}

// O trata un estado de error como una excepción:
$user = await(Http::getAsync('https://api.example.com/users/1'))->throw()->json();
```

**Fallos.** Un *estado* de error (404, 500) es una respuesta: el Future se
resuelve y tú inspeccionas `status()`. La ausencia total de respuesta (fallo de
DNS, conexión rechazada, plazo agotado, fallo de TLS) rechaza el Future con
`SfphpProject\src\Http\ClientException`. La extensión `curl` es obligatoria. Sin
ella, el Future se rechaza al instante con una `ClientException` que lo indica. Lo mismo ocurre con un valor de cabecera que
contiene un salto de línea, y con un cuerpo que JSON no puede codificar — que antes
se enviaba como una cadena vacía.

## Consultas a la base de datos

`ModelQuery` tiene cuatro métodos async:

| Método | Se resuelve con |
|---|---|
| `getAsync()` | un `array` de modelos |
| `firstAsync()` | un modelo o `null` |
| `countAsync()` | `int` |
| `findAsync($id)` | un modelo o `null` |

```php
<?php

use SfphpProject\app\models\User;

use function SfphpProject\src\Async\await;

$active = await(User::query()->where('active', true)->getAsync());
$first  = await(User::query()->orderBy('id')->firstAsync());
$count  = await(User::query()->countAsync());
$one    = await(User::query()->findAsync(1));
```

Usa `firstAsync()`, no `first()->...`. `first()` ejecuta la consulta y devuelve
el modelo, así que no se puede esperar.

**Estas bloquean.** Un `QueryFuture` ejecuta su consulta desde el event loop, a
través de PDO, y PDO no tiene API asíncrona: `execute()` espera al servidor, y
ninguna Fiber cambia eso. Esperar tres consultas juntas tarda lo mismo que las
tres seguidas. Lo que obtienes es la *forma* de Future: la consulta es un valor
que puedes pasar, combinar con `all()` y esperar como todo lo demás. Crear un
`QueryFuture` no cuesta nada. La consulta se ejecuta la primera vez que gira el
loop, y una consulta que nunca se espera podría no ejecutarse nunca.

Hay una ventaja real cuando una consulta se mezcla con HTTP. Inicia primero las
peticiones y luego espera la consulta. Las peticiones avanzan mientras el loop
espera, aunque no mientras PDO está bloqueado:

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

$weather = Http::getAsync('https://api.example.com/weather');
$news    = Http::getAsync('https://api.example.com/news');

$user = await(User::query()->findAsync(1));   // Bloquea mientras se ejecuta la consulta

[$weatherResponse, $newsResponse] = awaitAll($weather, $news);
```

Un `QueryFuture` no es `Cancellable`, así que un plazo no lo detiene (consulta
[Plazos y cancelación](#plazos-y-cancelación)).

## Componentes

Un componente `.phpx` es una función, así que puede llamar a `await()`:

```php
function UserPanel(string $url): Sfht
{
    $data = await(Http::getAsync($url))->json();

    return Sfht(
        <div class="card"><p>{{ $data['name'] ?? '' }}</p></div>
    );
}
```

El framework llama a los componentes uno tras otro, no como tareas. Un
componente que espera una petición, por tanto, la espera antes de que se llame
al siguiente componente. Para que las peticiones de dos componentes se solapen,
ambas deben estar en curso antes de que se espere cualquiera de ellas. O las
inicias antes (en el controlador) y pasas hacia abajo los Futures o los
resultados, o renderizas cada componente dentro de `async()` y esperas las dos
tareas juntas:

```php
[$left, $right] = awaitAll(
    async(fn () => UserPanel('https://api.example.com/users/1')),
    async(fn () => UserPanel('https://api.example.com/users/2')),
);
```

## Un planificador por petición: EnableAsync

Las funciones async funcionan sin ninguna configuración. La primera vez que
necesitan un planificador crean uno para todo el proceso, el planificador
*raíz*.

`SfphpProject\src\Http\Middleware\EnableAsync` es opcional. Le da a cada
petición su propio planificador, que se apila cuando la petición entra en el
pipeline y se retira cuando la respuesta sale de él. Así, nada de lo que una
petición deje pendiente (una tarea olvidada, una petición que nadie esperó)
puede pasar a la petición siguiente, lo cual importa cuando un worker atiende
muchas peticiones. Para usarlo, añádelo a la lista de middleware de
`public/index.php`:

```php
$router = (new Router($container))->middleware(
    new LogRequests(),
    new EnableAsync(),
    new SecurityHeaders(),
    // ...
);
```

El trabajo que siga pendiente cuando se devuelve la respuesta se abandona junto
con su planificador; no se termina más tarde. Espera lo que inicies.

## Async en tus propias clases: AsyncAware

El trait `SfphpProject\src\Async\AsyncAware` le da a una clase tres helpers
protegidos:

- `withAsync(callable $fn)`: ejecuta `$fn` como una tarea en un planificador
  propio (mediante `syncRun()`) y devuelve su resultado.
- `isAsyncEnabled()`: si ya se ha apilado o creado un planificador.
- `getScheduler()`: ese planificador, o `null`.

```php
<?php

use SfphpProject\src\Async\AsyncAware;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

final class ProfileService
{
    use AsyncAware;

    public function load(int $id): array
    {
        return $this->withAsync(function () use ($id): array {
            [$user, $posts] = awaitAll(
                Http::getAsync('https://api.example.com/users/' . $id),
                Http::getAsync('https://api.example.com/users/' . $id . '/posts'),
            );

            return ['user' => $user->json(), 'posts' => $posts->json()];
        });
    }
}

$profile = (new ProfileService())->load(1);
```

## Errores

Toda excepción que el runtime lanza por motivos propios extiende
`SfphpProject\src\Async\AsyncException`, que extiende `RuntimeException`:

| Excepción | Cuándo |
|---|---|
| `AsyncException` | Leer un Future que no ha concluido. Un deadlock (abajo). Retirar un planificador que no está. `Context::getScheduler()` sin planificador ("No active Scheduler"). |
| `TimeoutException` | `await($future, $timeout)` sobre un `Cancellable` que no concluyó a tiempo. |
| `CancelledException` | Leer o esperar un Future cancelado sin un motivo explícito. |

El error que lanza el propio trabajo se transmite sin cambios. Una tarea que
lanza `DomainException` hace que `await()` lance esa `DomainException`. Una
transferencia HTTP fallida lanza `SfphpProject\src\Http\ClientException`.

**Deadlock.** Cuando todas las tareas están aparcadas en algo que nada va a
concluir, y no hay ninguna transferencia ni temporizador pendiente, el
planificador se detiene con:

```
Deadlock: 1 task(s) are waiting and nothing is pending that could wake them.
```

Las tareas atascadas se cancelan con esa misma excepción, para que no se culpe
de ellas a un `await()` posterior sin relación. Esperar un Future así desde
fuera de una tarea informa:

```
Deadlock: the awaited operation is still pending and nothing is scheduled that could settle it.
```

Esto solo ocurre con Futures que construyes tú, como una subclase de `Pending`
que nunca concluye. Los Futures del framework siempre concluyen.

`SfphpProject\src\Async\Exceptions.php` se conserva solo para que el código que
lo requería siga cargando. Cada una de las tres clases tiene su propio archivo y
se carga con el autoloader.

## Adaptadores bloqueantes

Estas clases implementan `Future` pero no `Pending`. Son anteriores al runtime
actual y funcionan a la antigua: **no pasa nada hasta que se lee el valor, y
entonces se ejecutan, bloqueando.** `await()` sobre una de ellas simplemente
llama a `getValue()`. Añaden la forma de Future, no concurrencia, y ninguna es
`Cancellable`, así que un plazo no les afecta.

### FileFuture

`SfphpProject\src\Async\FileFuture` envuelve una operación de archivo:

| Fábrica | Se resuelve con |
|---|---|
| `FileFuture::read($path)` | El contenido. Se rechaza cuando el archivo no existe. |
| `FileFuture::write($path, $content)` | Bytes escritos. |
| `FileFuture::append($path, $content)` | Bytes escritos. |
| `FileFuture::delete($path)` | `true`, o `false` cuando el archivo no existía. |
| `FileFuture::copy($from, $to)` / `move($from, $to)` | `true`. |
| `FileFuture::exists($path)` | `bool`. |
| `FileFuture::size($path)` | Bytes, o `0` cuando no existe. |
| `FileFuture::mkdir($path, ['mode' => 0755, 'recursive' => true])` | `true`. |
| `FileFuture::scan($path)` | Las entradas sin `.` ni `..`, **con las claves tal como las dejó `scandir()`** (usa `array_values()` para obtener una lista). |

```php
<?php

use SfphpProject\src\Async\FileFuture;

use function SfphpProject\src\Async\await;

$path = sys_get_temp_dir() . '/sfphp-report.txt';

await(FileFuture::write($path, "first line\n"));
await(FileFuture::append($path, "second line\n"));

echo await(FileFuture::read($path));
echo await(FileFuture::size($path)), " bytes\n";

await(FileFuture::delete($path));
```

Pasa el propio Future a `await()`. `await(FileFuture::read($path)->getValue())`
pasa una cadena y falla con un `TypeError`.

### CacheFuture

`SfphpProject\src\Async\Adapters\CacheFuture` envuelve una operación de caché.
El driver es la caché del framework: el helper `cache()`, un `CacheManager` o
cualquier driver `SfphpProject\src\Cache\Cache`. Se usan sus `put()` y
`forget()`. Un objeto que tenga en su lugar `set()`/`delete()` (al estilo
PSR-16) también funciona.

| Fábrica | Hace |
|---|---|
| `CacheFuture::get($key, $cache)` | Lee (se resuelve con `null` cuando no existe). |
| `CacheFuture::set($key, $value, $ttl = 3600, $cache)` | Almacena. Un TTL de `0` o menos significa sin caducidad. Se resuelve con `true`. |
| `CacheFuture::delete($key, $cache)` | Elimina. Se resuelve con `true`. |
| `CacheFuture::has($key, $cache)` | `bool`. |
| `CacheFuture::increment($key, $by = 1, $cache)` | El nuevo valor, de forma atómica. |
| `CacheFuture::decrement($key, $by = 1, $cache)` | El nuevo valor. Los drivers sin `decrement()` reciben `increment(-$by)`. |

```php
<?php

use SfphpProject\src\Async\Adapters\CacheFuture;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

use function SfphpProject\src\Async\await;

$cache = new CacheManager(new MemoryDriver()); // En una aplicación: cache()

await(CacheFuture::set('greeting', 'hello', 60, $cache));
echo await(CacheFuture::get('greeting', $cache)), PHP_EOL;   // hello
echo await(CacheFuture::increment('visits', 1, $cache)), PHP_EOL;
await(CacheFuture::delete('greeting', $cache));
```

Como cada operación bloquea, la llamada síncrona es más corta y hace lo mismo:
`cache()->put('greeting', 'hello', 60)`. El adaptador es útil cuando una API
recibe un `Future`.

### CacheInvalidator

`SfphpProject\src\Async\CacheInvalidator` elimina una clave junto con las
claves registradas como dependientes de ella. No es asíncrono. Vive aquí porque
puede seguir a un `ReactiveState`.

```php
<?php

use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$cache = new CacheManager(new MemoryDriver()); // En una aplicación: cache()

$invalidator = (new CacheInvalidator($cache))
    ->registerDependency('user:1', ['user:1:profile', 'user:1:posts'])
    ->registerDependency('user:1:posts', ['feed:home']);

$invalidator->invalidate('user:1');
// Olvida user:1, user:1:profile, user:1:posts y feed:home.
```

- Las dependencias se siguen de forma transitiva, y cada clave se visita una
  sola vez, así que un ciclo (`a` depende de `b` y `b` de `a`) es seguro.
- `invalidateMany([...])` invalida varias claves.
- `invalidateByPattern('user:1:*')` llama al `deleteByPattern()` de la caché si
  lo tiene. Los drivers del framework no lo tienen, así que recurre a invalidar
  las *fuentes registradas* cuyos nombres coinciden (`*` coincide con
  cualquier carácter). Las claves que nunca se registraron no se encuentran de
  esta forma.
- `invalidateOnStateChange($state, $keys)` invalida `$keys` cada vez que cambia
  el `ReactiveState`.
- `createUserInvalidationPattern($id)` y
  `createResourceInvalidationPattern($type, $id)` devuelven listas de claves
  convencionales (`user:1`, `user:1:profile`, …) para pasar a
  `invalidateMany()`.

### ComponentFuture

`SfphpProject\src\Async\ComponentFuture` ejecuta un callable de renderizado
cuando se lee su valor, y lo reintenta si falla:

```php
<?php

use SfphpProject\src\Async\ComponentFuture;

$html = (new ComponentFuture(fn (): string => '<p>Hello</p>', maxRetries: 2))->getValue();

$safe = ComponentFuture::withFallbacks(
    fn (): string => throw new RuntimeException('The service is down.'),
    null,
    fn (Throwable $e): string => '<p class="error">' . htmlspecialchars($e->getMessage()) . '</p>',
    2,
);

echo $safe->getValue(), PHP_EOL;
```

- Los reintentos esperan `setRetryDelay($ms)` milisegundos (100 por defecto)
  con `usleep()`, que bloquea todo el proceso. Mantén los reintentos pocos y
  cortos.
- `getRetryCount()` indica cuántos reintentos se usaron.
- `withFallbacks($component, $loadingFallback, $errorFallback, $maxRetries)`:
  cuando el componente sigue fallando tras sus reintentos, `$errorFallback($e)`
  proporciona el resultado. `$loadingFallback` se acepta y nunca se llama,
  porque un renderizado en el servidor no tiene momento de carga.
- La sintaxis `(new ComponentFuture(...))->getValue()` necesita los paréntesis
  en PHP 8.1 a 8.3.

### StreamFuture

`SfphpProject\src\Async\StreamFuture` ejecuta un pipeline sobre un iterable (un
array, un generador o un callable que devuelva uno) bloque a bloque:

```php
<?php

use SfphpProject\src\Async\StreamFuture;

use function SfphpProject\src\Async\await;

$evenTimesTen = (new StreamFuture(range(1, 10), chunkSize: 3))
    ->filter(fn (int $n): bool => $n % 2 === 0)
    ->map(fn (int $n): int => $n * 10);

print_r(await($evenTimesTen));        // [20, 40, 60, 80, 100]

$sum = (new StreamFuture(range(1, 10), 3))
    ->reduce(fn (int $carry, int $n): int => $carry + $n, 0);

echo $sum, PHP_EOL;                    // 55
```

- `pipe(callable $stage)` añade una etapa que recibe un bloque (un array) y
  devuelve un array. `filter()` y `map()` son etapas construidas sobre ella.
- El pipeline se ejecuta cuando se lee el valor, una vez. El resultado son
  todos los elementos procesados en una sola lista.
- `reduce($fn, $initial)` es terminal. Pliega cada elemento a través del
  pipeline construido hasta ese momento y devuelve el valor directamente, no un
  Future. No modifica el pipeline, así que `getValue()` sigue funcionando
  después.
- `getProcessedCount()` cuenta los elementos que salieron del pipeline.

**Memoria.** La fuente se lee bloque a bloque. `reduce()` solo retiene un
bloque y el valor acumulado. `getValue()` reúne todos los elementos procesados
en su resultado, así que necesita memoria para toda la salida.

Tres fábricas:

- `StreamFuture::fromCsv($path, $chunkSize)`: un array por fila del CSV, leído
  línea a línea. Lanza `RuntimeException` cuando no se puede abrir el archivo.
- `StreamFuture::fromJsonLines($path, $chunkSize)`: un valor decodificado por
  línea. Las líneas que no son JSON se omiten.
- `StreamFuture::fromQuery($query, $chunkSize)`: filas de una consulta de
  modelo o del constructor de consultas. Las consultas de SFPHP no tienen
  cursor, así que todas las filas se obtienen con un único `get()` cuando el
  stream se lee por primera vez. El tamaño de bloque limita cuántas filas
  maneja cada etapa a la vez, no cuántas hay en memoria.

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Async\StreamFuture;

$names = (new StreamFuture(fn () => User::query()->get(), 500))
    ->map(fn (User $user): string => (string) $user->name)
    ->getValue();
```

## ReactiveState

`SfphpProject\src\Async\ReactiveState` guarda un valor junto con indicadores de
carga y de error, y notifica a los listeners cuando cambian:

```php
<?php

use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Http\Http;

$state = new ReactiveState(initialValue: null, ttl: 300);

$state->onChange(function (ReactiveState $s): void {
    echo $s->hasError() ? 'error: ' . $s->getError()->getMessage() : 'value changed', PHP_EOL;
});

$state->updateFromFuture(Http::getAsync('https://api.example.com/users/1'));

$response = $state->getValue();         // La ClientResponse
$view     = $state->toArray();          // value, loading, error, hasError, expired
```

- `updateFromFuture($future)` **espera** el Future. Si tiene éxito, se fija el
  valor. Si falla, la excepción se guarda con `setError()`. La carga está en
  `true` mientras espera y ya vuelve a estar en `false` cuando se notifica a los
  listeners.
- `setValue($v)` solo notifica cuando el valor no es idéntico (`!==`) al
  actual. `setError()`, `reset()` y `setLoading(false)` notifican siempre.
- Los listeners se llaman con el estado. Una excepción lanzada por un listener
  se ignora.
- `ttl` está en segundos (`0` significa que nunca caduca). Cuenta desde el
  último `setValue()`. Una vez transcurrido, `getValue()` devuelve `null` e
  `isExpired()` es verdadero.
- `addDependency($cacheKey)` y `getDependencies()` registran claves de caché
  para tu propio uso. Para invalidar de verdad al cambiar, usa
  `CacheInvalidator::invalidateOnStateChange()`.
- Un estado vive lo que un proceso PHP, y en PHP-FPM eso significa una
  petición. No se comparte entre peticiones ni con el navegador.

## EventBroadcaster

`SfphpProject\src\Async\EventBroadcaster` es un centro de publicación/suscripción
dentro del proceso. Está separado del despachador de eventos del framework
(`SfphpProject\src\Events\Dispatcher`) y no comparte listeners con él.

```php
<?php

use SfphpProject\src\Async\EventBroadcaster;

use function SfphpProject\src\Async\await;

$events = new EventBroadcaster();

$events->subscribe('user.created', function (string $event, mixed $payload): string {
    return 'welcome mail for ' . $payload['email'];
}, priority: 10);

$events->subscribe('user.*', fn (string $event): string => 'audit: ' . $event);

$report = await($events->broadcast('user.created', ['email' => 'ana@example.com']));
// O, de forma equivalente: $report = $events->broadcastSync('user.created', [...]);

print_r($report['results']);
```

**Entrega.** `broadcast()` devuelve una tarea. Los listeners se ejecutan dentro
de ella, uno tras otro, por orden de prioridad (la más alta primero). Como
cualquier tarea, se ejecuta cuando el planificador recibe un turno, que es en el
siguiente `await()`. `broadcastSync()` la espera por ti y devuelve el informe.
El informe es:

```
[
    'event'           => 'user.created',
    'listeners_count' => 2,
    'results'         => [listenerId => return value, ...],
    'errors'          => [listenerId => exception message, ...],
    'duration_ms'     => 0.05,
]
```

Un listener que lanza una excepción no detiene a los demás. Su mensaje va a
`errors`. Los listeners reciben `($event, $payload)`.

**Comodines.** `*` en una suscripción representa uno o más caracteres, puntos
incluidos:

| Patrón | Recibe | No recibe |
|---|---|---|
| `user.created` | `user.created` | ningún otro |
| `user.*` | `user.created`, `user.profile.updated` | `user`, `users.created` |
| `*.created` | `user.created`, `order.created` | `created` |
| `order.*.shipped` | `order.42.shipped` | `order.shipped` |

**Gestionar los listeners.**

- `subscribe()` devuelve un id de listener. `unsubscribe($event, $id)` elimina
  ese listener, y `unsubscribeAll($event)` elimina todos los listeners de ese
  patrón exacto.
- `getListenerCount($event)` cuenta los listeners a los que llegaría un evento,
  comodines incluidos. Sin argumento cuenta todos los listeners.
- `getEvents()` lista los patrones suscritos, y `clear()` elimina todos los
  listeners.
- `enableHistory(true, $limit)` registra cada emisión (`event`, `payload`,
  `timestamp`) y conserva las últimas `$limit`. Léelo con `getHistory()` y
  vacíalo con `clearHistory()`.
- `scope('billing')` devuelve un broadcaster que comparte estos listeners y
  este historial y antepone `billing.` a cada nombre de evento que recibe.
  `clear()` sobre un ámbito elimina solo los listeners de ese ámbito.

```php
<?php

use SfphpProject\src\Async\EventBroadcaster;

$events = new EventBroadcaster();
$billing = $events->scope('billing');

$billing->subscribe('paid', fn (string $event): string => 'heard ' . $event);

$report = $events->broadcastSync('billing.paid');   // Llega al listener
echo $report['listeners_count'], PHP_EOL;            // 1
```

Todo vive en memoria durante un proceso: nada se envía a otras peticiones,
workers, servidores ni navegadores. Para llegar a los navegadores, usa
server-sent events ([STREAMING.md](STREAMING.md)). Para trabajo que deba
sobrevivir a la petición, usa la cola.

## WebSocketFuture (experimental)

`SfphpProject\src\Async\WebSocketFuture` es **experimental y no es un cliente
WebSocket funcional.** No construyas funcionalidades sobre él.

Lo que hace:

- Abre una conexión TCP simple al host y al puerto de la URL (80 cuando la URL
  no indica ninguno). Concluye una vez que la conexión está abierta o ha
  fallado.
- `send($text)` escribe una trama de texto enmascarada. `queueMessage()` y
  `flushQueue()` agrupan envíos. `disconnect()` cierra el socket.

Lo que no hace:

- **Sin handshake de apertura.** Nunca envía la petición HTTP
  `Upgrade: websocket`, así que un servidor WebSocket real cierra la conexión.
  Las cabeceras pasadas al constructor se guardan y nunca se envían.
- **Sin TLS.** Una URL `wss://` se rechaza: `connect()` devuelve `false` y el
  Future se rechaza.
- **Sin recepción.** Nada lee el socket, así que los callbacks de `onMessage()`
  nunca se llaman. No hay tramas ping, pong ni close.
- **Fuera del event loop.** Conectar y escribir bloquean el proceso.

```php
<?php

use SfphpProject\src\Async\WebSocketFuture;

$socket = WebSocketFuture::open('wss://echo.example.com/socket');

var_dump($socket->isConnected());               // bool(false)
echo $socket->getException()->getMessage(), PHP_EOL;
```

`WebSocketFuture::open($url)` construye y conecta. `connect()` es el método de
instancia y devuelve `false` si falla en lugar de lanzar una excepción. Para
actualizaciones en tiempo real hacia un navegador, usa server-sent events y
`@stream` de SFJS ([STREAMING.md](STREAMING.md)).

## Bajo el capó

Tres clases hacen el trabajo. Rara vez las necesitas directamente.

**`EventLoop`** guarda todo aquello en lo que el proceso puede esperar:
transferencias cURL (en un único multi handle), temporizadores y observadores
de streams (`addWatcher()`, un punto de extensión para backends que exponen un
socket, que el framework todavía no usa). `tick()` es el único lugar donde el
proceso espera, y espera por todos ellos a la vez, como mucho 50 ms cada vez.

**`Scheduler`** mantiene las tareas *listas*, que ejecuta por turnos, y las
tareas *en espera*, que están aparcadas en un Future. Una tarea en espera no se
toca hasta que su Future concluye, y entonces vuelve a estar lista. Cuando no
hay nada listo, el planificador deja esperar al loop. `stats()` devuelve
`['ready' => …, 'waiting' => …, 'loop' => ['transfers' => …, 'timers' => …, 'watchers' => …]]`,
lo que resulta útil para comprobar que no queda nada pendiente al final de una
petición.

**`Context`** es una pila de planificadores. `Context::scheduler()` devuelve el
de arriba y crea el planificador raíz cuando la pila está vacía. EnableAsync y
`syncRun()` apilan y retiran los suyos. `Context::getScheduler()` lanza
`AsyncException` ("No active Scheduler") en lugar de crear uno, y
`Context::hasScheduler()` pregunta sin efectos secundarios.

```php
<?php

use SfphpProject\src\Async\Context;

use function SfphpProject\src\Async\{await, delay};

await(delay(10));

print_r(Context::scheduler()->stats());
```

## Cifras medidas

Estas cifras salen de `benchmarks/async.php`, ejecutado contra el servidor de
origen incluido, que responde a cada petición tras 300 ms desde un único proceso
no bloqueante:

```
php benchmarks/origin.php 127.0.0.1:8300 &
php benchmarks/async.php
```

Una ejecución en PHP 8.4.24, Linux 6.12, un Intel Core i7-12650H de 16 hilos y
curl 8.14.1:

| Caso | Tiempo |
|---|---|
| 1 petición | 301,3 ms |
| 3 peticiones, cada una esperada antes de que empiece la siguiente | 903,6 ms |
| 3 peticiones, todas en curso | 303,1 ms |
| 10 peticiones, todas en curso | 302,2 ms |
| 50 peticiones, todas en curso | 303,8 ms |
| 10 peticiones mediante tareas `async()` | 301,2 ms |
| Sobrecoste de `async()` + `await()` sin I/O | 5,6 µs por tarea |
| Pico de memoria de toda la ejecución | 2,00 MB |

Las cifras de una ejecución en una máquina describen esa máquina. Ejecuta el
benchmark en tu propio hardware antes de fiarte de ellas.
`benchmarks/database.php` mide el lado de las consultas contra un servidor MySQL
al que lo apuntes (consulta su cabecera). Muestra que las consultas esperadas
juntas cuestan más o menos lo mismo que las mismas consultas ejecutadas una tras
otra.

## Errores comunes

| Síntoma | Causa | Solución |
|---|---|---|
| `Call to undefined function async()` | La función no está importada. | `use function SfphpProject\src\Async\{async, await, delay, awaitAll};` |
| `AsyncException: This operation has not finished…` | `getValue()` sobre un Future que no ha concluido. | `await($future)`. |
| `TypeError` desde `await()` | Se pasó un valor en lugar de un Future, como `await($f->getValue())`. | `await($f)`. |
| `await(async(...))` devuelve un Future | El callable devolvió un Future sin esperarlo. | `async(fn () => await(...))`, o espera el Future directamente. |
| Tres consultas «en paralelo» tardan el triple | Las consultas bloquean (PDO). | Es lo esperado. Solo HTTP y los temporizadores se solapan. |
| Una petición no se solapó con otro trabajo | Se ejecutó trabajo bloqueante entre crear el Future y esperarlo. | Crea primero todas las peticiones y luego espéralas juntas. |
| `ClientException: … No host part in the URL` | Se pasó una URL relativa a `Http::*Async`. | Usa una URL absoluta. |
| Un plazo no detuvo la operación | El Future no es `Cancellable` (consulta, archivo, caché, componente). | Solo HTTP, temporizadores, tareas y compuestos respetan los plazos. |
| `AsyncException: Deadlock: …` | Una tarea espera un Future que nada va a concluir. | Haz que concluya, o no lo esperes. |
| Una tarea nunca se ejecutó | Nada esperó nada después de crearla. | Espérala, o espera otra cosa. |

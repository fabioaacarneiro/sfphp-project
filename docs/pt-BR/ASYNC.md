# Guia do Runtime Async

> **Leia em:** [English](../en/ASYNC.md) · [Português](ASYNC.md) · [Español](../es/ASYNC.md)

Este guia cobre o runtime async do SFPHP em profundidade: o que cada função e
classe faz, o que não faz, e onde o processo realmente espera. Ele acrescenta
detalhes à [seção Async da documentação principal](DOCUMENTATION.md#async) e
não a substitui.

Todo exemplo que começa com `<?php` roda como está escrito, desde que o
autoloader do Composer esteja carregado (dentro de uma aplicação SFPHP ele
sempre está). Os exemplos chamam `https://api.example.com`. Aponte-os para um
servidor que você controla para experimentá-los. Os exemplos de banco também
precisam de uma conexão configurada.

## Índice

- [Dois sentidos de async](#dois-sentidos-de-async)
- [Importando as funções](#importando-as-funções)
- [async() e await()](#async-e-await)
- [delay()](#delay)
- [Vários de uma vez: awaitAll() e CompositeFuture](#vários-de-uma-vez-awaitall-e-compositefuture)
- [syncRun()](#syncrun)
- [O contrato do Future](#o-contrato-do-future)
- [Tasks](#tasks)
- [Prazos e cancelamento](#prazos-e-cancelamento)
- [Requisições HTTP](#requisições-http)
- [Consultas ao banco](#consultas-ao-banco)
- [Componentes](#componentes)
- [Um scheduler por requisição: EnableAsync](#um-scheduler-por-requisição-enableasync)
- [Async nas suas próprias classes: AsyncAware](#async-nas-suas-próprias-classes-asyncaware)
- [Erros](#erros)
- [Adaptadores bloqueantes](#adaptadores-bloqueantes)
  - [FileFuture](#filefuture)
  - [CacheFuture](#cachefuture)
  - [CacheInvalidator](#cacheinvalidator)
  - [ComponentFuture](#componentfuture)
  - [StreamFuture](#streamfuture)
- [ReactiveState](#reactivestate)
- [EventBroadcaster](#eventbroadcaster)
- [WebSocketFuture (experimental)](#websocketfuture-experimental)
- [Por baixo dos panos](#por-baixo-dos-panos)
- [Números medidos](#números-medidos)
- [Erros comuns](#erros-comuns)

---

## Dois sentidos de async

A palavra cobre duas coisas diferentes, e a diferença decide quanto tempo o seu
código leva:

- **Agendamento assíncrono.** Uma operação é um valor (um *Future*) que o
  runtime pode segurar, passar adiante, combinar e aguardar. Tudo neste guia tem
  isso.
- **I/O não bloqueante.** Enquanto a operação espera, o processo faz outro
  trabalho. Só algumas operações têm isso.

| Operação | Tipo de Future | Se sobrepõe a outro trabalho? |
|---|---|---|
| Requisição HTTP (`Http::getAsync()` e as demais) | `HttpFuture` | **Sim.** As requisições rodam juntas em um único curl multi handle. |
| Temporizador (`delay()`) | `TimerFuture` | **Sim.** É um prazo pelo qual o event loop acorda. |
| Seu próprio código (`async()`) | `Task` | Só enquanto aguarda algo que se sobrepõe. |
| Consulta ao banco (`getAsync()`, `firstAsync()`, …) | `QueryFuture` | **Não.** O PDO bloqueia até o servidor responder. |
| Adaptadores de arquivo, cache, componente e stream | `FileFuture`, `CacheFuture`, … | **Não.** Eles rodam, bloqueando, quando são aguardados. |

No PHP puro (PHP-FPM, `php -S`, a CLI) isso é literalmente verdade. O SFPHP não
precisa de nenhuma extensão como Swoole ou RoadRunner para que requisições HTTP
e temporizadores se sobreponham. Ele também não torna uma chamada bloqueante não
bloqueante só por rodá-la dentro de uma Fiber: três consultas aguardadas juntas
levam o mesmo que as três somadas.

O runtime tem uma única thread. Ele nunca roda dois trechos de PHP no mesmo
instante. O que se sobrepõe é a *espera*: enquanto uma requisição espera a sua
resposta, os bytes de outra podem chegar.

## Importando as funções

Os quatro helpers são funções com namespace. Importe-os com `use function`:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, awaitAll};
```

O `syncRun` mora no mesmo namespace, e você pode acrescentá-lo à mesma lista.
As classes são importadas do jeito de sempre:

```php
<?php

use SfphpProject\src\Async\CompositeFuture;
use SfphpProject\src\Async\TimeoutException;
use SfphpProject\src\Http\Http;
```

## async() e await()

O `await($future)` espera um Future e devolve o seu valor, ou lança aquilo com
que ele foi rejeitado. O `async($callable)` roda o seu callable como uma
**Task** ao lado do que mais estiver rodando, e devolve a Task, que também é um
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

Como o `await()` espera depende de onde ele é chamado:

- **Dentro de uma Task** ele estaciona a Fiber da Task. O scheduler a retoma
  quando o Future aguardado conclui, e roda outras Tasks enquanto isso.
- **Fora de uma Task** (em um controller, um comando ou um teste) ele mesmo
  conduz o event loop até o Future concluir. Toda outra operação pendente
  continua progredindo enquanto ele espera.

Nos dois casos o processo nunca fica consultando em laço. Quando não há nada
pronto para rodar, ele espera em um único `select()` sobre todas as
transferências HTTP em aberto e o temporizador mais próximo.

O `await()` funciona em qualquer lugar. Não é preciso configuração nem
middleware, porque o runtime cria um scheduler para o processo na primeira vez
em que um é necessário (veja
[EnableAsync](#um-scheduler-por-requisição-enableasync) para ter um por
requisição).

**Uma Task começa quando algo conduz o scheduler, não na criação.** O
`async()` enfileira a Task. A Task roda na próxima vez em que qualquer
`await()` (de qualquer coisa) dá a vez ao scheduler. Uma Task que ninguém
aguarda, em um programa que nunca aguarda nada, nunca roda.

**O valor de uma Task é o que o callable devolve, tal como é.** Se o callable
devolve um Future, a Task resolve com esse Future e não com o valor dele.
Aguarde-o dentro do callable:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{async, await};

// Errado: $wrong é um HttpFuture, não uma resposta.
$wrong = await(async(fn () => Http::getAsync('https://api.example.com/a')));

// Certo: a Task aguarda a requisição e devolve a resposta.
$right = await(async(fn () => await(Http::getAsync('https://api.example.com/a'))));
```

Na maioria dos casos você nem precisa do `async()`. Um Future HTTP já está
rodando, então aguardá-lo diretamente basta. Use o `async()` quando você tiver
vários passos seus que devem rodar ao lado de outro trabalho, como "buscar, e
depois buscar de novo com o resultado".

## delay()

O `delay($milliseconds, $value = null)` devolve um Future que resolve com
`$value` depois da espera. É um temporizador do event loop, não um `usleep()`:
outras Tasks e transferências HTTP continuam progredindo enquanto ele corre.

```php
<?php

use SfphpProject\src\Async\CompositeFuture;

use function SfphpProject\src\Async\{await, delay};

$started = microtime(true);

// Três esperas de 250 ms aguardadas juntas levam cerca de 250 ms, não 750.
await(CompositeFuture::all(delay(250), delay(250), delay(250)));

$value = await(delay(10, 'done')); // 'done'

printf("%.0f ms\n", (microtime(true) - $started) * 1000);
```

## Vários de uma vez: awaitAll() e CompositeFuture

O `CompositeFuture` combina Futures. Ele tem exatamente dois modos:

- `CompositeFuture::all(...$futures)` resolve quando todas as partes resolvem,
  com os valores na ordem dada. Ele rejeita assim que uma parte falha, com a
  exceção dessa parte.
- `CompositeFuture::race(...$futures)` conclui com a primeira parte a concluir.
  Se essa parte falhou, a corrida rejeita.

O `awaitAll(...$futures)` é um atalho para `await(CompositeFuture::all(...))`.
As partes podem ter nome: `awaitAll(...['user' => $a, 'posts' => $b])` resolve
com `['user' => …, 'posts' => …]`, na ordem dada. Uma parte com nome era um
`TypeError` dentro do loop, que depois fazia falhar também o `await()` seguinte.

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

As três requisições se sobrepõem, então isso leva mais ou menos o tempo da mais
lenta.

Se algo se sobrepõe é decisão das partes. O `CompositeFuture` só escuta. Ele
não inicia nem conduz nada. Três chamadas a `Http::getAsync()` se sobrepõem
porque cada uma já estava no event loop. Três consultas passadas ao `all()`
continuam rodando uma depois da outra.

Quando o `all()` rejeita ou o `race()` conclui, as outras partes **não** são
canceladas. Elas pertencem a quem as criou, e uma requisição HTTP que perdeu uma
corrida continua rodando até o fim no loop. Para parar as outras partes, cancele
o próprio composto. O `CompositeFuture::cancel()` cancela toda parte que ainda
está pendente e pode ser cancelada.

O `all()` sem partes resolve na hora com `[]`.

## syncRun()

O `syncRun($future)` aguarda um Future em um scheduler novo, só dele, e depois
remove esse scheduler. Ele dá a um ponto de entrada, como um comando de console
ou um job de fila, um scheduler limpo que nada mais compartilha:

```php
<?php

use function SfphpProject\src\Async\{async, await, delay, syncRun};

$result = syncRun(async(function (): string {
    await(delay(50));

    return 'finished';
}));
```

Um Future criado *antes* da chamada fica preso ao event loop que era o atual
quando ele foi criado. Crie o trabalho dentro do callable, como acima, para que
ele pertença ao scheduler que o `syncRun()` conduz.

## O contrato do Future

Todo Future implementa `SfphpProject\src\Async\Future`:

| Método | Significado |
|---|---|
| `isPending()` | Ainda não concluiu. |
| `isResolved()` | Concluiu com um valor. |
| `isRejected()` | Concluiu com uma exceção. |
| `getValue()` | O valor. Lança a exceção se foi rejeitado. |
| `getException()` | A exceção, ou `null`. |
| `onResolve(callable $cb)` | Chama `$cb($future)` quando ele conclui, ou na hora se já tiver concluído. |

Os Futures do próprio runtime (`Task`, `HttpFuture`, `TimerFuture`,
`CompositeFuture`, `QueryFuture`) estendem `Pending`, que acrescenta:

| Método | Significado |
|---|---|
| `isCancelled()` | Foi cancelado. Um Future cancelado não é "rejeitado". |
| `isSettled()` | Resolvido, rejeitado ou cancelado. |
| `state()` | `'pending'`, `'running'`, `'resolved'`, `'rejected'` ou `'cancelled'`. |

Três regras:

1. **Concluído é definitivo.** Depois que um Future conclui, ele nunca mais
   muda.
2. **Ler cedo demais lança exceção.** O `getValue()` em um `Pending` que não
   concluiu lança `AsyncException` ("This operation has not finished. Await it
   before reading its value."). Ele não devolve `null`. Use `await()`.
3. **Um Future cancelado lança exceção ao ser lido.** O `getValue()` lança o
   motivo do cancelamento: uma `CancelledException`, ou o motivo que tiver sido
   dado.

```php
<?php

use SfphpProject\src\Async\AsyncException;

use function SfphpProject\src\Async\{async, await};

$task = async(fn (): int => 42);

try {
    $task->getValue();                 // Ainda não rodou
} catch (AsyncException $e) {
    echo $e->getMessage(), PHP_EOL;
}

echo await($task), PHP_EOL;            // 42
echo $task->getValue(), PHP_EOL;       // 42, agora que concluiu
```

Os callbacks passados ao `onResolve()` rodam de forma síncrona quando o Future
conclui. Uma exceção lançada em um deles não é engolida. Ela se propaga,
porque escondê-la deixaria uma Task que nunca é retomada.

## Tasks

Uma `Task` envolve uma Fiber. Ela fica `pending` até o scheduler chegar nela
pela primeira vez, `running` enquanto a sua Fiber está viva, e depois
`resolved`, `rejected` ou `cancelled`.

- Uma exceção lançada dentro do callable rejeita a Task. O `await()` a relança.
- `Fiber::suspend()` dentro de uma Task (sem aguardar nada) cede a vez às
  outras Tasks prontas. A Task vai para o fim da fila.
- `await()` em uma Task a agenda se ela ainda não estiver agendada.

```php
<?php

use function SfphpProject\src\Async\{async, await};

$outer = async(function (): int {
    $inner = async(fn (): int => 20);

    return await($inner) + 1;
});

echo await($outer), PHP_EOL; // 21
```

## Prazos e cancelamento

O `await()` aceita um timeout em milissegundos:

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

O prazo é um temporizador no event loop. Quando ele dispara, o Future aguardado
é **cancelado** e o `await()` lança `TimeoutException`.

**Só Futures que implementam `Cancellable` respeitam um timeout.** São eles:

| Future | O que o cancelamento faz |
|---|---|
| `HttpFuture` | Remove a transferência do curl multi handle e fecha a conexão. |
| `TimerFuture` (`delay()`) | Remove o temporizador. |
| `Task` | Conclui a Task como cancelada. A sua Fiber nunca é retomada, então o código dela para no `await()` em que está estacionado. |
| `CompositeFuture` | Cancela toda parte que ainda está pendente e é cancelável, e depois a si mesmo. |

Um Future que não é `Cancellable` ignora o timeout. Isso inclui o
`QueryFuture` e os [adaptadores bloqueantes](#adaptadores-bloqueantes). O
`await()` espera que ele conclua, exatamente como faria sem timeout, e
nenhuma `TimeoutException` é lançada. Para uma consulta não teria como ser
diferente: depois que o PDO a enviou, o processo fica bloqueado até o servidor
responder.

Um timeout em uma `Task` cancela a Task, não o trabalho dentro dela. Se uma Task
está estacionada em uma requisição HTTP quando o prazo vence, a requisição
continua rodando no loop. Para limitar a própria requisição, coloque o timeout
na requisição:

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

Você também pode cancelar explicitamente. O argumento opcional é o motivo que o
código que aguarda recebe:

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

## Requisições HTTP

O HTTP é onde o runtime sobrepõe trabalho de verdade. Cada chamada entrega uma
transferência ao curl multi handle do event loop e devolve um `HttpFuture` na
hora.

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

As assinaturas são:

| Método | Argumentos |
|---|---|
| `Http::getAsync` | `string $url, array $query = [], array $headers = []` |
| `Http::postAsync` / `putAsync` / `patchAsync` | `string $url, array\|string\|null $body = null, array $headers = []` |
| `Http::deleteAsync` | `string $url, array $headers = []` |

Um corpo em array é enviado como JSON com `Content-Type: application/json`. Um
corpo em string é enviado como está. Os seus próprios headers vencem esses
padrões.

O `HttpFuture` tem construtores estáticos equivalentes: `HttpFuture::get($url,
$headers, $options)`, `post($url, $body, $headers, $options)`, e `put`,
`patch`, `delete`. O último argumento recebe opções cruas do cURL
(`CURLOPT_*`), que vencem os padrões.

**O que o cliente async não compartilha com o síncrono.** Os métodos `*Async`
criam uma requisição nova a cada vez. Eles não usam `Http::base()`,
`withToken()` nem o restante da configuração de `Http::client()`. Então:

- **Use URLs absolutas.** `Http::getAsync('/users')` não tem host e rejeita com
  `ClientException` ("URL rejected: No host part in the URL").
- **Passe a autenticação como header:** `['Authorization' => 'Bearer ' . $token]`.
- Os padrões são fixos: 30 s no total, 10 s para conectar, até 5 redirecionamentos,
  redirecionamentos só para HTTPS (um redirecionamento de `https://` para
  `http://` é recusado). Mude-os por requisição através de `$options` no
  `HttpFuture`, ou limite a espera com `await(..., timeout: $ms)`.

**Quando a requisição progride.** A transferência é registrada quando o Future é
criado. Ela avança sempre que o processo espera no event loop, o que quer dizer
dentro de qualquer `await()`. Trabalho bloqueante feito entre criar o Future e
aguardá-lo (uma consulta, um `usleep()`, uma computação pesada) não se sobrepõe
à requisição. Comece as requisições primeiro, e depois aguarde:

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\awaitAll;

// As três são registradas antes de qualquer espera, então se sobrepõem.
$futures = [];

foreach ([1, 2, 3] as $id) {
    $futures[] = Http::getAsync('https://api.example.com/users/' . $id);
}

$responses = awaitAll(...$futures);
```

**A resposta.** Um `HttpFuture` resolve com um `ClientResponse`, a mesma classe
que o cliente síncrono devolve:

| Método | Devolve |
|---|---|
| `status()` | O código de status (`int`). |
| `ok()` | Verdadeiro para 2xx. |
| `failed()` | Verdadeiro para 4xx e 5xx. |
| `clientError()` / `serverError()` | Verdadeiro para 4xx / 5xx. |
| `body()` | O corpo cru (`string`). |
| `json(bool $strict = false)` | O corpo decodificado como array, ou `null` quando não é JSON. Com `true`, lança `ClientException` em vez de devolver `null`. |
| `header(string $name)` | Um header, buscado sem diferenciar maiúsculas, ou `null`. |
| `headers()` | Todos os headers da resposta final — não os dos redirecionamentos antes dela. |
| `url()` | A URL que respondeu, depois dos redirecionamentos. |
| `throw()` | Lança `ClientException` para 4xx/5xx; caso contrário, devolve a resposta. |

```php
<?php

use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\await;

$response = await(Http::getAsync('https://api.example.com/users/1'));

if ($response->ok()) {
    $user = $response->json();
    $type = $response->header('Content-Type');
}

// Ou trate um status de erro como exceção:
$user = await(Http::getAsync('https://api.example.com/users/1'))->throw()->json();
```

**Falhas.** Um *status* de erro (404, 500) é uma resposta: o Future resolve e
você inspeciona o `status()`. Nenhuma resposta (falha de DNS, conexão recusada,
timeout, falha de TLS) rejeita o Future com `SfphpProject\src\Http\ClientException`.
A extensão `curl` é obrigatória. Sem ela, o Future rejeita na hora com uma
`ClientException` que diz isso. O mesmo acontece com um valor de header que tem
uma quebra de linha, e com um corpo que o JSON não consegue codificar — que antes
era enviado como uma string vazia.

## Consultas ao banco

O `ModelQuery` tem quatro métodos async:

| Método | Resolve com |
|---|---|
| `getAsync()` | `array` de models |
| `firstAsync()` | um model ou `null` |
| `countAsync()` | `int` |
| `findAsync($id)` | um model ou `null` |

```php
<?php

use SfphpProject\app\models\User;

use function SfphpProject\src\Async\await;

$active = await(User::query()->where('active', true)->getAsync());
$first  = await(User::query()->orderBy('id')->firstAsync());
$count  = await(User::query()->countAsync());
$one    = await(User::query()->findAsync(1));
```

Use `firstAsync()`, não `first()->...`. O `first()` roda a consulta e devolve o
model, então não pode ser aguardado.

**Estas bloqueiam.** Um `QueryFuture` roda a sua consulta a partir do event
loop, pelo PDO, e o PDO não tem API assíncrona: o `execute()` espera o servidor,
e nenhuma Fiber muda isso. Aguardar três consultas juntas leva o mesmo que as
três em sequência. O que você ganha é a *forma* de Future: a consulta é um valor
que você pode passar adiante, combinar com `all()` e aguardar como todo o resto.
Criar um `QueryFuture` não custa nada. A consulta roda na primeira volta do
loop, e uma consulta que nunca é aguardada pode nunca rodar.

Há um benefício real quando uma consulta se mistura com HTTP. Comece as
requisições primeiro, e depois aguarde a consulta. As requisições progridem
enquanto o loop está esperando, mas não enquanto o PDO está bloqueado:

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Http\Http;

use function SfphpProject\src\Async\{await, awaitAll};

$weather = Http::getAsync('https://api.example.com/weather');
$news    = Http::getAsync('https://api.example.com/news');

$user = await(User::query()->findAsync(1));   // Bloqueia enquanto a consulta roda

[$weatherResponse, $newsResponse] = awaitAll($weather, $news);
```

Um `QueryFuture` não é `Cancellable`, então um timeout não o interrompe (veja
[Prazos e cancelamento](#prazos-e-cancelamento)).

## Componentes

Um componente `.phpx` é uma função, então ele pode chamar `await()`:

```php
function UserPanel(string $url): Sfht
{
    $data = await(Http::getAsync($url))->json();

    return Sfht(
        <div class="card"><p>{{ $data['name'] ?? '' }}</p></div>
    );
}
```

O framework chama os componentes um depois do outro, não como Tasks. Um
componente que aguarda uma requisição, portanto, espera por ela antes de o
próximo componente ser chamado. Para que as requisições de dois componentes se
sobreponham, as duas precisam estar em andamento antes de qualquer uma ser
aguardada. Ou você as começa mais cedo (no controller) e passa os Futures ou os
resultados adiante, ou renderiza cada componente dentro de `async()` e aguarda
as duas Tasks juntas:

```php
[$left, $right] = awaitAll(
    async(fn () => UserPanel('https://api.example.com/users/1')),
    async(fn () => UserPanel('https://api.example.com/users/2')),
);
```

## Um scheduler por requisição: EnableAsync

As funções async funcionam sem nenhuma configuração. Na primeira vez em que
precisam de um scheduler, elas criam um para o processo inteiro, o scheduler
*raiz*.

O `SfphpProject\src\Http\Middleware\EnableAsync` é opcional. Ele dá a cada
requisição o seu próprio scheduler, empilhado quando a requisição entra no
pipeline e removido quando a resposta sai dele. Assim, nada que uma requisição
deixe pendente (uma Task esquecida, uma requisição que ninguém aguardou) pode
passar para a requisição seguinte, o que importa quando um worker atende muitas
requisições. Para usá-lo, acrescente-o à lista de middlewares em
`public/index.php`:

```php
$router = (new Router($container))->middleware(
    new LogRequests(),
    new EnableAsync(),
    new SecurityHeaders(),
    // ...
);
```

O trabalho ainda pendente quando a resposta é devolvida é abandonado junto com o
seu scheduler, não terminado depois. Aguarde o que você começa.

## Async nas suas próprias classes: AsyncAware

A trait `SfphpProject\src\Async\AsyncAware` dá a uma classe três helpers
protegidos:

- `withAsync(callable $fn)`: roda `$fn` como uma Task em um scheduler só dela
  (através do `syncRun()`) e devolve o resultado.
- `isAsyncEnabled()`: se algum scheduler já foi empilhado ou criado.
- `getScheduler()`: esse scheduler, ou `null`.

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

## Erros

Toda exceção que o runtime lança por motivos próprios estende
`SfphpProject\src\Async\AsyncException`, que estende `RuntimeException`:

| Exceção | Quando |
|---|---|
| `AsyncException` | Ler um Future que não concluiu. Um deadlock (abaixo). Desempilhar um scheduler que não está lá. `Context::getScheduler()` sem scheduler ("No active Scheduler"). |
| `TimeoutException` | `await($future, $timeout)` em um `Cancellable` que não concluiu a tempo. |
| `CancelledException` | Ler ou aguardar um Future cancelado sem um motivo explícito. |

O erro que o próprio trabalho lançou passa sem alteração. Uma Task que lança
`DomainException` faz o `await()` lançar essa `DomainException`. Uma
transferência HTTP que falhou lança `SfphpProject\src\Http\ClientException`.

**Deadlock.** Quando toda Task está estacionada em algo que nada vai concluir, e
não há transferência nem temporizador pendente, o scheduler para com:

```
Deadlock: 1 task(s) are waiting and nothing is pending that could wake them.
```

As Tasks presas são canceladas com essa mesma exceção, para que um `await()`
posterior, sem relação com elas, não leve a culpa. Aguardar um Future assim de
fora de uma Task informa:

```
Deadlock: the awaited operation is still pending and nothing is scheduled that could settle it.
```

Isso só acontece com Futures que você mesmo constrói, como uma subclasse de
`Pending` que nunca conclui. Os Futures do framework sempre concluem.

O `SfphpProject\src\Async\Exceptions.php` é mantido só para que o código que o
incluía continue carregando. As três classes têm cada uma o seu próprio arquivo
e são carregadas pelo autoloader.

## Adaptadores bloqueantes

Estas classes implementam `Future`, mas não `Pending`. Elas são anteriores ao
runtime atual e funcionam do jeito antigo: **nada acontece até o valor ser lido,
e então ele roda, bloqueando.** O `await()` em uma delas simplesmente chama
`getValue()`. Elas acrescentam a forma de Future, não concorrência, e nenhuma
delas é `Cancellable`, então um timeout não as afeta.

### FileFuture

O `SfphpProject\src\Async\FileFuture` envolve uma operação de arquivo:

| Fábrica | Resolve com |
|---|---|
| `FileFuture::read($path)` | O conteúdo. Rejeita quando o arquivo não existe. |
| `FileFuture::write($path, $content)` | Bytes escritos. |
| `FileFuture::append($path, $content)` | Bytes escritos. |
| `FileFuture::delete($path)` | `true`, ou `false` quando o arquivo não existia. |
| `FileFuture::copy($from, $to)` / `move($from, $to)` | `true`. |
| `FileFuture::exists($path)` | `bool`. |
| `FileFuture::size($path)` | Bytes, ou `0` quando não existe. |
| `FileFuture::mkdir($path, ['mode' => 0755, 'recursive' => true])` | `true`. |
| `FileFuture::scan($path)` | Entradas sem `.` e `..`, **com as chaves que o `scandir()` deixou** (use `array_values()` para ter uma lista). |

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

Passe o próprio Future ao `await()`. `await(FileFuture::read($path)->getValue())`
passa uma string e falha com um `TypeError`.

### CacheFuture

O `SfphpProject\src\Async\Adapters\CacheFuture` envolve uma operação de cache. O
driver é o cache do framework: o helper `cache()`, um `CacheManager` ou qualquer
driver `SfphpProject\src\Cache\Cache`. São usados o `put()` e o `forget()` dele.
Um objeto que tem `set()`/`delete()` no lugar (no estilo PSR-16) também
funciona.

| Fábrica | Faz |
|---|---|
| `CacheFuture::get($key, $cache)` | Lê (resolve com `null` quando não existe). |
| `CacheFuture::set($key, $value, $ttl = 3600, $cache)` | Armazena. Um TTL de `0` ou menos significa sem expiração. Resolve com `true`. |
| `CacheFuture::delete($key, $cache)` | Remove. Resolve com `true`. |
| `CacheFuture::has($key, $cache)` | `bool`. |
| `CacheFuture::increment($key, $by = 1, $cache)` | O novo valor, de forma atômica. |
| `CacheFuture::decrement($key, $by = 1, $cache)` | O novo valor. Drivers sem `decrement()` recebem `increment(-$by)`. |

```php
<?php

use SfphpProject\src\Async\Adapters\CacheFuture;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

use function SfphpProject\src\Async\await;

$cache = new CacheManager(new MemoryDriver()); // Em uma aplicação: cache()

await(CacheFuture::set('greeting', 'hello', 60, $cache));
echo await(CacheFuture::get('greeting', $cache)), PHP_EOL;   // hello
echo await(CacheFuture::increment('visits', 1, $cache)), PHP_EOL;
await(CacheFuture::delete('greeting', $cache));
```

Como cada operação bloqueia, a chamada síncrona é mais curta e faz a mesma
coisa: `cache()->put('greeting', 'hello', 60)`. O adaptador é útil quando uma
API recebe um `Future`.

### CacheInvalidator

O `SfphpProject\src\Async\CacheInvalidator` remove uma chave junto com as chaves
registradas como dependentes dela. Ele não é assíncrono. Ele mora aqui porque
pode acompanhar um `ReactiveState`.

```php
<?php

use SfphpProject\src\Async\CacheInvalidator;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Cache\MemoryDriver;

$cache = new CacheManager(new MemoryDriver()); // Em uma aplicação: cache()

$invalidator = (new CacheInvalidator($cache))
    ->registerDependency('user:1', ['user:1:profile', 'user:1:posts'])
    ->registerDependency('user:1:posts', ['feed:home']);

$invalidator->invalidate('user:1');
// Esquece user:1, user:1:profile, user:1:posts e feed:home.
```

- As dependências são seguidas de forma transitiva, e cada chave é visitada uma
  vez, então um ciclo (`a` depende de `b` e `b` de `a`) é seguro.
- `invalidateMany([...])` invalida várias chaves.
- `invalidateByPattern('user:1:*')` chama o `deleteByPattern()` do cache, se ele
  tiver um. Os drivers do framework não têm, então ele recai em invalidar as
  *fontes registradas* cujos nomes casam (`*` casa com quaisquer caracteres).
  Chaves que nunca foram registradas não são encontradas assim.
- `invalidateOnStateChange($state, $keys)` invalida `$keys` toda vez que o
  `ReactiveState` muda.
- `createUserInvalidationPattern($id)` e
  `createResourceInvalidationPattern($type, $id)` devolvem listas de chaves
  convencionais (`user:1`, `user:1:profile`, …) para passar ao
  `invalidateMany()`.

### ComponentFuture

O `SfphpProject\src\Async\ComponentFuture` roda um callable de renderização
quando o seu valor é lido, tentando de novo em caso de falha:

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

- As novas tentativas esperam `setRetryDelay($ms)` milissegundos (100 por
  padrão) com `usleep()`, que bloqueia o processo inteiro. Mantenha as
  tentativas poucas e curtas.
- `getRetryCount()` diz quantas novas tentativas foram usadas.
- `withFallbacks($component, $loadingFallback, $errorFallback, $maxRetries)`:
  quando o componente ainda falha depois das novas tentativas,
  `$errorFallback($e)` fornece o resultado. O `$loadingFallback` é aceito e
  nunca chamado, porque uma renderização no servidor não tem momento de
  carregamento.
- A sintaxe `(new ComponentFuture(...))->getValue()` precisa dos parênteses no
  PHP 8.1 a 8.3.

### StreamFuture

O `SfphpProject\src\Async\StreamFuture` roda um pipeline sobre um iterável (um
array, um generator ou um callable que devolve um deles), bloco por bloco:

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

- `pipe(callable $stage)` acrescenta um estágio que recebe um bloco (um array) e
  devolve um array. `filter()` e `map()` são estágios construídos sobre ele.
- O pipeline roda quando o valor é lido, uma vez. O resultado é cada item
  processado em uma única lista.
- `reduce($fn, $initial)` é terminal. Ele dobra cada item pelo pipeline montado
  até ali e devolve o valor diretamente, não um Future. Ele não altera o
  pipeline, então o `getValue()` continua funcionando depois.
- `getProcessedCount()` conta os itens que saíram do pipeline.

**Memória.** A fonte é lida um bloco por vez. O `reduce()` segura apenas um
bloco e o valor acumulado. O `getValue()` junta cada item processado no seu
resultado, então precisa de memória para a saída inteira.

Três fábricas:

- `StreamFuture::fromCsv($path, $chunkSize)`: um array por linha do CSV, lido
  linha a linha. Lança `RuntimeException` quando o arquivo não pode ser aberto.
- `StreamFuture::fromJsonLines($path, $chunkSize)`: um valor decodificado por
  linha. Linhas que não são JSON são puladas.
- `StreamFuture::fromQuery($query, $chunkSize)`: linhas de uma consulta de model
  ou do query builder. As consultas do SFPHP não têm cursor, então todas as
  linhas são buscadas com um único `get()` quando o stream é lido pela primeira
  vez. O tamanho do bloco limita quantas linhas cada estágio trata de uma vez,
  não quantas estão na memória.

```php
<?php

use SfphpProject\app\models\User;
use SfphpProject\src\Async\StreamFuture;

$names = (new StreamFuture(fn () => User::query()->get(), 500))
    ->map(fn (User $user): string => (string) $user->name)
    ->getValue();
```

## ReactiveState

O `SfphpProject\src\Async\ReactiveState` guarda um valor junto com indicadores
de carregamento e de erro, e avisa os listeners quando eles mudam:

```php
<?php

use SfphpProject\src\Async\ReactiveState;
use SfphpProject\src\Http\Http;

$state = new ReactiveState(initialValue: null, ttl: 300);

$state->onChange(function (ReactiveState $s): void {
    echo $s->hasError() ? 'error: ' . $s->getError()->getMessage() : 'value changed', PHP_EOL;
});

$state->updateFromFuture(Http::getAsync('https://api.example.com/users/1'));

$response = $state->getValue();         // O ClientResponse
$view     = $state->toArray();          // value, loading, error, hasError, expired
```

- `updateFromFuture($future)` **aguarda** o Future. Em caso de sucesso, o valor
  é definido. Em caso de falha, a exceção é guardada com `setError()`. O
  carregamento fica `true` enquanto ele espera e já está `false` de novo quando
  os listeners são avisados.
- `setValue($v)` só avisa quando o valor não é idêntico (`!==`) ao atual.
  `setError()`, `reset()` e `setLoading(false)` sempre avisam.
- Os listeners são chamados com o estado. Uma exceção lançada por um listener é
  ignorada.
- `ttl` é em segundos (`0` significa nunca expirar). Ele conta a partir do
  último `setValue()`. Depois que passa, o `getValue()` devolve `null` e o
  `isExpired()` é verdadeiro.
- `addDependency($cacheKey)` e `getDependencies()` registram chaves de cache para
  o seu próprio uso. Para invalidar de fato na mudança, use
  `CacheInvalidator::invalidateOnStateChange()`.
- Um estado vive por um processo PHP, e no PHP-FPM isso quer dizer uma
  requisição. Ele não é compartilhado entre requisições nem com o navegador.

## EventBroadcaster

O `SfphpProject\src\Async\EventBroadcaster` é um hub de publish/subscribe dentro
do processo. Ele é separado do despachante de eventos do framework
(`SfphpProject\src\Events\Dispatcher`) e não compartilha listeners com ele.

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
// Ou, de forma equivalente: $report = $events->broadcastSync('user.created', [...]);

print_r($report['results']);
```

**Entrega.** O `broadcast()` devolve uma Task. Os listeners rodam dentro dela,
um depois do outro, em ordem de prioridade (a mais alta primeiro). Como qualquer
Task, ela roda quando o scheduler ganha a vez, o que acontece no próximo
`await()`. O `broadcastSync()` a aguarda por você e devolve o relatório. O
relatório é:

```
[
    'event'           => 'user.created',
    'listeners_count' => 2,
    'results'         => [listenerId => return value, ...],
    'errors'          => [listenerId => exception message, ...],
    'duration_ms'     => 0.05,
]
```

Um listener que lança exceção não interrompe os outros. A mensagem dele vai para
`errors`. Os listeners recebem `($event, $payload)`.

**Curingas.** O `*` em uma inscrição representa um ou mais caracteres, pontos
incluídos:

| Padrão | Recebe | Não recebe |
|---|---|---|
| `user.created` | `user.created` | qualquer outra coisa |
| `user.*` | `user.created`, `user.profile.updated` | `user`, `users.created` |
| `*.created` | `user.created`, `order.created` | `created` |
| `order.*.shipped` | `order.42.shipped` | `order.shipped` |

**Gerenciando listeners.**

- `subscribe()` devolve um id de listener. `unsubscribe($event, $id)` remove esse
  listener, e `unsubscribeAll($event)` remove todos os listeners daquele padrão
  exato.
- `getListenerCount($event)` conta os listeners que um evento alcançaria,
  curingas incluídos. Sem argumento, conta todos os listeners.
- `getEvents()` lista os padrões inscritos, e `clear()` remove todos os
  listeners.
- `enableHistory(true, $limit)` registra cada broadcast (`event`, `payload`,
  `timestamp`), mantendo os últimos `$limit`. Leia com `getHistory()` e esvazie
  com `clearHistory()`.
- `scope('billing')` devolve um broadcaster que compartilha esses listeners e
  esse histórico e coloca `billing.` na frente de todo nome de evento que
  recebe. O `clear()` em um escopo remove só os listeners daquele escopo.

```php
<?php

use SfphpProject\src\Async\EventBroadcaster;

$events = new EventBroadcaster();
$billing = $events->scope('billing');

$billing->subscribe('paid', fn (string $event): string => 'heard ' . $event);

$report = $events->broadcastSync('billing.paid');   // Alcança o listener
echo $report['listeners_count'], PHP_EOL;            // 1
```

Tudo vive na memória de um processo: nada é enviado a outras requisições,
workers, servidores ou navegadores. Para alcançar navegadores, use server-sent
events ([STREAMING.md](STREAMING.md)). Para trabalho que precisa sobreviver à
requisição, use a fila.

## WebSocketFuture (experimental)

O `SfphpProject\src\Async\WebSocketFuture` é **experimental e não é um cliente
WebSocket funcional.** Não construa funcionalidades sobre ele.

O que ele faz:

- Abre uma conexão TCP simples com o host e a porta da URL (80 quando a URL não
  tem porta). Ele conclui quando a conexão está aberta ou falhou.
- `send($text)` escreve um frame de texto mascarado. `queueMessage()` e
  `flushQueue()` agrupam envios. `disconnect()` fecha o socket.

O que ele não faz:

- **Não faz o handshake de abertura.** Ele nunca envia a requisição HTTP
  `Upgrade: websocket`, então um servidor WebSocket de verdade fecha a conexão.
  Os headers passados ao construtor são guardados e nunca enviados.
- **Não tem TLS.** Uma URL `wss://` é recusada: o `connect()` devolve `false` e
  o Future é rejeitado.
- **Não recebe.** Nada lê o socket, então os callbacks de `onMessage()` nunca
  são chamados. Não há frames de ping, pong nem close.
- **Não está no event loop.** Conectar e escrever bloqueiam o processo.

```php
<?php

use SfphpProject\src\Async\WebSocketFuture;

$socket = WebSocketFuture::open('wss://echo.example.com/socket');

var_dump($socket->isConnected());               // bool(false)
echo $socket->getException()->getMessage(), PHP_EOL;
```

O `WebSocketFuture::open($url)` constrói e conecta. O `connect()` é o método de
instância e devolve `false` em caso de falha em vez de lançar exceção. Para
atualizações em tempo real no navegador, use server-sent events e o `@stream`
do SFJS ([STREAMING.md](STREAMING.md)).

## Por baixo dos panos

Três classes fazem o trabalho. Raramente você precisa delas diretamente.

**`EventLoop`** guarda tudo o que o processo pode esperar: transferências cURL
(em um único multi handle), temporizadores e observadores de stream
(`addWatcher()`, um ponto de extensão para backends que expõem um socket, ainda
não usado pelo framework). O `tick()` é o único lugar em que o processo espera,
e ele espera por todos de uma vez, por no máximo 50 ms a cada vez.

**`Scheduler`** mantém as Tasks *prontas*, que ele roda em sequência, e as Tasks
*em espera*, que estão estacionadas em um Future. Uma Task em espera não é
tocada até o seu Future concluir, e então volta para as prontas. Quando nada
está pronto, o scheduler deixa o loop esperar. O `stats()` devolve
`['ready' => …, 'waiting' => …, 'loop' => ['transfers' => …, 'timers' => …, 'watchers' => …]]`,
o que é útil para conferir que nada ficou pendente no fim de uma requisição.

**`Context`** é uma pilha de schedulers. O `Context::scheduler()` devolve o do
topo e cria o scheduler raiz quando a pilha está vazia. O EnableAsync e o
`syncRun()` empilham e desempilham os seus. O `Context::getScheduler()` lança
`AsyncException` ("No active Scheduler") em vez de criar um, e o
`Context::hasScheduler()` pergunta sem efeitos colaterais.

```php
<?php

use SfphpProject\src\Async\Context;

use function SfphpProject\src\Async\{await, delay};

await(delay(10));

print_r(Context::scheduler()->stats());
```

## Números medidos

Estes números vêm do `benchmarks/async.php`, rodado contra o servidor de origem
que acompanha o projeto, que responde cada requisição depois de 300 ms a partir
de um único processo não bloqueante:

```
php benchmarks/origin.php 127.0.0.1:8300 &
php benchmarks/async.php
```

Uma execução no PHP 8.4.24, Linux 6.12, um Intel Core i7-12650H de 16 threads e
curl 8.14.1:

| Caso | Tempo |
|---|---|
| 1 requisição | 301,3 ms |
| 3 requisições, cada uma aguardada antes de a próxima começar | 903,6 ms |
| 3 requisições, todas em andamento | 303,1 ms |
| 10 requisições, todas em andamento | 302,2 ms |
| 50 requisições, todas em andamento | 303,8 ms |
| 10 requisições por Tasks `async()` | 301,2 ms |
| Custo de `async()` + `await()` sem I/O | 5,6 µs por Task |
| Pico de memória da execução inteira | 2,00 MB |

Números de uma execução em uma máquina descrevem aquela máquina. Rode o
benchmark no seu próprio hardware antes de confiar neles. O
`benchmarks/database.php` mede o lado das consultas contra um servidor MySQL que
você indicar (veja o cabeçalho dele). Ele mostra que consultas aguardadas juntas
custam mais ou menos o mesmo que as mesmas consultas rodadas uma depois da
outra.

## Erros comuns

| Sintoma | Causa | Correção |
|---|---|---|
| `Call to undefined function async()` | A função não foi importada. | `use function SfphpProject\src\Async\{async, await, delay, awaitAll};` |
| `AsyncException: This operation has not finished…` | `getValue()` em um Future que não concluiu. | `await($future)`. |
| `TypeError` vindo do `await()` | Foi passado um valor em vez de um Future, como `await($f->getValue())`. | `await($f)`. |
| `await(async(...))` devolve um Future | O callable devolveu um Future sem aguardá-lo. | `async(fn () => await(...))`, ou aguarde o Future diretamente. |
| Três consultas "paralelas" levam o triplo do tempo | Consultas bloqueiam (PDO). | Esperado. Só HTTP e temporizadores se sobrepõem. |
| Uma requisição não se sobrepôs a outro trabalho | Trabalho bloqueante rodou entre criar o Future e aguardá-lo. | Crie todas as requisições primeiro, e depois aguarde-as juntas. |
| `ClientException: … No host part in the URL` | URL relativa passada a `Http::*Async`. | Use uma URL absoluta. |
| Um timeout não interrompeu a operação | O Future não é `Cancellable` (consulta, arquivo, cache, componente). | Só HTTP, temporizadores, Tasks e compostos respeitam timeouts. |
| `AsyncException: Deadlock: …` | Uma Task aguarda um Future que nada vai concluir. | Conclua-o, ou não o aguarde. |
| Uma Task nunca rodou | Nada aguardou coisa alguma depois que ela foi criada. | Aguarde-a, ou aguarde outra coisa. |

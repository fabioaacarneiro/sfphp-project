# SFPHP Async Phase 3 - Auto-Setup

## Overview

**Phase 3** eliminates the boilerplate Scheduler setup. Com dois componentes simples, controllers e componentes podem usar `await()` sem configuração manual.

---

## 1. EnableAsync Middleware

O middleware `EnableAsync` automaticamente cria e gerencia o Scheduler para cada requisição.

### Instalação

Adicione ao seu router setup (ex: `public/server.php`):

```php
use SfphpProject\src\Http\Middleware\EnableAsync;

$router = (new Router($container))
    ->middleware(new EnableAsync())  // ← Adicione isto!
    ->middleware(new SecurityHeaders())
    ->middleware(new SetLocale(...))
    ->middleware(new VerifyCsrfToken());
```

### Resultado

Seus controllers agora podem usar `await()` diretamente, sem boilerplate:

```php
// Before (Phase 2)
public function show($id) {
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);
    try {
        $user = await(User::query()->findAsync($id));
        return Response::view('user', ['user' => $user]);
    } finally {
        Context::popScheduler();
    }
}

// After (Phase 3)
public function show($id) {
    $user = await(User::query()->findAsync($id));
    return Response::view('user', ['user' => $user]);
}
```

---

## 2. AsyncAware Trait

Para classes que precisam de contexto async (services, repositories, models), use o trait `AsyncAware`:

```php
namespace App\Services;

use SfphpProject\src\Async\AsyncAware;
use function SfphpProject\src\Async\await;

class UserService
{
    use AsyncAware;

    public function getUserWithPosts($id)
    {
        // Se já estamos em contexto async:
        if ($this->isAsyncEnabled()) {
            return await(User::query()->findAsync($id));
        }

        // Senão, criar contexto async temporário:
        return $this->withAsync(function () use ($id) {
            return await(User::query()->findAsync($id));
        });
    }
}
```

### Métodos Disponíveis

- **`withAsync(callable)`** - Executa callback em contexto async
- **`isAsyncEnabled()`** - Verifica se async está ativo
- **`getScheduler()`** - Obtém Scheduler ativo

---

## 3. Componentes com Async

Componentes podem usar async naturalmente quando renderizados dentro de um contexto async:

```php
// app/components/UserProfile.phpx
function UserProfile($userId)
{
    // Chamado dentro de contexto async (via middleware)
    $user = await(User::query()->findAsync($userId));
    $posts = await(Post::query()->where('user_id', $userId)->getAsync());

    return fsht('
        <article class="profile">
            <h1>{{ $user->name }}</h1>
            @foreach ($posts as $post)
                <PostCard :post="$post" />
            @endforeach
        </article>
    ');
}
```

---

## 4. Exemplos Práticos

### Simple Controller

```php
class ProductController
{
    public function show($id)
    {
        $product = await(Product::query()->findAsync($id));
        $reviews = await(Review::query()->where('product_id', $id)->getAsync());

        return Response::view('product.show', compact('product', 'reviews'));
    }
}
```

### Dashboard com Dados Paralelos

```php
class DashboardController
{
    public function index($userId)
    {
        [$user, $stats, $recent, $notifications] = await(
            Future::all(
                async(fn () => User::query()->findAsync($userId)),
                async(fn () => $this->getStats($userId)),
                async(fn () => Activity::query()->where('user_id', $userId)->limit(10)->getAsync()),
                async(fn () => Notification::query()->where('user_id', $userId)->unread()->getAsync()),
            )
        );

        return Response::view('dashboard', compact('user', 'stats', 'recent', 'notifications'));
    }

    private function getStats($userId)
    {
        return (object)[
            'posts' => await(Post::query()->where('user_id', $userId)->countAsync()),
            'followers' => await(Follower::query()->where('user_id', $userId)->countAsync()),
        ];
    }
}
```

### API com JSON

```php
class ApiController
{
    public function getUser($id)
    {
        try {
            $user = await(User::query()->findAsync($id));

            if (!$user) {
                return Response::json(['error' => 'Not found'], 404);
            }

            return Response::json([
                'id' => $user->id,
                'name' => $user->name,
                'posts_count' => await(
                    Post::query()->where('user_id', $id)->countAsync()
                ),
            ]);
        } catch (\Exception $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }
    }
}
```

### Service com AsyncAware

```php
namespace App\Services;

use SfphpProject\src\Async\AsyncAware;
use function SfphpProject\src\Async\await;

class UserRepository
{
    use AsyncAware;

    public function findWithDetails($id)
    {
        return $this->withAsync(function () use ($id) {
            $user = await(User::query()->findAsync($id));
            $user->posts = await(
                Post::query()->where('user_id', $id)->getAsync()
            );
            return $user;
        });
    }
}
```

---

## 5. Como Funciona Internamente

### Fluxo de Requisição

```
Request → EnableAsync Middleware
           ├─ Create Scheduler
           ├─ Push to Context
           │
           ├─ Controller/Component executa
           │  └─ pode usar await()
           │
           ├─ Response é gerada
           │
           └─ Pop Scheduler do Context
           
Response ← Enviada ao client
```

### Cleanupamento

O middleware garante que o Scheduler é limpado mesmo se houver exceções:

```php
try {
    // Seu código...
    $response = $next($request);
} finally {
    // Sempre executado, mesmo com exceções
    Context::popScheduler();
}
```

---

## 6. Tratamento de Erros

### Exceções em Async

Continuam funcionando normalmente:

```php
public function show($id)
{
    try {
        $user = await(User::query()->findAsync($id));
        return Response::view('user', ['user' => $user]);
    } catch (\Exception $e) {
        return Response::json(['error' => $e->getMessage()], 500);
    }
}
```

### Timeout (Phase 3+)

```php
use SfphpProject\src\Async\TimeoutException;

public function search($query)
{
    try {
        $results = await(
            SearchService::query($query),
            timeout: 5000  // 5 seconds
        );
        return Response::json($results);
    } catch (TimeoutException $e) {
        return Response::json(['error' => 'Search timeout'], 504);
    }
}
```

---

## 7. Comparação: Antes vs Depois

### Phase 1 - Manual Setup

```php
public function show($id) {
    $scheduler = new Scheduler();
    Context::pushScheduler($scheduler);
    
    try {
        $user = await(User::query()->findAsync($id));
        return Response::view('user', ['user' => $user]);
    } finally {
        Context::popScheduler();
    }
}
```
❌ Mucho boilerplate

### Phase 2 - QueryBuilder Support

```php
public function show($id) {
    // Ainda precisa setup manual
    $user = await(User::query()->findAsync($id));
    return Response::view('user', ['user' => $user]);
}
```
⚠️ API limpa, mas setup manual

### Phase 3 - Auto-Setup

```php
public function show($id) {
    $user = await(User::query()->findAsync($id));
    return Response::view('user', ['user' => $user]);
}
```
✅ Sem boilerplate, automático

---

## 8. Best Practices

### ✅ DO

```php
// Use async para operações concorrentes
[$user, $posts, $comments] = await(
    Future::all(
        async(fn () => User::query()->findAsync($id)),
        async(fn () => Post::query()->where('user_id', $id)->getAsync()),
        async(fn () => Comment::query()->where('user_id', $id)->getAsync()),
    )
);
```

### ❌ DON'T

```php
// Não crie Scheduler manual (ja existe via middleware)
$scheduler = new Scheduler();
Context::pushScheduler($scheduler);
// ...
Context::popScheduler();

// Não use await() fora de contexto async
// (sem middleware, vai falhar)
$user = await(User::query()->findAsync($id));
```

---

## 9. Progressão de Fases

| Phase | Feature | Setup | Boilerplate |
|-------|---------|-------|-------------|
| 1 | Core async/await | Manual | Alto |
| 2 | QueryBuilder support | Manual | Médio |
| 3 | Auto Scheduler | Middleware | Mínimo ✅ |
| 4 | True non-blocking I/O | Automatic | Nenhum |

---

## 10. Próximos Passos

### Phase 4 (Futura)
- [ ] True non-blocking I/O
- [ ] Socket support
- [ ] HTTP client async
- [ ] Cache async
- [ ] Advanced timeout handling

### Evoluindo

A API permanece a mesma em todas as fases. Só melhora o que está por baixo:

```php
// Funciona em todas as fases
$user = await(User::query()->findAsync($id));

// A diferença é como o Scheduler é gerenciado internamente
```

---

## Checklist de Migração (Phase 2 → Phase 3)

- [ ] Adicionar `EnableAsync` middleware no router
- [ ] Remover boilerplate `Scheduler` / `Context` manual
- [ ] Controllers podem usar `await()` diretamente
- [ ] Componentes podem usar `await()` diretamente
- [ ] Testar com dados reais

---

## Ver Também

- [ASYNC_USAGE.md](./ASYNC_USAGE.md) - Guia completo
- [example-simple-async.php](../example-simple-async.php) - Exemplos de controllers
- [ASYNC_SUMMARY.md](../ASYNC_SUMMARY.md) - Visão geral do projeto

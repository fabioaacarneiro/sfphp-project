# SFPHP - Simple Framework PHP

O SFPHP é um framework PHP projetado para fornecer uma estrutura básica para o desenvolvimento de aplicações web, eliminando a necessidade de recriar funcionalidades do zero. Com um design simples e altamente personalizável, ele permite que os desenvolvedores adaptem o framework conforme suas necessidades específicas.

## Requisitos

- PHP 8.1 ou superior
- Composer 2
- Extensões `pdo` e `json` (o driver PDO correspondente ao seu banco, se for usar banco)

## Instalação

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
```

Depois abra o `.env` e ajuste as variáveis. Duas observações importantes:

- **`JWT_KEY` precisa ser trocada.** O valor de exemplo é rejeitado, e a chave
  deve ter no mínimo 32 bytes. Para gerar uma:

  ```bash
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```

- **Configurar banco é opcional.** A conexão só é aberta quando um controller ou
  model pede o PDO, então páginas que não usam banco funcionam sem `DB_*`.

## Executando

### Servidor embutido do PHP (desenvolvimento)

```bash
php -S localhost:8000 -t public server.php
```

O `server.php` é necessário: o servidor embutido procura um arquivo de índice
dentro do diretório pedido, então sem ele qualquer rota além de `/` retorna 404
sem chegar ao front controller.

### Apache

O `DocumentRoot` deve apontar para o diretório `public/`, e o diretório precisa
de `AllowOverride All` para que o `public/.htaccess` seja aplicado:

```apache
<VirtualHost *:80>
    ServerName sfphp.local
    DocumentRoot /caminho/para/sfphp-project/public

    <Directory /caminho/para/sfphp-project/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Nginx

```nginx
server {
    listen 80;
    server_name sfphp.local;
    root /caminho/para/sfphp-project/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $realpath_root;
    }

    location ~ /\. {
        deny all;
    }
}
```

Em qualquer um dos três, o `DocumentRoot`/`root` aponta para `public/` — nunca
para a raiz do projeto, para que `.env`, `src/` e `app/` fiquem fora do alcance
do navegador.

## Qualidade

O projeto possui verificações nativas, sem dependências de desenvolvimento:

```bash
composer run lint
composer run test
```

Esses comandos também são executados em push e pull request pelo workflow do
GitHub Actions.

## CLI e migrations

O projeto agora inclui um binário PHP simples na raiz do repositório:

```bash
./sfphp help
```

Os comandos iniciais são:

```bash
./sfphp make:migration create_users_table
./sfphp migrate
./sfphp rollback
./sfphp status
```

As migrations ficam em `database/migrations/` e são geradas como arquivos PHP
que retornam uma instância anônima de `Migration`. O stub já vem com `up()` e
`down()` e um exemplo de uso do schema builder:

```php
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

O objetivo desta primeira etapa é deixar o fluxo operacional: criar migration,
aplicar, desfazer e inspecionar o estado. A camada de schema vai evoluir em
seguida com mais tipos e operações.

## Benefícios do Uso

Ao optar pelo SFPHP, você estará utilizando um framework que valoriza o aprendizado do PHP puro, exigindo conhecimento em SQL e promovendo a compreensão de como as funcionalidades básicas operam. Ele oferece a flexibilidade necessária para que o desenvolvedor implemente suas próprias soluções, sem as restrições de frameworks mais pesados e complexos.

## Segurança

SFPHP oferece recursos nativos para geração de JWT, pré sanitização das *super globais* **$_GET** e **$_POST**, ainda assim, você é livre para implementar medidas mais seguras e necessárias baseando em suas necessidades.

- Sistema nativo de CSRF para formulários e requests mutantes:
```php
<form method="post" action="/users">
    <?= csrf_field() ?>

    <input type="text" name="name">
    <button type="submit">Salvar</button>
</form>
```

`csrf_field()` emite um `input` oculto com o token da sessão, `csrf_meta()`
expõe o mesmo token para JavaScript e `csrf_verify()` valida o request atual
contra a sessão. O token é criado automaticamente na inicialização da
aplicação e fica disponível em qualquer view.

## Público-alvo

O SFPHP é ideal para desenvolvedores que buscam evitar frameworks pesados e repletos de recursos desnecessários. É recomendado para aqueles que estão aprendendo PHP e desejam evoluir seu conhecimento através de um framework que é fácil de entender e que respeita a simplicidade do PHP puro.

## Porque saber php puro é importante para o desenvolvedor PHP?

O SFPHP é desenvolvido com a crença de que o conhecimento do PHP puro é fundamental para a formação de um desenvolvedor competente. Isso porque, ao dominar a linguagem pura, o desenvolvedor:

- Pode escrever códigos mais eficientes e otimizados
- Entende melhor como os frameworks e bibliotecas funcionam
- Aprende mais rapidamente novas linguagens e tecnologias
- Tem uma visão mais clara do que está acontecendo por baixo dos panos
- Pode manter o código mais legível e organizado
- Pode personalizar o SFPHP para se adequar a sua necessidade


Com o SFPHP, o desenvolvedor pode ter a liberdade de criar seu próprio estilo de codificação, sem as restrições de um framework mais pesado e complexo, mas ainda recomendamos estar alinhado a PSR-PHP Standards Recommendations, isso eleva a qualidade do seu projeto como um todo, e apesar de não usarmos bibliotecas de terceiros na construção do SFPHP, você pode se sentir livre para instalar quantas e quaisquer que dejesar, porém, recomendamos usar os recursos nativos, e buscar por bibliotecas de terceiros que implementem recursos não existentes no SFPHP, e não nos responsabilizamos por conflitos e mal funcionamento caso seu projeto vier a parar de funcionar após a instalação de alguma biblioteca.

## Algumas caracteristicas do SFPHP:
 - Sistema nativo de Router
 - Sistema de definição de rotas por funções que devem ser definidos no arquivo `src/routes.php`:
 ```php
  <?php

use SfphpProject\src\Router;

Router::get("/", "MainController", "index");
Router::get("/hello/name:alpha", "MainController", "hello");
Router::get("/users", "UserController", "getAll");
Router::get("/users/id:number", "UserController", "getUserById");
Router::post("/users", "UserController", "createUser");
Router::post("/users/login", "UserController", "login");
Router::head("/health", "HealthController", "head");
Router::options("/users", "UserController", "options");
  ```

- Rotas nomeadas, grupos e geração de URLs:
```php
use SfphpProject\src\Router;

Router::get("/login", "AuthController", "login")
    ->name("login");

Router::get("/users/id:number/posts/slug:alpha", "PostController", "show")
    ->name("posts.show");

Router::group("/admin", function (): void {
    Router::get("/users/id:number", "AdminUserController", "show")
        ->name("show");
}, namePrefix: "admin.");

$loginUrl = Router::url("login");
$postUrl = Router::url(
    "posts.show",
    ["id" => 42, "slug" => "welcome"],
    ["page" => 2]
);
// /users/42/posts/welcome?page=2
```

`Router::url()` sempre retorna uma string; em uma view, faça escape da URL
antes de renderizá-la:

```php
<a href="<?= htmlspecialchars(
    Router::url("posts.show", ["id" => $post["id"], "slug" => $post["slug"]]),
    ENT_QUOTES,
    "UTF-8"
) ?>">Ler postagem</a>
```

Os parâmetros usam o formato `nome:tipo` e podem ser `number`, `alpha` ou
`alphanum`. Rotas nomeadas rejeitam nomes duplicados e valores ausentes,
extras ou inválidos. Quando uma URL existe mas o método HTTP não é aceito, o
roteador responde `405` com o header `Allow`; `OPTIONS` sem rota explícita
responde `204` com os métodos permitidos.

- Query builder nativo e SQL bruto com parâmetros vinculados. O builder atende
  consultas comuns e mantém PDO acessível para SQL específico do banco:
```php
use SfphpProject\src\Database as DB;

$users = DB::table('users')
    ->select('id', 'name', 'email')
    ->where('status', 'active')
    ->whereNull('deleted_at')
    ->orderBy('name')
    ->limit(20)
    ->get();

$user = DB::table('users')
    ->where('email', $email)
    ->first();

$newUserId = DB::table('users')->insert([
    'name' => 'Ada Lovelace',
    'email' => 'ada@example.com',
]);

$total = DB::query(
    'SELECT COUNT(*) FROM users WHERE created_at >= :date',
    ['date' => $date]
)->scalar();
```

Os valores são sempre vinculados ao PDO; nunca os concatene na consulta SQL.
O builder valida nomes de tabelas e colunas, operadores, direções de ordenação
e tipos de `JOIN`. Ele gera SQL portável para CRUD e adapta paginação aos
dialetos MySQL, PostgreSQL, SQLite, SQL Server, Oracle, Firebird e DBLIB.

- Classes auxiliares para segurança e qualidade:
```php
<?= csrf_meta() ?>
<?= e($user['name']) ?>
```
Para qualquer outro driver PDO, use `DB_DSN` no `.env`; CRUD sem paginação usa
SQL ANSI e identificadores com aspas padrão. Consultas específicas de dialeto,
como funções proprietárias ou paginação DBLIB com offset, devem usar
`DB::query()` com bindings.

- Sistema nativo de view simples renderizando páginas:
```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\src\View;

class MainController extends BaseController
{
    public function index()
    {
        $data = [
            "title" => "Home",
            "description" => "Welcome to the home page"
        ];

        View::render("home", $data);
    }

    public function hello($name)
    {
        $data = [
            "title" => "Hello",
            "name" => $name,
        ];

        View::render("hello", $data);
    }
}
```

- Sistema nativo e simplificado para composição de páginas com inclusão de *partials*:
```php
<?php

use SfphpProject\src\View;
?>

<?php View::partial("header"); ?>

<?php View::partial("content"); ?>

<?php View::partial("footer"); ?>
```

Use `e()` para dados dinâmicos em HTML e `asset()` para URLs absolutas de
arquivos públicos:

```php
<a href="<?= e(Router::url('login')) ?>">Entrar</a>
<img src="<?= e(asset('images/logo_32.png')) ?>" alt="Logo">
```

- Sistema nativo para trabalhar com JWT:
*gerando:*
```php
public function login()
    {
        $request = $this->getJsonRequest();

        $user = User::login(
            $request['email'] ?? '',
            $request['password'] ?? ''
        );

        if (!$user) {
            return $this->responseJSON(
                ['message' => 'Login failed'],
                HTTP_UNAUTHORIZED
            );
        }

        $token = JWT::generate($user);

        return $this->responseJSON(
            [
                'message' => 'Login successful',
                'token' => $token
            ],
            HTTP_OK
        );
    }
```
*validando:*
```php
public function getUserById(int $id)
{
    $token = $this->getBearerToken();

    // getBearerToken() devolve null quando não há header Authorization,
    // e JWT::validate() espera string. Sem essa checagem, uma requisição
    // sem token gera TypeError (500) em vez de 401.
    if ($token === null || !JWT::validate($token)) {
        return $this->responseJSON(
            ['message' => 'Unauthorized'],
            HTTP_UNAUTHORIZED
        );
    }

    return $this->responseJSON(
        User::getUserById($id),
        HTTP_OK
    );
}
```

- Sistema nativo de CSRF para formulários e requests mutantes:
```php
<form method="post" action="/users">
    <?= csrf_field() ?>

    <input type="text" name="name">
    <button type="submit">Salvar</button>
</form>
```

`csrf_field()` emite um `input` oculto com o token da sessão, `csrf_meta()`
expõe o mesmo token para JavaScript e `csrf_verify()` valida o request atual
contra a sessão. O token é criado automaticamente na inicialização da
aplicação e fica disponível em qualquer view.

- Coletar JSON da requisição por herança da clase **BaseAPIController**:
```php
<?php

namespace SfphpProject\app\controllers;

use SfphpProject\app\models\User;
use SfphpProject\src\JWT;

class UserController extends BaseAPIController
{
    // restante do código

    public function createUser()
    {
        $data = $this->getJsonRequest();
        // restante do código
    }

    // restante do código
}

```

- Sistema nativo de validação do corpo da requisição com resposta personalizada de erro na validação.

`Validator::validate()` devolve um `ValidationResult`, não um array: você
precisa checar `passes()`/`fails()` antes de acessar os dados. Isso existe
justamente para que uma validação que falhou não possa ser repassada por engano
para o model.

Repare também que o separador entre regras é sempre o pipe (`min:3|alpha`).
Escrever `min:3:alpha` aplicava só o `min` e ignorava o `alpha` em silêncio —
hoje uma regra desconhecida lança `InvalidArgumentException`.

```php
public function createUser()
{
    $data = $this->getJsonRequest();

    $result = \SfphpProject\src\Validator::validate($data, [
        "name" => "required|min:3|alpha",
        "surname" => "required|min:3|alpha",
        "email" => "required|email",
        "nick" => "required|min:3|alphanum",
        "password" => "required|min:8"
    ], [
        "name" => [
            "required" => "Name is required",
            "min" => "Name must be at least 3 characters long",
            "alpha" => "Name must contain only letters"
        ],
        "surname" => [
            "required" => "Surname is required",
            "min" => "Surname must be at least 3 characters long",
            "alpha" => "Surname must contain only letters"
        ],
        "email" => [
            "required" => "Email is required",
            "email" => "Email must be a valid email"
        ],
        "nick" => [
            "required" => "Nick is required",
            "min" => "Nick must be at least 3 characters long",
            "alpha" => "Nick must contain only letters"
        ],
        "password" => [
            "required" => "Password is required",
            "min" => "Password must be at least 8 characters long"
        ]
    ]);

    if ($result->fails()) {
        return $this->responseJSON(
            ['errors' => $result->errors()],
            HTTP_UNPROCESSABLE_ENTITY
        );
    }

    return $this->responseJSON(
        User::createUser($result->validated()),
        HTTP_CREATED
    );
}
```

- Sistema nativo para carregamento do arquivo .env:
```php
Dotenv::loadEnv(__DIR__ . "path_to_.env_file");

// Passe required: false para que a ausência do arquivo não seja um erro.
Dotenv::loadEnv(__DIR__ . "path_to_.env_file", required: false);
```

O `app/config/config.php` já carrega o `.env` da raiz do projeto em toda
requisição, como opcional, então normalmente você não precisa chamar isso.

- Classe base para criação de modelos MVC, pode ser herdados por uma classe base com implementações personalizadas onde suas classes **controllers** podem herdar essa personalizada ou herdar diretamente de **BaseController**:
```php
<?php

namespace SfphpProject\app\controllers;

/**
 * Base controller, other controllers needs extends this
 */
class BaseController {

    public function requestGET(string $key) {
        return filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    }

    public function requestPOST(string $key) {
        return filter_input(INPUT_POST, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    }
}
```

- Classe base para criação de APIs, pode ser herdados por uma classe base com implementações personalizadas onde suas classes **controllers** podem herdar essa personalizada ou herdar diretamente de **BaseAPIController**:
```php
<?php

namespace SfphpProject\app\controllers;

/**
 * Base API controller, other controllers api needs extends this
 */
class BaseAPIController {
    public function getRequest(): string {
        return file_get_contents('php://input');
    }

    // Retorna 400 para JSON inválido e 415 para Content-Type incompatível.
    public function getJsonRequest(): array {
        // ...
    }

    public function getHeader(string $name): ?string {
        // ...
    }

    public function getBearerToken(): ?string {
        // ...
    }

    public function responseJSON(
        array $data = [],
        int $httpCode = HTTP_OK
    ): never {
        // ...
    }
}
```

***SFPHP Ainda está em desenvolvimento, sabemos que criar um projeto do tamanho de um framework, que entregue recursos e funcionalidades que facilitem a produtividade do projeto, que entregue recursos e funcionalidades úteis, criadas do zero apenas com PHP puro, é um trabalho que demanda tempo e dedicação, por isso algumas funcionalidades podem tomar muito tempo para serem implementadas.***

## Autor

O SFPHP foi criado por Fabio Carneiro.

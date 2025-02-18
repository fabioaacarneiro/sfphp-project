# SFPHP - Simple Framework PHP

O SFPHP é um framework PHP projetado para fornecer uma estrutura básica para o desenvolvimento de aplicações web, eliminando a necessidade de recriar funcionalidades do zero. Com um design simples e altamente personalizável, ele permite que os desenvolvedores adaptem o framework conforme suas necessidades específicas.

## Benefícios do Uso

Ao optar pelo SFPHP, você estará utilizando um framework que valoriza o aprendizado do PHP puro, exigindo conhecimento em SQL e promovendo a compreensão de como as funcionalidades básicas operam. Ele oferece a flexibilidade necessária para que o desenvolvedor implemente suas próprias soluções, sem as restrições de frameworks mais pesados e complexos.

## Segurança

SFPHP oferece recursos nativos para geração de JWT, pré sanitização das *super globais* **$_GET** e **$_POST**, ainda assim, você é livre para implementar medidas mais seguras e necessárias baseando em suas necessidades.

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
  ```

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
<?php partial("header"); ?>

<?php partial("content"); ?>

<?php partial("footer"); ?>
```

- Sistema nativo para trabalhar com JWT:
*gerando:*
```php
public function login()
    {
        $request = json_decode($this->getRequest());

        $email = $request->email;
        $password = $request->password;

        $user = User::login($email, $password);

        if (!$user) {
            $this->responseJSON(
                ['message' => 'Login failed'],
                HTTP_NOT_FOUND
            );
        }

        $token = JWT::generate($user);
        $this->responseJSON(
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

    if (!JWT::validate($token)) {
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
        $data = json_decode($this->getRequest(), true);
        // restante do código
    }

    // restante do código
}

```

- Sistema nativo de validação do corpo da requisição com resposta personalizada de erro na validação:
```php
public function createUser()
{
    $data = json_decode($this->getRequest(), true);
    $data = validate($data, [
        "name" => "required|min:3:alpha",
        "surname" => "required|min:3:alpha",
        "email" => "required|email",
        "nick" => "required|min:3:alpha",
        "password" => "required|min:3"
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
            "min" => "Password must be at least 3 characters long"
        ]
    ]);

    $this->responseJSON(
        User::createUser($data),
        HTTP_CREATED
    );
}
```

- Sistema nativo para carregamento do arquivo .env:
```php
Dotenv::loadEnv(__DIR__ . "path_to_.env_file");
```

- Classe base para criação de modelos MVC, pode ser herdados por uma classe base com implementações personalizadas onde suas classes **controllers** podem herdar essa personalizada ou herdar diretamente de **BaseController**:
```php
<?php

namespace SfphpProject\app\controllers;

/**
 * Base controller, other controllers needs extends this
 */
class BaseController {

    public function requestGET(string $key = null) {
        return filter_input(INPUT_GET, $key, FILTER_SANITIZE_SPECIAL_CHARS);
    }

    public function requestPOST(string $key = null) {
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
    public function __construct() {
        header("Content-Type: application/json");
    }

    public function getRequest() {
        return file_get_contents('php://input');
    }

    public function getBearerToken(): ?string {
        $headers = getallheaders();
    
        if (isset($headers['Authorization'])) {
            if (preg_match('/Bearer\s(\S+)/', $headers['Authorization'], $matches)) {
                return $matches[1];
            }
        }
    }

    public function responseJSON($data = [], $httpCode = HTTP_OK) {
        http_response_code($httpCode);
        echo json_encode($data);
    }
}
```

***SFPHP Ainda está em desenvolvimento, sabemos que criar um projeto do tamanho de um framework, que entregue recursos e funcionalidades que facilitem a produtividade do projeto, que entregue recursos e funcionalidades úteis, criadas do zero apenas com PHP puro, é um trabalho que demanda tempo e dedicação, por isso algumas funcionalidades podem tomar muito tempo para serem implementadas.***

## Autor

O SFPHP foi criado por Fabio Carneiro.


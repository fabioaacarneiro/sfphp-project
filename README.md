# SFPHP - Simple Framework PHP

Um framework PHP leve e produtivo que valoriza a simplicidade do PHP puro. Ideal para desenvolvedores que desejam aprender PHP profundamente, sem as restrições de frameworks pesados.

## Requisitos

- PHP 8.1 ou superior
- Composer 2
- PDO (opcional, apenas se usar banco de dados)

## Instalação

```bash
git clone https://github.com/fabioaacarneiro/sfphp-project.git
cd sfphp-project
composer install
cp .env-example .env
```

Ajuste as variáveis no `.env`:

- **`JWT_KEY`**: Gere uma com `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- **Banco de dados**: Opcional. Configure apenas se precisar.

## Executando

### Servidor de Desenvolvimento

```bash
php -S localhost:8000 -t public server.php
```

### Produção

Configure seu servidor web (Apache, Nginx) apontando `DocumentRoot` para `public/`.

## Qualidade

```bash
composer run lint    # PHP linting
composer run test    # Testes
```

## Documentação

Veja a documentação completa em [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md) para:
- CLI e geração de código (20+ comandos)
- SFHT (template engine)
- SFCSS (framework CSS)
- Migrations e Schema Builder
- Roteamento, Controllers, Views
- Query Builder
- Validação, CSRF, JWT

## Segurança

SFPHP fornece proteção nativa:
- CSRF com `csrf_field()` e `csrf_verify()`
- JWT para autenticação de API
- Sanitização automática de `$_GET` e `$_POST`

## Autor

Criado por Fabio Carneiro.

# 🚀 SFPHP — Simple Framework PHP

> **Ama SFPHP?** ⭐ [Dê uma estrela no GitHub](https://github.com/fabioaacarneiro/sfphp-framework) — isso nos ajuda a crescer e mantém o framework prosperando!

> **Leia isso em:** [English](README.md) · [Português](README.pt-BR.md) · [Español](README.es.md)

**O framework PHP para desenvolvedores que se importam com performance, segurança e simplicidade.**

Um framework full-stack, pronto para produção, com **zero dependências em runtime**, construído para velocidade e projetado para entregar. Apenas PHP 8.1+, seu banco de dados e seu código—nada mais é necessário.

---

## Por Que Escolher SFPHP?

### ⚡ **Performance Raio Rápido**
- **Sem bloat de dependências** — apenas a biblioteca padrão do PHP e seu driver de banco
- **Sistema async/await built-in** — 3-5x mais rápido para I/O com Fibers nativos do PHP
- **Queries otimizadas** — detecção automática de N+1, relações eficientes, cache inteligente
- **Overhead mínimo do framework** — seu código roda imediatamente, não enterrado em camadas

### 🔒 **Segurança Built-In**
- **Zero-trust por padrão** — proteção CSRF, SQL binding, XSS escaping em tudo
- **Autenticação battle-tested** — sessões, tokens JWT, políticas, remember-me
- **Validação de requisições** — parâmetros tipados, filtragem de entrada
- **Sem teatro de segurança** — implementamos o que importa, pulamos o cargo-culting

### 🎯 **Experiência do Desenvolvedor**
- **Type-safe everywhere** — atributos PHP 8.1, parâmetros tipados, autocompletar da IDE
- **Geradores de código** — 12 geradores para modelos, migrações, controllers, etc
- **CLI abrangente** — 35 comandos para gerenciar sua aplicação
- **API intuitiva** — aprenda uma vez, funciona igual em tudo

### 🌍 **Verdadeiramente Multilíngue**
- **UTF-8 em primeiro lugar** — funciona perfeitamente com qualquer idioma, sem `mbstring`
- **i18n built-in** — catálogos de idiomas, regras de pluralização, negociação Accept-Language
- **Mensagens do framework em idioma do visitante** — até erros 404 respeitam locale

### 📦 **Tudo Que Você Precisa, Nada a Mais**
- 150+ recursos de segurança built-in
- Migrações e seeders de banco
- Email com SMTP/TLS
- Caching Redis & arquivo
- Filas de trabalho em background
- Gerenciamento de sessão
- Tratamento de upload de arquivos
- Logging & debugging

---

## Começando Rápido

```bash
composer create-project fabioaacarneiro/sfphp-framework meu-app
cd meu-app
./sfphp serve
```

Visite `http://localhost:8000` e comece a codificar! 🚀

---

## Documentação Completa

- **Início Rápido Async** — [5 minutos para o seu primeiro await](ASYNC_QUICK_START.md)
- **Guia Completo** — [Framework inteiro documentado](docs/pt-BR/DOCUMENTATION.md)
- **Sistema Async/Await** — [Async, streams, eventos e real-time](docs/ASYNC_COMPLETE_GUIDE.md)

---

## Licença

MIT — Veja [LICENSE](LICENSE)

**Criado por** Fabio Carneiro

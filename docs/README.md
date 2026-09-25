# SFPHP Documentation

Choose your language. Every version is complete — none of them is a summary of
another.

| Language | Framework | SFCSS | SFCSS utilities | Async | Streaming | PWA | .phpx components |
|---|---|---|---|---|---|---|---|
| 🇬🇧 **English** *(primary)* | [Documentation](en/DOCUMENTATION.md) | [SFCSS](en/SFCSS.md) | [Utilities reference](en/SFCSS_UTILITIES.md) | [Async](en/ASYNC.md) | [Streaming](en/STREAMING.md) | [PWA](en/PWA_GUIDE.md) | [Components](en/PHPX_COMPONENTS.md) |
| 🇧🇷 **Português** | [Documentação](pt-BR/DOCUMENTATION.md) | [SFCSS](pt-BR/SFCSS.md) | [Referência de utilitários](pt-BR/SFCSS_UTILITIES.md) | [Async](pt-BR/ASYNC.md) | [Streaming](pt-BR/STREAMING.md) | [PWA](pt-BR/PWA_GUIDE.md) | [Componentes](pt-BR/PHPX_COMPONENTS.md) |
| 🇪🇸 **Español** | [Documentación](es/DOCUMENTATION.md) | [SFCSS](es/SFCSS.md) | [Referencia de utilidades](es/SFCSS_UTILITIES.md) | [Async](es/ASYNC.md) | [Streaming](es/STREAMING.md) | [PWA](es/PWA_GUIDE.md) | [Componentes](es/PHPX_COMPONENTS.md) |

Every document lives in the folder of its language — `en/`, `pt-BR/`, `es/` —
under the same file name.

English is the repository's primary version, for the same reason the framework
itself defaults to English: a project meant to be used anywhere should not
assume its reader speaks the language its author happens to.

---

## Escolha o idioma

Todas as versões são completas — nenhuma é um resumo de outra.

- 🇬🇧 [English](en/DOCUMENTATION.md) *(versão principal)*
- 🇧🇷 [Português](pt-BR/DOCUMENTATION.md)
- 🇪🇸 [Español](es/DOCUMENTATION.md)

## Elige tu idioma

Todas las versiones están completas — ninguna es un resumen de otra.

- 🇬🇧 [English](en/DOCUMENTATION.md) *(versión principal)*
- 🇧🇷 [Português](pt-BR/DOCUMENTATION.md)
- 🇪🇸 [Español](es/DOCUMENTATION.md)

---

## Keeping the three in step

Any change to the documentation is applied to **all three languages**. A
version that falls behind is worse than one that does not exist, because a
reader cannot tell which pages are current.

Terminology is kept consistent across languages. Code, identifiers, CLI
commands, configuration keys and message keys are never translated — only the
prose around them. `$fillable` is `$fillable` in every version.

Prose cannot be compared mechanically, but structure can, so CI does:

```bash
composer run docs
```

It asserts that every document exists in all three languages, that the three
versions have the same sections, subsections, tables and code blocks, in the
same order and with the same fence languages, and that every relative link and
in-page anchor resolves — in every document and in the three READMEs. A section added to one language and
forgotten in the others fails the build, and so does a link left pointing at a
file that moved.

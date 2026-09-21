# SFPHP Documentation

Choose your language. Every version is complete — none of them is a summary of
another.

| Language | Framework | SFCSS | SFCSS utilities |
|---|---|---|---|
| 🇬🇧 **English** *(primary)* | [Documentation](en/DOCUMENTATION.md) | [SFCSS](en/SFCSS.md) | [Utilities reference](en/SFCSS_UTILITIES.md) |
| 🇧🇷 **Português** | [Documentação](pt-BR/DOCUMENTATION.md) | [SFCSS](pt-BR/SFCSS.md) | [Referência de utilitários](pt-BR/SFCSS_UTILITIES.md) |
| 🇪🇸 **Español** | [Documentación](es/DOCUMENTATION.md) | [SFCSS](es/SFCSS.md) | [Referencia de utilidades](es/SFCSS_UTILITIES.md) |

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

It asserts the three versions have the same sections, subsections, tables and
code blocks, in the same order and with the same fence languages, and that every
relative link and in-page anchor resolves. A section added to one language and
forgotten in the others fails the build, and so does a link left pointing at a
file that moved.

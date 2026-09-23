# 🚀 SFPHP — Simple Framework PHP

> **¿Amas SFPHP?** ⭐ [Dale una estrella en GitHub](https://github.com/fabioaacarneiro/sfphp-framework) — ¡eso nos ayuda a crecer y mantiene el framework próspero!

> **Lee esto en:** [English](README.md) · [Português](README.pt-BR.md) · [Español](README.es.md)

**El framework PHP para desarrolladores que se preocupan por el rendimiento, la seguridad y la simplicidad.**

Un framework full-stack, listo para producción, con **cero dependencias en tiempo de ejecución**, construido para velocidad y diseñado para entregar. Solo PHP 8.1+, tu base de datos y tu código—nada más es necesario.

---

## ¿Por Qué Elegir SFPHP?

### ⚡ **Rendimiento Rayo Rápido**
- **Sin bloat de dependencias** — solo la biblioteca estándar de PHP y tu driver de base de datos
- **Sistema async/await incorporado** — 3-5x más rápido para I/O con Fibers nativos de PHP
- **Consultas optimizadas** — detección automática de N+1, relaciones eficientes, caché inteligente
- **Overhead mínimo del framework** — tu código se ejecuta inmediatamente, no enterrado en capas

### 🔒 **Seguridad Incorporada**
- **Zero-trust por defecto** — protección CSRF, vinculación SQL, escape XSS en todo
- **Autenticación battle-tested** — sesiones, tokens JWT, políticas, remember-me
- **Validación de solicitudes** — parámetros tipados, filtrado de entrada
- **Sin teatro de seguridad** — implementamos lo que importa, saltamos el cargo-culting

### 🎯 **Experiencia del Desarrollador**
- **Type-safe en todas partes** — atributos PHP 8.1, parámetros tipados, autocompletado del IDE
- **Generadores de código** — 12 generadores para modelos, migraciones, controladores, etc
- **CLI integral** — 35 comandos para gestionar tu aplicación
- **API intuitiva** — aprende una vez, funciona igual en todo

### 🌍 **Verdaderamente Multilingüe**
- **UTF-8 en primer lugar** — funciona perfectamente con cualquier idioma, sin `mbstring`
- **i18n incorporado** — catálogos de idiomas, reglas de pluralización, negociación Accept-Language
- **Mensajes del framework en idioma del visitante** — incluso los errores 404 respetan la localización

### 📦 **Todo Lo Que Necesitas, Nada Más**
- 150+ características de seguridad incorporadas
- Migraciones y semillas de base de datos
- Correo electrónico con SMTP/TLS
- Caché de Redis y archivo
- Colas de trabajo en segundo plano
- Gestión de sesiones
- Manejo de carga de archivos
- Registro y depuración

---

## Comenzar Rápido

```bash
composer create-project fabioaacarneiro/sfphp-framework mi-app
cd mi-app
./sfphp serve
```

¡Visita `http://localhost:8000` y comienza a codificar! 🚀

---

## Documentación Completa

- **Guía Rápida Async** — [5 minutos para tu primer await](ASYNC_QUICK_START.md)
- **Documentación Completa** — [Framework completo documentado](docs/es/DOCUMENTATION.md)
- **Sistema Async/Await** — [Async, streams, eventos y real-time](docs/ASYNC_COMPLETE_GUIDE.md)

---

## Licencia

MIT — Ver [LICENSE](LICENSE)

**Creado por** Fabio Carneiro

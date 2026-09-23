# Security policy

## Reporting a vulnerability

Report it privately through GitHub's **Report a vulnerability** button under
[Security](https://github.com/fabioaacarneiro/sfphp-project/security), or by
email to fabioaacarneiro@gmail.com.

Please do not open a public issue for something exploitable. Include what you
did, what happened, and the version — a proof of concept, however rough, is
worth more than a description.

This is a project maintained by one person: expect an acknowledgement rather
than an SLA, and expect the fix to be announced in a release note that credits
you unless you ask otherwise.

## Supported versions

The latest released minor is the one that receives fixes. While the project is
below 1.0, that means a fix lands in a new patch of the current minor and older
minors are not backported.

## What the framework does on your behalf

- **SQL** — every value is bound; identifiers are checked against a whitelist.
  The query builder has no path that concatenates a value into a statement.
- **Output** — `{{ }}` escapes with `ENT_QUOTES | ENT_SUBSTITUTE` in UTF-8. Raw
  output requires `{!! !!}` or a value the framework itself produced.
- **Sessions** — idle and absolute deadlines, strict id validation, and a store
  that can be shared between instances.
- **CSRF** — verified on every state-changing request by the shipped middleware.
- **Uploads** — the type is read from the file's bytes, storage names are
  generated, and a forged `$_FILES` entry is refused.
- **Passwords and tokens** — hashing through PHP's own password API, and
  comparisons that do not leak timing.
- **Outbound HTTP** — a redirect from `https://` to `http://` is refused, so a
  server cannot downgrade a request that carries an Authorization header.

## What it leaves to you

Stated because a framework that implies otherwise is the more dangerous one:

- **A URL that came from a visitor is a request an attacker chose.** The HTTP
  client will fetch whatever your network can reach; validate URLs you did not
  build (SSRF).
- **Content-Security-Policy is not set**, because a policy that does not match
  your assets breaks the page silently. The documentation has a value to start
  from.
- **Trusted proxies are empty by default.** Behind a TLS-terminating balancer,
  set them, or every request appears to come from the balancer and the session
  cookie loses its `secure` flag.
- **`dd()` and `tinker` are development tools.** `tinker` evaluates input with
  `eval()`; never expose the CLI to untrusted input.
- **`Sfht` bypasses escaping by design.** Wrapping a request value in one is a
  decision to trust it.

## Where the detail is

The security chapter of the documentation — in
[English](docs/en/DOCUMENTATION.md#security),
[Portuguese](docs/pt-BR/DOCUMENTATION.md#seguranca) and
[Spanish](docs/es/DOCUMENTATION.md#seguridad) — covers each of these with the
reasoning and the code.

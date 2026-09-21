<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\I18n\Translator;

/**
 * Chooses the locale for the request and puts it on the request.
 *
 * The locale is resolved once, at the edge, rather than being worked out again
 * wherever a message is rendered. Everything downstream reads
 * Translator::locale() or $request->attribute('locale').
 *
 * Setting it per request also matters for a persistent runtime: the translator
 * keeps the active locale in a static, so a worker that handled a Portuguese
 * request would answer the next one in Portuguese unless something reset it.
 * This middleware is that something, which is why it belongs in the global
 * pipeline rather than being called from a controller.
 */
final class SetLocale implements Middleware
{
    /**
     * Create the middleware.
     *
     * @param array<int, string> $available The locales the application offers
     * @param string|null $default Used when the client asks for none of them
     */
    public function __construct(
        private array $available = ['en'],
        private ?string $default = null
    ) {}

    /**
     * Resolve the locale and continue.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        $locale = $request->preferredLanguage(
            $this->available,
            $this->default ?? $this->available[0] ?? 'en'
        );

        Translator::setLocale($locale);

        return $next($request->withAttribute('locale', Translator::locale()))
            ->withHeader('Content-Language', str_replace('_', '-', Translator::locale()));
    }
}

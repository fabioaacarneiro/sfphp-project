<?php

namespace SfphpProject\src\Http;

use SfphpProject\src\Router;

/**
 * An optional base class for controllers that render pages.
 *
 * Optional is the word that matters. The framework asks a controller for an
 * action that returns a Response and nothing else — no interface, no class to
 * extend — and that stays true. This exists because most controllers end up
 * writing the same four lines, not because anything requires it.
 *
 *     final class PostController extends Controller
 *     {
 *         public function index(Request $request): Response
 *         {
 *             return $this->view('posts/index', ['posts' => $posts]);
 *         }
 *     }
 *
 * There used to be a class like this in the example application, and that was
 * the mistake: `app/` is not in the package, so `$this->view()` was a line the
 * documentation taught and a project that installed the framework could not
 * run. Living here, it works in both.
 */
abstract class Controller
{
    /**
     * Render a view as an HTML response.
     *
     * @param string $view The view name
     * @param array<string, mixed> $data Values the view may read
     * @param int $status The HTTP status code
     * @return Response The response
     */
    protected function view(string $view, array $data = [], int $status = HTTP_OK): Response
    {
        return Response::view($view, $data, $status);
    }

    /**
     * Send the visitor somewhere else.
     *
     * @param string $url The destination
     * @param int $status The HTTP status code
     * @return Response The response
     */
    protected function redirect(string $url, int $status = HTTP_FOUND): Response
    {
        return Response::redirect($url, $status);
    }

    /**
     * Send the visitor to a named route.
     *
     * @param string $name The route name
     * @param array<string, string|int> $parameters The route parameters
     * @param array<string, string|int> $query Query string values
     * @return Response The response
     */
    protected function route(string $name, array $parameters = [], array $query = []): Response
    {
        return Response::redirect(Router::url($name, $parameters, $query));
    }

    /**
     * Send the visitor back where they came from.
     *
     * The referer is a header, which means the visitor controls it, which means
     * it is a redirect destination an attacker can choose. Following it to
     * another site would be an open redirect — the classic way a phishing link
     * borrows your domain's good name. Only a path on this site is followed,
     * and anything else falls back.
     *
     * @param Request $request The current request
     * @param string $fallback Where to go when there is no usable referer
     * @return Response The response
     */
    protected function back(Request $request, string $fallback = '/'): Response
    {
        $referer = trim((string) $request->header('Referer'));

        if ($referer === '' || str_starts_with($referer, '//')) {
            // "//evil.example/x" has no scheme and is still another origin to a
            // browser, which is why it is refused before anything is parsed.
            return Response::redirect($fallback);
        }

        $parts = parse_url($referer);

        if ($parts === false) {
            return Response::redirect($fallback);
        }

        $host = $parts['host'] ?? null;

        /*
         * A referer naming another host is not somewhere "back" should go, even
         * though only its path would be used: a visitor arriving from a search
         * engine would be sent to whatever that engine's path happens to spell
         * on this site.
         */
        if ($host !== null && strcasecmp($host, (string) $request->header('Host')) !== 0) {
            return Response::redirect($fallback);
        }

        $path = $parts['path'] ?? '';

        if ($path === '' || !str_starts_with($path, '/')) {
            return Response::redirect($fallback);
        }

        $query = $parts['query'] ?? '';

        return Response::redirect($path . ($query !== '' ? '?' . $query : ''));
    }
}

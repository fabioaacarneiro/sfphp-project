<?php

namespace SfphpProject\src\Http\Middleware;

use SfphpProject\src\Auth\Auth;
use SfphpProject\src\Cache\CacheManager;
use SfphpProject\src\Http\Middleware;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

/**
 * Limits how often the same client may hit a route.
 *
 * This is what makes the login form's other defences worth having. Equalising
 * the time a failed login takes stops an attacker from learning which accounts
 * exist, but it does nothing about simply trying passwords — without a limit,
 * they do not need to enumerate anything.
 *
 *     Router::post('/login', 'AuthController', 'login')
 *         ->middleware(new RateLimit(maxAttempts: 5, decaySeconds: 60));
 *
 * Counters live in the cache, so the limit holds across processes when a
 * shared driver is configured. With the default file driver it holds across
 * requests on one machine, which is already the common case.
 *
 * It counts by client address, and that address is only as trustworthy as the
 * trusted-proxy configuration: behind a load balancer with none declared,
 * every request looks like it comes from the balancer and the whole site
 * shares one bucket. See Request::setTrustedProxies().
 */
final class RateLimit implements Middleware
{
    private CacheManager $cache;

    /**
     * Create the middleware.
     *
     * @param int $maxAttempts How many requests are allowed in the window
     * @param int $decaySeconds How long the window lasts
     * @param string $name A name for the bucket, when several limits share a route
     * @param CacheManager|null $cache The store, or null for the default
     */
    public function __construct(
        private int $maxAttempts = 60,
        private int $decaySeconds = 60,
        private string $name = 'default',
        ?CacheManager $cache = null
    ) {
        $this->cache = $cache ?? new CacheManager();
    }

    /**
     * Count the request and refuse once the limit is reached.
     *
     * @param Request $request The incoming request
     * @param callable(Request): Response $next The rest of the pipeline
     * @return Response The response to send
     */
    public function handle(Request $request, callable $next): Response
    {
        $key = $this->key($request);
        $window = $this->cache->get($key);

        $now = time();

        if (!is_array($window) || ($window['expires'] ?? 0) <= $now) {
            $window = ['hits' => 0, 'expires' => $now + $this->decaySeconds];
        }

        $window['hits']++;
        $remainingSeconds = max(1, $window['expires'] - $now);

        /*
         * The TTL follows the window rather than being reset to the full decay
         * on every hit. Refreshing it would let a client that keeps knocking
         * hold its own bucket open forever, so the window would never close
         * and the counter would never forgive.
         */
        $this->cache->put($key, $window, $remainingSeconds);

        $remaining = max(0, $this->maxAttempts - $window['hits']);

        if ($window['hits'] > $this->maxAttempts) {
            return $this->refuse($request, $remainingSeconds);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    /**
     * Build the cache key identifying this client and route.
     *
     * An authenticated request counts per user, so several people behind one
     * office address do not exhaust each other's allowance.
     *
     * @param Request $request The incoming request
     * @return string The cache key
     */
    private function key(Request $request): string
    {
        $identity = Auth::id() ?? $request->ip() ?? 'unknown';

        return 'ratelimit:' . $this->name . ':' . sha1($request->path . '|' . $identity);
    }

    /**
     * Refuse a request that is over the limit.
     *
     * @param Request $request The incoming request
     * @param int $retryAfter Seconds until the window closes
     * @return Response The refusal
     */
    private function refuse(Request $request, int $retryAfter): Response
    {
        $message = __('http.too_many_requests_message');

        $response = $request->expectsJson()
            ? Response::json(['message' => $message], HTTP_TOO_MANY_REQUESTS)
            : Response::html(
                '<!doctype html><html lang="' . str_replace('_', '-', locale()) . '">'
                . '<head><meta charset="UTF-8"><title>429</title></head>'
                . '<body><h1>429</h1><p>'
                . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</p></body></html>',
                HTTP_TOO_MANY_REQUESTS
            );

        return $response
            ->withHeader('Retry-After', (string) $retryAfter)
            ->withHeader('X-RateLimit-Limit', (string) $this->maxAttempts)
            ->withHeader('X-RateLimit-Remaining', '0');
    }
}

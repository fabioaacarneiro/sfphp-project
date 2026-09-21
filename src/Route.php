<?php

namespace SfphpProject\src;

use InvalidArgumentException;
use LogicException;

/**
 * Represents a route registered in the router.
 */
final class Route
{
    /*
     * "alpha" and "alphanum" match Unicode letters and digits so that routes
     * work outside ASCII: /produtos/cafe-expresso and /produtos/日本語 are both
     * legitimate paths for an application serving a global audience, and the
     * previous [a-zA-Z] classes rejected every one of them.
     *
     * "number" stays ASCII on purpose. Its values are meant to be cast to int
     * by the receiving controller, and PHP's integer cast does not understand
     * Eastern Arabic or Devanagari digits, so accepting them would turn a
     * valid-looking id into a silent zero.
     */
    private const PARAMETER_TYPES = [
        'number' => '[0-9]+',
        'alphanum' => '[\p{L}\p{N}]+',
        'alpha' => '\p{L}+',
    ];

    private const PARAMETER_PATTERN = '/([A-Za-z_][A-Za-z0-9_]*):(number|alphanum|alpha)/';

    private ?string $name = null;
    private array $parameters = [];

    /**
     * Middleware that runs for this route, group middleware first.
     *
     * @var array<int, mixed>
     */
    private array $middleware = [];

    /**
     * Create a route.
     *
     * @param string $method The HTTP method
     * @param string $uri The route URI pattern
     * @param string $controller The controller class name
     * @param string $action The controller action name
     * @param string $namePrefix The route name prefix inherited from its group
     * @throws InvalidArgumentException If the URI contains invalid parameters
     */
    public function __construct(
        private string $method,
        private string $uri,
        private string $controller,
        private string $action,
        private string $namePrefix = ''
    ) {
        $this->parameters = $this->parseParameters();
    }

    /**
     * Assign a unique name to the route.
     *
     * @param string $name The route name
     * @return self The current route
     * @throws InvalidArgumentException If the name is invalid or already registered
     * @throws LogicException If the route already has a name
     */
    public function name(string $name): self
    {
        Router::registerName($this, $name);

        return $this;
    }

    /**
     * Add middleware that runs before this route's action.
     *
     * Accepts class names, instances and callables, singly or as arrays, and
     * is chainable alongside name(). Middleware inherited from the enclosing
     * group is already present and stays first, so a group's authentication
     * still runs before a route's own checks.
     *
     * @param mixed ...$middleware Class names, instances or callables
     * @return self The current route
     */
    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $entry) {
            foreach (is_array($entry) ? $entry : [$entry] as $stage) {
                $this->middleware[] = $stage;
            }
        }

        return $this;
    }

    /**
     * Get the middleware that runs for this route.
     *
     * @return array<int, mixed> The middleware, in execution order
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Check whether the URI path matches this route.
     *
     * @param string $path The request path
     * @return array|null The named route parameters, or null when it does not match
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->compilePattern(), $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($this->parameters as $name => $type) {
            $parameters[$name] = $matches[$name];
        }

        return $parameters;
    }

    /**
     * Generate a path by replacing route parameters with supplied values.
     *
     * @param array $parameters The values indexed by parameter name
     * @return string The generated URI path
     * @throws InvalidArgumentException If a parameter is missing, invalid, or unknown
     */
    public function generateUrl(array $parameters = []): string
    {
        $remaining = $parameters;

        $url = preg_replace_callback(
            self::PARAMETER_PATTERN,
            function (array $matches) use (&$remaining): string {
                $name = $matches[1];
                $type = $matches[2];

                if (!array_key_exists($name, $remaining)) {
                    throw new InvalidArgumentException(
                        "Missing value for route parameter \"$name\"."
                    );
                }

                $value = $remaining[$name];
                unset($remaining[$name]);

                if (!is_scalar($value) || !preg_match(
                    '/^' . self::PARAMETER_TYPES[$type] . '$/u',
                    (string) $value
                )) {
                    throw new InvalidArgumentException(
                        "Invalid value for route parameter \"$name\"."
                    );
                }

                return rawurlencode((string) $value);
            },
            $this->uri
        );

        if ($remaining !== []) {
            throw new InvalidArgumentException(
                'Unknown route parameters: ' . implode(', ', array_keys($remaining)) . '.'
            );
        }

        return $url;
    }

    /**
     * Get the HTTP method accepted by the route.
     *
     * @return string The HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    public function getPath(): string
    {
        return $this->uri;
    }

    /**
     * Get the controller class name.
     *
     * @return string The controller class name
     */
    public function getController(): string
    {
        return $this->controller;
    }

    /**
     * Get the controller action name.
     *
     * @return string The controller action name
     */
    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * Get the fully-qualified route name.
     *
     * @return string|null The route name, or null when unnamed
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the route name prefix inherited from its group.
     *
     * @return string The route name prefix
     */
    public function getNamePrefix(): string
    {
        return $this->namePrefix;
    }

    /**
     * Set the fully-qualified route name.
     *
     * @param string $name The route name
     * @return void
     * @throws LogicException If the route already has a name
     */
    public function setName(string $name): void
    {
        if ($this->name !== null) {
            throw new LogicException('A route can only have one name.');
        }

        $this->name = $name;
    }

    /**
     * Extract and validate parameter declarations from the URI.
     *
     * @return array The parameter types indexed by parameter name
     * @throws InvalidArgumentException If the URI contains invalid parameters
     */
    private function parseParameters(): array
    {
        preg_match_all(self::PARAMETER_PATTERN, $this->uri, $matches, PREG_SET_ORDER);

        $parameters = [];
        foreach ($matches as $match) {
            $name = $match[1];
            if (isset($parameters[$name])) {
                throw new InvalidArgumentException(
                    "Route parameter \"$name\" is declared more than once."
                );
            }

            $parameters[$name] = $match[2];
        }

        $uriWithoutParameters = preg_replace(self::PARAMETER_PATTERN, '', $this->uri);
        if (str_contains($uriWithoutParameters, ':')) {
            throw new InvalidArgumentException(
                "Invalid route parameter declaration in \"$this->uri\"."
            );
        }

        return $parameters;
    }

    /**
     * Compile the URI pattern into a regular expression.
     *
     * @return string The regular expression used to match request paths
     */
    private function compilePattern(): string
    {
        $parameterPatterns = [];
        $uri = preg_replace_callback(
            self::PARAMETER_PATTERN,
            function (array $matches) use (&$parameterPatterns): string {
                $placeholder = '__SFPHP_PARAMETER_' . count($parameterPatterns) . '__';
                $parameterPatterns[$placeholder] = '(?P<' . $matches[1] . '>'
                    . self::PARAMETER_TYPES[$matches[2]] . ')';

                return $placeholder;
            },
            $this->uri
        );

        return '#^' . str_replace(
            array_keys($parameterPatterns),
            $parameterPatterns,
            preg_quote($uri, '#')
        ) . '$#Du';
    }
}

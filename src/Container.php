<?php

namespace SfphpProject\src;

use Closure;
use Exception;
use ReflectionClass;

class Container
{
    private array $instances = [];

    private array $factories = [];

    /**
     * Register an entry in the container.
     *
     * Keys are looked up by the autowiring resolver using the fully qualified
     * class name of a constructor parameter, so bind services under their FQCN
     * (PDO::class, not "pdo") or they will never be found.
     *
     * A Closure is stored as a factory and only invoked the first time the key
     * is requested, which keeps expensive services (such as a database
     * connection) out of requests that never use them. The factory receives the
     * container and its return value is reused afterwards.
     *
     * @param string $key The key to bind, normally a fully qualified class name
     * @param object $value The instance to bind, or a Closure that builds it
     * @return void
     */
    public function set(
        string $key,
        object $value
    ): void {
        if ($value instanceof Closure) {
            $this->factories[$key] = $value;
            unset($this->instances[$key]);

            return;
        }

        $this->instances[$key] = $value;
    }

    /**
     * Get an instance for the given key, creating it if needed.
     *
     * @param string $key The key to resolve, normally a fully qualified class name
     * @return object An instance for the requested key
     */
    public function get(string $key): object
    {
        if (isset($this->instances[$key])) {
            return $this->instances[$key];
        }

        if (isset($this->factories[$key])) {
            return $this->instances[$key] = ($this->factories[$key])($this);
        }

        return $this->instances[$key] = $this->resolve($key);
    }

    /**
     * Check whether a key is bound or can be autowired.
     *
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->instances[$key])
            || isset($this->factories[$key])
            || class_exists($key);
    }

    /**
     * Resolve a class instance.
     *
     * This method will resolve all the dependencies required by the class
     * constructor and return an instance of the class.
     *
     * @param string $class The class name to resolve
     * @return object An instance of the resolved class
     * @throws Exception If the class or any of its dependencies cannot be resolved
     */
    function resolve(string $class)
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new Exception("Class $class is not instantiable");
        }

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $class;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type) {
                throw new Exception("Cannot resolve parameter {$param->getName()} in $class");
            }

            $dependencies[] = $this->get($type->getName());
        }

        return $reflection->newInstanceArgs($dependencies);
    }
}
<?php

namespace SfphpProject\src;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;

class Container
{
    private array $instances = [];

    private array $factories = [];

    private array $resolving = [];

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

        if (isset($this->resolving[$key])) {
            throw new RuntimeException("Circular dependency detected while resolving $key.");
        }

        $this->resolving[$key] = true;

        try {
            if (isset($this->factories[$key])) {
                $instance = ($this->factories[$key])($this);
                if (!is_object($instance)) {
                    throw new RuntimeException("Factory for $key must return an object.");
                }

                return $this->instances[$key] = $instance;
            }

            return $this->instances[$key] = $this->resolve($key);
        } finally {
            unset($this->resolving[$key]);
        }
    }

    /**
     * Check whether a key is bound or can be autowired.
     *
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        if (isset($this->instances[$key]) || isset($this->factories[$key])) {
            return true;
        }

        if (!class_exists($key)) {
            return false;
        }

        return (new ReflectionClass($key))->isInstantiable();
    }

    /**
     * Resolve a class instance.
     *
     * This method will resolve all the dependencies required by the class
     * constructor and return an instance of the class.
     *
     * @param string $class The class name to resolve
     * @return object An instance of the resolved class
     * @throws RuntimeException If the class or any of its dependencies cannot be resolved
     */
    public function resolve(string $class): object
    {
        if (!class_exists($class)) {
            throw new RuntimeException("Class $class does not exist.");
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Class $class is not instantiable.");
        }

        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $class;
        }

        $dependencies = [];

        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (!$type) {
                if ($param->isDefaultValueAvailable()) {
                    $dependencies[] = $param->getDefaultValue();

                    continue;
                }

                throw new RuntimeException(
                    "Cannot resolve untyped parameter \${$param->getName()} in $class."
                );
            }

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $dependencies[] = $this->get($type->getName());

                continue;
            }

            if ($type instanceof ReflectionUnionType) {
                $dependency = $this->resolveUnionDependency($type);
                if ($dependency !== null) {
                    $dependencies[] = $dependency;

                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();

                continue;
            }

            if ($type->allowsNull()) {
                $dependencies[] = null;

                continue;
            }

            throw new RuntimeException(
                "Cannot resolve parameter \${$param->getName()} in $class. "
                . 'Bind a service or provide a default value.'
            );
        }

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve the first bound or instantiable class in a union type.
     *
     * @param ReflectionUnionType $type The union type to resolve
     * @return object|null A resolved dependency, or null when none can be resolved
     */
    private function resolveUnionDependency(ReflectionUnionType $type): ?object
    {
        foreach ($type->getTypes() as $candidate) {
            if (!$candidate instanceof ReflectionNamedType || $candidate->isBuiltin()) {
                continue;
            }

            if ($this->has($candidate->getName())) {
                return $this->get($candidate->getName());
            }
        }

        return null;
    }
}
<?php

namespace SfphpProject\src;

use Exception;
use ReflectionClass;

class Container
{
    private array $instances = [];

    /**
     * Set an instance of a class in the container.
     *
     * @param string $key The key name to set an instance of
     * @param object $instance The instance to set for the key
     * @return void
     */
    public function set(
        string $key, 
        object $instance
    ) {
        $this->instances[$key] = $instance;
    }

    /**
     * Get an instance of a class, if it doesn't exist, create it.
     *
     * @param string $class The class name to get an instance of
     * @return object An instance of the requested class
     */
    public function get(string $key)
    {
        if (!isset($this->instances[$key])) {
            $this->instances[$key] = $this->resolve($key);
        }
        
        return $this->instances[$key];
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
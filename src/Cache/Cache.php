<?php

namespace SfphpProject\src\Cache;

interface Cache
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value, ?int $seconds = null): void;

    public function forget(string $key): void;

    public function flush(): void;

    public function has(string $key): bool;
}

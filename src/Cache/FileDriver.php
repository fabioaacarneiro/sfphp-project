<?php

namespace SfPhp\Cache;

class FileDriver implements Cache
{
    protected string $directory;

    public function __construct(string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/sfphp-cache';

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return $default;
        }

        $data = json_decode(file_get_contents($path), true);

        if ($data === null || !isset($data['expires'])) {
            return $default;
        }

        if ($data['expires'] !== null && $data['expires'] < time()) {
            unlink($path);
            return $default;
        }

        return $data['value'] ?? $default;
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $path = $this->path($key);
        $expires = $seconds ? time() + $seconds : null;

        $data = [
            'value' => $value,
            'expires' => $expires,
        ];

        file_put_contents($path, json_encode($data), LOCK_EX);
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function flush(): void
    {
        $files = glob($this->directory . '/*');

        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    protected function path(string $key): string
    {
        $hash = md5($key);
        return $this->directory . '/' . $hash . '.cache';
    }
}

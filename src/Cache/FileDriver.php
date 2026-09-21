<?php

namespace SfphpProject\src\Cache;

use RuntimeException;

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

    /**
     * Add to a counter and return its new value, atomically.
     *
     * The whole read-modify-write happens inside one exclusive lock. The lock
     * is what makes this a counter: `LOCK_EX` on the write alone, which is what
     * `put()` uses, only stops two writes from interleaving mid-file — it does
     * nothing about two processes that both read 4 and both write 5.
     *
     * The file is opened with 'c+', which creates it when absent without
     * truncating it when present, so the lock is taken before the contents are
     * read rather than after the file has already been emptied.
     *
     * @param string $key The counter's key
     * @param int $by How much to add
     * @param int|null $seconds Lifetime for a counter being created, or null for none
     * @return int The value after adding
     * @throws RuntimeException When the file cannot be opened or locked
     */
    public function increment(string $key, int $by = 1, ?int $seconds = null): int
    {
        $path = $this->path($key);
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the cache file for ' . $key . '.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the cache file for ' . $key . '.');
            }

            $contents = stream_get_contents($handle);
            $data = $contents === '' ? null : json_decode($contents, true);

            $now = time();
            $missing = !is_array($data)
                || !array_key_exists('expires', $data)
                || ($data['expires'] !== null && $data['expires'] < $now);

            /*
             * An expired counter is a new counter: it starts from zero and gets
             * a fresh expiry. A live one keeps the expiry it already had, so the
             * window it belongs to closes when it was always going to.
             */
            $value = $missing ? 0 : (int) ($data['value'] ?? 0);
            $expires = $missing
                ? ($seconds !== null ? $now + $seconds : null)
                : $data['expires'];

            $value += $by;

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(['value' => $value, 'expires' => $expires]));
            fflush($handle);

            return $value;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * How many seconds remain before an entry expires.
     *
     * @param string $key The entry's key
     * @return int|null The seconds remaining, or null when the key is absent or never expires
     */
    public function ttl(string $key): ?int
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (!is_array($data) || ($data['expires'] ?? null) === null) {
            return null;
        }

        return max(0, $data['expires'] - time());
    }

    protected function path(string $key): string
    {
        $hash = md5($key);
        return $this->directory . '/' . $hash . '.cache';
    }
}

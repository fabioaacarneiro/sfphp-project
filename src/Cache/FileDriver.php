<?php

namespace SfphpProject\src\Cache;

use RuntimeException;
use SfphpProject\src\PrivateDirectory;

/**
 * A cache kept as one file per key.
 *
 * The directory defaults to storage/cache inside the project. It used to be a
 * fixed name under the system temporary directory, which every application and
 * every user on the machine shared: one could read another's cached sessions
 * and flush another's keys.
 */
class FileDriver implements Cache
{
    protected string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory === null
            ? PrivateDirectory::storage('cache')
            : PrivateDirectory::ensure(PrivateDirectory::resolve($directory));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $data = $this->read($this->path($key));

        return $data === null ? $default : $data['value'];
    }

    public function put(string $key, mixed $value, ?int $seconds = null): void
    {
        $path = $this->path($key);
        $data = json_encode(
            ['value' => $value, 'expires' => Ttl::expiresAt($seconds)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );

        /*
         * Written beside the entry and renamed over it, so a reader sees the
         * old entry or the new one and never half of one.
         */
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($temporary, $data, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the cache file for ' . $key . '.');
        }

        @chmod($temporary, 0600);

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException('Unable to write the cache file for ' . $key . '.');
        }
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Remove every entry.
     *
     * Only the files this driver writes are removed, so a CACHE_PATH pointed
     * at a directory that holds anything else does not lose it.
     */
    public function flush(): void
    {
        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function has(string $key): bool
    {
        return $this->read($this->path($key)) !== null;
    }

    public function prune(): int
    {
        $removed = 0;

        foreach (glob($this->directory . '/*.cache') ?: [] as $file) {
            $data = json_decode((string) @file_get_contents($file), true);

            if (!is_array($data) || !array_key_exists('expires', $data) || Ttl::expired($data['expires'])) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Add to a counter and return its new value, atomically.
     *
     * The whole read-modify-write happens inside one exclusive lock. The lock
     * is what makes this a counter: `LOCK_EX` on the write alone only stops two
     * writes from interleaving mid-file — it does nothing about two processes
     * that both read 4 and both write 5.
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
        $expiresAt = Ttl::expiresAt($seconds);
        $path = $this->path($key);
        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the cache file for ' . $key . '.');
        }

        @chmod($path, 0600);

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock the cache file for ' . $key . '.');
            }

            $contents = stream_get_contents($handle);
            $data = $contents === '' ? null : json_decode($contents, true);

            $missing = !is_array($data)
                || !array_key_exists('expires', $data)
                || Ttl::expired($data['expires']);

            /*
             * An expired counter is a new counter: it starts from zero and gets
             * a fresh expiry. A live one keeps the expiry it already had, so the
             * window it belongs to closes when it was always going to.
             */
            $value = ($missing ? 0 : (int) ($data['value'] ?? 0)) + $by;
            $expires = $missing ? $expiresAt : $data['expires'];

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
        $data = $this->read($this->path($key));

        if ($data === null || $data['expires'] === null) {
            return null;
        }

        return max(0, $data['expires'] - time());
    }

    protected function path(string $key): string
    {
        return $this->directory . '/' . md5($key) . '.cache';
    }

    /**
     * Read a live entry.
     *
     * An entry stored without a lifetime has `expires: null`, and it used to be
     * read as missing: the check was isset(), which is false for null. Every
     * put() without a TTL, every remember() with a null TTL and every counter
     * without a window was written and never found again.
     *
     * @param string $path The entry's file
     * @return array{value: mixed, expires: int|null}|null The entry, or null when absent or expired
     */
    private function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);

        if (!is_array($data) || !array_key_exists('expires', $data)) {
            return null;
        }

        if (Ttl::expired($data['expires'])) {
            @unlink($path);

            return null;
        }

        return ['value' => $data['value'] ?? null, 'expires' => $data['expires']];
    }
}

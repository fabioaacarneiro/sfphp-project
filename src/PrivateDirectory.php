<?php

namespace SfphpProject\src;

use RuntimeException;

/**
 * A directory only this process's user can read or write.
 *
 * The file cache and the compiled templates used to live in a fixed name under
 * the system temporary directory. That directory is shared by every user on
 * the machine, so the first one to create `sfphp-cache` owned it: another
 * application on the same host read and flushed the same keys, and the
 * compiled templates — PHP files the framework include()s — could be planted
 * by anyone who got there first.
 *
 * The default is now a directory inside the project, and a directory that
 * already exists is only accepted when this user owns it and nobody else can
 * write to it.
 */
final class PrivateDirectory
{
    /**
     * Make sure a private directory exists, and return its path.
     *
     * @param string $path The directory
     * @return string The same path
     * @throws RuntimeException When it cannot be created, or is not private
     */
    public static function ensure(string $path): string
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf(
                'Cannot create the directory %s. Create it, or point the setting that names it somewhere writable.',
                $path
            ));
        }

        self::assertPrivate($path);

        return $path;
    }

    /**
     * The project's storage directory, or a directory inside it.
     *
     * When the project directory cannot be written — a read-only container
     * image, say — this falls back to a directory under the system temporary
     * directory that carries the user's id and the project's path in its name,
     * so two users or two projects never share one.
     *
     * @param string $relative A path inside storage/, such as "cache"
     * @return string The absolute path, created and private
     */
    public static function storage(string $relative): string
    {
        $inProject = Bootstrap::basePath('storage/' . $relative);

        try {
            return self::ensure($inProject);
        } catch (RuntimeException) {
            $owner = function_exists('posix_geteuid') ? (string) posix_geteuid() : get_current_user();
            $fallback = sys_get_temp_dir() . '/sfphp-' . $owner . '-' . substr(md5(Bootstrap::basePath()), 0, 12)
                . '/' . $relative;

            return self::ensure($fallback);
        }
    }

    /**
     * Resolve a configured path against the project root.
     *
     * A relative CACHE_PATH used to resolve against whatever directory the
     * process happened to start in, so the CLI and the web server read two
     * different caches.
     *
     * @param string $path The configured path
     * @return string The absolute path
     */
    public static function resolve(string $path): string
    {
        if ($path === '' || $path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return Bootstrap::basePath($path);
    }

    /**
     * Refuse a directory someone else owns or can write to.
     *
     * @param string $path The directory
     * @return void
     * @throws RuntimeException When the directory is not private
     */
    private static function assertPrivate(string $path): void
    {
        // Windows has no owner ids or mode bits to check.
        if (DIRECTORY_SEPARATOR === '\\') {
            return;
        }

        clearstatcache(true, $path);
        $owner = @fileowner($path);
        $mode = @fileperms($path);

        if (function_exists('posix_geteuid') && $owner !== false && $owner !== posix_geteuid()) {
            throw new RuntimeException(sprintf(
                'The directory %s belongs to another user, so what it holds cannot be trusted. Remove it or use another path.',
                $path
            ));
        }

        if ($mode !== false && ($mode & 0o022) !== 0) {
            // Ours but writable by others: tighten it rather than refuse.
            if (!@chmod($path, 0700)) {
                throw new RuntimeException(sprintf(
                    'The directory %s can be written by other users. Make it private (chmod 700).',
                    $path
                ));
            }
        }
    }
}

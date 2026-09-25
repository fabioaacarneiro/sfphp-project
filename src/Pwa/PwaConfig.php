<?php

namespace SfphpProject\src\Pwa;

/**
 * The settings make:pwa builds the manifest and the service worker from.
 *
 * It reads the shape of app/pwa/config.php: the manifest fields at the top
 * level, and everything the service worker needs under `service_worker`. This
 * class used to expect a different shape (`cache.*`, `push_notifications`, an
 * `enabled` switch) that the shipped config file did not have, and nothing
 * read it anyway, so every value in that file was decoration. Now make:pwa
 * reads it, and a key missing from the file falls back to the default below.
 */
final class PwaConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    /**
     * @param array<string, mixed> $config The settings, in the shape of app/pwa/config.php
     */
    public function __construct(array $config = [])
    {
        $this->config = self::merge($this->defaults(), $config);
    }

    /**
     * Lay the file's settings over the defaults.
     *
     * Not array_replace_recursive(): it merges lists by index, so a file that
     * lists two static assets would still get the third default one, and a
     * file that declares a single icon would keep the second default icon. A
     * list in the file replaces the default list; only keyed sections merge.
     *
     * @param array<array-key, mixed> $base The defaults
     * @param array<array-key, mixed> $over The file's settings
     * @return array<array-key, mixed> The merged settings
     */
    private static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            $base[$key] = is_array($value) && !array_is_list($value) && is_array($base[$key] ?? null)
                ? self::merge($base[$key], $value)
                : $value;
        }

        return $base;
    }

    /**
     * Load a config file, or the defaults when there is none.
     *
     * @param string $path The file, which returns an array
     * @return self The settings
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $config = require $path;
        return new self(is_array($config) ? $config : []);
    }

    /**
     * Read a setting by dotted key, such as `service_worker.version`.
     *
     * @param string $key The key
     * @param mixed $default What to return when it is not set
     * @return mixed The value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function name(): string
    {
        return (string) $this->get('name', 'My App');
    }

    public function shortName(): string
    {
        return (string) $this->get('short_name', mb_substr($this->name(), 0, 12));
    }

    public function description(): string
    {
        return (string) $this->get('description', '');
    }

    public function themeColor(): string
    {
        return (string) $this->get('theme_color', '#007AFF');
    }

    public function backgroundColor(): string
    {
        return (string) $this->get('background_color', '#ffffff');
    }

    public function display(): string
    {
        return (string) $this->get('display', 'standalone');
    }

    public function startUrl(): string
    {
        return (string) $this->get('start_url', '/');
    }

    public function scope(): string
    {
        return (string) $this->get('scope', '/');
    }

    public function orientation(): string
    {
        return (string) $this->get('orientation', 'portrait-primary');
    }

    /**
     * The cache version. The service worker's cache is named
     * `<app-slug>-<version>`, so changing it makes the next service worker
     * delete the old cache when it activates.
     *
     * @return string The version
     */
    public function version(): string
    {
        return (string) $this->get('service_worker.version', 'v1');
    }

    /** @return list<string> URLs precached when the service worker installs */
    public function staticAssets(): array
    {
        return array_values((array) $this->get('service_worker.static_assets', []));
    }

    /** @return list<string> Path patterns, `*` as a wildcard, that are never cached */
    public function apiRoutes(): array
    {
        return array_values((array) $this->get('service_worker.api_routes', []));
    }

    public function offlineFallback(): string
    {
        return (string) $this->get('service_worker.offline_fallback', '/offline.html');
    }

    public function pushNotificationsEnabled(): bool
    {
        return $this->get('service_worker.enable_push_notifications', false) === true;
    }

    public function backgroundSyncEnabled(): bool
    {
        return $this->get('service_worker.enable_background_sync', false) === true;
    }

    /** @return list<array{src: string, sizes: string, type?: string, purpose?: string}> The manifest icons */
    public function icons(): array
    {
        return array_values((array) $this->get('icons', []));
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => '#007AFF',
            'background_color' => '#ffffff',
            'orientation' => 'portrait-primary',
            'service_worker' => [
                'version' => 'v1',
                'static_assets' => [
                    '/assets/css/sfcss.min.css',
                    '/assets/js/sfjs.min.js',
                    '/offline.html',
                ],
                'api_routes' => [],
                'offline_fallback' => '/offline.html',
                'enable_push_notifications' => false,
                'enable_background_sync' => false,
            ],
            'icons' => [
                ['src' => '/assets/icons/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/assets/icons/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ];
    }
}

<?php

namespace SfphpProject\src\Pwa;

/**
 * PWA Configuration container.
 *
 * Manages all PWA settings: manifest, service worker, caching, push notifications,
 * and background sync configuration.
 */
final class PwaConfig
{
    /** @var array<string, mixed> */
    private array $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->defaults(), $config);
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $config = require $path;
        return new self(is_array($config) ? $config : []);
    }

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

    public function isEnabled(): bool
    {
        return $this->get('enabled', false) === true;
    }

    public function name(): string
    {
        return $this->get('name', 'My App');
    }

    public function shortName(): string
    {
        return $this->get('short_name', 'App');
    }

    public function description(): string
    {
        return $this->get('description', '');
    }

    public function themeColor(): string
    {
        return $this->get('theme_color', '#ffffff');
    }

    public function backgroundColor(): string
    {
        return $this->get('background_color', '#ffffff');
    }

    public function display(): string
    {
        return $this->get('display', 'standalone');
    }

    public function startUrl(): string
    {
        return $this->get('start_url', '/');
    }

    public function scope(): string
    {
        return $this->get('scope', '/');
    }

    public function orientation(): string
    {
        return $this->get('orientation', 'portrait-primary');
    }

    public function cacheName(): string
    {
        return $this->get('cache.name', 'sfphp-app-v1');
    }

    /** @return array<int, string> */
    public function staticAssets(): array
    {
        return $this->get('cache.static_assets', [
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
        ]);
    }

    /** @return array<int, string> */
    public function apiRoutes(): array
    {
        return $this->get('cache.api_routes', ['/api/*']);
    }

    public function offlineFallback(): string
    {
        return $this->get('cache.offline_fallback', '/offline.html');
    }

    public function pushNotificationsEnabled(): bool
    {
        return $this->get('push_notifications', false) === true;
    }

    public function backgroundSyncEnabled(): bool
    {
        return $this->get('background_sync', false) === true;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'enabled' => false,
            'name' => 'My App',
            'short_name' => 'App',
            'description' => '',
            'theme_color' => '#ffffff',
            'background_color' => '#ffffff',
            'display' => 'standalone',
            'start_url' => '/',
            'scope' => '/',
            'orientation' => 'portrait-primary',
            'cache' => [
                'name' => 'sfphp-app-v1',
                'static_assets' => [
                    '/css/sfcss.min.css',
                    '/js/sfjs.min.js',
                ],
                'api_routes' => ['/api/*'],
                'offline_fallback' => '/offline.html',
            ],
            'push_notifications' => false,
            'background_sync' => false,
        ];
    }
}

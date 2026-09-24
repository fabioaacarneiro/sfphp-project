<?php

namespace SfphpProject\src\Pwa;

use InvalidArgumentException;

/**
 * Generates a web app manifest (manifest.json) for PWA configuration.
 *
 * The manifest file defines how your PWA appears on home screen, splash screen,
 * theme colors, orientation, and more.
 */
final class ManifestGenerator
{
    private string $name = 'My App';
    private string $shortName = 'App';
    private string $description = '';
    private string $startUrl = '/';
    private string $scope = '/';
    private string $display = 'standalone';
    private string $themeColor = '#ffffff';
    private string $backgroundColor = '#ffffff';
    private string $orientation = 'portrait-primary';

    /** @var array<int, array<string, string>> */
    private array $icons = [];

    /** @var array<int, array<string, string|null>> */
    private array $screenshots = [];

    /** @var array<string, mixed> */
    private array $shortcuts = [];

    /** @var array<string, mixed> */
    private array $categories = [];

    public function __construct(private string $basePath = '') {}

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function shortName(string $shortName): self
    {
        $this->shortName = $shortName;
        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function startUrl(string $startUrl): self
    {
        $this->startUrl = $startUrl;
        return $this;
    }

    public function scope(string $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function display(string $display): self
    {
        if (!in_array($display, ['standalone', 'fullscreen', 'minimal-ui', 'browser'], true)) {
            throw new InvalidArgumentException('Invalid display mode: ' . $display);
        }
        $this->display = $display;
        return $this;
    }

    public function themeColor(string $color): self
    {
        $this->themeColor = $color;
        return $this;
    }

    public function backgroundColor(string $color): self
    {
        $this->backgroundColor = $color;
        return $this;
    }

    public function orientation(string $orientation): self
    {
        if (!in_array($orientation, ['portrait-primary', 'portrait-secondary', 'landscape-primary', 'landscape-secondary', 'portrait', 'landscape'], true)) {
            throw new InvalidArgumentException('Invalid orientation: ' . $orientation);
        }
        $this->orientation = $orientation;
        return $this;
    }

    public function icon(string $src, string $sizes, string $type = 'image/png', string $purpose = 'any'): self
    {
        $this->icons[] = [
            'src' => $src,
            'sizes' => $sizes,
            'type' => $type,
            'purpose' => $purpose,
        ];
        return $this;
    }

    public function screenshot(string $src, string $sizes, ?string $type = 'image/png', ?string $formFactor = null): self
    {
        $this->screenshots[] = [
            'src' => $src,
            'sizes' => $sizes,
            'type' => $type,
            'form_factor' => $formFactor,
        ];
        return $this;
    }

    public function shortcut(string $name, string $url, string $icon = '', string $shortName = ''): self
    {
        $this->shortcuts[] = [
            'name' => $name,
            'short_name' => $shortName ?: $name,
            'url' => $url,
            'icons' => $icon ? [['src' => $icon, 'sizes' => '192x192']] : [],
        ];
        return $this;
    }

    public function category(string $category): self
    {
        if (!in_array($category, ['business', 'consumer', 'developer', 'education', 'entertainment', 'health', 'lifestyle', 'media', 'medical', 'music', 'news', 'personalization', 'photo', 'politics', 'productivity', 'security', 'shopping', 'social', 'sports', 'travel', 'utilities', 'weather'], true)) {
            throw new InvalidArgumentException('Invalid category: ' . $category);
        }
        $this->categories[$category] = true;
        return $this;
    }

    public function generate(): string
    {
        $manifest = [
            'name' => $this->name,
            'short_name' => $this->shortName,
            'description' => $this->description,
            'start_url' => $this->startUrl,
            'scope' => $this->scope,
            'display' => $this->display,
            'theme_color' => $this->themeColor,
            'background_color' => $this->backgroundColor,
            'orientation' => $this->orientation,
        ];

        if ($this->icons !== []) {
            $manifest['icons'] = $this->icons;
        }

        if ($this->screenshots !== []) {
            $manifest['screenshots'] = $this->screenshots;
        }

        if ($this->shortcuts !== []) {
            $manifest['shortcuts'] = $this->shortcuts;
        }

        if ($this->categories !== []) {
            $manifest['categories'] = array_keys($this->categories);
        }

        return json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    public function save(string $path): bool
    {
        return file_put_contents($path, $this->generate()) !== false;
    }
}

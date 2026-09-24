<?php

namespace SfphpProject\src\Console\Commands;

use SfphpProject\src\Console\Command;
use SfphpProject\src\Pwa\ManifestGenerator;
use SfphpProject\src\Pwa\ServiceWorkerGenerator;
use SfphpProject\src\Pwa\IconGenerator;

/**
 * Generate a complete PWA setup for your SFPHP app.
 *
 * Generates:
 * - manifest.json (PWA metadata)
 * - service-worker.js (offline support, caching)
 * - offline.html (offline fallback page)
 * - icons (192x192, 512x512, apple touch)
 * - config/pwa.php (configuration)
 *
 * Usage:
 *   ./sfphp make:pwa --name="My App" --short="App" --color="#007AFF"
 */
class MakePwaCommand extends Command
{
    protected string $name = 'make:pwa';
    protected string $description = 'Generate a complete Progressive Web App setup';

    public function handle(): int
    {
        $this->info('🚀 Creating PWA setup...\n');

        // Get configuration
        $name = $this->option('name') ?? $this->ask('App name', 'My App');
        $shortName = $this->option('short') ?? $this->ask('Short name (home screen)', mb_substr($name, 0, 12));
        $description = $this->option('description') ?? $this->ask('App description', '');
        $color = $this->option('color') ?? $this->ask('Theme color', '#007AFF');
        $logo = $this->option('logo');

        $basePath = $this->getBasePath();
        $publicPath = $basePath . '/public';
        $configPath = $basePath . '/app/pwa';
        $resourcesPath = $basePath . '/resources/pwa';

        // Create directories
        @mkdir($publicPath . '/icons', 0755, true);
        @mkdir($configPath, 0755, true);

        // 1. Generate manifest.json
        $this->info('  ✓ Generating manifest.json');
        $manifest = new ManifestGenerator();
        $manifest
            ->name($name)
            ->shortName($shortName)
            ->description($description)
            ->themeColor($color)
            ->icon('/icons/icon-192x192.png', '192x192', 'image/png')
            ->icon('/icons/icon-512x512.png', '512x512', 'image/png');

        $manifest->save($publicPath . '/manifest.json');

        // 2. Generate service-worker.js
        $this->info('  ✓ Generating service-worker.js');
        $sw = new ServiceWorkerGenerator('v1');
        $sw->appName(mb_strtolower(str_replace(' ', '-', $name)));

        if ($this->option('enable-push')) {
            $sw->enablePushNotifications();
            $this->info('    └─ Push notifications enabled');
        }

        if ($this->option('enable-sync')) {
            $sw->enableBackgroundSync();
            $this->info('    └─ Background sync enabled');
        }

        $sw->save($publicPath . '/service-worker.js');

        // 3. Copy offline page
        $this->info('  ✓ Generating offline.html');
        $offlineTemplate = $resourcesPath . '/offline-template.html';
        if (is_file($offlineTemplate)) {
            copy($offlineTemplate, $publicPath . '/offline.html');
        } else {
            file_put_contents($publicPath . '/offline.html', $this->generateOfflineHtml());
        }

        // 4. Copy install script
        $this->info('  ✓ Copying install-sw.js');
        $installScript = $resourcesPath . '/install-sw.js';
        if (is_file($installScript)) {
            copy($installScript, $publicPath . '/install-sw.js');
        }

        // 5. Generate icons
        if ($logo && is_file($logo)) {
            $this->info('  ✓ Generating icons from ' . basename($logo));
            try {
                $iconGen = new IconGenerator($logo);
                $iconGen->generate($publicPath . '/icons');
            } catch (\Exception $e) {
                $this->warn('    └─ Could not generate icons: ' . $e->getMessage());
                $this->info('    └─ Please add icons manually to /public/icons/');
            }
        } else {
            $this->warn('  ⚠ Skipping icon generation (no logo provided)');
            $this->info('  Usage: ./sfphp make:pwa --logo=path/to/logo.png');
        }

        // 6. Create config file
        $this->info('  ✓ Creating app/pwa/config.php');
        $this->createConfigFile($configPath, $name, $shortName, $description, $color);

        // 7. Update base layout
        $this->info('  ✓ Updating layout with PWA tags');
        $this->updateLayout($basePath, $color);

        // 8. Generate documentation
        $this->info('  ✓ Creating documentation');
        $this->generateDocumentation($basePath);

        $this->line('');
        $this->success('✨ PWA setup complete!\n');

        // Show next steps
        $this->showNextSteps($logo === null);

        return Command::SUCCESS;
    }

    private function createConfigFile(string $path, string $name, string $shortName, string $description, string $color): void
    {
        $config = <<<PHP
<?php

return [
    'enabled' => env('PWA_ENABLED', true),

    'name' => '{$name}',
    'short_name' => '{$shortName}',
    'description' => '{$description}',

    'theme_color' => '{$color}',
    'background_color' => '#ffffff',

    'display' => 'standalone',
    'start_url' => '/',
    'scope' => '/',
    'orientation' => 'portrait-primary',

    'cache' => [
        'name' => 'app-v1',
        'static_assets' => [
            '/css/sfcss.min.css',
            '/js/sfjs.min.js',
            '/index.html',
        ],
        'api_routes' => ['/api/*'],
        'offline_fallback' => '/offline.html',
    ],

    'push_notifications' => false,
    'background_sync' => false,
];
PHP;

        file_put_contents($path . '/config.php', $config);
    }

    private function updateLayout(string $basePath, string $color): void
    {
        $layoutPath = $basePath . '/app/views/layouts/base.sfht';

        if (!is_file($layoutPath)) {
            return;
        }

        $content = file_get_contents($layoutPath);

        // Add PWA meta tags before closing </head>
        $pwaTag = <<<'HTML'

    <!-- PWA Meta Tags -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="{$color}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <script src="/install-sw.js" defer></script>
HTML;

        $pwaTag = str_replace('{$color}', $color, $pwaTag);

        if (strpos($content, '/manifest.json') === false) {
            $content = str_replace('</head>', $pwaTag . "\n</head>", $content);
            file_put_contents($layoutPath, $content);
        }
    }

    private function generateOfflineHtml(): string
    {
        return file_get_contents(__DIR__ . '/../../resources/pwa/offline-template.html');
    }

    private function generateDocumentation(string $basePath): void
    {
        $docsPath = $basePath . '/docs/PWA.md';

        $doc = file_get_contents(__DIR__ . '/../../resources/pwa/PWA_GUIDE.md');
        @mkdir(dirname($docsPath), 0755, true);
        file_put_contents($docsPath, $doc);
    }

    private function showNextSteps(bool $noLogo): void
    {
        $this->line('📋 Next Steps:\n');

        $this->line('1. <fg=blue>Generate icons (if not done)</>');
        if ($noLogo) {
            $this->line('   ./sfphp make:pwa --logo=path/to/your/logo.png\n');
        } else {
            $this->line('   ✓ Icons already generated\n');
        }

        $this->line('2. <fg=blue>Review your app configuration</>');
        $this->line('   app/pwa/config.php\n');

        $this->line('3. <fg=blue>Test your PWA</>');
        $this->line('   - Open DevTools > Application');
        $this->line('   - Check Manifest tab');
        $this->line('   - Check Service Workers');
        $this->line('   - Try installing to home screen\n');

        $this->line('4. <fg=blue>Enable push notifications (optional)</>');
        $this->line('   - Set PWA_PUSH_NOTIFICATIONS=true in .env\n');

        $this->line('📚 <fg=cyan>Read the full guide:</>');
        $this->line('   docs/PWA.md\n');

        $this->line('🚀 <fg=green>Ready to deploy your PWA!</>\n');
    }

    private function getBasePath(): string
    {
        return getcwd();
    }
}

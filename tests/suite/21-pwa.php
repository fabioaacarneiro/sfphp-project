<?php

/*
 * PWA: make:pwa, the config and the service worker.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Bootstrap;
use SfphpProject\src\Console\Application;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;

$tests->run('make:pwa builds from app/pwa/config.php, and flags override it', function () use ($tests): void {
    $base = sys_get_temp_dir() . '/sfphp-pwa-' . bin2hex(random_bytes(6));
    mkdir($base . '/app/pwa', 0777, true);
    mkdir($base . '/public', 0777, true);

    file_put_contents($base . '/app/pwa/config.php', '<?php return ' . var_export([
        'name' => "Joe's Café",
        'short_name' => 'Joe',
        'theme_color' => '#112233',
        'background_color' => '#445566',
        'service_worker' => [
            'version' => 'v7',
            'static_assets' => ['/offline.html'],
            'api_routes' => ['/api/*'],
            'enable_background_sync' => true,
        ],
    ], true) . ';');

    Bootstrap::load($base, ['env' => null]);

    try {
        ob_start();
        $status = (new Application(['sfphp', 'make:pwa']))->run();
        ob_end_clean();

        $tests->assertSame(0, $status);

        $manifest = json_decode((string) file_get_contents($base . '/public/manifest.json'), true);
        $tests->assertSame("Joe's Café", $manifest['name']);
        $tests->assertSame('Joe', $manifest['short_name']);
        $tests->assertSame('#112233', $manifest['theme_color']);
        $tests->assertSame('#445566', $manifest['background_color']);
        $tests->assertSame('/assets/icons/icon-192x192.png', $manifest['icons'][0]['src']);

        // The version names the cache, and the name is encoded, not pasted between quotes.
        $worker = (string) file_get_contents($base . '/public/service-worker.js');
        $tests->assertTrue(str_contains($worker, 'const CACHE_NAME = "joe\'s-café-v7";'));
        $tests->assertTrue(str_contains($worker, 'const STATIC_ASSETS = ["\/offline.html"];'));
        $tests->assertTrue(str_contains($worker, "addEventListener('sync'"));
        $tests->assertTrue(!str_contains($worker, "addEventListener('push'"));

        // A flag wins over the file, for that run.
        ob_start();
        (new Application(['sfphp', 'make:pwa', '--name=Other App', '--color=#000000', '--enable-push']))->run();
        ob_end_clean();

        $manifest = json_decode((string) file_get_contents($base . '/public/manifest.json'), true);
        $tests->assertSame('Other App', $manifest['name']);
        $tests->assertSame('Other App', $manifest['short_name']);
        $tests->assertSame('#000000', $manifest['theme_color']);
        $tests->assertTrue(str_contains((string) file_get_contents($base . '/public/service-worker.js'), "addEventListener('push'"));

        // install-sw.js points at the icons where they are generated.
        $installer = (string) file_get_contents($base . '/public/install-sw.js');
        $tests->assertTrue(!str_contains($installer, "'/icon-192x192.png'"));
        $tests->assertTrue(str_contains($installer, '/assets/icons/icon-192x192.png'));

        // A logo that cannot become icons is a warning, never a fatal error.
        file_put_contents($base . '/not-an-image.png', 'plain text');
        $status = (new Application(['sfphp', 'make:pwa', '--logo=' . $base . '/not-an-image.png']))->run();
        $tests->assertSame(0, $status);
        $tests->assertTrue(!is_file($base . '/public/assets/icons/icon-192x192.png'));
    } finally {
        Bootstrap::load(dirname(dirname(__DIR__)), ['env' => null]);
        exec('rm -rf ' . escapeshellarg($base));
    }

    // Without a config file, --name is required.
    $empty = sys_get_temp_dir() . '/sfphp-pwa-' . bin2hex(random_bytes(6));
    mkdir($empty . '/public', 0777, true);
    Bootstrap::load($empty, ['env' => null]);

    try {
        ob_start();
        $status = (new Application(['sfphp', 'make:pwa']))->run();
        ob_end_clean();
        $tests->assertSame(1, $status);
    } finally {
        Bootstrap::load(dirname(dirname(__DIR__)), ['env' => null]);
        exec('rm -rf ' . escapeshellarg($empty));
    }
});

$tests->run('the service worker precaches what exists, keeps asset versions apart, and falls back to one offline', function () use ($tests): void {
    /*
     * asset() versions every URL, and the service worker matched the whole
     * URL, so the precached /assets/js/sfjs.min.js never answered a page that
     * asks for sfjs.min.js?v=81d0e4aa: the precache was never used. And
     * addAll() is all or nothing, so one missing file — plugins.min.js in a
     * project with no plugins — left nothing precached at all.
     *
     * Service workers do not run from file://, so the generated script runs
     * in node here, over a fake Cache Storage and a fake network.
     */
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    if ($node === '') {
        return;
    }

    $directory = sys_get_temp_dir() . '/sfphp-sw-' . bin2hex(random_bytes(6));
    mkdir($directory, 0755, true);

    file_put_contents($directory . '/sw.js', (new \SfphpProject\src\Pwa\ServiceWorkerGenerator())->generate());
    file_put_contents($directory . '/harness.js', <<<'JS'
    const base = 'https://app.test';
    const abs = (what) => new URL(typeof what === 'string' ? what : what.url, base).href;
    const store = new Map();
    let online = true;

    const cache = {
      add: async (what) => {
        const response = await fetch(abs(what));
        if (!response.ok) throw new Error('HTTP ' + response.status);
        store.set(abs(what), response);
      },
      put: async (request, response) => { store.set(abs(request), response); },
      keys: async () => [...store.keys()].map((url) => new Request(url)),
      delete: async (request) => store.delete(abs(request)),
    };

    globalThis.caches = {
      open: async () => cache,
      keys: async () => [],
      delete: async () => true,
      match: async (request, options = {}) => {
        const wanted = new URL(abs(request));
        for (const [url, response] of store) {
          const cached = new URL(url);
          if (url === wanted.href || (options.ignoreSearch && cached.pathname === wanted.pathname)) return response.clone();
        }
        return undefined;
      },
    };

    globalThis.fetch = async (what) => {
      const url = abs(what);
      if (!online) throw new TypeError('Failed to fetch');
      if (url.includes('plugins.min.js')) return new Response('missing', { status: 404 });
      return new Response('body of ' + url.slice(base.length), { status: 200 });
    };

    const listeners = {};
    globalThis.self = {
      addEventListener: (type, listener) => { listeners[type] = listener; },
      skipWaiting: async () => {},
      clients: { claim: async () => {} },
    };

    require(process.argv[2]);

    const event = (extra = {}) => {
      const holder = {};
      return [holder, { waitUntil: (p) => { holder.p = p; }, respondWith: (p) => { holder.p = p; }, ...extra }];
    };

    (async () => {
      const [installed, install] = event();
      listeners.install(install);
      await installed.p;
      const precached = [...store.keys()].map((url) => url.slice(base.length));

      store.set(base + '/assets/js/sfjs.min.js?v=old', new Response('old'));

      const [fresh, online1] = event({ request: new Request(base + '/assets/js/sfjs.min.js?v=new') });
      listeners.fetch(online1);
      const freshText = await (await fresh.p).text();
      await new Promise((resolve) => setTimeout(resolve, 20));
      const afterNew = [...store.keys()].map((url) => url.slice(base.length)).filter((url) => url.includes('sfjs'));

      online = false;
      const [fallback, offline1] = event({ request: new Request(base + '/assets/css/sfcss.min.css?v=zzz') });
      listeners.fetch(offline1);
      const fallbackText = await (await fallback.p).text();

      console.log(JSON.stringify({ precached, freshText, afterNew, fallbackText }));
    })();
    JS);

    try {
        $output = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg($directory . '/harness.js') . ' ' . escapeshellarg($directory . '/sw.js') . ' 2>&1', $output);
        $result = json_decode((string) end($output), true);

        $tests->assertSame(true, is_array($result));

        // One missing file does not cost the others their place.
        $tests->assertSame(true, in_array('/assets/css/sfcss.min.css', $result['precached'], true));
        $tests->assertSame(true, in_array('/assets/js/sfjs.min.js', $result['precached'], true));
        $tests->assertSame(false, in_array('/assets/js/plugins.min.js', $result['precached'], true));

        // Online, a new version comes from the network, and the one it replaces goes.
        $tests->assertSame('body of /assets/js/sfjs.min.js?v=new', $result['freshText']);
        sort($result['afterNew']);
        $tests->assertSame(['/assets/js/sfjs.min.js', '/assets/js/sfjs.min.js?v=new'], $result['afterNew']);

        // Offline, a version never seen is answered with the copy that was precached.
        $tests->assertSame('body of /assets/css/sfcss.min.css', $result['fallbackText']);
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
});

$tests->run('a PWA config list replaces the default list instead of merging into it', function () use ($tests): void {
    $config = new \SfphpProject\src\Pwa\PwaConfig([
        'service_worker' => ['static_assets' => ['/a.css']],
        'icons' => [['src' => '/one.png', 'sizes' => '64x64']],
    ]);

    $tests->assertSame(['/a.css'], $config->staticAssets());
    $tests->assertSame(1, count($config->icons()));
    // A keyed section still merges: the version default survives.
    $tests->assertSame('v1', $config->version());
});

<?php

/*
 * The console, the project layout, the package and upgrades.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Config;
use SfphpProject\src\Dotenv;
use SfphpProject\src\Bootstrap;
use SfphpProject\src\Console\Application;
use SfphpProject\src\Assets;
use SfphpProject\src\Env;
use SfphpProject\src\Auth\Authenticatable;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\View;

$tests->run('cli commands only call helpers that exist', function () use ($tests): void {
    /*
     * db:seed and queue:work called $this->getOption(), which was never
     * defined: both died with "undefined method" the moment they ran. Nothing
     * caught it because no test executed a CLI command body.
     */
    $reflection = new ReflectionClass(Application::class);
    $source = file_get_contents($reflection->getFileName());

    preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\(/', $source, $matches);

    foreach (array_unique($matches[1]) as $method) {
        $tests->assertTrue($reflection->hasMethod($method));
    }
});

$tests->run('every generator produces a class that actually loads', function () use ($tests): void {
    /*
     * getNamespace() used to ucfirst() the directory, so a file written to
     * app/models declared SfphpProject\app\Models and PSR-4 looked for
     * app/Models on a case-sensitive filesystem. Every generator was affected,
     * and a generated controller could not even be found by the router, which
     * looks for the lower-case namespace.
     */
    $root = dirname(dirname(__DIR__));
    $generators = [
        'controller' => 'app/controllers/GenProbeController.php',
        'model' => 'app/models/GenProbe.php',
        'repository' => 'app/repositories/GenProbeRepository.php',
        'service' => 'app/services/GenProbeService.php',
        'request' => 'app/requests/GenProbeRequest.php',
        'policy' => 'app/policies/GenProbePolicy.php',
        'event' => 'app/events/GenProbeEvent.php',
        'listener' => 'app/listeners/GenProbeListener.php',
        'middleware' => 'app/middleware/GenProbeMiddleware.php',
    ];

    $created = [];

    try {
        foreach ($generators as $command => $relative) {
            (new Application(['sfphp', "make:$command", 'GenProbe']))->run();

            $path = $root . '/' . $relative;
            $tests->assertTrue(is_file($path));
            $created[] = $path;

            $source = file_get_contents($path);
            preg_match('/^namespace (.+);/m', $source, $namespace);
            // Anchored: a docblock mentioning "the class name" is not a declaration.
            preg_match('/^(?:final )?class (\w+)/m', $source, $class);

            $fqcn = $namespace[1] . '\\' . $class[1];

            require_once $path;
            $tests->assertTrue(class_exists($fqcn, false));
        }
    } finally {
        foreach ($created as $path) {
            @unlink($path);
        }


        foreach (array_unique(array_map(
            static fn (string $relative): string => $root . '/' . dirname($relative),
            $generators
        )) as $directory) {
            if (is_dir($directory) && (glob($directory . '/*') ?: []) === []) {
                @rmdir($directory);
            }
        }
    }
});

$tests->run('the package is a project somebody can start developing in', function () use ($tests): void {
    /*
     * A skeleton, not a library. `composer create-project` hands over a working
     * application — routes, a controller, views, migrations, the front
     * controller and the console at the root — because "install it and start
     * developing" is the promise, and a framework you have to wire up first is
     * not that.
     */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/../composer.json'), true);

    $tests->assertSame('project', $composer['type']);

    // The application's namespaces are the project's, not a development extra.
    foreach (['SfphpProject\\src\\' => 'src/', 'SfphpProject\\app\\' => 'app/'] as $prefix => $path) {
        $tests->assertSame($path, $composer['autoload']['psr-4'][$prefix] ?? null);
    }

    // Nothing the package loads may live outside src/.
    foreach ($composer['autoload']['files'] as $file) {
        $tests->assertTrue(str_starts_with($file, 'src/'));
        $tests->assertTrue(is_file(dirname(__DIR__) . '/../' . $file));
    }

    /*
     * Nothing is loaded through an autoload "files" entry that lives under
     * app/. That entry runs whenever this package is the root one — which it is
     * in a created project — and it used to require app/config/config.php,
     * which broke the install at autoload time, before any script could run.
     * The front controller and the console call Bootstrap::load() themselves.
     */
    foreach ($composer['autoload']['files'] ?? [] as $file) {
        $tests->assertTrue(str_starts_with($file, 'src/'));
    }

    $tests->assertSame(null, $composer['autoload-dev'] ?? null);

    /*
     * Creating a project leaves it ready to run rather than ready to configure.
     * There is no post-install hook any more: `composer require` was the path
     * that needed one, and that path is gone — it put a whole second
     * application inside the consumer's vendor/, which is exactly what this
     * layout exists to avoid.
     */
    // init writes .env, publishes the assets, and adds the project's .gitignore and scripts.
    $tests->assertSame(['@php sfphp init'], $composer['scripts']['post-create-project-cmd']);
    $tests->assertSame(null, $composer['scripts']['post-install-cmd'] ?? null);

    // And excluded from what a `composer require` downloads.
    $attributes = (string) file_get_contents(dirname(__DIR__) . '/../.gitattributes');

    /*
     * What stays behind is what belongs to developing the framework rather than
     * to developing with it. The application, its views, its migrations and the
     * front controller travel: they are the project.
     */
    foreach (['/tests', '/docs', '/.github'] as $directory) {
        $tests->assertTrue((bool) preg_match('#^' . preg_quote($directory, '#') . '\s+export-ignore#m', $attributes));
    }

    foreach (['/app', '/public', '/database', '/lang', '/server.php', '/.env-example'] as $shipped) {
        $tests->assertSame(0, preg_match('#^' . preg_quote($shipped, '#') . '\s+export-ignore#m', $attributes));
    }

    /*
     * tools/ used to be excluded whole, which made "SFCSS is generated from
     * sfcss.config.json" untrue for everybody who installed the framework: they
     * had the stylesheet and no way to build another. The builders travel; the
     * documentation parity check, which is about this repository's own three
     * languages, does not.
     */
    $tests->assertTrue((bool) preg_match('#^/tools/docs-parity\.php\s+export-ignore#m', $attributes));
    $tests->assertSame(0, preg_match('#^/tools\s+export-ignore#m', $attributes));
});

$tests->run('the framework reads no constant it did not define itself', function () use ($tests): void {
    /*
     * Every setting the framework consults has to survive being absent, because
     * an application that installs the package may never call Bootstrap::load()
     * — or may call it after something has already asked. A bare `defined()`
     * check is the whole contract, and this asserts nobody added a read without
     * one.
     */
    $unguarded = [];
    $names = 'APP_NAME|APP_VERSION|APP_ENV|APP_LOCALE|APP_LOCALES|APP_TIMEZONE'
        . '|LOG_CHANNEL|LOG_PATH|LOG_LEVEL'
        . '|SESSION_DRIVER|SESSION_LIFETIME|SESSION_ABSOLUTE_LIFETIME|SESSION_TABLE';

    $directory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/../src'));

    foreach ($directory as $file) {
        if ($file->getExtension() !== 'php'
            || in_array($file->getFilename(), ['Bootstrap.php', 'Config.php'], true)) {
            continue;
        }

        foreach (file($file->getPathname()) as $number => $line) {
            // Comments and docblocks name these constants while explaining them.
            $code = trim($line);

            if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '/*')) {
                continue;
            }

            /*
             * Two forms are safe: a defined() guard, and a read through
             * Config, which falls back to a default when the constant was
             * never defined. Anything else is a framework file assuming an
             * application set something up for it.
             */
            $guarded = str_contains($line, 'defined(') || str_contains($line, 'Config::');

            if (preg_match('/\b(' . $names . ')\b/', $line) === 1 && !$guarded) {
                $unguarded[] = basename($file->getPathname()) . ':' . ($number + 1);
            }
        }
    }

    $tests->assertSame([], $unguarded);
});

$tests->run('bootstrap registers the application paths without owning them', function () use ($tests): void {
    $base = sys_get_temp_dir() . '/sfphp-bootstrap-' . bin2hex(random_bytes(6));
    mkdir($base . '/views', 0777, true);
    file_put_contents($base . '/views/greeting.sfht', 'hello {{ $name }}');

    Bootstrap::load($base, ['env' => null, 'views' => 'views', 'lang' => null]);

    $tests->assertSame($base, Bootstrap::basePath());
    $tests->assertSame($base . DIRECTORY_SEPARATOR . 'views', Bootstrap::basePath('views'));

    // An absolute path is left alone rather than joined to the root twice.
    $tests->assertSame('/etc/sfphp', Bootstrap::basePath('/etc/sfphp'));

    /*
     * The view layer used to reach into "../app/resources/views" from inside
     * src/, which only worked while the framework and the application were the
     * same checkout.
     */
    $tests->assertSame('hello Ana', View::make('greeting', ['name' => 'Ana']));

    // Put this checkout back, for anything running after.
    Bootstrap::load(dirname(dirname(__DIR__)), ['env' => null]);

    // Whole, because rendering may leave a compiled copy beside the view.
    exec('rm -rf ' . escapeshellarg($base));
});

$tests->run('the console finds the project autoloader, not its own', function () use ($tests): void {
    /*
     * Found by installing the package into a throwaway project. A path
     * repository copies the working tree, so the package arrived carrying the
     * framework's own vendor/ — and the binary tried that one first, loaded an
     * autoloader built for a different checkout, and died on a dev file the
     * package does not even ship.
     *
     * Composer's binary proxy already publishes the right answer, so the fix is
     * to ask it, and to put the local checkout last among the guesses.
     */
    $binary = (string) file_get_contents(dirname(__DIR__) . '/../sfphp');

    $composerGlobal = strpos($binary, "_composer_autoload_path");
    $tests->assertTrue($composerGlobal !== false);

    $positions = [];

    foreach ([
        'installed_sibling' => "__DIR__ . '/../../autoload.php'",
        'installed_bin' => "__DIR__ . '/../autoload.php'",
        'local_checkout' => "__DIR__ . '/vendor/autoload.php'",
    ] as $name => $needle) {
        $at = strpos($binary, $needle);
        $tests->assertTrue($at !== false);
        $positions[$name] = $at;
    }

    // Composer's own answer is consulted before any guess.
    $tests->assertTrue($composerGlobal < $positions['installed_sibling']);

    // And the checkout is the last guess, never the first.
    $tests->assertTrue($positions['local_checkout'] > $positions['installed_sibling']);
    $tests->assertTrue($positions['local_checkout'] > $positions['installed_bin']);

    // The package must not carry a vendor/ of its own into a consumer.
    $attributes = (string) file_get_contents(dirname(__DIR__) . '/../.gitattributes');
    $tests->assertTrue((bool) preg_match('#^/vendor\s+export-ignore#m', $attributes));
});

$tests->run('settings can be overridden without a separate process', function () use ($tests): void {
    /*
     * They were constants, and a constant cannot be unset — fine for an
     * application that decides once at boot, awkward for a test that wants to
     * know what happens with a different value.
     */
    $tests->assertSame(APP_ENV, Config::get('APP_ENV'));

    /*
     * A value nothing else would produce. This compared against the literal
     * 'production', which held only while the checkout had no .env — so
     * creating one turned a passing test red without anything being wrong.
     */
    $constant = APP_ENV;
    Config::set('APP_ENV', 'sentinel-environment');
    $tests->assertSame('sentinel-environment', Config::get('APP_ENV'));

    // The constant is untouched; the override sits in front of it.
    $tests->assertSame($constant, APP_ENV);

    Config::forget('APP_ENV');
    $tests->assertSame(APP_ENV, Config::get('APP_ENV'));

    // A setting nobody defined answers the default rather than failing.
    $tests->assertSame('fallback', Config::get('NO_SUCH_SETTING', 'fallback'));
    $tests->assertSame(false, Config::has('NO_SUCH_SETTING'));
    $tests->assertSame(7, Config::int('NO_SUCH_SETTING', 7));
    $tests->assertSame('', Config::string('NO_SUCH_SETTING'));

    // int() and string() coerce rather than trusting what is there.
    Config::set('A_NUMBER', '42');
    $tests->assertSame(42, Config::int('A_NUMBER'));
    Config::set('A_NUMBER', ['not scalar']);
    $tests->assertSame(9, Config::int('A_NUMBER', 9));
    Config::forget();
});

$tests->run('dotenv reads a file, and an absent one is not fatal when optional', function () use ($tests): void {
    $path = sys_get_temp_dir() . '/sfphp-env-' . bin2hex(random_bytes(6));

    file_put_contents($path, <<<'ENV'
        # a comment
        PLAIN=value
        QUOTED="with spaces"
        SINGLE='single quoted'
        EMPTY=
        WITH_EQUALS=a=b=c

        SPACED = padded
        ENV);

    Dotenv::loadEnv($path);

    $tests->assertSame('value', $_ENV['PLAIN']);
    $tests->assertSame('with spaces', $_ENV['QUOTED']);
    $tests->assertSame('single quoted', $_ENV['SINGLE']);
    $tests->assertSame('', $_ENV['EMPTY']);

    // A value containing "=" keeps all of it; only the first separator counts.
    $tests->assertSame('a=b=c', $_ENV['WITH_EQUALS']);
    $tests->assertSame('padded', $_ENV['SPACED']);

    // A comment is not a variable.
    $tests->assertSame(false, isset($_ENV['# a comment']));

    unlink($path);

    /*
     * Optional is why a fresh clone boots. A required .env made requiring the
     * autoloader fatal before the application could say what was missing.
     */
    Dotenv::loadEnv($path, required: false);
    $tests->assertThrows(fn () => Dotenv::loadEnv($path, required: true), RuntimeException::class);
});

$tests->run('the environment is read wherever PHP put it', function () use ($tests): void {
    /*
     * The framework read $_ENV alone, and variables_order decides whether PHP
     * fills it — php.ini-production leaves the E out. On such a host a
     * container started with -e DB_HOST=... passed a value nothing could see,
     * and the failure was a default being used silently.
     */
    $key = 'SFPHP_ENV_PROBE_' . bin2hex(random_bytes(4));

    $tests->assertSame(false, Env::has($key));
    $tests->assertSame('fallback', Env::get($key, 'fallback'));

    putenv($key . '=from-getenv');
    $tests->assertSame(true, Env::has($key));
    $tests->assertSame('from-getenv', Env::get($key));

    // $_ENV wins, being the nearest source.
    $_ENV[$key] = 'from-env-superglobal';
    $tests->assertSame('from-env-superglobal', Env::get($key));

    // An environment variable is always a string, so the words everybody
    // writes for nothing have to mean nothing.
    $_ENV[$key] = 'null';
    $tests->assertSame(null, Env::get($key));
    $_ENV[$key] = 'false';
    $tests->assertSame('', Env::get($key));

    unset($_ENV[$key]);
    putenv($key);
});

$tests->run('the framework ships its stylesheet and script, and can publish them', function () use ($tests): void {
    /*
     * SFCSS and SFJS are tools the framework ships, not files of the example
     * application, so they live where a composer require can reach them.
     */
    foreach (Assets::files() as $relative) {
        $tests->assertSame(true, is_file(Assets::path() . '/' . $relative));
    }

    $tests->assertSame(true, str_contains(Assets::css(), '.card'));
    $tests->assertSame(true, str_contains(Assets::js(), 'function') || Assets::js() !== '');

    $target = sys_get_temp_dir() . '/sfphp-assets-' . bin2hex(random_bytes(4));

    try {
        $written = Assets::publish($target)['written'];
        $tests->assertSame(Assets::files(), $written);
        $tests->assertSame(true, is_file($target . '/css/sfcss.min.css'));

        // Publishing twice copies nothing: reporting work that did not happen
        // is how a command stops being believed.
        $tests->assertSame([], Assets::publish($target)['written']);
        $tests->assertSame(Assets::files(), Assets::publish($target, true)['written']);

        /*
         * A stylesheet somebody built from their own config lives here. The
         * next composer install runs publish, and overwriting it would throw
         * their palette away silently — the worst way to lose work.
         */
        file_put_contents($target . '/css/sfcss.css', '/* mine */');
        $again = Assets::publish($target);

        $tests->assertSame(['css/sfcss.css'], $again['kept']);
        $tests->assertSame('/* mine */', file_get_contents($target . '/css/sfcss.css'));

        // Asking for it explicitly does replace it.
        $tests->assertSame(true, in_array('css/sfcss.css', Assets::publish($target, true)['written'], true));

        /*
         * Copying means the same bytes exist twice: once in the package and
         * once under a document root a browser can reach. Where symbolic links
         * work, that can be one file instead — which is the answer to why there
         * appear to be two.
         */
        $tests->assertThrows(fn () => Assets::link($target), RuntimeException::class);

        $tests->assertSame(['css', 'js'], Assets::link($target, true));
        $tests->assertSame(true, is_link($target . '/css'));
        $tests->assertSame(
            md5_file(Assets::path() . '/css/sfcss.min.css'),
            md5_file($target . '/css/sfcss.min.css')
        );

        // Linking twice is not an error and not work.
        $tests->assertSame([], Assets::link($target));
    } finally {
        foreach (['css', 'js'] as $directory) {
            if (is_link($target . '/' . $directory)) {
                @unlink($target . '/' . $directory);
            }
        }

        foreach (Assets::files() as $relative) {
            @unlink($target . '/' . $relative);
        }

        @rmdir($target . '/css');
        @rmdir($target . '/js');
        @rmdir($target);
    }
});

$tests->run('the published package is a project that runs out of the box', function () use ($tests): void {
    /*
     * What somebody receives is the git archive, which honours the
     * export-ignore rules in .gitattributes — not what is in the repository.
     * The two drift silently: a directory added here appears in every created
     * project until somebody notices, and a rule that stops matching removes
     * something the project needs to run.
     */
    $root = dirname(dirname(__DIR__));

    if (!is_dir($root . '/.git')) {
        // An exported copy has no history to archive. Nothing to check.
        return;
    }

    /*
     * The index rather than HEAD. What gets published is a commit, but running
     * against HEAD means a shipped file reads as missing for as long as it is
     * staged and not yet committed — the test would be red during exactly the
     * change it exists to check.
     */
    $tree = trim((string) shell_exec('git -C ' . escapeshellarg($root) . ' write-tree 2>/dev/null'));
    $ref = $tree === '' ? 'HEAD' : $tree;

    $command = 'git -C ' . escapeshellarg($root) . ' archive --format=tar ' . escapeshellarg($ref)
        . ' 2>/dev/null | tar -t 2>/dev/null';
    $listing = shell_exec($command);

    if (!is_string($listing) || trim($listing) === '') {
        // No git or no tar on this machine; the CI job has both.
        return;
    }

    $entries = array_filter(explode("\n", trim($listing)));
    $top = array_values(array_unique(array_map(
        static fn (string $path): string => explode('/', $path)[0],
        $entries
    )));
    sort($top);

    $tests->assertSame(
        [
            /*
             * The editor settings travel too. Language associations for .phpx
             * and .sfht are the project's rather than a person's — without them
             * a contributor opens a component and sees uncoloured text and
             * false syntax errors.
             */
            '.editorconfig', '.env-example', '.vscode', '.zed',
            'LICENSE', 'README.md', 'app', 'composer.json', 'database',
            'lang', 'public', 'resources', 'server.php', 'sfphp', 'src', 'tools',
        ],
        $top
    );

    // The pieces a consumer actually needs, named rather than assumed.
    foreach ([
        'src/Bootstrap.php',
        'src/helpers.php',
        'src/I18n/lang/en/http.php',
        'resources/assets/css/sfcss.min.css',
        'resources/assets/js/sfjs.js',
        'resources/assets/js/sfjs.min.js',
        // Without these, "generated from a config" is not true for anybody who
        // installed the framework rather than cloning it.
        'tools/css-builder/sfcss-builder.php',
        'tools/css-builder/sfcss.config.json',
        'tools/css-builder/sfcss-base.css',
        'tools/js-builder/sfjs-builder.php',
        // The application: what makes this a project rather than a framework
        // somebody still has to assemble.
        'public/index.php',
        'server.php',
        'src/routes.php',
        'app/controllers/MainController.php',
        'app/resources/views/home.sfht',
        'database/migrations/2026_09_21_000001_create_users_table.php',
    ] as $needed) {
        $tests->assertSame(true, in_array($needed, $entries, true));
    }

    // The console is run as a command, so it has to arrive executable.
    $mode = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -s sfphp 2>/dev/null');
    $tests->assertSame(true, is_string($mode) && str_starts_with(trim((string) $mode), '100755'));
});

$tests->run('upgrade replaces the framework and leaves the application alone', function () use ($tests): void {
    /*
     * Tested against a project of its own, for the same reason reset is: this
     * deletes and overwrites, and a test that can destroy the checkout it runs
     * in is not a test anybody should have to trust.
     *
     * What it has to get right is the division. A file under src/ is the
     * framework's and is replaced; a language file a project added sits beside
     * the framework's and stays; a controller is never touched; and
     * public/index.php is the project's even though releases change it, so the
     * new one is written next to it rather than over it.
     */
    $root = sys_get_temp_dir() . '/sfphp-upgrade-test-' . bin2hex(random_bytes(6));
    $source = sys_get_temp_dir() . '/sfphp-upgrade-src-' . bin2hex(random_bytes(6));

    foreach ([$root, $source] as $directory) {
        foreach (['vendor', 'src/Console', 'app/controllers', 'lang', 'public', 'resources', 'tools'] as $part) {
            mkdir($directory . '/' . $part, 0755, true);
        }
    }

    // The project: an autoloader shim and a copy of the binary, as reset does.
    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require ' . var_export(dirname(dirname(__DIR__)) . '/vendor/autoload.php', true) . ';'
    );
    copy(dirname(dirname(__DIR__)) . '/sfphp', $root . '/sfphp');
    chmod($root . '/sfphp', 0755);

    file_put_contents($root . '/src/Bootstrap.php', '<?php // the old framework');
    file_put_contents($root . '/src/Leftover.php', '<?php // a file the new release removed');
    file_put_contents($root . '/app/controllers/MineController.php', '<?php // mine');
    file_put_contents($root . '/lang/mine.json', '{"mine": true}');
    file_put_contents($root . '/lang/en.json', '{"old": true}');
    mkdir($root . '/tools/css-builder', 0755, true);
    file_put_contents($root . '/tools/css-builder/sfcss.config.json', '{"mine": true}');
    file_put_contents($root . '/public/index.php', '<?php // my front controller');
    file_put_contents($root . '/server.php', '<?php // old');

    // The release being upgraded to.
    file_put_contents($source . '/src/Bootstrap.php', '<?php // the new framework');
    file_put_contents($source . '/sfphp', '#!/usr/bin/env php' . PHP_EOL . '<?php // new binary');
    file_put_contents($source . '/server.php', '<?php // new');
    file_put_contents($source . '/lang/en.json', '{"new": true}');
    mkdir($source . '/tools/css-builder', 0755, true);
    file_put_contents($source . '/tools/css-builder/sfcss.config.json', '{"theirs": true}');
    file_put_contents($source . '/public/index.php', '<?php // the new front controller');

    $binary = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/sfphp');
    $from = ' --from=' . escapeshellarg($source);

    try {
        // A dry run says what it would do and does none of it.
        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' --dry-run 2>&1', $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(str_contains(implode("\n", $output), 'nothing was changed'));
        $tests->assertSame('<?php // the old framework', file_get_contents($root . '/src/Bootstrap.php'));

        // With no terminal to answer at, it refuses rather than proceeding.
        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' < /dev/null 2>&1', $output, $status);

        $tests->assertSame(1, $status);

        $output = [];
        $status = 0;
        exec($binary . ' upgrade' . $from . ' --force 2>&1', $output, $status);
        clearstatcache(true);

        $tests->assertSame(0, $status);

        // The framework is the new one, and what the release dropped is gone.
        $tests->assertSame('<?php // the new framework', file_get_contents($root . '/src/Bootstrap.php'));
        $tests->assertSame(false, is_file($root . '/src/Leftover.php'));
        $tests->assertSame('<?php // new', file_get_contents($root . '/server.php'));

        // The application is untouched.
        $tests->assertSame('<?php // mine', file_get_contents($root . '/app/controllers/MineController.php'));

        // A merged directory keeps what is only yours and takes what is theirs.
        $tests->assertSame('{"mine": true}', file_get_contents($root . '/lang/mine.json'));
        $tests->assertSame('{"new": true}', file_get_contents($root . '/lang/en.json'));

        // The front controller is yours; the new one arrives beside it.
        $tests->assertSame('<?php // my front controller', file_get_contents($root . '/public/index.php'));
        $tests->assertSame('<?php // the new front controller', file_get_contents($root . '/public/index.php.new'));

        /*
         * The SFCSS configuration is documented as yours to edit and sits
         * inside a merged directory, where it was being overwritten by the
         * release's copy: a project that had customised its palette lost it.
         */
        $tests->assertSame('{"mine": true}', file_get_contents($root . '/tools/css-builder/sfcss.config.json'));
        $tests->assertSame('{"theirs": true}', file_get_contents($root . '/tools/css-builder/sfcss.config.json.new'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root) . ' ' . escapeshellarg($source) . ' 2>/dev/null');
    }
});

$tests->run('reset removes the example application and refuses to do it in silence', function () use ($tests): void {
    /*
     * This deletes a project's application, so it is tested against a project
     * of its own: a directory with an autoloader shim and a copy of the binary,
     * which is what the command reads the root from. Pointing it at anything
     * else would be a test that can destroy the thing it is testing.
     */
    $root = sys_get_temp_dir() . '/sfphp-reset-' . bin2hex(random_bytes(6));

    foreach ([
        'vendor', 'src', 'app/config', 'app/components/page', 'app/controllers',
        'app/models', 'app/Jobs', 'app/resources/views/partials', 'app/routes',
        'database/seeders', 'database/factories', 'database/migrations',
    ] as $directory) {
        mkdir($root . '/' . $directory, 0755, true);
    }

    file_put_contents(
        $root . '/vendor/autoload.php',
        '<?php require ' . var_export(dirname(dirname(__DIR__)) . '/vendor/autoload.php', true) . ';'
    );
    copy(dirname(dirname(__DIR__)) . '/sfphp', $root . '/sfphp');
    chmod($root . '/sfphp', 0755);

    $removed = [
        'app/components/page/Card.phpx',
        'app/controllers/MainController.php',
        'app/models/User.php',
        'app/Jobs/SendEmailJob.php',
        'app/resources/views/home.sfht',
        'app/resources/views/partials/header.sfht',
        'database/seeders/DatabaseSeeder.php',
        'database/factories/UserFactory.php',
        // A migration the project wrote goes with the rest of what it wrote.
        'database/migrations/2026_10_01_000001_create_posts_table.php',
    ];

    $kept = [
        'app/config/config.php',
        'database/migrations/2026_01_01_000001_create_users_table.php',
        'database/migrations/2026_01_01_000002_create_sessions_table.php',
        // A .gitkeep exists to hold an empty directory, which is what is left.
        'database/migrations/.gitkeep',
    ];

    foreach ([...$removed, ...$kept] as $file) {
        file_put_contents($root . '/' . $file, '<?php // example');
    }

    file_put_contents($root . '/app/routes/web.php', "<?php\n\nRouter::get('/', [MainController::class, 'index']);\n");
    file_put_contents($root . '/app/routes/api.php', "<?php\n\n// API routes\n");

    $binary = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/sfphp');

    try {
        /*
         * With no terminal to answer at, it has to refuse. A command that
         * deletes an application must not proceed on silence, which is exactly
         * what a pipe or a CI job gives it.
         */
        $output = [];
        $status = 0;
        exec($binary . ' reset < /dev/null 2>&1', $output, $status);

        $tests->assertSame(1, $status);
        $tests->assertTrue(is_file($root . '/app/controllers/MainController.php'));

        // It still says what it would have done, so the warning is readable.
        $printed = implode("\n", $output);
        $tests->assertTrue(str_contains($printed, 'cannot be undone'));

        $output = [];
        $status = 0;
        exec($binary . ' reset --force 2>&1', $output, $status);

        /*
         * is_file() above filled the stat cache, so without this the file that
         * was checked before the deletion still reports as present — a test
         * that fails while the command is correct.
         */
        clearstatcache(true);

        $tests->assertSame(0, $status);

        foreach ($removed as $file) {
            $tests->assertSame(false, is_file($root . '/' . $file));
        }

        // The directories stay, because they are where the next thing goes.
        $tests->assertTrue(is_dir($root . '/app/controllers'));
        $tests->assertTrue(is_dir($root . '/app/resources/views'));

        /*
         * The framework's own migrations survive. The users and sessions
         * tables are what the authentication guard and the database session
         * driver are written against, and a project that lost them would find
         * out at a login. A migration the project wrote is the project's, and
         * goes with the rest of it.
         */
        foreach ($kept as $file) {
            $tests->assertTrue(is_file($root . '/' . $file));
        }

        /*
         * The routes file is rewritten, or the application boots into a
         * controller that is no longer there. This read src/routes.php, which
         * the project does not have: it got an empty string, which never
         * contains "MainController", so it passed whatever reset did.
         */
        $tests->assertTrue(is_file($root . '/app/routes/web.php'));
        $routes = (string) file_get_contents($root . '/app/routes/web.php');
        $tests->assertSame(false, str_contains($routes, 'MainController'));

        // Running it again has nothing left to do, and says so.
        $output = [];
        $status = 0;
        exec($binary . ' reset < /dev/null 2>&1', $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(str_contains(implode("\n", $output), 'already gone'));
    } finally {
        exec('rm -rf ' . escapeshellarg($root) . ' 2>/dev/null');
    }
});

$tests->run('generators write into the project that ran them', function () use ($tests): void {
    /*
     * The namespace was the literal string "SfphpProject\app", which is this
     * repository's example application. Every generator therefore wrote a class
     * into the framework's own namespace: unusable in any project that
     * installed the framework, because nothing there could autoload it — and
     * make:controller extended a base class the package does not even ship.
     */
    $project = sys_get_temp_dir() . '/sfphp-generators-' . bin2hex(random_bytes(4));
    mkdir($project, 0755, true);

    try {
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\Shop\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        $generator = new SfphpProject\src\Console\Generators\ControllerGenerator($project);
        $path = $generator->generate('Post');

        // The project's prefix, and the directory PSR-4 expects for it.
        $tests->assertSame(true, str_ends_with($path, 'app/Controllers/PostController.php'));

        $source = (string) file_get_contents($path);
        $tests->assertSame(true, str_contains($source, 'namespace Acme\\Shop\\Controllers;'));

        /*
         * Nothing from the example application, which a consumer never
         * receives. Extending is fine now that the base class is in the
         * package, and that is the whole point of where it lives.
         */
        $tests->assertSame(false, str_contains($source, 'SfphpProject\\app'));
        $tests->assertSame(false, str_contains($source, 'extends'));
        $tests->assertSame(true, str_contains($source, 'Response::sfht('));

        // And it is a real action: a Response, not a string.
        $tests->assertSame(true, str_contains($source, 'public function index(Request $request): Response'));

        $status = 0;
        $output = [];
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        // A project that declares nothing gets App\, which is what init writes.
        file_put_contents($project . '/composer.json', '{}');
        $bare = new SfphpProject\src\Console\Generators\ControllerGenerator($project);
        $tests->assertSame(true, str_contains((string) file_get_contents($bare->generate('Bare')), 'namespace App\\Controllers;'));
    } finally {
        foreach (glob($project . '/app/Controllers/*.php') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($project . '/composer.json');
        @rmdir($project . '/app/Controllers');
        @rmdir($project . '/app');
        @rmdir($project);
    }
});

$tests->run('the console finds a project laid out like a project, not like this repository', function () use ($tests): void {
    /*
     * Both of these were found only by running the commands from an installed
     * project. `routes` looked for src/routes.php, which is this repository's
     * layout and nobody else's, so it failed everywhere it was installed. And
     * the generators capitalised every directory segment for a foreign project,
     * which sent a seeder to database/Seeders while `db:seed` kept reading
     * database/seeders.
     */
    $source = (string) file_get_contents(dirname(__DIR__) . '/../src/Console/Application.php');

    $tests->assertSame(false, str_contains($source, "rootPath() . '/src/routes.php'"));
    $tests->assertSame(true, str_contains($source, "projectPath('app/routes/web.php')"));
    $tests->assertSame(true, str_contains($source, "projectPath('app/routes/api.php')"));

    $project = sys_get_temp_dir() . '/sfphp-paths-' . bin2hex(random_bytes(4));
    mkdir($project, 0755, true);

    try {
        file_put_contents($project . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\Shop\\' => 'app/']],
        ], JSON_THROW_ON_ERROR));

        // app/ follows the project's PSR-4 prefix, so its spelling follows too.
        $controller = (new SfphpProject\src\Console\Generators\ControllerGenerator($project))->generate('Post');
        $tests->assertSame(true, str_ends_with($controller, 'app/Controllers/PostController.php'));

        /*
         * database/ does not: those paths are read back by db:seed and by the
         * factory loader, at names this framework decides.
         */
        $seeder = (new SfphpProject\src\Console\Generators\SeederGenerator($project))->generate('Post');
        $factory = (new SfphpProject\src\Console\Generators\FactoryGenerator($project))->generate('Post');

        $tests->assertSame(true, str_contains($seeder, '/database/seeders/'));
        $tests->assertSame(true, str_contains($factory, '/database/factories/'));

        // And they return a path, like every other generator, rather than a
        // sentence the command then prints inside its own sentence.
        $tests->assertSame(true, is_file($seeder));
        $tests->assertSame(true, is_file($factory));
    } finally {
        foreach (['app/Controllers', 'database/seeders', 'database/factories'] as $directory) {
            foreach (glob($project . '/' . $directory . '/*') ?: [] as $file) {
                @unlink($file);
            }
        }

        @unlink($project . '/composer.json');

        foreach (['app/Controllers', 'app', 'database/seeders', 'database/factories', 'database'] as $directory) {
            @rmdir($project . '/' . $directory);
        }

        @rmdir($project);
    }
});

$tests->run('the development branch declares which release it is heading for', function () use ($tests): void {
    /*
     * Without a branch alias, Packagist offers the default branch only as
     * dev-master, so nobody can depend on the next minor before it is tagged
     * and a caret constraint matches nothing at all. It is also the one place
     * that says out loud which version this branch becomes.
     */
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/../composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $alias = $composer['extra']['branch-alias']['dev-master'] ?? null;

    $tests->assertTrue(is_string($alias));
    $tests->assertSame(1, preg_match('/^\d+\.\d+\.x-dev$/', (string) $alias));
});

$tests->run('the console reports the version it actually is', function () use ($tests): void {
    /*
     * This was a literal in the line the command prints, so every release
     * depended on somebody remembering to edit a string — and the release where
     * they forget is the one whose console claims to be the previous version.
     * Composer's InstalledVersions is generated into the autoloader rather than
     * required as a package, so asking it costs no dependency.
     */
    $composer = json_decode((string) file_get_contents(dirname(__DIR__) . '/../composer.json'), true);

    // The constant upgrade replaces, and composer.json, name the same release.
    $tests->assertSame($composer['version'], SfphpProject\src\Console\Application::version());
    $tests->assertSame($composer['version'], \SfphpProject\src\Sfphp::VERSION);
});

$tests->run('every command the console answers is in help and list, and nothing else is', function () use ($tests): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/../src/Console/Application.php');
    $start = strpos($source, 'return match ($command) {');
    $block = substr($source, $start, strpos($source, 'default =>', $start) - $start);
    preg_match_all("/'([a-z:-]+)'(?:, '[^']+')* =>/", $block, $matches);

    $dispatched = array_values(array_filter($matches[1], static fn (string $name): bool => !str_starts_with($name, '-')));
    $listed = Application::commandNames();
    sort($dispatched);
    sort($listed);

    $tests->assertSame($dispatched, $listed);
});

$tests->run('a generator never overwrites, trims the suffix it adds, and a generated test runs', function () use ($tests): void {
    $root = sys_get_temp_dir() . '/sfphp-gen-' . bin2hex(random_bytes(4));
    mkdir($root, 0777, true);
    file_put_contents($root . '/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]]));

    try {
        $controller = new \SfphpProject\src\Console\Generators\ControllerGenerator($root);
        $file = $controller->generate('productController');
        $tests->assertTrue(str_ends_with($file, '/ProductController.php'));

        /*
         * Only the controller: the page is make:sfht's or make:phpx's to write.
         * And its action answers on its own, rather than rendering a template
         * that does not exist and failing the first request.
         */
        $tests->assertSame(false, is_dir($root . '/app/resources/views'));
        $tests->assertSame(false, is_dir($root . '/app/components'));
        $tests->assertTrue(str_contains((string) file_get_contents($file), "return Response::html('<h1>Product</h1>');"));

        file_put_contents($file, "<?php // mine\n");
        $tests->assertThrows(fn () => (new \SfphpProject\src\Console\Generators\ControllerGenerator($root))->generate('Product'), \SfphpProject\src\Console\Generators\GeneratorFileExists::class);
        $tests->assertSame("<?php // mine\n", file_get_contents($file));

        (new \SfphpProject\src\Console\Generators\ControllerGenerator($root, true))->generate('Product');
        $tests->assertTrue(str_contains((string) file_get_contents($file), 'final class ProductController'));

        $test = (new \SfphpProject\src\Console\Generators\TestGenerator($root))->generate('PostTest');
        $tests->assertTrue(str_ends_with($test, '/tests/PostTest.php'));

        $policy = (string) file_get_contents((new \SfphpProject\src\Console\Generators\PolicyGenerator($root))->generate('PostPolicy'));
        $tests->assertTrue(str_contains($policy, 'final class PostPolicy'));
        $tests->assertTrue(str_contains($policy, '?Authenticatable $user'));
        $tests->assertSame(false, str_contains($policy, 'return true'));

        $seeder = (new \SfphpProject\src\Console\Generators\SeederGenerator($root))->generate('Product');
        $tests->assertTrue(str_ends_with($seeder, '/database/seeders/ProductSeeder.php'));

        $lines = [];
        $passed = (new \SfphpProject\src\Testing\Runner())->run($root . '/tests', null, function (string $line) use (&$lines): void { $lines[] = $line; });
        $tests->assertTrue($passed);
        $tests->assertTrue(in_array('PASS Tests\\PostTest::testExample', $lines, true));
    } finally {
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$tests->run('make:controller refuses the view options it no longer has, and says what to use', function () use ($tests): void {
    /*
     * --template picked between an .sfht and a .phpx, and ignored in silence a
     * value it did not know. The controller writes no page now; an option
     * that asks for one is an error that names the command that does.
     */
    $makeController = new ReflectionMethod(Application::class, 'makeController');

    foreach (['--no-view', '--template=phpx', '--template=blade'] as $option) {
        try {
            // Refused before anything is written.
            $makeController->invoke(new Application(['sfphp']), ['productController', $option]);
            $tests->assertSame('an exception', 'none for ' . $option);
        } catch (InvalidArgumentException $e) {
            $tests->assertSame(true, str_contains($e->getMessage(), $option . ' is not an option'));
            $tests->assertSame(true, str_contains($e->getMessage(), 'make:sfht Product') && str_contains($e->getMessage(), 'make:phpx ProductPage'));
        }
    }
});

$tests->run('an option takes its value after = or after a space, and the value is not read as a name', function () use ($tests): void {
    $app = new Application(['sfphp']);
    $option = new ReflectionMethod($app, 'option');
    $positionals = new ReflectionMethod($app, 'positionals');

    $tests->assertSame('8001', $option->invoke($app, ['--port', '8001'], 'port'));
    $tests->assertSame('8001', $option->invoke($app, ['--port=8001'], 'port'));
    $tests->assertSame('My App', $option->invoke($app, ['--name', 'My App'], 'name'));
    $tests->assertSame(['create_posts', 'title:string'], $positionals->invoke($app, ['--path', 'db/m', 'create_posts', '--force', 'title:string']));
});

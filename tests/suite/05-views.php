<?php

/*
 * Views: SFHT templates, .phpx components and the page directives.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Container;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Http\Response;
use SfphpProject\src\Router;
use SfphpProject\src\Time;
use SfphpProject\src\View;
use SfphpProject\src\View\Phpx;
use SfphpProject\src\View\SfhtEngine;

$tests->run('views escape output and protect view names', function () use ($tests): void {
    $tests->assertSame('&lt;script&gt;', e('<script>'));
    $tests->assertSame('/assets/images/logo.png', asset('images/logo.png'));
    $tests->assertThrows(
        fn () => asset('../.env'),
        InvalidArgumentException::class
    );

    $output = View::makePartial('header', ['title' => '<script>']);
    $tests->assertTrue(str_contains($output, '&lt;script&gt;'));
});

$tests->run('sfht keeps literal text that is not syntax', function () use ($tests, $sfht): void {
    // "@300" used to be read as a directive, which erased the whole line.
    $tests->assertSame(
        '<link href="?family=Inter:wght@300;400">',
        $sfht('<link href="?family=Inter:wght@300;400">')
    );

    // Text sharing a line with a directive used to be dropped.
    $tests->assertSame('<p>Ola sim</p>', $sfht('<p>Ola @if($x)sim@endif</p>', ['x' => true]));

    // addslashes() does not escape "$", so page text was interpolated.
    $tests->assertSame('Total: $valor', $sfht('Total: $valor', ['valor' => 'LEAKED']));

    $tests->assertSame('a@b.com', $sfht('a@b.com'));
    $tests->assertSame('AB', $sfht('A{{-- hidden --}}B'));
});

$tests->run('sfht escapes output unless raw is asked for', function () use ($tests, $sfht): void {
    $tests->assertSame(
        '&lt;script&gt;alert(1)&lt;/script&gt;',
        $sfht('{{ $v }}', ['v' => '<script>alert(1)</script>'])
    );

    $tests->assertSame('<b>', $sfht('{!! $v !!}', ['v' => '<b>']));

    // Expressions are compiled as PHP, not quoted into a literal string.
    $tests->assertSame('3', $sfht('{{ count($items) }}', ['items' => [1, 2, 3]]));
    $tests->assertSame('HELL...', $sfht('{{ $s | upper | truncate(7) }}', ['s' => 'hello world']));
    $tests->assertSame('日本...', $sfht('{{ $s | truncate(5) }}', ['s' => '日本語テキスト']));
});

$tests->run('sfht resolves template inheritance', function () use ($tests, $sfhtDirectory, $sfhtEngine): void {
    mkdir($sfhtDirectory . '/layouts', 0755, true);
    file_put_contents(
        $sfhtDirectory . '/layouts/base.sfht',
        "<title>@block('title')Default@endblock</title><body>@block('content')empty@endblock</body>"
    );

    file_put_contents(
        $sfhtDirectory . '/child.sfht',
        "@extends('layouts/base')@block('title')Page@endblock@block('content')<p>Hi</p>@endblock"
    );
    $tests->assertSame(
        '<title>Page</title><body><p>Hi</p></body>',
        $sfhtEngine->render('child')
    );

    // A block the child leaves alone falls back to the layout's version.
    file_put_contents(
        $sfhtDirectory . '/partial-child.sfht',
        "@extends('layouts/base')@block('content')only content@endblock"
    );
    $tests->assertSame(
        '<title>Default</title><body>only content</body>',
        $sfhtEngine->render('partial-child')
    );

    $tests->assertSame(
        '<title>Default</title><body>empty</body>',
        $sfhtEngine->render('layouts/base')
    );
});

$tests->run('sfht reports unbalanced directives instead of failing silently', function () use ($tests, $sfht): void {
    $tests->assertThrows(fn () => $sfht('@if(true)no end'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@endif'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@foreach($a as $b)@endif', ['a' => []]), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('{{ $unclosed'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('{{ $x | nosuchfilter }}', ['x' => 1]), RuntimeException::class);
});

$tests->run('a template that arrives older than its cache is still recompiled', function () use ($tests): void {
    /*
     * Reported from a project installed with composer create-project: the
     * page rendered was the previous release's, while the file on disk was
     * already the new one. Extracting an archive writes the package's own
     * timestamps, so the updated template arrived *older* than a cache file
     * written minutes earlier — and a newer-than comparison called that cache
     * current. Time moving forward was never a safe thing to rely on.
     */
    $directory = sys_get_temp_dir() . '/sfphp-cache-age-' . bin2hex(random_bytes(6));
    mkdir($directory . '/views', 0755, true);

    $template = $directory . '/views/page.sfht';
    $engine = new SfhtEngine([$directory . '/views'], $directory . '/cache');

    try {
        file_put_contents($template, '<p>first</p>');
        $tests->assertSame('<p>first</p>', $engine->render('page'));

        // The new file, carrying an older timestamp, exactly as a dist does.
        file_put_contents($template, '<p>second</p>');
        touch($template, time() - 3600);
        clearstatcache(true, $template);

        $tests->assertSame('<p>second</p>', $engine->render('page'));

        // And the version nobody has any more does not pile up.
        $tests->assertSame(1, count(glob($directory . '/cache/*.php') ?: []));
    } finally {
        foreach (glob($directory . '/cache/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($template);
        @rmdir($directory . '/cache');
        @rmdir($directory . '/views');
        @rmdir($directory);
    }
});

$tests->run('sfht compiles to an includable file rather than eval', function () use ($tests, $sfht, $sfhtDirectory): void {
    $sfht('<p>{{ $a }}</p>', ['a' => 1]);

    $compiled = glob($sfhtDirectory . '/cache/*.php');
    $tests->assertTrue($compiled !== [] && $compiled !== false);
    $tests->assertTrue(str_starts_with(file_get_contents($compiled[0]), '<?php'));
});

$tests->run('phpx compiles markup that lives inside a function', function () use ($tests): void {
    /*
     * The whole point of the compiler in one source: a region opened with
     * sfht( and closed by its matching parenthesis, with a parenthesis inside
     * an attribute that must not be mistaken for the closing one.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\Phpx;

    use SfphpProject\src\View\Sfht;

    function Badge(string $label): Sfht
    {
        return Sfht(
            <span class="badge" title="a)b">{{ $label }}</span>
        );
    }

    function Panel(string $text): Sfht
    {
        return Sfht(
            <div>
                @php $count = 2; @endphp
                {{ Badge('ok') }}
                {{ $text }}
                @if ($count === 2)<i>two</i>@endif
            </div>
        );
    }

    function notsfht(): string
    {
        return 'kept';
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        // What build --phpx checks, checked here too: the output has to be PHP.
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        require $file;

        $badge = SfphpTest\Phpx\Badge('<b>');
        $tests->assertSame('<span class="badge" title="a)b">&lt;b&gt;</span>', (string) $badge);

        $panel = (string) SfphpTest\Phpx\Panel('<script>');

        /*
         * Both interpolations are {{ }}. The component renders and the string
         * is escaped, because the type decides — this is the reason a
         * component returns Sfht instead of a string.
         */
        $tests->assertTrue(str_contains($panel, '<span class="badge" title="a)b">ok</span>'));
        $tests->assertTrue(str_contains($panel, '&lt;script&gt;'));
        $tests->assertSame(0, substr_count($panel, '<script>'));

        // @php and the directives work inside a region, as they do in a .sfht.
        $tests->assertTrue(str_contains($panel, '<i>two</i>'));

        // A function whose name merely ends in sfht( is not a markup region.
        $tests->assertSame('kept', SfphpTest\Phpx\notsfht());
    } finally {
        @unlink($file);
    }
});

$tests->run('phpx keeps the line numbers of the file the author wrote', function () use ($tests): void {
    /*
     * A region spans several lines and compiles to one expression, so without
     * padding every line after it would shift and php -l would name the wrong
     * one — which is the only thing standing between a syntax error and a
     * useless error message.
     */
    $source = "<?php\nfunction A(): \\SfphpProject\\src\\View\\Sfht\n{\n    return Sfht(\n        <p>one</p>\n        <p>two</p>\n    );\n}\n// marker\n";
    $compiled = (new Phpx())->compile($source);

    $tests->assertSame(substr_count($source, "\n"), substr_count($compiled, "\n"));
    $tests->assertSame(8, array_search('// marker', explode("\n", $compiled), true));
});

$tests->run('build --phpx mirrors the folders the components live in', function () use ($tests): void {
    /*
     * One component per file is the convention, so a page's parts live in a
     * folder of their own — and the build has to walk into it. It used to scan
     * the first level only, which silently compiled nothing for a project that
     * organised its components at all.
     */
    $root = sys_get_temp_dir() . '/sfphp-build-' . bin2hex(random_bytes(6));
    mkdir($root . '/src/page', 0755, true);

    file_put_contents(
        $root . '/src/Loose.phpx',
        "<?php\nfunction Loose(): \\SfphpProject\\src\\View\\Sfht\n{\n    return Sfht(<p>loose</p>);\n}\n"
    );
    file_put_contents(
        $root . '/src/page/Nested.phpx',
        "<?php\nfunction Nested(): \\SfphpProject\\src\\View\\Sfht\n{\n    return Sfht(<p>nested</p>);\n}\n"
    );

    try {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(dirname(__DIR__)) . '/sfphp')
            . ' build --phpx --from=' . escapeshellarg($root . '/src')
            . ' --to=' . escapeshellarg($root . '/out') . ' 2>&1';

        $output = [];
        $status = 0;
        exec($command, $output, $status);

        $tests->assertSame(0, $status);
        $tests->assertTrue(is_file($root . '/out/Loose.php'));
        $tests->assertTrue(is_file($root . '/out/page/Nested.php'));
    } finally {
        foreach (['/out/page/Nested.php', '/out/Loose.php', '/src/page/Nested.phpx', '/src/Loose.phpx'] as $file) {
            @unlink($root . $file);
        }

        foreach (['/out/page', '/out', '/src/page', '/src', ''] as $directory) {
            @rmdir($root . $directory);
        }
    }
});

$tests->run('phpx refuses a markup region that is never closed', function () use ($tests): void {
    $tests->assertThrows(
        fn () => (new Phpx())->compile("<?php\n\nfunction B() { return Sfht(\n  <p>x</p>\n; }\n"),
        RuntimeException::class
    );
});

$tests->run('sfht runs @php blocks as code, not as text', function () use ($tests, $sfht): void {
    // Listed as a directive but never implemented: the body was tokenized as
    // template text, so "@php $x = 1; @endphp" printed the statement.
    $tests->assertSame('2', trim($sfht('@php $x = 1 + 1; @endphp{{ $x }}')));
    $tests->assertThrows(fn () => $sfht('@php $x = 1;'), RuntimeException::class);
    $tests->assertThrows(fn () => $sfht('@endphp'), RuntimeException::class);
});

$tests->run('views can be rendered to a string without echoing', function () use ($tests): void {
    // Response needs a body it can carry; output that already escaped is no use.
    $rendered = View::makePartial('header', ['title' => '<script>']);

    $tests->assertTrue(str_contains($rendered, '&lt;script&gt;'));
    $tests->assertSame(false, str_contains($rendered, '<script>'));

    $tests->assertThrows(
        fn () => View::make('../../../etc/passwd'),
        InvalidArgumentException::class
    );
});

$tests->run('the page directives write SFCSS, SFJS, the plugins and each declared script once', function () use ($tests): void {
    /*
     * @script can sit in any component, however deep, because a page renders
     * from the top down and @sfjs comes last in the body. A component used
     * twice asks twice and gets one tag.
     */
    $root = sys_get_temp_dir() . '/sfphp-page-scripts-' . bin2hex(random_bytes(6));
    mkdir($root . '/public/assets/js/scripts', 0755, true);

    foreach (['plugins.js', 'plugins.min.js', 'scripts/home.min.js', 'scripts/chart.min.js'] as $built) {
        file_put_contents($root . '/public/assets/js/' . $built, '');
    }

    $log = $root . '/php.log';
    $previousLog = ini_set('error_log', $log);

    $source = <<<'PHPX'
    <?php

    namespace SfphpTest\Scripts;

    function Chart(): \SfphpProject\src\View\Sfht
    {
        return Sfht(
            <canvas></canvas>@script('chart')
        );
    }

    function Page(): \SfphpProject\src\View\Sfht
    {
        return Sfht(
            <html><head>@sfcss</head><body>
            @script('home')
            {{ Chart() }}{{ Chart() }}
            <p>write to me@sfjs.dev</p>
            @sfjs
            </body></html>
        );
    }
    PHPX;

    $file = $root . '/page.php';
    file_put_contents($file, (new Phpx())->compile($source));

    \SfphpProject\src\View\PageScripts::usePath($root);
    \SfphpProject\src\View\PageScripts::reset();

    try {
        require $file;

        $html = (string) \SfphpTest\Scripts\Page();

        $tests->assertSame(true, str_contains($html, '<link rel="stylesheet" href="/assets/css/sfcss.min.css'));
        $tests->assertSame(true, str_contains($html, 'me@sfjs.dev'));
        $tests->assertSame(false, str_contains($html, 'data-sf-warning'));

        preg_match_all('/<script src="\/assets\/js\/([^"?]+)/', $html, $matches);
        $tests->assertSame(['sfjs.min.js', 'plugins.min.js', 'scripts/home.min.js', 'scripts/chart.min.js'], $matches[1]);

        // A second page in the same request would put scripts after they were written.
        $tests->assertThrows(static fn () => \SfphpTest\Scripts\Page(), RuntimeException::class);

        \SfphpProject\src\View\PageScripts::reset();

        // The readable builds.
        $tests->assertSame(true, str_contains(\SfphpProject\src\View\PageScripts::sfcss('normal'), 'css/sfcss.css'));
        $readable = \SfphpProject\src\View\PageScripts::sfjs('normal');
        $tests->assertSame(true, str_contains($readable, 'js/sfjs.js') && str_contains($readable, 'js/plugins.js'));

        // A value that is neither: the minified file, and a warning in the page and in the log.
        \SfphpProject\src\View\PageScripts::reset();
        $wrong = \SfphpProject\src\View\PageScripts::sfjs('minified');
        $tests->assertSame(true, str_contains($wrong, 'js/sfjs.min.js'));
        $tests->assertSame(true, str_contains($wrong, 'data-sf-warning="@sfjs(&#039;minified&#039;) expects &#039;min&#039; or &#039;normal&#039;; the minified file was used."'));
        $tests->assertSame(true, str_contains((string) file_get_contents($log), "@sfjs('minified') expects"));

        // A script that was never built says so, instead of a silent 404.
        \SfphpProject\src\View\PageScripts::reset();
        \SfphpProject\src\View\PageScripts::script('ghost');
        $tests->assertSame(true, str_contains(\SfphpProject\src\View\PageScripts::sfjs(), 'has no public/assets/js/scripts/ghost.min.js'));

        // "home.js" is "home", and a path out of the folder is refused.
        \SfphpProject\src\View\PageScripts::reset();
        \SfphpProject\src\View\PageScripts::script('home.js');
        \SfphpProject\src\View\PageScripts::script('home');
        $tests->assertSame(1, substr_count(\SfphpProject\src\View\PageScripts::sfjs(), 'scripts/home.min.js'));
        $tests->assertThrows(static fn () => \SfphpProject\src\View\PageScripts::script('../secret'), RuntimeException::class);
    } finally {
        \SfphpProject\src\View\PageScripts::usePath(null);
        \SfphpProject\src\View\PageScripts::reset();
        ini_set('error_log', $previousLog === false ? '' : $previousLog);
        exec('rm -rf ' . escapeshellarg($root));
    }
});

$tests->run('a request starts with no scripts left over from the last one', function () use ($tests): void {
    /*
     * In a persistent worker the list outlives the request. Without the reset
     * in dispatch, the next visitor's page would refuse its first @script,
     * because the last page had already written its scripts.
     */
    $log = sys_get_temp_dir() . '/sfphp-reset-' . bin2hex(random_bytes(6)) . '.log';
    $previousLog = ini_set('error_log', $log);

    try {
        \SfphpProject\src\View\PageScripts::script('left-over');
        \SfphpProject\src\View\PageScripts::sfjs();

        (new Router(new Container()))->dispatch(Request::create('GET', '/sfphp-no-such-page'));

        \SfphpProject\src\View\PageScripts::script('next');
        $tests->assertSame(true, str_contains(\SfphpProject\src\View\PageScripts::sfjs(), 'scripts/next.min.js'));
    } finally {
        \SfphpProject\src\View\PageScripts::reset();
        ini_set('error_log', $previousLog === false ? '' : $previousLog);
        @unlink($log);
    }
});

$tests->run('a template answers with sfht(), a component with phpx()', function () use ($tests): void {
    $directory = sys_get_temp_dir() . '/sfphp-response-' . bin2hex(random_bytes(4));
    mkdir($directory);
    file_put_contents($directory . '/hello.sfht', '<p>Hello, {{ $name }}</p>');

    try {
        View::setPaths([$directory], $directory . '/cache');

        $page = Response::sfht('hello', ['name' => '<Ana>'], 201);
        $tests->assertSame(201, $page->status());
        $tests->assertSame('<p>Hello, &lt;Ana&gt;</p>', $page->body());
        $tests->assertSame(false, method_exists(Response::class, 'view'));

        $component = Response::phpx(new \SfphpProject\src\View\Sfht('<b>ok</b>'));
        $tests->assertSame('<b>ok</b>', $component->body());
        $tests->assertTrue(str_starts_with((string) $component->header('Content-Type'), 'text/html'));
    } finally {
        View::setPaths([]);
        array_map('unlink', glob($directory . '/cache/*') ?: []);
        @rmdir($directory . '/cache');
        array_map('unlink', glob($directory . '/*.sfht') ?: []);
        rmdir($directory);
    }
});

$tests->run('a phpx region is opened with Sfht(, the type it returns', function () use ($tests): void {
    $compiled = (new Phpx())->compile(
        "<?php\nfunction Hi(string \$n): \\SfphpProject\\src\\View\\Sfht\n{\n    return Sfht(<p>Hi {{ \$n }}</p>);\n}\n"
    );
    $tests->assertTrue(str_contains($compiled, 'new \\SfphpProject\\src\\View\\Sfht('));

    // The old opening is refused with the fix, not left to PHP as a syntax error.
    try {
        (new Phpx())->compile("<?php\nfunction Old() {\n    return sfht(<p>x</p>);\n}\n");
        $tests->assertTrue(false, 'sfht( should have been refused');
    } catch (RuntimeException $e) {
        $tests->assertTrue(str_contains($e->getMessage(), 'Line 3'));
        $tests->assertTrue(str_contains($e->getMessage(), 'Write Sfht('));
    }

    // Built by hand, called as a method, or named Response::sfht — none of
    // these is a markup region, and none is rewritten.
    $plain = "<?php\nfunction Manual() { return new Sfht('<b>x</b>'); }\n"
        . "function Page() { return \\SfphpProject\\src\\Http\\Response::sfht('home'); }\n"
        . "function Chain(\$o) { return \$o->Sfht('x'); }\n";
    $tests->assertSame($plain, (new Phpx())->compile($plain));
});

$tests->run('phpx ends a region by its markup, whatever the text inside holds', function () use ($tests): void {
    /*
     * The end of a region used to be found by counting parentheses and
     * stepping over quotes, as if the markup were PHP. The apostrophe in
     * "Don't" then opened a quote that never closed, and the ")" in "Step 1)"
     * closed the region in the middle of a paragraph. Text is text: only the
     * markup's own structure may end it.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\PhpxText;

    function Notes(string $step): \SfphpProject\src\View\Sfht
    {
        return Sfht(
            <section title="a)b" data-x='it"s'>
                <p>Don't panic</p>
                <p>Step 1) open the {{ $step === 'x)' ? "lid" : 'box' }}</p>
                <ul><li>one (1<li>two</ul>
                <br>
                <!-- a comment with ) and ' in it -->
                <script>if (a < b) { c('it\'s'); }</script>
                @if ($step !== ')')<i>{{ $step }}</i>@endif
            </section>
        );
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $status);
        $tests->assertSame(0, $status);

        require $file;

        $html = (string) SfphpTest\PhpxText\Notes('lid');
        $tests->assertTrue(str_contains($html, "<p>Don't panic</p>"));
        $tests->assertTrue(str_contains($html, '<p>Step 1) open the box</p>'));
        $tests->assertTrue(str_contains($html, "c('it\\'s');"));
        $tests->assertTrue(str_contains($html, '<i>lid</i>'));
        $tests->assertTrue(str_ends_with($html, '</section>'));
    } finally {
        @unlink($file);
    }

    // An element left open is named, because it is why the ) was never seen.
    try {
        (new Phpx())->compile("<?php\nfunction U() {\n    return Sfht(\n        <div><p>x</p>\n    );\n}\n");
        $tests->assertTrue(false);
    } catch (RuntimeException $exception) {
        $tests->assertTrue(str_contains($exception->getMessage(), '<div> on line 4 is still open'));
    }
});

$tests->run('phpx runs filters and refuses @include with the line of the .phpx', function () use ($tests): void {
    /*
     * A component is a function call with no template engine around it, so a
     * filter compiled to $__engine->filter() failed on null the first time it
     * ran, and @include did the same. Filters now apply directly; what cannot
     * work in a function is refused at build time, on the author's line.
     */
    $source = <<<'PHPX'
    <?php
    namespace SfphpTest\PhpxFilters;

    function Shout(string $name, array $items): \SfphpProject\src\View\Sfht
    {
        return Sfht(
            <b>{{ $name | upper }}</b><i>{{ $name | truncate(3, '…') }}</i><s>{{ $items | length }}</s>
        );
    }
    PHPX;

    $file = sys_get_temp_dir() . '/sfphp-phpx-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, (new Phpx())->compile($source));

    try {
        require $file;

        $tests->assertSame(
            '<b>ÁGUA &amp; SAL</b><i>ág…</i><s>2</s>',
            (string) SfphpTest\PhpxFilters\Shout('água & sal', ['a', 'b'])
        );
    } finally {
        @unlink($file);
    }

    $messageOf = static function (string $markup): string {
        try {
            (new Phpx())->compile("<?php\nfunction I() {\n    return Sfht(\n        <div>\n            {$markup}\n        </div>\n    );\n}\n");
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return '';
    };

    // Line 5 of the .phpx, not line 2 of the markup.
    $tests->assertTrue(str_contains($messageOf("@include('card')"), '@include on line 5 cannot be used in a .phpx component'));
    $tests->assertTrue(str_contains($messageOf('@extends(\'layout\')'), 'on line 5'));
    $tests->assertTrue(str_contains($messageOf('{{ $a | shout }}'), 'Unknown filter "shout" on line 5'));
    $tests->assertTrue(str_contains($messageOf('@if (true)'), 'Unclosed @if opened on line 5'));
});

$tests->run('phpx keeps the author\'s lines inside a region and after it', function () use ($tests): void {
    /*
     * Every {{ }} and directive used to compile to a statement of its own
     * line, so a region grew as it compiled and every line after it shifted
     * down: php -l named a line of the compiled file, not of the .phpx.
     */
    $source = implode("\n", [
        '<?php',                                                  // 1
        'function L(array $rows, string $a): \SfphpProject\src\View\Sfht',
        '{',
        '    return Sfht(',
        '        <ul>',                                           // 5
        '            {{-- a comment',
        '                 over two lines --}}',
        '            @foreach ($rows as $row)',
        '                <li>{{ $row }} {{ $a | upper }}</li>',
        '            @endforeach',                                // 10
        '            @php $n = 1; // counted',
        '            @endphp',
        '            @forelse ($rows as $r) {{ $r }} @empty none @endforelse',
        '            {{',
        '                $a',                                     // 15
        '            }}',
        '            <b>{{ $marker }}</b>',
        '        </ul>',
        '    );',
        '}',                                                      // 20
        '// after',
        '',
    ]);

    $compiled = explode("\n", (new Phpx())->compile($source));

    $tests->assertSame(substr_count($source, "\n"), count($compiled) - 1);
    $tests->assertSame(20, array_search('// after', $compiled, true));
    $tests->assertTrue(str_contains($compiled[8], '($row)'));
    $tests->assertTrue(str_contains($compiled[16], '($marker)'));
});

$tests->run('a template imports a component with @use and calls it by its bare name', function () use ($tests): void {
    /*
     * A compiled template runs in the global namespace and a component is a
     * namespaced function, so {{ Card() }} was "Call to undefined function".
     * @use is PHP's own import, hoisted to the top of the compiled file, so
     * it works even written inside @if or @block.
     */
    eval('namespace SfphpTest\UseComponents; function Card(string $t): \SfphpProject\src\View\Sfht { return new \SfphpProject\src\View\Sfht("<b>" . $t . "</b>"); }');

    $directory = sys_get_temp_dir() . '/sfphp-use-' . bin2hex(random_bytes(6));
    mkdir($directory . '/views', 0777, true);
    file_put_contents(
        $directory . '/views/page.sfht',
        "<p>mail someone@use.example</p>\n@if (true)\n@use(function SfphpTest\\UseComponents\\Card)\n@use('function SfphpTest\\UseComponents\\Card')\n{{ Card(\$title) }}\n@endif"
    );

    try {
        $engine = new SfhtEngine([$directory . '/views'], $directory . '/cache');
        $html = $engine->render('page', ['title' => 'Hi']);

        $tests->assertTrue(str_contains($html, '<b>Hi</b>'));
        $tests->assertTrue(str_contains($html, 'someone@use.example'));
        $tests->assertThrows(
            fn () => (new \SfphpProject\src\View\Compiler())->compile('@use(1 + 1)'),
            RuntimeException::class
        );
    } finally {
        exec('rm -rf ' . escapeshellarg($directory));
    }
});

$tests->run('sfht leaves e-mail addresses alone, lets default() cover a missing variable and nests forelse', function () use ($tests, $sfht): void {
    $tests->assertSame('Write to webmaster@php.net or me@if.io, hi@block.xyz', $sfht('Write to webmaster@php.net or me@if.io, hi@block.xyz'));
    $tests->assertSame('<p>Ola sim</p>', $sfht('<p>Ola @if($x)sim@endif</p>', ['x' => true]));
    $tests->assertSame('Type @if to start', $sfht('Type @@if to start'));
    $tests->assertSame('Anonymous', $sfht('{{ $name | default("Anonymous") }}'));
    $tests->assertSame('&lt;b&gt;', $sfht('{{ $x | escape }}', ['x' => '<b>']));
    $tests->assertSame('}}', $sfht("{{ \$x ? '}}' : 'no' }}", ['x' => true]));
    // Text in brackets after a directive that takes none is text.
    $tests->assertSame(' (maybe)', $sfht('@if(false)no@else (maybe)@endif'));
    $tests->assertSame(
        '[A:1][B: none]',
        $sfht("@forelse(\$outer as \$o)[{{ \$o['n'] }}:@forelse(\$o['items'] as \$i){{ \$i }}@empty none@endforelse]@empty OUTER@endforelse", ['outer' => [['n' => 'A', 'items' => [1]], ['n' => 'B', 'items' => []]]])
    );
});

$tests->run('phpx ignores Sfht( written in a comment or a string, and a component that throws leaves no buffer open', function () use ($tests): void {
    $source = <<<'PHPX'
        <?php
        // Sfht( is how a region opens
        /** Write Sfht( to start one. */
        function Hint(string $t): \SfphpProject\src\View\Sfht
        {
            $label = "use Sfht( to open";
            $other = 'or sfht( the old way';
            return Sfht(<p>{{ $t }} {{ $label }}</p>);
        }
        PHPX;

    $compiled = (new Phpx())->compile($source);
    $tests->assertSame(1, substr_count($compiled, 'get_defined_vars()'));

    eval('namespace SfphpTest\PhpxLiteral; ?>' . $compiled);
    $tests->assertSame('<p>hi use Sfht( to open</p>', (string) \SfphpTest\PhpxLiteral\Hint('hi'));

    eval('namespace SfphpTest\PhpxThrow; ?>' . (new Phpx())->compile('<?php function Boom(): \SfphpProject\src\View\Sfht { return Sfht(<p>{{ throw new \RuntimeException("x") }}</p>); }'));
    $level = ob_get_level();
    $tests->assertThrows(fn () => \SfphpTest\PhpxThrow\Boom(), RuntimeException::class);
    $tests->assertSame($level, ob_get_level());
});

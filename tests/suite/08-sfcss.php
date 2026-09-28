<?php

/*
 * SFCSS: the build and the stylesheet it produces.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Console\Application;
use SfphpProject\src\Assets;
use SfphpProject\src\Debug\HtmlDump;

$tests->run('the built stylesheet is css, not the builder log', function () use ($tests): void {
    /*
     * ./sfphp css:build captured the builder's stdout and wrote it over
     * sfcss.css. The builder writes both files itself and only prints a
     * summary, so every run replaced the stylesheet with two lines of log.
     */
    /*
     * resources/, which is where the builder writes and what the package
     * carries. This read public/assets, which is now a published copy and
     * gitignored — so the test passed on a working tree that had published and
     * failed on a fresh checkout, which is the wrong way round.
     */
    $stylesheet = dirname(dirname(__DIR__)) . '/resources/assets/css/sfcss.css';

    $tests->assertTrue(is_file($stylesheet));

    $head = file_get_contents($stylesheet, false, null, 0, 64);
    $tests->assertTrue(str_starts_with($head, '/* SFCSS'));
    $tests->assertTrue(filesize($stylesheet) > 10000);

    $source = file_get_contents(dirname(dirname(__DIR__)) . '/src/Console/Application.php');
    $tests->assertSame(false, str_contains($source, "file_put_contents(\$outputPath, \$css)"));
});

$tests->run('a project sfcss config holds only what it changes', function () use ($tests, $buildSfcss): void {
    /*
     * A project config used to have to be a full copy of the default one:
     * leaving "spacing" out to change one colour broke the build. It is merged
     * over the defaults now, so three lines are a complete config.
     */
    $build = $buildSfcss(['colors' => ['primary' => '#7c3aed']]);

    $tests->assertSame(0, $build['status']);
    $tests->assertTrue(str_contains($build['css'], '--primary: #7c3aed;'));
    $tests->assertTrue(str_contains($build['css'], '.p-3 { padding: 1rem; }'));
    $tests->assertTrue(str_contains($build['css'], '.text-blue-600 { color: #2563eb; }'));
});

$tests->run('sfcss picks readable text for every colour, and says when it cannot', function () use ($tests, $buildSfcss): void {
    /*
     * White on the default blue-500 was 3.7:1, below the 4.5:1 WCAG AA asks
     * for. The text colour on a colour is computed now: white where it reads,
     * dark where white would not.
     */
    $build = $buildSfcss(['colors' => ['primary' => '#3b82f6', 'warning' => '#facc15']]);

    $tests->assertTrue(str_contains($build['css'], '--primary-contrast: #111827;'));
    $tests->assertTrue(str_contains($build['css'], '--warning-contrast: #111827;'));
    $tests->assertSame('', trim($build['stderr']));

    // A mid-grey that neither white nor near-black reaches 4.5:1 on is reported.
    $grey = $buildSfcss(['colors' => ['primary' => '#777777'], 'contrast' => ['dark' => '#555555']]);
    $tests->assertTrue(str_contains($grey['stderr'], 'colors.primary'));
});

$tests->run('a colour added to the sfcss config gets every variant', function () use ($tests, $buildSfcss): void {
    // A new colour used to produce a custom property and nothing else.
    $css = $buildSfcss(['colors' => ['brand' => '#0f766e']])['css'];

    foreach (['.btn-brand {', '.btn-outline-brand {', '.badge-brand {', '.alert-brand {', '.text-brand {', '.bg-brand-subtle {', '--brand-emphasis:'] as $needle) {
        $tests->assertTrue(str_contains($css, $needle), "missing {$needle}");
    }
});

$tests->run('the sfcss utility map takes additions and removals from the config', function () use ($tests, $buildSfcss): void {
    $css = $buildSfcss(['utilities' => [
        'cursor' => false,
        'tab-size' => ['class' => 'tab', 'property' => 'tab-size', 'values' => ['2' => '2', '4' => '4'], 'responsive' => true],
    ]])['css'];

    $tests->assertSame(false, str_contains($css, '.cursor-pointer'));
    $tests->assertTrue(str_contains($css, '.tab-4 { tab-size: 4; }'));
    $tests->assertTrue(str_contains($css, '.md\:tab-4 { tab-size: 4; }'));
});

$tests->run('sfcss options prefix variables and switch features off', function () use ($tests, $buildSfcss): void {
    $css = $buildSfcss(['options' => [
        'prefix' => 'sf',
        'components' => false,
        'responsiveVariants' => false,
        'reducedMotion' => false,
    ]])['css'];

    $tests->assertTrue(str_contains($css, '--sf-primary:'));
    $tests->assertTrue(str_contains($css, 'var(--sf-surface)'));
    $tests->assertSame(false, str_contains($css, 'var(--primary)'));
    $tests->assertSame(false, str_contains($css, '.btn {'));
    $tests->assertSame(false, str_contains($css, '.md\:'));
    $tests->assertSame(false, str_contains($css, 'prefers-reduced-motion'));

    // The accessibility baseline is not a component and stays.
    $tests->assertTrue(str_contains($css, ':focus-visible'));

    // Role colours are utilities, not components, so they stay too.
    $tests->assertTrue(str_contains($css, '.text-primary {'));
});

$tests->run('an sfcss prefix leaves the anchor SFJS writes alone', function () use ($tests, $buildSfcss): void {
    // SFJS sets --sf-anchor on every popover it positions; renaming it to
    // --acme-sf-anchor left every dropdown and tooltip unanchored.
    $css = $buildSfcss(['options' => ['prefix' => 'acme']])['css'];

    $tests->assertTrue(str_contains($css, '--acme-primary:'));
    $tests->assertTrue(str_contains($css, 'var(--sf-anchor)'));
    $tests->assertSame(false, str_contains($css, '--acme-sf-anchor'));
});

$tests->run('sfcss rounded:false squares the radius utilities too', function () use ($tests, $buildSfcss): void {
    // The rounded-* classes used literal values, so turning rounding off
    // squared the components and left every rounded-lg on the page round.
    $css = $buildSfcss(['options' => ['rounded' => false]])['css'];

    $tests->assertTrue(str_contains($css, '--radius-lg: 0;'));
    $tests->assertTrue(str_contains($css, '.rounded-lg { border-radius: var(--radius-lg); }'));
    $tests->assertTrue(str_contains($css, '--radius-full: 9999px;'));
});

$tests->run('an sfcss palette class wins over the component it is written on', function () use ($tests): void {
    // class="card bg-blue-50" kept the card's own background because the
    // palette was emitted before the components.
    $css = file_get_contents(dirname(dirname(__DIR__)) . '/resources/assets/css/sfcss.css');

    $tests->assertTrue(strpos($css, '.card {') < strpos($css, '.bg-blue-50 {'));
    $tests->assertTrue(strpos($css, '.btn {') < strpos($css, '.hover\:bg-blue-700:hover {'));
});

$tests->run('sfcss spacing means the same with and without a breakpoint', function () use ($tests): void {
    /*
     * p-3 was 1rem and md:p-3 was 0.75rem: the base classes and the
     * breakpoint variants came from two different scales. One scale produces
     * both now.
     */
    $css = file_get_contents(dirname(dirname(__DIR__)) . '/resources/assets/css/sfcss.css');

    $tests->assertTrue(str_contains($css, '.p-3 { padding: 1rem; }'));
    $tests->assertTrue(str_contains($css, '.md\:p-3 { padding: 1rem; }'));
    $tests->assertSame(false, str_contains($css, '@media (max-width: 768px)'));
});

$tests->run('the dark theme changes nothing a page did not ask for', function () use ($tests): void {
    /*
     * This shipped applying prefers-color-scheme to :root directly, so a page
     * that had never asked for a dark theme got dark cards — while bg-blue-50
     * and text-slate-600, being fixed palette values, stayed as light as they
     * were. A light heading on a dark card is not a theme, it is a collision.
     */
    $css = Assets::css(false);

    // No bare prefers-color-scheme block: the media query only applies to a
    // root that opted in.
    $tests->assertSame(0, preg_match('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root\s*\{/', $css));

    $tests->assertSame(true, str_contains($css, ':root[data-theme="dark"]'));
    $tests->assertSame(true, str_contains($css, ':root[data-theme="auto"]'));

    /*
     * The light values sit on a bare :root, so a page that says nothing is
     * light — and the dark ones only ever appear under a [data-theme] selector.
     */
    $tests->assertSame(1, preg_match('/^:root \{[^}]*--surface: #ffffff/m', $css));

    // Every dark definition sits inside a data-theme block, never on its own.
    $parts = explode('--surface: #17181c', $css);

    for ($index = 1; $index < count($parts); $index++) {
        $tests->assertSame(true, str_contains(substr($parts[$index - 1], -400), 'data-theme'));
    }

    /*
     * The framework's own screens are the framework's pages, not somebody's, so
     * they do follow the reader's setting.
     */
    $tests->assertSame(true, str_contains(HtmlDump::render([1]), 'data-theme="auto"'));
});

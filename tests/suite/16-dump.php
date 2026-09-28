<?php

/*
 * dump() and the dump screen.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Debug\Dumper;
use SfphpProject\src\Debug\HtmlDump;
use SfphpProject\src\Debug\TextDump;
use SfphpProject\src\Http\Emitter;
use SfphpProject\src\Http\Response;

$tests->run('a dump describes a value without following it forever', function () use ($tests): void {
    $node = new class {
        public string $name = 'root';
        protected int $depth = 2;
        private array $tags = ['a', 'b'];
        public ?object $self = null;
        public int $uninitialised;
    };
    $node->self = $node;

    $described = Dumper::describe($node);

    $tests->assertSame('object', $described['type']);

    $by = [];

    foreach ($described['children'] as $child) {
        $by[$child['key']] = $child;
    }

    // Visibility is part of what a dump is for: a private property read as
    // public sends somebody looking in the wrong place.
    $tests->assertSame('public', $by['name']['visibility']);
    $tests->assertSame('protected', $by['depth']['visibility']);
    $tests->assertSame('private', $by['tags']['visibility']);

    // A value that points at itself is reported, not followed.
    $tests->assertSame(true, $by['self']['value']['circular'] ?? false);

    // A typed property with no value throws when read. That is a state worth
    // showing rather than an error worth propagating.
    $tests->assertSame('uninitialised', $by['uninitialised']['value']['type']);
});

$tests->run('a dump states what it had to cut', function () use ($tests): void {
    $long = str_repeat('a', Dumper::MAX_STRING + 50);
    $string = Dumper::describe($long);

    $tests->assertSame(true, $string['truncated']);
    $tests->assertSame(Dumper::MAX_STRING + 50, $string['length']);
    $tests->assertSame(Dumper::MAX_STRING, strlen($string['value']));

    // Depth is capped, and the cap is reported rather than silently flattened.
    $deep = 'bottom';

    for ($i = 0; $i < Dumper::MAX_DEPTH + 3; $i++) {
        $deep = [$deep];
    }

    $described = Dumper::describe($deep);

    for ($i = 0; $i < Dumper::MAX_DEPTH; $i++) {
        $described = $described['children'][0]['value'];
    }

    $tests->assertSame(true, $described['deep'] ?? false);

    // A string that is not valid UTF-8 is reported as bytes rather than being
    // put into an HTML page, where it would produce a blank screen.
    $tests->assertSame(true, Dumper::describe("\xff\xfe")['binary']);
});

$tests->run('the dump screen is SFCSS, escaped, and asks nothing of the network', function () use ($tests): void {
    $html = HtmlDump::render([['<script>alert(1)</script>' => "it's \"quoted\""]], [
        'file' => '/app/routes.php',
        'line' => 12,
    ]);

    // Escaped: a dump renders values an attacker may control.
    $tests->assertSame(false, str_contains($html, '<script>alert(1)</script>'));
    $tests->assertSame(true, str_contains($html, '&lt;script&gt;'));
    $tests->assertSame(true, str_contains($html, '&quot;quoted&quot;'));

    // SFCSS, inlined rather than linked: the screen has to render when the
    // application around it is what is broken.
    $tests->assertSame(true, str_contains($html, 'class="card mb-4"'));
    $tests->assertSame(true, str_contains($html, '.card-header'));
    $tests->assertSame(0, preg_match('#(src|href)=["\']https?://#', $html));
    $tests->assertSame(0, preg_match('#<link\b#', $html));

    // Where it was called from, because a dump you cannot locate is a riddle.
    $tests->assertSame(true, str_contains($html, 'routes.php:12'));
});

$tests->run('dump appends a fragment, and the stylesheet only once', function () use ($tests): void {
    /*
     * dump() writes into a response that is already being written. A second
     * <!DOCTYPE html> in the middle of a document is malformed, and repeating
     * ninety kilobytes of stylesheet for every call in a loop is its own
     * problem.
     */
    HtmlDump::forgetStylesheet();

    $first = HtmlDump::fragment([['a' => 1]], ['file' => '/app/x.php', 'line' => 3]);

    $tests->assertSame(false, str_contains($first, '<!DOCTYPE'));
    $tests->assertSame(false, str_contains($first, '<body'));
    $tests->assertSame(true, str_contains($first, 'sf-dump-fragment'));
    $tests->assertSame(true, str_contains($first, '<style>'));
    $tests->assertSame(true, str_contains($first, 'x.php:3'));

    $second = HtmlDump::fragment([['b' => 2]]);

    // The second one is cards and nothing else.
    $tests->assertSame(false, str_contains($second, '<style>'));
    $tests->assertSame(true, str_contains($second, 'sf-dump-fragment'));

    /*
     * Under a persistent runtime the flag would otherwise carry into the next
     * request and the second visitor would get an unstyled dump.
     */
    HtmlDump::forgetStylesheet();
    $tests->assertSame(true, str_contains(HtmlDump::fragment([1]), '<style>'));

    // dd()'s page is still a whole document.
    $tests->assertSame(true, str_contains(HtmlDump::render([1]), '<!DOCTYPE'));
});

$tests->run('a dump renders for a terminal too, without colour when redirected', function () use ($tests): void {
    $plain = TextDump::render([['a' => 1, 'b' => [true, null]]], null, false);

    $tests->assertSame(true, str_contains($plain, '"a" => 1'));
    $tests->assertSame(true, str_contains($plain, 'array ['));
    // No escape codes when colour is off: piped into a file they are noise.
    $tests->assertSame(false, str_contains($plain, "\033["));

    $coloured = TextDump::render([1], null, true);
    $tests->assertSame(true, str_contains($coloured, "\033["));
});

$tests->run('a dump made while the action runs is put into the page instead of breaking the response', function () use ($tests): void {
    \SfphpProject\src\Debug\PendingDumps::add('<pre class="sf-dump">X</pre>');

    ob_start();
    (new Emitter())->emit(Response::html('<html><body><p>page</p></body></html>'));
    $sent = (string) ob_get_clean();

    $tests->assertSame('<html><body><p>page</p><pre class="sf-dump">X</pre></body></html>', $sent);

    // A JSON body is never corrupted by a dump.
    \SfphpProject\src\Debug\PendingDumps::add('<pre>X</pre>');
    ob_start();
    (new Emitter())->emit(Response::json(['ok' => true]));
    $tests->assertSame('{"ok":true}', (string) ob_get_clean());
    $tests->assertSame([], \SfphpProject\src\Debug\PendingDumps::take());
});

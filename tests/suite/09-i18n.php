<?php

/*
 * Translations and locales.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\ErrorHandler;
use SfphpProject\src\Container;
use SfphpProject\src\Http\Middleware\SetLocale;
use SfphpProject\src\I18n\Translator;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Router;

$tests->run('translations resolve, fall back and interpolate', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('en');
        $tests->assertSame('404 - Page not found', __('http.not_found_title'));

        Translator::setLocale('pt-BR');
        $tests->assertSame('pt_BR', Translator::locale());   // normalizado
        $tests->assertSame('404 - Página não encontrada', __('http.not_found_title'));

        // A aplicação sobrescreve o catálogo do framework, chave a chave.
        $tests->assertSame('Bem-vindo, Ana!', __('app.welcome', ['name' => 'Ana']));

        /*
         * Uma chave sem tradução volta como está, para que a falta apareça
         * onde ela é, em vez de renderizar vazio.
         */
        $tests->assertSame('nao.existe.chave', __('nao.existe.chave'));
        $tests->assertSame(false, Translator::has('nao.existe.chave'));
        $tests->assertTrue(Translator::has('http.not_found_title'));

        // Um locale que a aplicação não tem cai no fallback.
        $tests->assertSame('404 - Page not found', __('http.not_found_title', [], 'de'));

        $tests->assertThrows(
            fn () => Translator::setLocale('não é um locale'),
            InvalidArgumentException::class
        );
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('plural forms are chosen by explicit range or by locale rule', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('pt_BR');
        $tests->assertSame('Nenhum item', trans_choice('app.items', 0));
        $tests->assertSame('Um item', trans_choice('app.items', 1));
        $tests->assertSame('5 itens', trans_choice('app.items', 5));

        Translator::setLocale('en');
        $tests->assertSame('No items', trans_choice('app.items', 0));
        $tests->assertSame('3 items', trans_choice('app.items', 3));

        /*
         * Ranges cover most languages but not all: Polish picks its form from
         * the last digits, so 22 and 12 differ although both exceed five.
         * Shipping an incomplete copy of the CLDR rules would be quietly
         * wrong, so the selector is a hook the application registers.
         */
        Translator::pluralizer('pl', function (int $count): int {
            if ($count === 1) {
                return 0;
            }

            $mod10 = $count % 10;
            $mod100 = $count % 100;

            return ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 1 : 2;
        });

        // O seletor é inspecionado diretamente: o que importa aqui é a regra,
        // não o catálogo que forneceria as formas.
        $reflection = new ReflectionMethod(Translator::class, 'selectPlural');
        $reflection->setAccessible(true);

        $tests->assertSame(0, $reflection->invoke(null, 'pl', 1));
        $tests->assertSame(1, $reflection->invoke(null, 'pl', 22));   // "pliki"
        $tests->assertSame(2, $reflection->invoke(null, 'pl', 12));   // "plików"
        $tests->assertSame(2, $reflection->invoke(null, 'pl', 5));

        // Sem seletor registrado, a regra é a do inglês.
        $tests->assertSame(0, $reflection->invoke(null, 'en', 1));
        $tests->assertSame(1, $reflection->invoke(null, 'en', 7));
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('the locale is negotiated from the request', function () use ($tests): void {
    $request = fn (string $header): Request
        => Request::create('GET', '/', ['headers' => ['Accept-Language' => $header]]);

    // A ordem sai das qualidades, e um empate mantém a ordem escrita.
    $tests->assertSame(
        ['pt-BR', 'pt', 'en'],
        $request('pt-BR,pt;q=0.9,en;q=0.8')->acceptedLanguages()
    );

    // q=0 é como o cliente diz que NÃO quer um idioma.
    $tests->assertSame(['en'], $request('de;q=0,en')->acceptedLanguages());
    $tests->assertSame([], Request::create('GET', '/')->acceptedLanguages());

    $available = ['en', 'pt_BR'];
    $tests->assertSame('pt_BR', $request('pt-BR')->preferredLanguage($available));

    // Pedir "pt" e receber pt_BR é melhor do que receber inglês.
    $tests->assertSame('pt_BR', $request('pt')->preferredLanguage($available));
    $tests->assertSame('en', $request('de,fr')->preferredLanguage($available, 'en'));
});

$tests->run('a 404 is rendered in the visitor language', function () use ($tests): void {
    /*
     * The interesting part: a request that matches no route never reaches a
     * controller, so the only way it can be translated is the locale being
     * resolved by global middleware.
     */
    Router::reset();

    $router = (new Router(new Container()))
        ->middleware(new SetLocale(['en', 'pt_BR'], 'en'));

    $portugues = $router->dispatch(Request::create('GET', '/nada', [
        'headers' => ['Accept-Language' => 'pt-BR'],
    ]));
    $ingles = $router->dispatch(Request::create('GET', '/nada', [
        'headers' => ['Accept-Language' => 'en'],
    ]));

    $tests->assertSame(HTTP_NOT_FOUND, $portugues->status());
    $tests->assertTrue(str_contains($portugues->body(), 'não foi encontrada'));
    $tests->assertSame('pt-BR', $portugues->header('Content-Language'));

    $tests->assertTrue(str_contains($ingles->body(), 'was not found'));
    $tests->assertSame('en', $ingles->header('Content-Language'));

    // E o atributo lang do documento acompanha.
    $tests->assertTrue(str_contains($portugues->body(), '<html lang="pt-BR"'));

    Translator::setLocale(APP_LOCALE);
});

$tests->run('the 500 page follows the visitor language', function () use ($tests): void {
    $previous = locale();

    Translator::setLocale('es');
    $spanish = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($spanish->body(), 'lang="es"'));
    $tests->assertTrue(str_contains($spanish->body(), 'Error interno'));

    Translator::setLocale('pt_BR');
    $portuguese = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($portuguese->body(), 'lang="pt-BR"'));

    Translator::setLocale('en');
    $english = ErrorHandler::toResponse(new RuntimeException('boom'));
    $tests->assertTrue(str_contains($english->body(), 'lang="en"'));
    $tests->assertTrue(str_contains($english->body(), 'Internal error'));

    Translator::setLocale($previous);
});

$tests->run('a regional locale reads its base language before the fallback', function () use ($tests): void {
    $dir = sys_get_temp_dir() . '/sfphp-base-' . bin2hex(random_bytes(4));
    mkdir($dir . '/es', 0777, true);
    mkdir($dir . '/en', 0777, true);
    file_put_contents($dir . '/es/greet.php', "<?php return ['hi' => 'Hola'];");
    file_put_contents($dir . '/en/greet.php', "<?php return ['hi' => 'Hello'];");
    Translator::addPath($dir);

    $tests->assertSame('Hola', __('greet.hi', [], 'es_MX'));
    exec('rm -rf ' . escapeshellarg($dir));
});

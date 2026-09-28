<?php

/*
 * Validation, on the server and as the browser sees it.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\I18n\Translator;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Str;
use SfphpProject\src\Validator;

$tests->run('validator rejects malformed rules', function () use ($tests): void {
    $tests->assertThrows(
        fn () => Validator::validate(['name' => 'Ada'], ['name' => 'min:abc']),
        InvalidArgumentException::class
    );
});

$tests->run('unicode aware string helpers count characters, not bytes', function () use ($tests): void {
    $tests->assertSame(3, Str::length('日本語'));
    $tests->assertSame(4, Str::length('José'));
    $tests->assertSame('日本...', Str::truncate('日本語テキスト', 5));
    $tests->assertSame('本', Str::substr('日本語', 1, 1));
    $tests->assertSame('語本日', Str::reverse('日本語'));
    $tests->assertTrue(Str::isUtf8(Str::truncate('日本語テキスト', 5)));

    $tests->assertTrue(Str::isAlpha('José'));
    $tests->assertTrue(Str::isAlpha('Владимир'));
    $tests->assertTrue(Str::isAlpha('北京'));
    $tests->assertSame(false, Str::isAlpha('abc123'));
    $tests->assertTrue(Str::isAlphanumeric('José99'));

    // ASCII-only on purpose: the value is meant to survive an (int) cast.
    $tests->assertTrue(Str::isNumeric('123'));
    $tests->assertSame(false, Str::isNumeric('١٢٣'));
});

$tests->run('validator measures characters and accepts every alphabet', function () use ($tests): void {
    $tests->assertTrue(Validator::validate(
        ['name' => 'José'],
        ['name' => 'alpha']
    )->passes());

    $tests->assertTrue(Validator::validate(
        ['name' => '北京'],
        ['name' => 'alpha']
    )->passes());

    // "日本語" is 3 characters but 9 bytes; a byte-based max:5 rejected it.
    $tests->assertTrue(Validator::validate(
        ['bio' => '日本語'],
        ['bio' => 'max:5']
    )->passes());

    $tests->assertSame(false, Validator::validate(
        ['bio' => '日本語テキスト'],
        ['bio' => 'max:5']
    )->passes());

    $tests->assertSame(false, Validator::validate(
        ['name' => 'abc123'],
        ['name' => 'alpha']
    )->passes());
});

$tests->run('validation messages follow the locale and inflect by count', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        Translator::setLocale('en');
        $errors = Validator::validate(['nome' => ''], ['nome' => 'required'])->errors();
        $tests->assertSame('nome is required.', $errors['nome'][0]);

        Translator::setLocale('pt_BR');
        $errors = Validator::validate(['nome' => ''], ['nome' => 'required'])->errors();
        $tests->assertSame('nome é obrigatório.', $errors['nome'][0]);

        // A regra de comprimento flexiona: "no máximo um caractere", não "1
        // caracteres". (Testado pelo max: um campo vazio é opcional e não é
        // validado, então o singular de min:1 não é mais alcançável.)
        $errors = Validator::validate(['nome' => 'ab'], ['nome' => 'max:1'])->errors();
        $tests->assertSame('nome deve ter no máximo um caractere.', $errors['nome'][0]);

        $errors = Validator::validate(['nome' => 'ab'], ['nome' => 'min:5'])->errors();
        $tests->assertSame('nome deve ter ao menos 5 caracteres.', $errors['nome'][0]);

        // Uma mensagem passada pelo chamador vence intocada.
        $errors = Validator::validate(
            ['nome' => ''],
            ['nome' => 'required'],
            ['nome' => ['required' => 'Informe seu nome.']]
        )->errors();
        $tests->assertSame('Informe seu nome.', $errors['nome'][0]);
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('a pattern that needs a pipe is given as an array', function () use ($tests): void {
    /*
     * Rules are pipe separated, so a pattern containing one cannot be written
     * in the string form — the separator cannot tell them apart. The array
     * form exists for exactly that, and for nothing else.
     */
    $rules = ['colour' => ['required', 'pattern:^(blue|green)$']];

    $tests->assertSame(true, Validator::validate(['colour' => 'blue'], $rules)->passes());
    $tests->assertSame(true, Validator::validate(['colour' => 'red'], $rules)->fails());

    // url is the other half of the parity that was missing.
    $tests->assertSame(true, Validator::validate(['site' => 'https://example.com'], ['site' => 'url'])->passes());
    $tests->assertSame(true, Validator::validate(['site' => 'not a url'], ['site' => 'url'])->fails());
});

$tests->run('the documented field vocabulary is the one the code accepts', function () use ($tests): void {
    /*
     * A reader asked for every type and every modifier to be listed, because
     * otherwise they are guessed at. A list written by hand is a list that
     * drifts, so this compares the documentation against the constants: adding
     * a type without documenting it fails here, in all three languages.
     */
    $draft = new ReflectionClass(SfphpProject\src\Migrations\MigrationDraft::class);

    $vocabulary = array_merge(
        $draft->getConstant('PLAIN_TYPES'),
        $draft->getConstant('SIZED_TYPES'),
        $draft->getConstant('PRECISION_TYPES'),
        $draft->getConstant('FLAGS'),
        $draft->getConstant('SHORTHANDS')
    );

    foreach (['en', 'pt-BR', 'es'] as $language) {
        $documentation = (string) file_get_contents(dirname(dirname(__DIR__)) . '/docs/' . $language . '/DOCUMENTATION.md');

        foreach ($vocabulary as $word) {
            if (!str_contains($documentation, '`' . $word . '`')) {
                throw new RuntimeException(sprintf('%s does not document "%s".', $language, $word));
            }
        }

        foreach ($draft->getConstant('VALUED') as $word) {
            if (!str_contains($documentation, '`' . $word . '=`')) {
                throw new RuntimeException(sprintf('%s does not document "%s=".', $language, $word));
            }
        }
    }

    $tests->assertSame(true, true);
});

$tests->run('a request validates what it carried, not what is in a superglobal', function () use ($tests): void {
    /*
     * The documentation used to show Validator::validate($_POST, ...), which
     * contradicts the layer it sits in: a superglobal is process-wide state, so
     * a test has to fake it, a second request in the same worker inherits it,
     * and a controller written against it cannot be called twice with different
     * input.
     */
    $request = Request::create('POST', '/users', [
        'body' => ['name' => 'Jo', 'email' => 'not-an-email', 'age' => '30'],
    ]);

    $result = $request->validate([
        'name' => 'required|min:3',
        'email' => 'required|email',
        'age' => 'required|number',
    ]);

    $tests->assertSame(true, $result->fails());
    $tests->assertSame(true, isset($result->errors()['name']));
    $tests->assertSame(true, isset($result->errors()['email']));

    // The field that passed is in validated(); the ones that did not are not.
    $tests->assertSame(false, isset($result->errors()['age']));

    // Two requests, two answers, with nothing shared between them.
    $second = Request::create('POST', '/users', [
        'body' => ['name' => 'Joana', 'email' => 'joana@example.com', 'age' => '30'],
    ]);

    $tests->assertSame(true, $second->validate([
        'name' => 'required|min:3',
        'email' => 'required|email',
        'age' => 'required|number',
    ])->passes());

    // Query string counts too: a GET form is still input.
    $query = Request::create('GET', '/search?term=ab');
    $tests->assertSame(true, $query->validate(['term' => 'required|min:3'])->fails());
});

$tests->run('required is judged first, and an optional field is judged only when it has a value', function () use ($tests): void {
    $rules = ['nickname' => 'min:3|max:10', 'name' => 'min:3|required', 'email' => 'email'];

    // Absent or blank and optional: nothing runs. Absent or blank and
    // required: that one message, even though required comes last.
    foreach ([[], ['nickname' => '', 'name' => '   ', 'email' => '']] as $data) {
        $errors = Validator::validate($data, $rules)->errors();

        $tests->assertSame(['name'], array_keys($errors));
        $tests->assertSame(['name is required.'], $errors['name']);
    }

    // With a value, required is satisfied and says nothing; the other rules
    // speak for themselves.
    $errors = Validator::validate(
        ['nickname' => 'ab', 'name' => 'Jo', 'email' => 'not-an-email'],
        $rules
    )->errors();
    $tests->assertSame(['nickname must be at least 3 characters long.'], $errors['nickname']);
    $tests->assertSame(['name must be at least 3 characters long.'], $errors['name']);
    $tests->assertSame(['email must be a valid email.'], $errors['email']);

    $result = Validator::validate(['nickname' => 'Ana', 'name' => 'Joana', 'email' => 'ana@example.com'], $rules);
    $tests->assertTrue($result->passes());

    // "0" is a value, not an empty field.
    $tests->assertSame(
        ['count must be at least 1.'],
        Validator::validate(['count' => '0'], ['count' => 'required|number|min:1'])->errors()['count'] ?? []
    );

    // A misspelt rule is refused even on a field that was not sent.
    $tests->assertThrows(
        fn () => Validator::validate([], ['nickname' => 'mni:3']),
        InvalidArgumentException::class
    );
});

$tests->run('validated() hands back only the fields that had rules', function () use ($tests): void {
    /*
     * The whole input used to come back, so a smuggled is_admin rode along
     * into whatever validated() was handed to.
     */
    $result = Validator::validate(
        ['name' => 'Joana', 'email' => 'joana@example.com', 'is_admin' => '1', 'tags' => ['a', 'b']],
        ['name' => 'required|min:3', 'email' => 'required|email', 'tags' => 'required']
    );

    $tests->assertSame(true, $result->passes());
    $tests->assertSame(
        ['name' => 'Joana', 'email' => 'joana@example.com', 'tags' => ['a', 'b']],
        $result->validated()
    );

    // Through the request, which is how a controller gets there.
    $request = Request::create('POST', '/users', [
        'body' => ['name' => 'Joana', 'role' => 'admin'],
    ]);
    $tests->assertSame(['name' => 'Joana'], $request->validate(['name' => 'required'])->validated());
});

$tests->run('the validator counts arrays by their items, accepts addresses in any script and reads patterns as written', function () use ($tests): void {
    $previous = Translator::locale();
    Translator::setLocale('en');

    try {
        $errors = fn (array $data, array $rules): array => Validator::validate($data, $rules)->errors();

        // An array used to read as "", so it passed every text rule.
        $tests->assertTrue(isset($errors(['tags' => ['a', 'b', 'c']], ['tags' => 'max:2'])['tags']));
        $tests->assertSame([], $errors(['tags' => ['a', 'b']], ['tags' => 'max:2']));
        $tests->assertTrue(isset($errors(['name' => ['x']], ['name' => 'maxLength:5|alpha'])['name']));

        $tests->assertSame([], $errors(['e' => 'josé@exemplo.com.br'], ['e' => 'email']));
        $tests->assertSame([], $errors(['u' => 'https://exemplo.com.br/café'], ['u' => 'url']));
        $tests->assertTrue(isset($errors(['u' => 'javascript:alert(1)'], ['u' => 'url'])['u']));
        $tests->assertTrue(isset($errors(['u' => 'foo:bar'], ['u' => 'url'])['u']));
        $tests->assertTrue(isset($errors(['e' => 'a@b..com'], ['e' => 'email'])['e']));

        // Letters written with combining marks are letters.
        $tests->assertSame([], $errors(['n' => 'हिन्दी', 'm' => "Jose\u{0301}"], ['n' => 'alpha', 'm' => 'alpha|maxLength:4']));

        // An escaped slash stays escaped; an invalid pattern is a programming error.
        $tests->assertSame([], $errors(['p' => 'a/b'], ['p' => ['pattern:^a\/b$']]));
        $tests->assertThrows(fn () => $errors(['p' => 'x'], ['p' => ['pattern:(']]), InvalidArgumentException::class);

        // A count no plural form covers gets the general form, not the raw catalog string.
        $message = $errors(['n' => 'x'], ['n' => 'max:0'])['n'][0];
        $tests->assertTrue(!str_contains($message, '|') && !str_contains($message, '[2,*]'));

        // The field's name in the visitor's language.
        Translator::setLocale('pt_BR');
        $dir = sys_get_temp_dir() . '/sfphp-attr-' . bin2hex(random_bytes(4));
        mkdir($dir . '/pt_BR', 0777, true);
        file_put_contents($dir . '/pt_BR/validation.php', "<?php return ['attributes' => ['name' => 'nome']];");
        Translator::addPath($dir);
        $tests->assertSame('nome é obrigatório.', $errors([], ['name' => 'required'])['name'][0]);
        exec('rm -rf ' . escapeshellarg($dir));
    } finally {
        Translator::setLocale($previous);
    }
});

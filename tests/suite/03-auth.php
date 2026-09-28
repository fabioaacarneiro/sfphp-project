<?php

/*
 * Authentication, authorization, passwords and tokens.
 *
 * Loaded by tests/run.php, which defines $tests and the shared fixtures.
 */

use SfphpProject\src\Container;
use SfphpProject\src\Auth\RememberToken;
use SfphpProject\src\JWT;
use SfphpProject\src\Auth\Auth;
use SfphpProject\src\Auth\AuthorizationException;
use SfphpProject\src\Auth\Gate;
use SfphpProject\src\Auth\Hash;
use SfphpProject\src\Auth\ModelUserProvider;
use SfphpProject\src\Auth\SessionGuard;
use SfphpProject\src\Auth\TokenGuard;
use SfphpProject\src\Database\MassAssignmentException;
use SfphpProject\src\Database\Model;
use SfphpProject\src\Http\Middleware\Authenticate;
use SfphpProject\src\I18n\Translator;
use SfphpProject\src\Http\Request;
use SfphpProject\src\Router;

$tests->run('requests accept redirected bearer tokens', function () use ($tests): void {
    /*
     * Apache hands the Authorization header over as REDIRECT_HTTP_* once a
     * rewrite has run, so a token would be invisible without this. Header
     * normalisation moved from BaseAPIController to Request.
     */
    $server = $_SERVER;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer test-token'];

    try {
        $tests->assertSame('test-token', Request::fromGlobals()->bearerToken());
    } finally {
        $_SERVER = $server;
    }
});

$tests->run('JWT rejects tampered tokens', function () use ($tests): void {
    $_ENV['JWT_KEY'] = str_repeat('a', 32);
    $token = JWT::generate(['id' => 1, 'email' => 'test@example.com']);
    $tests->assertTrue(JWT::validate($token));
    $tests->assertSame(false, JWT::validate($token . 'x'));
});

$tests->run('password hashing delegates to PHP and stays current', function () use ($tests): void {
    $hash = Hash::make('segredo');

    $tests->assertTrue(Hash::check('segredo', $hash));
    $tests->assertSame(false, Hash::check('errado', $hash));
    $tests->assertSame(false, Hash::check('', $hash));
    $tests->assertSame(false, Hash::needsRehash($hash));

    /*
     * The throwaway hash used to equalise timing on a failed lookup must carry
     * the parameters password_hash() uses on the PHP that is running. A
     * mismatch in either direction makes the no-such-user path take a
     * different time from the wrong-password path, which is the difference an
     * attacker uses to learn which accounts exist.
     *
     * The assertion is about the hash actually used, not the baked constant:
     * PASSWORD_DEFAULT is bcrypt cost 10 on PHP 8.1 to 8.3 and cost 12 on 8.4,
     * so no single constant can be right everywhere. Asserting the constant is
     * what made this pass on 8.4 and fail on every other supported version.
     */
    $resolve = new ReflectionMethod(Auth::class, 'timingHash');
    $resolve->setAccessible(true);
    $timing = $resolve->invoke(null);

    $tests->assertSame(false, password_needs_rehash($timing, PASSWORD_DEFAULT));

    // And it really is a hash something can be verified against.
    $tests->assertSame(false, password_verify('anything at all', $timing));
});

$tests->run('credentials are checked and a session login is kept', function () use ($tests): void {
    $hash = Hash::make('segredo');
    $pdo = new ModelPdoTest(['users' => [
        ['id' => 1, 'name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => $hash],
    ]]);

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('web', new SessionGuard(Auth::provider()));

    try {
        $tests->assertTrue(Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'segredo']));
        $tests->assertTrue(Auth::check());
        $tests->assertSame(1, Auth::id());
        $tests->assertSame('Ana', Auth::user()->name);

        Auth::logout();
        $tests->assertSame(false, Auth::check());
        $tests->assertSame(null, Auth::user());
        $tests->assertTrue(Auth::guest());

        $tests->assertSame(
            false,
            Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'errada'])
        );

        /*
         * The password is never part of the lookup. Matching on a hash could
         * only work by comparing hashes as strings, which defeats the salt.
         */
        $pdo->queries = [];
        Auth::attempt(['email' => 'ana@exemplo.com', 'password' => 'errada']);
        $tests->assertSame(false, str_contains($pdo->queries[0] ?? '', '`password`'));
    } finally {
        Model::useConnection(null);
        Auth::reset();
    }
});

$tests->run('a bearer token identifies a request without a session', function () use ($tests): void {
    $pdo = new ModelPdoTest(['users' => [
        ['id' => 1, 'name' => 'Ana', 'email' => 'ana@exemplo.com', 'password' => 'x'],
    ]]);

    $key = $_ENV['JWT_KEY'] ?? null;
    $_ENV['JWT_KEY'] = bin2hex(random_bytes(32));

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('api', new TokenGuard(Auth::provider()));

    try {
        $token = JWT::generate(['id' => 1, 'email' => 'ana@exemplo.com']);

        // claims() answers who the token is about; validate() only whether to trust it.
        $tests->assertSame('ana@exemplo.com', JWT::claims($token)['email']);
        $tests->assertSame(null, JWT::claims($token . 'x'));

        $authenticated = Request::create('GET', '/api', [
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);

        $tests->assertSame(1, Auth::resolve($authenticated, 'api')?->getAuthIdentifier());
        $tests->assertSame(null, Auth::resolve(Request::create('GET', '/api'), 'api'));
        $tests->assertSame(null, Auth::resolve(Request::create('GET', '/api', [
            'headers' => ['Authorization' => 'Bearer nao.e.um.token'],
        ]), 'api'));
    } finally {
        Model::useConnection(null);
        Auth::reset();

        if ($key === null) {
            unset($_ENV['JWT_KEY']);
        } else {
            $_ENV['JWT_KEY'] = $key;
        }
    }
});

$tests->run('the authenticate middleware resolves, and refuses when required', function () use ($tests): void {
    $pdo = new ModelPdoTest(['users' => [['id' => 1, 'name' => 'Ana', 'password' => 'x']]]);

    $key = $_ENV['JWT_KEY'] ?? null;
    $_ENV['JWT_KEY'] = bin2hex(random_bytes(32));

    Model::useConnection($pdo);
    Auth::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('api', new TokenGuard(Auth::provider()));

    Router::reset();
    Router::get('/aberta', [AuthControllerTest::class, 'open']);
    Router::get('/secreta', [AuthControllerTest::class, 'secret'])
        ->middleware(new Authenticate('api', required: true));

    try {
        $token = JWT::generate(['id' => 1, 'email' => 'ana@exemplo.com']);
        $router = (new Router(new Container()))->middleware(new Authenticate('api'));

        $withToken = ['headers' => ['Authorization' => 'Bearer ' . $token]];

        // Registered globally it only resolves: an anonymous request carries on.
        $tests->assertSame('anonimo', $router->dispatch(Request::create('GET', '/aberta'))->body());
        $tests->assertSame('logado', $router->dispatch(Request::create('GET', '/aberta', $withToken))->body());

        // Attached to a route with required: true it refuses instead.
        $refused = $router->dispatch(Request::create('GET', '/secreta', [
            'headers' => ['Accept' => 'application/json'],
        ]));
        $tests->assertSame(HTTP_UNAUTHORIZED, $refused->status());

        $tests->assertSame(
            'secreto:1',
            $router->dispatch(Request::create('GET', '/secreta', $withToken))->body()
        );

        // A browser is redirected rather than shown a bare 401.
        $browser = $router->dispatch(Request::create('GET', '/secreta'));
        $tests->assertSame(HTTP_FOUND, $browser->status());
        $tests->assertSame('/login', $browser->header('Location'));
    } finally {
        Model::useConnection(null);
        Auth::reset();
        Router::reset();

        if ($key === null) {
            unset($_ENV['JWT_KEY']);
        } else {
            $_ENV['JWT_KEY'] = $key;
        }
    }
});

$tests->run('policies and abilities finally have something that calls them', function () use ($tests): void {
    /*
     * make:policy has generated classes since long before this; nothing ever
     * invoked one. Gate is what invokes them.
     */
    $pdo = new ModelPdoTest(['users' => [['id' => 1, 'password' => 'x']]]);

    Model::useConnection($pdo);
    Auth::reset();
    Gate::reset();
    Auth::provider(new ModelUserProvider(AuthUserTest::class));
    Auth::guard('web', new SessionGuard(Auth::provider()));

    try {
        Auth::login(AuthUserTest::find(1));

        Gate::policy(AuthPostTest::class, AuthPostPolicyTest::class);
        Gate::define('admin', static fn (?object $user): bool
            => $user !== null && $user->getAuthIdentifier() === 99);

        $tests->assertTrue(Gate::allows('update', new AuthPostTest(1)));
        $tests->assertSame(false, Gate::allows('update', new AuthPostTest(2)));
        $tests->assertTrue(Gate::denies('update', new AuthPostTest(2)));
        $tests->assertTrue(Gate::allows('view', new AuthPostTest(2)));
        $tests->assertSame(false, Gate::allows('admin'));

        // An ability nobody declared is denied; allowing by default would mean
        // a typo in an ability name silently opens a door.
        $tests->assertSame(false, Gate::allows('inventada', new AuthPostTest(1)));

        $tests->assertThrows(
            fn () => Gate::authorize('update', new AuthPostTest(2)),
            AuthorizationException::class
        );

        Gate::authorize('update', new AuthPostTest(1));   // não lança

        $tests->assertSame(false, Gate::forUser(null, 'update', new AuthPostTest(1)));
    } finally {
        Model::useConnection(null);
        Auth::reset();
        Gate::reset();
    }
});

$tests->run('auth messages exist in every shipped locale', function () use ($tests): void {
    $previous = Translator::locale();

    try {
        foreach (['en', 'pt_BR', 'es'] as $locale) {
            Translator::setLocale($locale);

            foreach (['auth.failed', 'auth.unauthenticated', 'auth.unauthorized'] as $key) {
                // A key with no translation comes back as the key itself.
                $tests->assertSame(false, __($key) === $key);
            }

            $tests->assertSame(false, __('http.not_found_title') === 'http.not_found_title');
            $tests->assertSame(false, __('validation.required') === 'validation.required');
        }
    } finally {
        Translator::setLocale($previous);
    }
});

$tests->run('mass assignment needs an explicit list', function () use ($tests): void {
    /*
     * The natural line — Model::create($request->all()) — used to store every
     * column the attacker chose to submit. A registration form that never
     * showed an "is_admin" field still wrote one if the request carried it.
     */
    $submitted = [
        'name' => 'Ana',
        'email' => 'ana@exemplo.com',
        'is_admin' => 1,
        'balance' => 999999,
    ];

    $user = new AuthUserTest(Request::create('POST', '/cadastro', ['body' => $submitted])->all());

    $tests->assertSame('Ana', $user->getAttribute('name'));
    $tests->assertSame(null, $user->getAttribute('is_admin'));
    $tests->assertSame(null, $user->getAttribute('balance'));

    /*
     * A model that declares nothing cannot be filled at all. Defaulting to
     * permissive would protect only the developers who already knew to declare
     * the list, which is the wrong set of people.
     */
    $tests->assertThrows(
        fn () => new UnguardedModelTest(['qualquer' => 1]),
        MassAssignmentException::class
    );

    // An empty array is not an attempt to mass assign, so it does not raise.
    $tests->assertTrue((new UnguardedModelTest()) instanceof UnguardedModelTest);

    // forceFill is the way in for values the application itself chose.
    $forced = (new AuthUserTest())->forceFill(['is_admin' => 1]);
    $tests->assertSame(1, $forced->getAttribute('is_admin'));

    $tests->assertSame(['name', 'email', 'password'], AuthUserTest::fillable());
});

$tests->run('a remember cookie is not a password that can be replayed', function () use ($tests): void {
    $issued = RememberToken::issue();
    $parsed = RememberToken::parse($issued['cookie']);

    $tests->assertSame($issued['selector'], $parsed['selector']);
    $tests->assertSame(true, RememberToken::matches($parsed['verifier'], $issued['hash']));
    $tests->assertSame(false, RememberToken::matches('guessed', $issued['hash']));

    /*
     * The verifier is stored hashed. A database someone can read otherwise
     * hands them a working cookie for every remembered user — the column is
     * the one thing read access gets for free.
     */
    $tests->assertSame(false, str_contains($issued['hash'], $parsed['verifier']));
    $tests->assertSame(64, strlen($issued['hash']));

    // Two issues never collide, which is what makes the selector a lookup key.
    $tests->assertSame(false, RememberToken::issue()['selector'] === RememberToken::issue()['selector']);

    // A malformed cookie is refused rather than half-read.
    foreach (['', 'nocolon', ':empty', 'empty:'] as $malformed) {
        $tests->assertSame(null, RememberToken::parse($malformed));
    }

    $tests->assertSame(true, $issued['expires'] > time());
});

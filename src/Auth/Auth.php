<?php

namespace SfphpProject\src\Auth;

use RuntimeException;
use SfphpProject\src\Http\Request;

/**
 * The entry point for authentication.
 *
 * Guards are registered once, at boot, and asked for by name afterwards. The
 * default guard is the one a web request uses; an API route asks for the token
 * guard explicitly.
 *
 *     Auth::provider(new ModelUserProvider(User::class));
 *     Auth::guard('web', new SessionGuard(Auth::provider()));
 *     Auth::guard('api', new TokenGuard(Auth::provider()));
 *
 * The resolved user is held in a static for the duration of a request, so
 * asking twice does not query twice. Under a persistent runtime that same
 * static would carry one visitor's identity into the next request, which is
 * why forgetUser() exists and why the Authenticate middleware calls it at the
 * start of every request. It is the same hazard the translator has with its
 * active locale: static state in a process that serves many requests needs an
 * explicit owner that resets it.
 */
final class Auth
{
    /**
     * A throwaway hash, used only to equalise timing on a failed lookup.
     *
     * It must have been produced with the same parameters password_hash()
     * uses today, or the equalising fails in the direction it was meant to
     * prevent: PHP 8.4 raised bcrypt's default cost from 10 to 12, and a hash
     * left at 10 verifies roughly four times faster than a real one. A test
     * asserts this constant does not need rehashing, so a future change to
     * PHP's default is caught by CI rather than silently reopening the leak.
     */
    private const TIMING_HASH = '$2y$12$GZ2ly5LRlJT3kVnAlCoAC.mQHH/5x9AsFwWq2VmJtRC0vDNinSq36';

    /** @var array<string, Guard> */
    private static array $guards = [];

    private static ?UserProvider $provider = null;

    private static string $default = 'web';

    private static ?Authenticatable $user = null;

    private static bool $resolved = false;

    /**
     * Register or read the user provider.
     *
     * @param UserProvider|null $provider The provider to register, or null to read
     * @return UserProvider The registered provider
     * @throws RuntimeException If read before one is registered
     */
    public static function provider(?UserProvider $provider = null): UserProvider
    {
        if ($provider !== null) {
            self::$provider = $provider;
        }

        if (self::$provider === null) {
            throw new RuntimeException(
                'No user provider registered. Call Auth::provider(new ModelUserProvider(User::class)).'
            );
        }

        return self::$provider;
    }

    /**
     * Register a guard, or get one by name.
     *
     * @param string $name The guard name
     * @param Guard|null $guard The guard to register, or null to read
     * @return Guard The registered guard
     * @throws RuntimeException If the name is not registered
     */
    public static function guard(string $name, ?Guard $guard = null): Guard
    {
        if ($guard !== null) {
            self::$guards[$name] = $guard;
        }

        if (!isset(self::$guards[$name])) {
            throw new RuntimeException("Auth guard \"$name\" is not registered.");
        }

        return self::$guards[$name];
    }

    /**
     * Set which guard is used when none is named.
     *
     * @param string $name The guard name
     * @return void
     */
    public static function setDefaultGuard(string $name): void
    {
        self::$default = $name;
    }

    /**
     * Get the name of the default guard.
     *
     * @return string The guard name
     */
    public static function defaultGuard(): string
    {
        return self::$default;
    }

    /**
     * Identify the user behind a request and remember the answer.
     *
     * @param Request $request The incoming request
     * @param string|null $guard The guard to ask, or null for the default
     * @return Authenticatable|null The user, or null when the request is anonymous
     */
    public static function resolve(Request $request, ?string $guard = null): ?Authenticatable
    {
        self::$user = self::guard($guard ?? self::$default)->resolve($request);
        self::$resolved = true;

        return self::$user;
    }

    /**
     * Get the user resolved for this request.
     *
     * @return Authenticatable|null The user, or null when anonymous
     */
    public static function user(): ?Authenticatable
    {
        return self::$user;
    }

    /**
     * Check whether a user is logged in.
     *
     * @return bool True when there is a user
     */
    public static function check(): bool
    {
        return self::$user !== null;
    }

    /**
     * Check whether the request is anonymous.
     *
     * @return bool True when there is no user
     */
    public static function guest(): bool
    {
        return self::$user === null;
    }

    /**
     * Get the identifier of the user resolved for this request.
     *
     * @return mixed The identifier, or null when anonymous
     */
    public static function id(): mixed
    {
        return self::$user?->getAuthIdentifier();
    }

    /**
     * Check whether a guard has already been asked this request.
     *
     * @return bool True once resolve() has run
     */
    public static function resolved(): bool
    {
        return self::$resolved;
    }

    /**
     * Try to log in with credentials.
     *
     * The password is checked even when no user matched, against a throwaway
     * hash. Returning early would make a request for an unknown account
     * measurably faster than one for a known account with the wrong password,
     * and that difference is enough to enumerate which accounts exist.
     *
     * @param array<string, mixed> $credentials The submitted credentials
     * @param string|null $guard The guard to log into, or null for the default
     * @return bool True when the credentials are correct
     * @throws RuntimeException If the guard cannot hold a login
     */
    public static function attempt(array $credentials, ?string $guard = null): bool
    {
        $provider = self::provider();
        $user = $provider->retrieveByCredentials($credentials);

        if ($user === null) {
            self::burnTime($credentials);

            return false;
        }

        if (!$provider->validateCredentials($user, $credentials)) {
            return false;
        }

        self::login($user, $guard);

        return true;
    }

    /**
     * Log a user in without checking a password.
     *
     * @param Authenticatable $user The user to log in
     * @param string|null $guard The guard to log into, or null for the default
     * @return void
     * @throws RuntimeException If the guard cannot hold a login
     */
    public static function login(Authenticatable $user, ?string $guard = null): void
    {
        $instance = self::guard($guard ?? self::$default);

        if (!$instance instanceof SessionGuard) {
            throw new RuntimeException(sprintf(
                'Guard "%s" cannot hold a login; only %s keeps state between requests.',
                $guard ?? self::$default,
                SessionGuard::class
            ));
        }

        $instance->login($user);

        self::$user = $user;
        self::$resolved = true;
    }

    /**
     * Log the current user out.
     *
     * @param string|null $guard The guard to log out of, or null for the default
     * @return void
     */
    public static function logout(?string $guard = null): void
    {
        $instance = self::guard($guard ?? self::$default);

        if ($instance instanceof SessionGuard) {
            $instance->logout();
        }

        self::forgetUser();
    }

    /**
     * Forget the user resolved for this request.
     *
     * @internal Called by the Authenticate middleware at the start of a request.
     * @return void
     */
    public static function forgetUser(): void
    {
        self::$user = null;
        self::$resolved = false;
    }

    /**
     * Forget every registration.
     *
     * @internal Exposed for tests and for worker reloads in a persistent runtime.
     * @return void
     */
    public static function reset(): void
    {
        self::$guards = [];
        self::$provider = null;
        self::$default = 'web';

        self::forgetUser();
    }

    /**
     * Spend the time a password check would have taken.
     *
     * A fixed hash rather than a freshly generated one: generating costs about
     * as much as verifying, so hashing here would make the no-such-user path
     * roughly twice as slow as the wrong-password path — the opposite of what
     * this is for. The value is not a secret, it is a stopwatch.
     *
     * @param array<string, mixed> $credentials The submitted credentials
     * @return void
     */
    private static function burnTime(array $credentials): void
    {
        $field = self::$provider instanceof ModelUserProvider
            ? self::$provider->passwordField()
            : 'password';

        $plain = $credentials[$field] ?? '';

        Hash::check(is_string($plain) ? $plain : '', self::TIMING_HASH);
    }
}

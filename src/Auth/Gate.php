<?php

namespace SfphpProject\src\Auth;

use InvalidArgumentException;

/**
 * Decides whether a user may do something.
 *
 * Two ways to declare a rule. A policy groups the rules about one kind of
 * thing, which is what make:policy generates:
 *
 *     Gate::policy(Post::class, PostPolicy::class);
 *     Gate::allows('update', $post);      // calls PostPolicy::update($user, $post)
 *
 * And a closure covers an ability that is not about one model:
 *
 *     Gate::define('access-admin', fn (?Authenticatable $user): bool
 *         => $user !== null && $user->role === 'admin');
 *
 * Until now make:policy generated a class nothing ever called; this is what
 * calls it.
 */
final class Gate
{
    /** @var array<string, string> */
    private static array $policies = [];

    /** @var array<string, callable> */
    private static array $abilities = [];

    /**
     * Map a class to the policy that governs it.
     *
     * @param string $class The class the policy is about
     * @param string $policy The policy class
     * @return void
     * @throws InvalidArgumentException If the policy class does not exist
     */
    public static function policy(string $class, string $policy): void
    {
        if (!class_exists($policy)) {
            throw new InvalidArgumentException("Policy $policy does not exist.");
        }

        self::$policies[$class] = $policy;
    }

    /**
     * Define an ability that is not about a particular class.
     *
     * @param string $ability The ability name
     * @param callable $callback Receives the user, then any arguments
     * @return void
     */
    public static function define(string $ability, callable $callback): void
    {
        self::$abilities[$ability] = $callback;
    }

    /**
     * Check whether the current user may do something.
     *
     * An ability nobody declared is denied. Defaulting to allowed would mean a
     * typo in an ability name silently opens a door.
     *
     * @param string $ability The ability name
     * @param mixed ...$arguments The subject, and anything else the rule needs
     * @return bool True when allowed
     */
    public static function allows(string $ability, mixed ...$arguments): bool
    {
        return self::check(Auth::user(), $ability, $arguments);
    }

    /**
     * Check whether the current user may not do something.
     *
     * @param string $ability The ability name
     * @param mixed ...$arguments The subject, and anything else the rule needs
     * @return bool True when denied
     */
    public static function denies(string $ability, mixed ...$arguments): bool
    {
        return !self::allows($ability, ...$arguments);
    }

    /**
     * Check whether a specific user may do something.
     *
     * @param Authenticatable|null $user The user to ask about
     * @param string $ability The ability name
     * @param mixed ...$arguments The subject, and anything else the rule needs
     * @return bool True when allowed
     */
    public static function forUser(?Authenticatable $user, string $ability, mixed ...$arguments): bool
    {
        return self::check($user, $ability, $arguments);
    }

    /**
     * Allow, or raise.
     *
     * @param string $ability The ability name
     * @param mixed ...$arguments The subject, and anything else the rule needs
     * @return void
     * @throws AuthorizationException When the user is not allowed
     */
    public static function authorize(string $ability, mixed ...$arguments): void
    {
        if (!self::allows($ability, ...$arguments)) {
            throw new AuthorizationException(__('auth.unauthorized'));
        }
    }

    /**
     * Forget every registration.
     *
     * @internal Exposed for tests and for worker reloads in a persistent runtime.
     * @return void
     */
    public static function reset(): void
    {
        self::$policies = [];
        self::$abilities = [];
    }

    /**
     * Resolve a rule and run it.
     *
     * @param Authenticatable|null $user The user to ask about
     * @param string $ability The ability name
     * @param array<int, mixed> $arguments The subject, and anything else
     * @return bool True when allowed
     */
    private static function check(?Authenticatable $user, string $ability, array $arguments): bool
    {
        if (isset(self::$abilities[$ability])) {
            return (bool) (self::$abilities[$ability])($user, ...$arguments);
        }

        $subject = $arguments[0] ?? null;
        $class = is_object($subject) ? $subject::class : (is_string($subject) ? $subject : null);

        if ($class === null || !isset(self::$policies[$class])) {
            return false;
        }

        $policy = self::$policies[$class];

        if (!method_exists($policy, $ability)) {
            return false;
        }

        /*
         * A policy method takes the user first. An anonymous request reaches
         * it as null rather than being refused outright, so a policy can
         * choose to allow something publicly — reading a published post, say.
         */
        return (bool) (new $policy())->{$ability}($user, ...$arguments);
    }
}

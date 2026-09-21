<?php

namespace SfphpProject\src\Auth;

use InvalidArgumentException;
use SfphpProject\src\Database\Model;

/**
 * Looks users up through a model.
 *
 * @see UserProvider
 */
final class ModelUserProvider implements UserProvider
{
    /**
     * Create the provider.
     *
     * @param class-string<Model&Authenticatable> $model The model holding users
     * @param string $passwordField The column holding the password hash
     * @throws InvalidArgumentException If the model cannot act as a user
     */
    public function __construct(
        private string $model,
        private string $passwordField = 'password'
    ) {
        if (!is_subclass_of($model, Model::class) || !is_subclass_of($model, Authenticatable::class)) {
            throw new InvalidArgumentException(sprintf(
                '%s must extend %s and implement %s.',
                $model,
                Model::class,
                Authenticatable::class
            ));
        }
    }

    /**
     * Find a user by identifier.
     *
     * @param mixed $identifier The identifier, as stored
     * @return Authenticatable|null The user, or null when absent
     */
    public function retrieveById(mixed $identifier): ?Authenticatable
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;
        $user = $model::find($identifier);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Find a user by the credentials submitted, ignoring the password.
     *
     * @param array<string, mixed> $credentials The submitted credentials
     * @return Authenticatable|null The candidate user, or null when absent
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        unset($credentials[$this->passwordField]);

        if ($credentials === []) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;
        $query = $model::query();

        foreach ($credentials as $column => $value) {
            $query->where((string) $column, $value);
        }

        $user = $query->first();

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Check a candidate's password.
     *
     * @param Authenticatable $user The candidate found by retrieveByCredentials()
     * @param array<string, mixed> $credentials The submitted credentials
     * @return bool True when the password matches
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = $credentials[$this->passwordField] ?? null;

        if (!is_string($plain)) {
            return false;
        }

        return Hash::check($plain, $user->getAuthPassword());
    }

    /**
     * Get the column holding the password hash.
     *
     * @return string The column name
     */
    public function passwordField(): string
    {
        return $this->passwordField;
    }
}

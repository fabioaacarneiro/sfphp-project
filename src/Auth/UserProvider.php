<?php

namespace SfphpProject\src\Auth;

/**
 * Where users come from.
 *
 * Separating this from the guards is what lets the same login flow work over a
 * database table, an LDAP directory or an in-memory list in a test. A guard
 * decides how a request proves who it is; a provider decides where that
 * identity is looked up.
 */
interface UserProvider
{
    /**
     * Find a user by identifier.
     *
     * @param mixed $identifier The identifier, as stored
     * @return Authenticatable|null The user, or null when absent
     */
    public function retrieveById(mixed $identifier): ?Authenticatable;

    /**
     * Find a user by the credentials submitted, ignoring the password.
     *
     * The password is deliberately not part of the lookup: a query matching on
     * a password hash could only ever work by comparing hashes as strings,
     * which defeats the salt and turns the check into an equality test.
     *
     * @param array<string, mixed> $credentials The submitted credentials
     * @return Authenticatable|null The candidate user, or null when absent
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /**
     * Check a candidate's password.
     *
     * @param Authenticatable $user The candidate found by retrieveByCredentials()
     * @param array<string, mixed> $credentials The submitted credentials
     * @return bool True when the password matches
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool;
}

<?php

namespace SfphpProject\src\Auth;

/**
 * Something that can log in.
 *
 * Three methods, because that is all the framework needs to know about a user:
 * how to name its key, what the key is, and what to compare a password
 * against. Everything else — name, e-mail, roles — belongs to the
 * application, and the framework never reads it.
 *
 * A model implements this in a few lines:
 *
 *     final class User extends Model implements Authenticatable
 *     {
 *         public function getAuthIdentifierName(): string { return 'id'; }
 *         public function getAuthIdentifier(): mixed { return $this->id; }
 *         public function getAuthPassword(): string { return (string) $this->password; }
 *     }
 */
interface Authenticatable
{
    /**
     * Get the name of the column holding the identifier.
     *
     * @return string The column name
     */
    public function getAuthIdentifierName(): string;

    /**
     * Get the identifier that names this user.
     *
     * @return mixed The identifier
     */
    public function getAuthIdentifier(): mixed;

    /**
     * Get the stored password hash.
     *
     * @return string The hash, never the plain-text password
     */
    public function getAuthPassword(): string;
}

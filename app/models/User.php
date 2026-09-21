<?php

namespace SfphpProject\app\models;

use SfphpProject\src\Auth\Authenticatable;
use SfphpProject\src\Database\Model;

/**
 * Example user model.
 *
 * Implementing Authenticatable is the whole contract: name the key, return
 * the key, return the stored hash. The framework never reads anything else
 * about a user.
 */
final class User extends Model implements Authenticatable
{
    protected static string $table = 'users';

    /*
     * Deliberately omits any column the application decides for itself.
     * "password" is here because a registration form submits one, but it is
     * hashed before it reaches the model — never stored as typed.
     */
    protected static array $fillable = ['name', 'email'];

    protected static array $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getAuthIdentifierName(): string
    {
        return static::primaryKey();
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getAttribute(static::primaryKey());
    }

    public function getAuthPassword(): string
    {
        return (string) $this->getAttribute('password');
    }
}

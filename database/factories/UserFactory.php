<?php

namespace Database\Factories;

use SfphpProject\app\models\User;
use SfphpProject\src\Database\Factory;

/**
 * Example users, for tests and for `./sfphp db:seed`.
 *
 * definition() runs once per row, so every user gets its own e-mail and the
 * unique index on users.email holds.
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        $id = bin2hex(random_bytes(4));

        return [
            'name' => 'User ' . $id,
            'email' => 'user-' . $id . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
        ];
    }

    protected function model(): string
    {
        return User::class;
    }
}

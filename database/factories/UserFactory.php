<?php

namespace Database\Factories;

use SfphpProject\src\Database\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'User ' . mt_rand(1000, 9999),
            'email' => 'user' . mt_rand(1000, 9999) . '@example.com',
            'password' => password_hash('password', PASSWORD_BCRYPT),
            'is_active' => true,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    protected function model(): string
    {
        return \SfphpProject\app\Models\User::class;
    }
}

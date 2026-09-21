<?php

namespace Database\Seeders;

use Database\Factories\UserFactory;
use SfphpProject\src\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        UserFactory::class;
        // Example: Create 50 users using factory
        // User::factory()->count(50)->create();
    }
}

<?php

namespace Database\Seeders;

use Database\Factories\UserFactory;
use SfphpProject\src\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Ten example users, each with the password "password".
        (new UserFactory())->count(10)->create();
    }
}

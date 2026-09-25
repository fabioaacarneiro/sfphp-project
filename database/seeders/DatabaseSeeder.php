<?php

namespace Database\Seeders;

use SfphpProject\src\Database\Seeder;

/**
 * What `./sfphp db:seed` runs when no --class is given.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
        ]);
    }
}

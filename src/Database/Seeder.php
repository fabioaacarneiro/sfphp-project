<?php

namespace SfphpProject\src\Database;

abstract class Seeder
{
    protected function call(string|array $seeders): void
    {
        $seeders = is_array($seeders) ? $seeders : [$seeders];

        foreach ($seeders as $seeder) {
            $class = class_exists($seeder)
                ? $seeder
                : "Database\\Seeders\\{$seeder}";

            if (!class_exists($class)) {
                throw new \Exception("Seeder not found: {$class}");
            }

            echo "Seeding: {$class}\n";
            (new $class())->run();
        }
    }

    abstract public function run(): void;
}

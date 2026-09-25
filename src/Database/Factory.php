<?php

namespace SfphpProject\src\Database;

use Closure;

/**
 * Builds rows for tests and seeders.
 *
 *     final class UserFactory extends Factory
 *     {
 *         public function definition(): array
 *         {
 *             return ['name' => 'User ' . bin2hex(random_bytes(3)), 'email' => ...];
 *         }
 *
 *         protected function model(): string
 *         {
 *             return User::class;
 *         }
 *     }
 *
 *     (new UserFactory())->count(50)->create();
 *
 * `definition()` runs once per row, so a random value differs from row to row.
 * A value given as a closure is called for each row, with the factory and the
 * row's index. Only closures are called: a string that happens to name a PHP
 * function — "key", "date", "count" — is a string.
 *
 * `create()` saves with forceFill(), because the values are the application's
 * own. Through fill() a column outside $fillable — a password, usually — was
 * dropped, and the insert failed on the NOT NULL column it left empty.
 */
abstract class Factory
{
    protected int $count = 1;

    /** @var array<string, mixed> */
    protected array $overrides = [];

    /**
     * How many rows to build.
     *
     * @param int $count The number of rows, at least 1
     * @return static The factory
     */
    public function count(int $count): static
    {
        $this->count = max(1, $count);

        return $this;
    }

    /**
     * Values every row gets, whatever definition() says.
     *
     * @param array<string, mixed> $attributes The values
     * @return static The factory
     */
    public function state(array $attributes): static
    {
        $this->overrides = array_merge($this->overrides, $attributes);

        return $this;
    }

    /**
     * Build the rows without saving them.
     *
     * @param array<string, mixed> $attributes Values that override the definition
     * @return array<string, mixed>|list<array<string, mixed>> One row, or a list when count() is above 1
     */
    public function make(array $attributes = []): array
    {
        $rows = [];

        for ($index = 0; $index < $this->count; $index++) {
            $rows[] = $this->row($attributes, $index);
        }

        return $this->count === 1 ? $rows[0] : $rows;
    }

    /**
     * Build the rows and save them through the model.
     *
     * @param array<string, mixed> $attributes Values that override the definition
     * @return Model|list<Model> One model, or a list when count() is above 1
     */
    public function create(array $attributes = []): Model|array
    {
        /** @var class-string<Model> $model */
        $model = $this->model();
        $saved = [];

        for ($index = 0; $index < $this->count; $index++) {
            $instance = (new $model())->forceFill($this->row($attributes, $index));
            $instance->save();
            $saved[] = $instance;
        }

        return $this->count === 1 ? $saved[0] : $saved;
    }

    /**
     * One row: a fresh definition, the overrides, and the closures called.
     *
     * @param array<string, mixed> $attributes The call's overrides
     * @param int $index The row's position
     * @return array<string, mixed> The row
     */
    private function row(array $attributes, int $index): array
    {
        $row = array_merge($this->definition(), $this->overrides, $attributes);

        foreach ($row as $key => $value) {
            if ($value instanceof Closure) {
                $row[$key] = $value($this, $index);
            }
        }

        return $row;
    }

    /**
     * The values of one row.
     *
     * @return array<string, mixed> Column => value
     */
    abstract public function definition(): array;

    /**
     * The model create() saves through.
     *
     * @return class-string<Model> The model class
     */
    abstract protected function model(): string;
}

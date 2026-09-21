<?php

namespace SfphpProject\src\Database;

use PDO;
use SfphpProject\src\QueryBuilder;

/**
 * A Query Builder that returns models instead of arrays.
 *
 * Every filtering method forwards straight to QueryBuilder, so the whole query
 * language is available and there is nothing new to learn. The only additions
 * are hydration and with().
 */
final class ModelQuery
{
    private QueryBuilder $builder;

    /** @var array<int, string> */
    private array $with = [];

    /**
     * Create a query for a model.
     *
     * @param class-string<Model> $model The model to hydrate into
     * @param PDO $pdo The connection
     * @param string $table The table to read from
     */
    public function __construct(
        private string $model,
        PDO $pdo,
        string $table
    ) {
        $this->builder = (new QueryBuilder($pdo))->from($table);
    }

    /**
     * Load relations in one query each, instead of one per row.
     *
     * Reading $post->author inside a loop over a hundred posts is a hundred
     * queries. Declaring with('author') turns those into one.
     *
     * @param string ...$relations The relation method names
     * @return self The query
     */
    public function with(string ...$relations): self
    {
        foreach ($relations as $relation) {
            $this->with[] = $relation;
        }

        return $this;
    }

    /**
     * Add an AND condition.
     *
     * @param string $column The column name
     * @param mixed $operatorOrValue The operator, or the value for equality
     * @param mixed $value The value when an operator is given
     * @return self The query
     */
    public function where(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        func_num_args() === 2
            ? $this->builder->where($column, $operatorOrValue)
            : $this->builder->where($column, $operatorOrValue, $value);

        return $this;
    }

    /**
     * Add an OR condition.
     *
     * @param string $column The column name
     * @param mixed $operatorOrValue The operator, or the value for equality
     * @param mixed $value The value when an operator is given
     * @return self The query
     */
    public function orWhere(string $column, mixed $operatorOrValue, mixed $value = null): self
    {
        func_num_args() === 2
            ? $this->builder->orWhere($column, $operatorOrValue)
            : $this->builder->orWhere($column, $operatorOrValue, $value);

        return $this;
    }

    /**
     * Add an IS NULL condition.
     *
     * @param string $column The column name
     * @return self The query
     */
    public function whereNull(string $column): self
    {
        $this->builder->whereNull($column);

        return $this;
    }

    /**
     * Add an IS NOT NULL condition.
     *
     * @param string $column The column name
     * @return self The query
     */
    public function whereNotNull(string $column): self
    {
        $this->builder->whereNotNull($column);

        return $this;
    }

    /**
     * Add an IN condition.
     *
     * @param string $column The column name
     * @param array<int, mixed> $values The accepted values
     * @return self The query
     */
    public function whereIn(string $column, array $values): self
    {
        $this->builder->whereIn($column, $values);

        return $this;
    }

    /**
     * Join another table.
     *
     * @param string $table The joined table
     * @param string $first The first column
     * @param string $operator The comparison operator
     * @param string $second The second column
     * @param string $type INNER or LEFT
     * @return self The query
     */
    public function join(
        string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'INNER'
    ): self {
        $this->builder->join($table, $first, $operator, $second, $type);

        return $this;
    }

    /**
     * Order the results.
     *
     * @param string $column The column name
     * @param string $direction ASC or DESC
     * @return self The query
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $this->builder->orderBy($column, $direction);

        return $this;
    }

    /**
     * Limit the number of rows.
     *
     * @param int $limit The maximum number of rows
     * @return self The query
     */
    public function limit(int $limit): self
    {
        $this->builder->limit($limit);

        return $this;
    }

    /**
     * Skip rows.
     *
     * @param int $offset The number of rows to skip
     * @return self The query
     */
    public function offset(int $offset): self
    {
        $this->builder->offset($offset);

        return $this;
    }

    /**
     * Run the query and hydrate the rows.
     *
     * @return array<int, Model> The models
     */
    public function get(): array
    {
        /** @var class-string<Model> $model */
        $model = $this->model;

        $models = array_map(
            static fn (array $row): Model => $model::hydrate($row),
            $this->builder->get()
        );

        if ($models !== [] && $this->with !== []) {
            $this->eagerLoad($models);
        }

        return $models;
    }

    /**
     * Run the query and hydrate the first row.
     *
     * @return Model|null The model, or null when no row matches
     */
    public function first(): ?Model
    {
        $row = $this->builder->limit(1)->get();

        if ($row === []) {
            return null;
        }

        /** @var class-string<Model> $model */
        $model = $this->model;
        $instance = $model::hydrate($row[0]);

        if ($this->with !== []) {
            $this->eagerLoad([$instance]);
        }

        return $instance;
    }

    /**
     * Count the matching rows.
     *
     * @return int The number of rows
     */
    public function count(): int
    {
        return $this->builder->count();
    }

    /**
     * Get the SQL without running it.
     *
     * @return string The generated statement
     */
    public function toSql(): string
    {
        return $this->builder->toSql();
    }

    /**
     * Get the underlying Query Builder.
     *
     * The escape hatch: anything this class does not forward is still one call
     * away, and the result is plain arrays again.
     *
     * @return QueryBuilder The builder
     */
    public function builder(): QueryBuilder
    {
        return $this->builder;
    }

    /**
     * Load the declared relations for a set of models, one query per relation.
     *
     * @param array<int, Model> $models The models to attach relations to
     * @return void
     */
    private function eagerLoad(array $models): void
    {
        foreach ($this->with as $name) {
            $relation = $this->describe($models[0], $name);

            if ($relation === null) {
                continue;
            }

            $keys = [];
            foreach ($models as $model) {
                $key = $model->getAttribute($relation->parentKey());

                if ($key !== null) {
                    $keys[(string) $key] = $key;
                }
            }

            $grouped = $keys === [] ? [] : $this->fetchRelated($relation, array_values($keys));

            foreach ($models as $model) {
                $key = (string) $model->getAttribute($relation->parentKey());
                $matches = $grouped[$key] ?? [];

                $model->setRelation(
                    $name,
                    $relation->type === Relation::HAS_MANY ? $matches : ($matches[0] ?? null)
                );
            }
        }
    }

    /**
     * Fetch the related rows and group them by the joining column.
     *
     * @param Relation $relation The relation being loaded
     * @param array<int, mixed> $keys The values to match
     * @return array<string, array<int, Model>> The related models, grouped
     */
    private function fetchRelated(Relation $relation, array $keys): array
    {
        /** @var class-string<Model> $related */
        $related = $relation->related;

        $grouped = [];
        foreach ($related::query()->whereIn($relation->relatedKey(), $keys)->get() as $child) {
            $grouped[(string) $child->getAttribute($relation->relatedKey())][] = $child;
        }

        return $grouped;
    }

    /**
     * Read a relation declaration from a model.
     *
     * @param Model $model The model declaring the relation
     * @param string $name The relation method name
     * @return Relation|null The relation, or null when the name is not one
     */
    private function describe(Model $model, string $name): ?Relation
    {
        if (!method_exists($model, $name)) {
            return null;
        }

        $relation = $model->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }
}

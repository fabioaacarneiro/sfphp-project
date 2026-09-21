<?php

namespace SfphpProject\src\Database;

/**
 * Describes how one model reaches another.
 *
 * A relation is only a description — the related class and the two columns
 * that join them. Nothing is queried until the relation is resolved, either
 * lazily when the property is read or in one batch by ModelQuery::with().
 *
 * Keeping it a plain description rather than a live query object is what makes
 * eager loading possible without a second code path: with() reads the same
 * metadata the lazy read would have used.
 */
final class Relation
{
    public const HAS_MANY = 'hasMany';
    public const HAS_ONE = 'hasOne';
    public const BELONGS_TO = 'belongsTo';
    public const BELONGS_TO_MANY = 'belongsToMany';

    /**
     * Describe a relation.
     *
     * @param string $type One of the class constants
     * @param class-string<Model> $related The model on the other side
     * @param string $foreignKey The column holding the reference
     * @param string $localKey The column the reference points at
     * @param Model $parent The model the relation was read from
     * @param string|null $pivotTable The join table, for a many-to-many relation
     * @param string|null $relatedPivotKey The pivot column pointing at the related model
     */
    public function __construct(
        public readonly string $type,
        public readonly string $related,
        public readonly string $foreignKey,
        public readonly string $localKey,
        public readonly Model $parent,
        public readonly ?string $pivotTable = null,
        public readonly ?string $relatedPivotKey = null
    ) {}

    /**
     * Check whether this relation goes through a join table.
     *
     * @return bool True for a many-to-many relation
     */
    public function isThroughPivot(): bool
    {
        return $this->type === self::BELONGS_TO_MANY;
    }

    /**
     * Resolve the relation with a query of its own.
     *
     * Reading several parents' relations this way is the N+1 problem; use
     * ModelQuery::with() to load them in one query instead.
     *
     * @return array<int, Model>|Model|null The related models
     */
    public function resolve(): array|Model|null
    {
        $value = $this->parent->getAttribute($this->parentKey());

        if ($value === null) {
            return $this->returnsMany() ? [] : null;
        }

        /** @var class-string<Model> $related */
        $related = $this->related;

        if ($this->isThroughPivot()) {
            return $this->throughPivot([$value]);
        }

        $query = $related::query()->where($this->relatedKey(), $value);

        return $this->returnsMany() ? $query->get() : $query->first();
    }

    /**
     * Check whether the relation yields a list rather than a single model.
     *
     * @return bool True when many models are expected
     */
    public function returnsMany(): bool
    {
        return $this->type === self::HAS_MANY || $this->type === self::BELONGS_TO_MANY;
    }

    /**
     * Load the related models through the join table.
     *
     * The pivot column is selected under an alias so the caller can tell which
     * parent each row belongs to — that is what makes eager loading through a
     * pivot possible in one query instead of one per parent.
     *
     * @param array<int, mixed> $values The parent key values to match
     * @return array<int, Model> The related models, each carrying the pivot key
     */
    public function throughPivot(array $values): array
    {
        /** @var class-string<Model> $related */
        $related = $this->related;
        $relatedTable = $related::table();

        return $related::query()
            ->select(
                $relatedTable . '.*',
                $this->pivotTable . '.' . $this->foreignKey . ' AS ' . Model::PIVOT_KEY
            )
            ->join(
                $this->pivotTable,
                $this->pivotTable . '.' . $this->relatedPivotKey,
                '=',
                $relatedTable . '.' . $this->localKey
            )
            ->whereIn($this->pivotTable . '.' . $this->foreignKey, $values)
            ->get();
    }

    /**
     * Get the column on the parent side of the relation.
     *
     * @return string The parent column
     */
    public function parentKey(): string
    {
        if ($this->type === self::BELONGS_TO) {
            return $this->foreignKey;
        }

        /*
         * For a many-to-many relation the parent contributes its own primary
         * key to the pivot, not the column the pivot points at on the other
         * side — that one is $localKey and belongs to the related table.
         */
        if ($this->type === self::BELONGS_TO_MANY) {
            return $this->parent::primaryKey();
        }

        return $this->localKey;
    }

    /**
     * Get the column on the related side of the relation.
     *
     * @return string The related column
     */
    public function relatedKey(): string
    {
        return $this->type === self::BELONGS_TO ? $this->localKey : $this->foreignKey;
    }
}

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

    /**
     * Describe a relation.
     *
     * @param string $type One of the class constants
     * @param class-string<Model> $related The model on the other side
     * @param string $foreignKey The column holding the reference
     * @param string $localKey The column the reference points at
     * @param Model $parent The model the relation was read from
     */
    public function __construct(
        public readonly string $type,
        public readonly string $related,
        public readonly string $foreignKey,
        public readonly string $localKey,
        public readonly Model $parent
    ) {}

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
            return $this->type === self::HAS_MANY ? [] : null;
        }

        /** @var class-string<Model> $related */
        $related = $this->related;
        $query = $related::query()->where($this->relatedKey(), $value);

        return $this->type === self::HAS_MANY ? $query->get() : $query->first();
    }

    /**
     * Get the column on the parent side of the relation.
     *
     * @return string The parent column
     */
    public function parentKey(): string
    {
        return $this->type === self::BELONGS_TO ? $this->foreignKey : $this->localKey;
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

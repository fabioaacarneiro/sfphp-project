<?php

namespace SfphpProject\src\Database;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonException;
use JsonSerializable;
use RuntimeException;
use PDO;
use ReflectionClass;
use SfphpProject\src\Database;
use SfphpProject\src\QueryBuilder;

/**
 * A row, as an object.
 *
 * This is deliberately not a full ORM. There is no identity map, no unit of
 * work, no lazy-loading proxy and no schema derived from the class. What it
 * does give is the part people actually feel: rows arrive as typed objects
 * instead of arrays, relations are declared once instead of joined by hand at
 * every call site, and with() loads them in one query instead of N+1.
 *
 * Everything else stays the Query Builder's job, and Model::query() hands it
 * over whenever this class is in the way.
 *
 *     final class Post extends Model
 *     {
 *         protected static string $table = 'posts';
 *
 *         public function author(): Relation
 *         {
 *             return $this->belongsTo(User::class, 'user_id');
 *         }
 *     }
 *
 *     $post = Post::find(1);
 *     $post->title = 'Novo título';
 *     $post->save();
 *
 *     foreach (Post::query()->with('author')->get() as $post) {
 *         echo $post->author->name;   // one query for every author, not one each
 *     }
 */
abstract class Model implements JsonSerializable
{
    /**
     * Alias the pivot key is selected under when loading a many-to-many relation.
     */
    public const PIVOT_KEY = '__pivot_key';

    /**
     * The table this model reads from. Inferred from the class name when unset.
     */
    protected static string $table = '';

    /**
     * The primary key column.
     */
    protected static string $primaryKey = 'id';

    /**
     * Columns that may be set from an array.
     *
     * A model must declare this before fill() — and therefore before the
     * constructor and create() — will assign anything. Leaving it empty is not
     * "no restriction", it is "this model cannot be mass assigned", and the
     * attempt raises instead of silently succeeding.
     *
     * The reason is the most natural line anyone writes:
     *
     *     User::create($request->all());
     *
     * Without a list, that stores every column the attacker chose to submit.
     * A registration form that never showed an "is_admin" field still writes
     * one if the request carries it. Defaulting to permissive would protect
     * only the developers who already knew to declare the list, which is
     * exactly the wrong set of people.
     *
     * Keys outside the list are dropped rather than raising, so a form that
     * submits an extra field a browser added still works.
     *
     * Use forceFill() for values the application itself chose.
     *
     * @var array<int, string>
     */
    protected static array $fillable = [];

    /**
     * Attribute types, as column name => cast.
     *
     * PDO hands back what the driver gives it, which for a DATETIME column is
     * a string and for a JSON column is also a string. Declaring the type here
     * means the conversion happens once instead of at every call site:
     *
     *     protected static array $casts = [
     *         'published' => 'bool',
     *         'meta' => 'json',
     *         'published_at' => 'datetime',
     *         'price' => 'decimal:2',
     *     ];
     *
     * Available casts: int, float, bool, string, json, array, datetime, date
     * and decimal:N.
     *
     * @var array<string, string>
     */
    protected static array $casts = [];

    /**
     * Connection override, used by tests and by anything running outside a request.
     */
    private static ?PDO $connection = null;

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var array<string, mixed> */
    private array $dirty = [];

    /** @var array<string, mixed> */
    private array $relations = [];

    private bool $exists = false;

    /**
     * Create a model, optionally filling it.
     *
     * @param array<string, mixed> $attributes The initial attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Use a specific connection for every model.
     *
     * @internal Exposed for tests and for scripts running outside a request.
     * @param PDO|null $connection The connection, or null to fall back to Database
     * @return void
     */
    public static function useConnection(?PDO $connection): void
    {
        self::$connection = $connection;
    }

    /**
     * Start a query for this model.
     *
     * @return ModelQuery The query, hydrating into this model
     */
    public static function query(): ModelQuery
    {
        return new ModelQuery(static::class, self::connection(), static::table());
    }

    /**
     * Get every row.
     *
     * @return array<int, static> The models
     */
    public static function all(): array
    {
        return static::query()->get();
    }

    /**
     * Find a row by primary key.
     *
     * @param mixed $id The primary key value
     * @return static|null The model, or null when no row matches
     */
    public static function find(mixed $id): ?static
    {
        return static::query()->where(static::$primaryKey, $id)->first();
    }

    /**
     * Find a row by primary key, or fail.
     *
     * @param mixed $id The primary key value
     * @return static The model
     * @throws RuntimeException When no row matches
     */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            throw new RuntimeException(sprintf(
                'No %s found with %s %s.',
                static::class,
                static::$primaryKey,
                var_export($id, true)
            ));
        }

        return $model;
    }

    /**
     * Insert a row and return the model.
     *
     * @param array<string, mixed> $attributes The column values
     * @return static The saved model
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Build a model from a row that is already in the database.
     *
     * @internal Called by ModelQuery.
     * @param array<string, mixed> $row The row
     * @return static The hydrated model
     */
    public static function hydrate(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->dirty = [];
        $model->exists = true;

        return $model;
    }

    /**
     * Get the table this model reads from.
     *
     * The inference is deliberately simple, and a model with an irregular name
     * should set $table rather than rely on it.
     *
     * @return string The table name
     */
    public static function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }

        $name = strtolower((new ReflectionClass(static::class))->getShortName());

        if (str_ends_with($name, 'y')) {
            return substr($name, 0, -1) . 'ies';
        }

        if (preg_match('/(s|x|z|ch|sh)$/', $name) === 1) {
            return $name . 'es';
        }

        return $name . 's';
    }

    /**
     * Get the primary key column.
     *
     * @return string The column name
     */
    public static function primaryKey(): string
    {
        return static::$primaryKey;
    }

    /**
     * Get the connection every query runs on.
     *
     * @return PDO The connection
     */
    private static function connection(): PDO
    {
        return self::$connection ?? Database::connect();
    }

    /**
     * Start a Query Builder for this model's table.
     *
     * @return QueryBuilder The builder
     */
    private static function builder(): QueryBuilder
    {
        return (new QueryBuilder(self::connection()))->from(static::table());
    }

    /**
     * Set several attributes at once.
     *
     * @param array<string, mixed> $attributes The values to set
     * @return static The model
     */
    public function fill(array $attributes): static
    {
        if ($attributes === []) {
            return $this;
        }

        if (static::$fillable === []) {
            throw new MassAssignmentException(sprintf(
                '%s must declare $fillable before it can be filled from an array. '
                . 'Use forceFill() for values the application itself chose.',
                static::class
            ));
        }

        foreach ($attributes as $key => $value) {
            if (in_array((string) $key, static::$fillable, true)) {
                $this->setAttribute((string) $key, $value);
            }
        }

        return $this;
    }

    /**
     * Set attributes without consulting $fillable.
     *
     * For values the application produced itself — a generated token, a
     * timestamp, a foreign key it just resolved. Never for request data.
     *
     * @param array<string, mixed> $attributes The values to set
     * @return static The model
     */
    public function forceFill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute((string) $key, $value);
        }

        return $this;
    }

    /**
     * Get the columns that may be set from an array.
     *
     * @return array<int, string> The column names
     */
    public static function fillable(): array
    {
        return static::$fillable;
    }

    /**
     * Insert or update the row.
     *
     * An existing model writes only the attributes that changed, so a save
     * after touching one field does not rewrite every column.
     *
     * @return bool True when something was written
     */
    public function save(): bool
    {
        $builder = self::builder();

        if (!$this->exists) {
            $id = $builder->insert($this->forStorage($this->attributes));

            if (!array_key_exists(static::$primaryKey, $this->attributes)) {
                $this->attributes[static::$primaryKey] = $id;
            }

            $this->exists = true;
            $this->dirty = [];

            return true;
        }

        if ($this->dirty === []) {
            return false;
        }

        $builder->where(static::$primaryKey, $this->attributes[static::$primaryKey] ?? null)
            ->update($this->forStorage($this->dirty));

        $this->dirty = [];

        return true;
    }

    /**
     * Delete the row.
     *
     * @return bool True when a row was deleted
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        self::builder()
            ->where(static::$primaryKey, $this->attributes[static::$primaryKey] ?? null)
            ->delete();

        $this->exists = false;

        return true;
    }

    /**
     * Check whether this model came from the database.
     *
     * @return bool True when the row exists
     */
    public function exists(): bool
    {
        return $this->exists;
    }

    /**
     * Get an attribute exactly as it is stored, without applying a cast.
     *
     * Relations join on these values, and a cast would change what they
     * compare against — a key read as an int on one side and a string on the
     * other would silently stop matching. Reading the property applies the
     * cast; this does not.
     *
     * @param string $key The attribute name
     * @return mixed The stored value, or null when absent
     */
    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    /**
     * Get an attribute with its declared cast applied.
     *
     * @param string $key The attribute name
     * @return mixed The converted value
     */
    public function cast(string $key): mixed
    {
        return $this->castFromDatabase($key, $this->attributes[$key] ?? null);
    }

    /**
     * Get every attribute.
     *
     * @return array<string, mixed> The attributes
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * Attach a loaded relation.
     *
     * @internal Called by ModelQuery when eager loading.
     * @param string $name The relation name
     * @param mixed $value The loaded value
     * @return void
     */
    public function setRelation(string $name, mixed $value): void
    {
        $this->relations[$name] = $value;
    }

    /**
     * Check whether a relation has been loaded.
     *
     * @param string $name The relation name
     * @return bool True when loaded
     */
    public function relationLoaded(string $name): bool
    {
        return array_key_exists($name, $this->relations);
    }

    /**
     * Represent the model, and any loaded relations, as an array.
     *
     * @return array<string, mixed> The representation
     */
    public function toArray(): array
    {
        $data = [];

        foreach ($this->attributes as $key => $value) {
            $data[$key] = $this->castForArray((string) $key, $value);
        }

        foreach ($this->relations as $name => $value) {
            if (is_array($value)) {
                $data[$name] = array_map(
                    static fn (Model $model): array => $model->toArray(),
                    $value
                );

                continue;
            }

            $data[$name] = $value instanceof self ? $value->toArray() : $value;
        }

        return $data;
    }

    /**
     * Serialize the model for json_encode(), so Response::json() accepts it.
     *
     * @return array<string, mixed> The representation
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Declare a one-to-many relation.
     *
     * @param class-string<Model> $related The model on the other side
     * @param string $foreignKey The column on the related table
     * @param string|null $localKey The column on this table
     * @return Relation The relation description
     */
    protected function hasMany(string $related, string $foreignKey, ?string $localKey = null): Relation
    {
        return new Relation(
            Relation::HAS_MANY,
            $related,
            $foreignKey,
            $localKey ?? static::$primaryKey,
            $this
        );
    }

    /**
     * Declare a one-to-one relation.
     *
     * @param class-string<Model> $related The model on the other side
     * @param string $foreignKey The column on the related table
     * @param string|null $localKey The column on this table
     * @return Relation The relation description
     */
    protected function hasOne(string $related, string $foreignKey, ?string $localKey = null): Relation
    {
        return new Relation(
            Relation::HAS_ONE,
            $related,
            $foreignKey,
            $localKey ?? static::$primaryKey,
            $this
        );
    }

    /**
     * Declare the inverse of a one-to-many or one-to-one relation.
     *
     * @param class-string<Model> $related The model on the other side
     * @param string $foreignKey The column on this table
     * @param string|null $ownerKey The column on the related table
     * @return Relation The relation description
     */
    protected function belongsTo(string $related, string $foreignKey, ?string $ownerKey = null): Relation
    {
        /** @var class-string<Model> $related */
        return new Relation(
            Relation::BELONGS_TO,
            $related,
            $foreignKey,
            $ownerKey ?? $related::primaryKey(),
            $this
        );
    }

    /**
     * Declare a many-to-many relation through a join table.
     *
     *     public function tags(): Relation
     *     {
     *         return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
     *     }
     *
     * @param class-string<Model> $related The model on the other side
     * @param string $pivotTable The join table
     * @param string $foreignPivotKey The pivot column pointing at this model
     * @param string $relatedPivotKey The pivot column pointing at the related model
     * @param string|null $relatedKey The key on the related table
     * @return Relation The relation description
     */
    protected function belongsToMany(
        string $related,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        ?string $relatedKey = null
    ): Relation {
        /** @var class-string<Model> $related */
        return new Relation(
            Relation::BELONGS_TO_MANY,
            $related,
            $foreignPivotKey,
            $relatedKey ?? $related::primaryKey(),
            $this,
            $pivotTable,
            $relatedPivotKey
        );
    }

    /**
     * Read an attribute or a relation.
     *
     * A relation that has not been loaded is resolved on the spot. Doing that
     * inside a loop is the N+1 problem, which is what with() exists to avoid.
     *
     * @param string $name The attribute or relation name
     * @return mixed The value
     */
    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->relations)) {
            return $this->relations[$name];
        }

        if (array_key_exists($name, $this->attributes)) {
            return $this->castFromDatabase($name, $this->attributes[$name]);
        }

        if (method_exists($this, $name)) {
            $relation = $this->{$name}();

            if ($relation instanceof Relation) {
                return $this->relations[$name] = $relation->resolve();
            }
        }

        return null;
    }

    /**
     * Set an attribute.
     *
     * @param string $name The attribute name
     * @param mixed $value The value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->setAttribute($name, $value);
    }

    /**
     * Check whether an attribute or loaded relation is present.
     *
     * @param string $name The name to check
     * @return bool True when present and not null
     */
    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]) || isset($this->relations[$name]);
    }

    /**
     * Record an attribute and mark it dirty.
     *
     * @param string $key The attribute name
     * @param mixed $value The value
     * @return void
     */
    private function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
        $this->dirty[$key] = $value;
    }

    /**
     * Convert a set of attributes into the values to write.
     *
     * @param array<string, mixed> $attributes The in-memory values
     * @return array<string, mixed> The values to store
     */
    private function forStorage(array $attributes): array
    {
        $storable = [];

        foreach ($attributes as $key => $value) {
            $storable[$key] = $this->castToDatabase((string) $key, $value);
        }

        return $storable;
    }

    /**
     * Apply the declared cast when reading an attribute.
     *
     * @param string $key The attribute name
     * @param mixed $value The stored value
     * @return mixed The converted value
     */
    private function castFromDatabase(string $key, mixed $value): mixed
    {
        $cast = static::$casts[$key] ?? null;

        if ($cast === null || $value === null) {
            return $value;
        }

        [$type, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => (bool) $value,
            'string' => (string) $value,
            'decimal' => round((float) $value, (int) ($argument ?? 2)),
            'json', 'array' => $this->decodeJson($value),
            'datetime', 'date' => $this->toDateTime($value),
            default => $value,
        };
    }

    /**
     * Apply the declared cast when writing an attribute.
     *
     * @param string $key The attribute name
     * @param mixed $value The in-memory value
     * @return mixed The value to store
     */
    private function castToDatabase(string $key, mixed $value): mixed
    {
        $cast = static::$casts[$key] ?? null;

        if ($cast === null || $value === null) {
            return $value;
        }

        [$type, $argument] = array_pad(explode(':', $cast, 2), 2, null);

        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            /*
             * Stored as 1 and 0 rather than true and false: a boolean bound as
             * PDO::PARAM_BOOL lands as an empty string in some drivers, which
             * reads back as neither.
             */
            'bool', 'boolean' => $value ? 1 : 0,
            'string' => (string) $value,
            'decimal' => number_format((float) $value, (int) ($argument ?? 2), '.', ''),
            'json', 'array' => is_string($value)
                ? $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'datetime' => $this->toDateTime($value)?->format('Y-m-d H:i:s'),
            'date' => $this->toDateTime($value)?->format('Y-m-d'),
            default => $value,
        };
    }

    /**
     * Convert a cast value into something json_encode() renders usefully.
     *
     * A DateTimeImmutable encodes as an object full of internal fields, which
     * is not what an API consumer wants, so dates become ISO 8601 strings.
     *
     * @param string $key The attribute name
     * @param mixed $value The stored value
     * @return mixed The representable value
     */
    private function castForArray(string $key, mixed $value): mixed
    {
        $cast = $this->castFromDatabase($key, $value);

        return $cast instanceof DateTimeImmutable ? $cast->format(DATE_ATOM) : $cast;
    }

    /**
     * Decode a JSON attribute, tolerating a value that is already decoded.
     *
     * @param mixed $value The stored value
     * @return mixed The decoded value
     */
    private function decodeJson(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * Convert a value into a date, tolerating one that already is one.
     *
     * @param mixed $value The stored value
     * @return DateTimeImmutable|null The date, or null when unparsable
     */
    private function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception) {
            return null;
        }
    }
}

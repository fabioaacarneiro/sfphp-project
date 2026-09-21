<?php

namespace SfphpProject\src\Console\Generators;

/**
 * Generates model skeleton files.
 */
final class ModelGenerator extends GeneratorBase
{
    public function generate(string $name): string
    {
        $name = $this->validateName($name);
        $namespace = $this->getNamespace('app/models');
        $filePath = $this->getFilePath('app/models', $name);
        $table = strtolower($name) . 's';

        $content = <<<'PHP'
<?php

namespace {NAMESPACE};

use SfphpProject\src\Database\Model;
use SfphpProject\src\Database\Relation;

/**
 * {CLASS} model.
 */
final class {CLASS} extends Model
{
    /**
     * The table this model reads from.
     */
    protected static string $table = '{TABLE}';

    /**
     * Columns that may be set from an array.
     *
     * Required before this model can be filled. List only what a form is
     * allowed to submit — never a column the application decides for itself,
     * such as a role, a balance or a verification flag. Use forceFill() for
     * those.
     *
     * @var array<int, string>
     */
    protected static array $fillable = [
        // 'title',
        // 'body',
    ];

    /*
     * Declare relations as methods returning a Relation. Reading the property
     * of the same name resolves it:
     *
     *     public function author(): Relation
     *     {
     *         return $this->belongsTo(User::class, 'user_id');
     *     }
     *
     *     public function comments(): Relation
     *     {
     *         return $this->hasMany(Comment::class, '{TABLE_SINGULAR}_id');
     *     }
     *
     * Reading a relation inside a loop runs one query per row. Load them in
     * one query instead:
     *
     *     {CLASS}::query()->with('author')->get();
     */
}
PHP;

        $content = str_replace(
            ['{NAMESPACE}', '{CLASS}', '{TABLE_SINGULAR}', '{TABLE}'],
            [$namespace, $name, strtolower($name), $table],
            $content
        );

        return $this->writeFile($filePath, $content);
    }
}

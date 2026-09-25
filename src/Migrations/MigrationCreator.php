<?php

namespace SfphpProject\src\Migrations;

use InvalidArgumentException;
use RuntimeException;

/**
 * Creates new migration files.
 */
final class MigrationCreator
{
    /**
     * Create a migration creator.
     *
     * @param string $directory The migrations directory
     */
    public function __construct(private string $directory) {}

    /**
     * Create a new migration stub.
     *
     * @param string $name The migration name
     * @return string The created file path
     */
    public function create(string $name): string
    {
        $normalized = $this->normalizeName($name);
        $this->ensureDirectory();

        $file = sprintf('%s_%s.php', $this->nextStamp(), $normalized);

        $path = rtrim($this->directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $file;
        if (is_file($path)) {
            throw new RuntimeException("Migration file already exists: $path");
        }

        file_put_contents($path, $this->stub($normalized));

        return $path;
    }

    /**
     * A timestamp later than every migration already in the directory.
     *
     * Migrations run in file-name order, and two made in the same second
     * shared a timestamp: "add_status_to_orders" sorted before
     * "create_orders", so the ALTER would have run before the table existed.
     * A clock that went backwards has the same effect. The stamp is now, or
     * one second past the newest existing one, whichever is later.
     *
     * @return string The stamp, as Y_m_d_His in UTC
     */
    private function nextStamp(): string
    {
        $next = time();

        foreach (glob(rtrim($this->directory, DIRECTORY_SEPARATOR) . '/*.php') ?: [] as $existing) {
            if (preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})(\d{2})(\d{2})_/', basename($existing), $m) === 1) {
                $at = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
                $next = max($next, $at + 1);
            }
        }

        return gmdate('Y_m_d_His', $next);
    }

    /**
     * Normalise a migration name for file generation.
     *
     * @param string $name The raw migration name
     * @return string
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9_-]+/', '_', $name) ?? '';
        $name = preg_replace('/_+/', '_', $name) ?? '';
        $name = trim($name, '_-');

        if ($name === '') {
            throw new InvalidArgumentException('Migration name cannot be empty.');
        }

        return $name;
    }

    /**
     * Ensure the migrations directory exists.
     *
     * @return void
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        if (!mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create migrations directory: {$this->directory}");
        }
    }

    /**
     * Render the migration stub content.
     *
     * @param string $name The migration name
     * @return string
     */
    private function stub(string $name): string
    {
        $humanName = str_replace('_', ' ', $name);

        return <<<PHP
<?php

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Migration;
use SfphpProject\src\Migrations\Schema;

return new class extends Migration
{
    public function up(Schema \$schema): void
    {
        // \$schema->create('$humanName', function (Blueprint \$table): void {
        //     \$table->id();
        //     \$table->timestamps();
        // });
    }

    public function down(Schema \$schema): void
    {
        // \$schema->dropIfExists('$humanName');
    }
};
PHP;
    }
}

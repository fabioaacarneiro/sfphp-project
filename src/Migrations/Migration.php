<?php

namespace SfphpProject\src\Migrations;

/**
 * Base class for generated migrations.
 */
abstract class Migration
{
    /**
     * Apply the migration.
     *
     * @param Schema $schema The schema builder
     * @return void
     */
    abstract public function up(Schema $schema): void;

    /**
     * Revert the migration.
     *
     * @param Schema $schema The schema builder
     * @return void
     */
    abstract public function down(Schema $schema): void;
}

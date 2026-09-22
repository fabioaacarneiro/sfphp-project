<?php

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Migration;
use SfphpProject\src\Migrations\Schema;

/**
 * The table the "database" session driver writes to.
 *
 * Only needed with SESSION_DRIVER=database. It is a migration rather than a
 * table created on demand because a session store that quietly issues DDL is a
 * session store that can fail halfway through a request, on a connection
 * without rights to create tables.
 */
return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('sessions', function (Blueprint $table): void {
            /*
             * The session id is the key. PHP's default is 32 characters, and a
             * deployment that raises session.sid_length needs room for it.
             */
            $table->string('id', 128);
            $table->primary('id');

            $table->text('payload');

            /*
             * An integer, not a datetime. The comparison then never depends on
             * how the column's time zone was interpreted, and a session store
             * is the last place worth having that argument.
             */
            $table->unsignedBigInteger('expires_at');
            $table->index('expires_at');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('sessions');
    }
};

<?php

use SfphpProject\src\Migrations\Blueprint;
use SfphpProject\src\Migrations\Migration;
use SfphpProject\src\Migrations\Schema;

return new class extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            /*
             * Sized for the longest hash password_hash() can return today,
             * with room for a future algorithm: bcrypt is 60 characters and
             * argon2id is longer.
             */
            $table->string('password', 255);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};

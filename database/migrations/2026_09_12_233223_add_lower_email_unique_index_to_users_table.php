<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * users_email_unique stays: the framework looks accounts up by exact address, which this index cannot serve.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX users_lower_email_unique ON users (lower(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_lower_email_unique');
    }
};

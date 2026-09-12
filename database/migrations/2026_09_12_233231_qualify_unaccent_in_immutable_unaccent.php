<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // pg_dump and pg_restore run with an empty search_path, and tasks.search_vector calls this on every restored row.
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$
        SQL);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT unaccent('unaccent', $1) $$
        SQL);
    }
};

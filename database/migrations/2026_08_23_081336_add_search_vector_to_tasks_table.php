<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        // unaccent() is only STABLE, and a generated column requires an IMMUTABLE function.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text
            LANGUAGE sql
            IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT unaccent('unaccent', $1) $$
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tasks ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector(
                    'simple',
                    immutable_unaccent(coalesce(title, '') || ' ' || coalesce(description, ''))
                )
            ) STORED
        SQL);

        DB::statement('CREATE INDEX tasks_search_vector_index ON tasks USING gin (search_vector)');
    }

    public function down(): void
    {
        Schema::table('tasks', function ($table): void {
            $table->dropColumn('search_vector');
        });

        DB::statement('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
    }
};

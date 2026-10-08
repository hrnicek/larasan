<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// PostgreSQL cannot change a generated column's expression, so the column is dropped and re-added.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks DROP COLUMN search_vector');

        DB::statement(<<<'SQL'
            ALTER TABLE tasks ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector(
                    'simple',
                    immutable_unaccent(
                        coalesce(title, '')
                        || ' '
                        || regexp_replace(coalesce(description, ''), '<[^>]*>', ' ', 'g')
                    )
                )
            ) STORED
        SQL);

        DB::statement('CREATE INDEX tasks_search_vector_index ON tasks USING gin (search_vector)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tasks DROP COLUMN search_vector');

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
};

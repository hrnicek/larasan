<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What search reads (ADR-0012).
 *
 * A **stored generated column** rather than a trigger: it cannot drift from the row it
 * describes, and nothing has to remember to update it.
 *
 * `unaccent` is wrapped in an IMMUTABLE function because the extension's own is only STABLE —
 * PostgreSQL refuses a generated column built from a function it cannot promise will give the
 * same answer tomorrow. The wrapper names the dictionary explicitly, which is what makes that
 * promise true.
 *
 * The `simple` configuration, not a language's: a workspace writing in one language should not
 * have its search shaped by a stemmer chosen for another, and prefix matching (`term:*`) is what
 * this application uses instead of stemming (ADR-0012).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

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

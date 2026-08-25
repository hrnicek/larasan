<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A description is rich text now, so the column holds markup — and the generated column that
 * feeds search was reading it word for word.
 *
 * Left alone, `p`, `li`, `strong`, `href` and every attribute value become terms: searching for
 * *strong* would return every task somebody had emboldened a word in, and the task that actually
 * says "strong" would be one result among them.
 *
 * `regexp_replace` is IMMUTABLE, which is what a stored generated column requires — the same
 * constraint that made `immutable_unaccent` necessary in the first place. A space rather than an
 * empty string, so `one</p><p>two` does not index as `onetwo`.
 *
 * The column has to be dropped and re-added: PostgreSQL has no way to change the expression
 * behind a generated column, and the index goes with it.
 */
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

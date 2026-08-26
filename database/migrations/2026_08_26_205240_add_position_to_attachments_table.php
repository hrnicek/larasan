<?php

declare(strict_types=1);

use App\Domain\Shared\Ordering\SparsePosition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order somebody put their files in.
 *
 * Sparse integers, the same arithmetic sections and placements use (ADR-0009), because the same
 * two things are true here: a move should write one row, and two moves computing the same slot
 * must collide loudly rather than leave the order ambiguous.
 *
 * Which matters more than it does for a section, because the board card draws *the first* image
 * of a task — so "first" has to be a decision somebody made rather than whichever row PostgreSQL
 * happened to return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->integer('position')->nullable();
        });

        /*
         * Backfilled in the order the relation already read in — oldest first, the id breaking
         * the tie — so nothing moves on the day this runs. Done in the database rather than in
         * PHP because it is one statement over every row, and a chunked loop here would be a
         * slower way to write the same thing.
         */
        DB::statement(<<<'SQL'
            UPDATE attachments
               SET position = ranked.rank * :gap
              FROM (
                    SELECT id,
                           row_number() OVER (
                               PARTITION BY attachable_type, attachable_id
                               ORDER BY created_at, id
                           ) AS rank
                      FROM attachments
                   ) ranked
             WHERE attachments.id = ranked.id
            SQL, ['gap' => SparsePosition::GAP]);

        Schema::table('attachments', function (Blueprint $table): void {
            $table->integer('position')->nullable(false)->change();

            /*
             * The slot guard, and the index the ordered read uses. Signed rather than unsigned
             * because normalisation parks every row in negative space before writing its final
             * position — the constraint is what makes that dance necessary and what makes it
             * safe.
             */
            $table->unique(['attachable_type', 'attachable_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropUnique(['attachable_type', 'attachable_id', 'position']);
            $table->dropColumn('position');
        });
    }
};

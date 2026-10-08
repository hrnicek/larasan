<?php

declare(strict_types=1);

use App\Domain\Shared\Ordering\SparsePosition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->integer('position')->nullable();
        });

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

            // Positions stay signed: normalisation parks rows at negative positions to avoid colliding here.
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

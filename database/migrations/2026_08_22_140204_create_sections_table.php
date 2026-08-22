<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();

            // User content (ADR-0004). No application logic reads it: "Done" is a column
            // somebody named, and completion is `tasks.completed_at`.
            $table->string('name');
            $table->string('color')->nullable();

            /*
             * Sparse, allocated with a gap of 65536 (ADR-0009), so inserting between two
             * neighbours writes one row instead of rewriting the tail.
             */
            $table->integer('position');

            $table->timestamps();

            /*
             * Both the ordered read and the collision guard. Two moves computing the same
             * midpoint must meet a database error rather than quietly producing two
             * sections in one slot, and the index this constraint creates is the one
             * `order by position` uses.
             */
            $table->unique(['project_id', 'position']);
        });

        /*
         * The same palette constraint `projects.color` carries: the column is cast to an
         * enum, and a value outside it is accepted silently and then throws inside the cast
         * on every read of the row. NULL stays legal — an uncoloured section inherits the
         * neutral default.
         */
        $values = implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));

        DB::statement("ALTER TABLE sections ADD CONSTRAINT sections_color_check CHECK (color IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('sections');
    }
};

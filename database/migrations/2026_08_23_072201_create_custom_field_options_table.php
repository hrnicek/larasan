<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The choices a `select` field offers.
 *
 * Scoped by joining its field (ADR-0005) rather than carrying a workspace of its own. The
 * position is a plain integer with a unique constraint: an option list is short and reordered
 * whole, so the sparse-position machinery tasks and sections need would be weight for nothing
 * (ADR-0009 is about lists people drag one item at a time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_options', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('custom_field_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('color')->nullable();
            $table->unsignedInteger('position');
            $table->timestamps();

            // Two options cannot claim one slot, so the order a screen draws is the order the
            // table states rather than whichever row PostgreSQL returned first.
            $table->unique(['custom_field_id', 'position']);
        });

        $values = implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));

        DB::statement("ALTER TABLE custom_field_options ADD CONSTRAINT custom_field_options_color_check CHECK (color IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_options');
    }
};

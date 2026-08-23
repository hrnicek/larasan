<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A name and a colour, per workspace.
 *
 * The uniqueness is **case-insensitive**: "Bug" and "bug" are one tag to everybody except a
 * database, and a workspace that ends up with both has a filter that quietly finds half the
 * work. PostgreSQL expresses that as a unique index on `lower(name)`, which is a thing a plain
 * `unique()` cannot say.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Direct, and indexed by the unique index below: a tag belongs to a workspace and
            // to nothing smaller (ADR-0005).
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The accent palette, reused rather than a second one invented — the name is stored
            // so it can be re-tuned globally without a data migration (`ProjectColor`).
            $table->string('color')->nullable();

            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX tags_workspace_id_lower_name_unique ON tags (workspace_id, lower(name))');

        /*
         * The colour is cast to an enum, and a value outside it is accepted silently by the
         * column and then throws inside the cast on every request that reads the row — the same
         * reason `projects.color` carries this constraint.
         */
        $values = implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));

        DB::statement("ALTER TABLE tags ADD CONSTRAINT tags_color_check CHECK (color IN ({$values}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};

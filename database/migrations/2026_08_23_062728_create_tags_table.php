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
        Schema::create('tags', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            $table->string('color')->nullable();

            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX tags_workspace_id_lower_name_unique ON tags (workspace_id, lower(name))');

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

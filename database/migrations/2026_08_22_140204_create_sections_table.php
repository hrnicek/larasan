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

            $table->string('name');
            $table->string('color')->nullable();

            $table->integer('position');

            $table->timestamps();

            $table->unique(['project_id', 'position']);
        });

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

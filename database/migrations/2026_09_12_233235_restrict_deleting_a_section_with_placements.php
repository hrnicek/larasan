<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Nulling section_id keeps each card's position, which can collide in the ungrouped bucket. DeleteSection moves the cards first.
    public function up(): void
    {
        Schema::table('task_project_memberships', function (Blueprint $table): void {
            $table->dropForeign(['section_id']);

            $table->foreign('section_id')->references('id')->on('sections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_project_memberships', function (Blueprint $table): void {
            $table->dropForeign(['section_id']);

            $table->foreign('section_id')->references('id')->on('sections')->nullOnDelete();
        });
    }
};

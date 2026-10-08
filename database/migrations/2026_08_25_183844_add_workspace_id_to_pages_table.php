<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The search engine cannot join, so a page must carry its own tenant. See ADR-0016.
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->foreignUuid('workspace_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE pages SET workspace_id = projects.workspace_id FROM projects WHERE projects.id = pages.project_id');

        Schema::table('pages', function (Blueprint $table): void {
            $table->uuid('workspace_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};

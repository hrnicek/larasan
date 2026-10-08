<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ui_theme', 32)
                ->default(UiTheme::default()->value)
                ->after('current_workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_theme');
        });
    }
};

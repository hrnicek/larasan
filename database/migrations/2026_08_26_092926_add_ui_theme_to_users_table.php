<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The surface scheme travels with the person rather than with the browser (ADR-0019), which
     * is the difference between this and `appearance`: light or dark is a property of the room
     * you are sitting in, and a theme is a property of you.
     *
     * A string rather than a database enum, so retiring a scheme is a code change and not a
     * migration. `UiTheme::tryFrom()` is what turns a value nobody ships any more back into the
     * default.
     */
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

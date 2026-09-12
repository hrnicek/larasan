<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('ui_theme', array_column(UiTheme::cases(), 'value'))
            ->update(['ui_theme' => UiTheme::default()->value]);
    }

    public function down(): void {}
};

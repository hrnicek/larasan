<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `paper` and `carbon` were replaced by the five schemes in ADR-0019. The column is a plain
     * string so nothing in the database objects to a value the code no longer ships — but
     * `UiTheme::class` is an enum cast, and reading such a row throws rather than degrading. A
     * cookie holding a retired value is already safe (`HandleUiTheme` uses `tryFrom`); a stored
     * one is not, which is what this corrects.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('ui_theme', array_column(UiTheme::cases(), 'value'))
            ->update(['ui_theme' => UiTheme::default()->value]);
    }

    /**
     * Nothing to restore: the retired values named schemes that no longer have tokens, so putting
     * them back would leave those rows selecting a block that is not in the stylesheet.
     */
    public function down(): void {}
};

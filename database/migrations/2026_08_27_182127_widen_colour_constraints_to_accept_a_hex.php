<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\ProjectColor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A colour is one of the eight names or a hex somebody chose (ADR-0021).
 *
 * The four constraints existed for one reason — a value outside the enum was accepted silently by
 * the column and then threw inside the cast on every request that read the row — and that reason
 * is unchanged. What widens is the set: the names, or `#rrggbb` in lower case. Case is the
 * application's job on the way in, and the pattern refuses upper case so a row that skipped
 * `AccentColor` cannot make two colours out of one.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = ['projects', 'sections', 'tags', 'custom_field_options'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_color_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_color_check CHECK (
                color IS NULL OR color IN ({$this->names()}) OR color ~ '^#[0-9a-f]{6}$'
            )");
        }
    }

    public function down(): void
    {
        // Every hex becomes no colour rather than an invalid one: the constraint below would
        // refuse the row, and a colour is the one thing here that can be lost without losing work.
        foreach (self::TABLES as $table) {
            DB::table($table)->where('color', 'like', '#%')->update(['color' => null]);

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$table}_color_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_color_check CHECK (color IN ({$this->names()}))");
        }
    }

    private function names(): string
    {
        return implode(', ', array_map(
            fn (ProjectColor $case): string => "'".$case->value."'",
            ProjectColor::cases(),
        ));
    }
};

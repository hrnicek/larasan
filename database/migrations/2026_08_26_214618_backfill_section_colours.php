<?php

declare(strict_types=1);

use App\Domain\Section\Data\CreateSectionData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every column that has never been given a colour gets the neutral one.
 *
 * A null colour draws no band at all, which reads as a column somebody forgot rather than a
 * column nobody has coloured — and next to one that *has* a colour, the difference looks like a
 * bug. Slate is what a column starts as from now on (`CreateSectionData::DEFAULT_COLOR`), and
 * this is the same statement about the columns that already exist.
 *
 * Deliberately not a NOT NULL column: the palette's *Clear* still means something, and a column
 * somebody has cleared is a decision rather than an absence.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sections')
            ->whereNull('color')
            ->update(['color' => CreateSectionData::DEFAULT_COLOR->value]);
    }

    /**
     * Irreversible in the only sense that matters: which columns were null before this ran is
     * not recorded anywhere, and clearing every slate column on the way down would throw away
     * colours somebody chose.
     */
    public function down(): void {}
};

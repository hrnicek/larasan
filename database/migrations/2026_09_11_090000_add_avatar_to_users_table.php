<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A face is one of the illustrations the application ships, or a picture somebody uploaded —
     * never both, and never an illustration that does not exist. Both rules are constraints
     * rather than validation, because a console command or a seeder arrives without a request.
     *
     * The bound is written out rather than read from `AvatarPresets::COUNT`: a migration records
     * what the schema was when it ran, and shipping a twenty-seventh illustration is a new
     * migration widening this one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->smallInteger('avatar_preset')->nullable()->after('ui_theme');
            $table->string('avatar_path')->nullable()->after('avatar_preset');
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_avatar_preset_known CHECK (avatar_preset BETWEEN 1 AND 26)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_avatar_one_source CHECK (avatar_preset IS NULL OR avatar_path IS NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_avatar_one_source');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_avatar_preset_known');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['avatar_preset', 'avatar_path']);
        });
    }
};

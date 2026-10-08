<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('user_id');
        });

        DB::statement('ALTER TABLE workspace_memberships ALTER COLUMN user_id DROP NOT NULL');

        // The (workspace_id, user_id) unique index ignores null user_ids, so unclaimed invites need this one.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_memberships_workspace_id_email_unique
            ON workspace_memberships (workspace_id, email)
            WHERE user_id IS NULL
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_memberships
            ADD CONSTRAINT workspace_memberships_subject_check
            CHECK (user_id IS NOT NULL OR email IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE workspace_memberships
            ADD CONSTRAINT workspace_memberships_email_lowercase_check
            CHECK (email IS NULL OR email = lower(email))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workspace_memberships DROP CONSTRAINT IF EXISTS workspace_memberships_email_lowercase_check');
        DB::statement('ALTER TABLE workspace_memberships DROP CONSTRAINT IF EXISTS workspace_memberships_subject_check');
        DB::statement('DROP INDEX IF EXISTS workspace_memberships_workspace_id_email_unique');

        DB::table('workspace_memberships')->whereNull('user_id')->delete();

        DB::statement('ALTER TABLE workspace_memberships ALTER COLUMN user_id SET NOT NULL');

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
    }
};

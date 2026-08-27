<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An invitation is addressed to an email. Whether an account answers to it is a fact about
 * the world that can change between the invitation being sent and being accepted, so the
 * membership row holds the address and fills `user_id` in when there is one.
 *
 * The alternative was a second table of pending invitations. It would have duplicated role,
 * deadline, inviter and status — and with them the sweep that expires them, the answer
 * Action and the screen — for the sole difference that one of the two knows a user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->string('email')->nullable()->after('user_id');
        });

        DB::statement('ALTER TABLE workspace_memberships ALTER COLUMN user_id DROP NOT NULL');

        /*
         * `UNIQUE(workspace_id, user_id)` stops counting once user_id is null — Postgres
         * treats every null as distinct — so the address needs a uniqueness rule of its own
         * for exactly the rows that index no longer covers. Cancelling an unclaimed
         * invitation deletes the row rather than revoking it, which is what keeps this from
         * blocking a fresh invitation to an address that was invited and thought better of.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX workspace_memberships_workspace_id_email_unique
            ON workspace_memberships (workspace_id, email)
            WHERE user_id IS NULL
        SQL);

        // A row that names neither a person nor an address is a membership of nobody.
        DB::statement(<<<'SQL'
            ALTER TABLE workspace_memberships
            ADD CONSTRAINT workspace_memberships_subject_check
            CHECK (user_id IS NOT NULL OR email IS NOT NULL)
        SQL);

        /*
         * The application lower-cases on the way in; this catches the row that skipped it,
         * because two cases of one address would otherwise be two invitations and the index
         * above would allow both.
         */
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

        // Rows without a user cannot survive the column becoming required again.
        DB::table('workspace_memberships')->whereNull('user_id')->delete();

        DB::statement('ALTER TABLE workspace_memberships ALTER COLUMN user_id SET NOT NULL');

        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropColumn('email');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            /*
             * The invitation's own deadline. Nullable because an accepted membership has
             * none: expiry belongs to the invitation, not to the person. Deriving it from
             * created_at plus a config constant was the alternative, and it cannot express
             * an inviter choosing a different window.
             */
            $table->timestamp('expires_at')->nullable()->after('joined_at');

            /*
             * Nulled rather than cascaded when the inviter's account goes: losing the
             * inviter must not remove the member they invited. The column is provenance,
             * not a dependency.
             */
            $table->foreignId('invited_by')->nullable()->after('expires_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_memberships', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('expires_at');
        });
    }
};

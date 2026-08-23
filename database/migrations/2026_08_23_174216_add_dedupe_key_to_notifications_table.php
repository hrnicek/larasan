<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What makes a notification the same notification (TASK-180-021).
 *
 * The listeners that send these are queued, and a queued job that fails after its insert is
 * retried up to three times — so a worker that died between writing the row and finishing its
 * job used to leave a second line in somebody's Inbox for the same comment.
 *
 * A key rather than a check in the listener: two workers can pass a check at the same moment,
 * and a unique index cannot. Nullable, because a notification with nothing to be deduplicated
 * by — one that is genuinely new every time — should not be forced to invent a key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('dedupe_key')->nullable()->after('type');

            /*
             * Per person, because the same comment notifies several people and each of them is
             * entitled to their own line. PostgreSQL treats nulls as distinct, so the rows with
             * no key never compete for a slot.
             *
             * **`dedupe_key` leads deliberately.** Written the other way round — notifiable
             * first — this index answers "all of this person's notifications" and the planner
             * chose it for the Inbox read, seeking on the person and then filtering the
             * workspace and the read state it could have sought. Leading with the selective
             * column makes it useless for anything but the question it exists for, which is
             * what `IndexPlanTest` caught within the hour (TASK-180-004, TASK-180-021).
             */
            $table->unique(['dedupe_key', 'notifiable_type', 'notifiable_id'], 'notifications_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_dedupe_unique');
            $table->dropColumn('dedupe_key');
        });
    }
};

<?php

declare(strict_types=1);

use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Asserted through raw inserts, before a model exists, so what is proven is the database's
 * behaviour rather than a model's.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertNotification(Workspace $workspace, User $notifiable, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('notifications')->insert([
        'id' => $id,
        'workspace_id' => $workspace->id,
        'notifiable_type' => 'user',
        'notifiable_id' => $notifiable->id,
        'type' => 'App\\Notifications\\TaskAssigned',
        'data' => json_encode(['task_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);

    return $id;
}

it('carries the workspace the framework s own table does not', function (): void {
    /*
     * Laravel's default notifications table assumes a notification belongs to a person and
     * nothing else. The Inbox here is workspace-scoped and the shell's badge is a per-workspace
     * count, so without this column every badge would have to load whatever each row points at.
     */
    expect(Schema::hasColumn('notifications', 'workspace_id'))->toBeTrue();
});

it('goes with the workspace', function (): void {
    $workspace = Workspace::factory()->create();
    insertNotification($workspace, memberOf($workspace));

    $workspace->delete();

    expect(DB::table('notifications')->count())->toBe(0);
});

it('goes with the account it was addressed to', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace);
    $other = memberOf($workspace);
    insertNotification($workspace, $member);
    insertNotification($workspace, $other);

    $member->delete();

    /*
     * A notification is addressed to one person and means nothing without them — the opposite
     * of a comment or an activity, which outlive their author because other people took part.
     *
     * `notifiable_id` is a morph column and cannot carry a foreign key, so this cleanup is the
     * domain's rather than the schema's (TASK-110-017), and somebody else's inbox is not
     * touched by it.
     */
    expect(DB::table('notifications')->where('notifiable_id', $member->id)->count())->toBe(0)
        ->and(DB::table('notifications')->where('notifiable_id', $other->id)->count())->toBe(1);
});

it('starts unread', function (): void {
    $workspace = Workspace::factory()->create();
    $id = insertNotification($workspace, memberOf($workspace));

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->toBeNull();
});

it('refuses a notification missing anything it needs', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace);

    foreach (['workspace_id', 'notifiable_type', 'notifiable_id', 'type', 'data'] as $column) {
        expect(fn (): string => DB::transaction(fn (): string => insertNotification($workspace, $member, [$column => null])))
            ->toThrow(QueryException::class);
    }
});

it('indexes the inbox read and the badge read, and neither prefix', function (): void {
    $indexes = collect(Schema::getIndexes('notifications'))->pluck('columns');

    expect($indexes)->toContain(['notifiable_type', 'notifiable_id', 'read_at'])
        ->and($indexes)->toContain(['workspace_id', 'notifiable_id', 'read_at'])
        // `morphs()` would have added this one — a prefix of the first, paid for on every
        // write and never chosen (TASK-070-002).
        ->and($indexes)->not->toContain(['notifiable_type', 'notifiable_id']);
});

it('plans the badge s unread count as an index scan', function (): void {
    $workspace = Workspace::factory()->create();
    $member = memberOf($workspace);
    $rows = [];

    /*
     * Three thousand notifications across twenty accounts, `ANALYZE`d, most of them read: the
     * badge's query is a needle in a table that only ever grows, and it runs on every page.
     */
    foreach (range(1, 20) as $ignored) {
        $notifiable = memberOf($workspace);

        foreach (range(1, 150) as $index) {
            $rows[] = [
                'id' => (string) Str::uuid7(),
                'workspace_id' => $workspace->id,
                'notifiable_type' => 'user',
                'notifiable_id' => $notifiable->id,
                'type' => 'App\\Notifications\\TaskAssigned',
                'data' => json_encode(['task_id' => (string) Str::uuid7()], JSON_THROW_ON_ERROR),
                'read_at' => $index % 10 === 0 ? null : now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }

    DB::statement('ANALYZE notifications');

    $query = DB::table('notifications')
        ->selectRaw('count(*)')
        ->where('workspace_id', $workspace->id)
        ->where('notifiable_id', $member->id)
        ->whereNull('read_at');

    $explained = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    /** @var string $json */
    $json = ((array) $explained[0])['QUERY PLAN'];
    $plan = (string) json_encode(json_decode($json, true, 512, JSON_THROW_ON_ERROR));

    // An index the planner ignores is not an index the query has (TASK-070-002).
    expect($plan)->toContain('notifications_workspace_id_notifiable_id_read_at_index')
        ->and($plan)->not->toContain('Seq Scan');
});

<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Notification\Notifications\CommentPostedNotification;
use App\Domain\Notification\Notifications\TaskAssignedNotification;
use App\Domain\Shared\Enums\ActivityType;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectIcon;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

/**
 * A workspace that has been used for a year.
 *
 * Fifty projects, tens of people and thousands of tasks, with comments, history, files,
 * tags, custom field values and an inbox behind them — the volume the application will
 * actually meet, rather than the six cards `DevelopmentSeeder` puts on one board. What it
 * is for is the questions volume asks and a demo dataset cannot: which listing query goes
 * quadratic, which sidebar cap matters, what a year of activity does to a task panel.
 *
 * **Rows are written directly, not through the domain Actions.** This is the one seeder
 * where that is the right trade, for two reasons that are not about speed: an Action stamps
 * `now()`, so nothing it creates can have happened last March, and a year of history is the
 * entire point here. Every shape written below is one the Actions produce — an owner
 * membership beside every `owner_id`, a placement per project rather than a column on the
 * task, `completed_by` set wherever `completed_at` is, activity for the events that record
 * it — and where the two could drift, the enum, the constant or the model is read rather
 * than copied.
 *
 * Deterministic: the same seed produces the same workspace, so a query plan measured today
 * can be measured again tomorrow. Idempotent by refusal — it declines to run twice rather
 * than doubling everything, because merging into a dataset like this one is not a thing a
 * seeder can do cheaply or correctly.
 *
 * @phpstan-type SeedPerson array{id: int, name: string, role: WorkspaceRole, status: WorkspaceMembershipStatus, joined: CarbonImmutable}
 * @phpstan-type SeedColumn array{id: string, name: string}
 * @phpstan-type SeedProject array{id: string, name: string, created: CarbonImmutable, columns: list<SeedColumn>, owner: int, editors: list<int>, commenters: list<int>, fields: list<int>}
 * @phpstan-type SeedField array{id: string, type: CustomFieldType, options: list<string>}
 * @phpstan-type SeedTask array{id: string, created: CarbonImmutable, creator: int, assignee: int|null, completed: CarbonImmutable|null, editors: list<int>, commenters: list<int>}
 */
class HeavySeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const PRIMARY_EMAIL = 'hrncir@example.com';

    private const WORKSPACE_NAME = 'Northwind';

    private const MAIL_DOMAIN = 'northwind.test';

    /** One seed for the whole run, so two runs of the same scale produce the same rows. */
    private const RANDOM_SEED = 20260825;

    private const CHUNK = 500;

    public function __construct(
        private readonly int $peopleCount = 42,
        private readonly int $projectCount = 50,
        private readonly int $taskCount = 6000,
        private readonly int $dayCount = 365,
    ) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Seeding is refused in production: these seeders create accounts with a known password.');
        }

        if (Workspace::query()->where('slug', Str::slug(self::WORKSPACE_NAME))->exists()) {
            $this->command->warn(self::WORKSPACE_NAME.' already exists — nothing was seeded. Rebuild with `php artisan migrate:fresh --seed` first.');

            return;
        }

        mt_srand(self::RANDOM_SEED);
        fake()->seed(self::RANDOM_SEED);

        $now = CarbonImmutable::now();
        $opened = $now->subDays($this->dayCount)->startOfDay();

        DB::transaction(function () use ($opened, $now): void {
            $people = $this->people($opened, $now);
            $workspace = $this->workspace($people[0]['id'], $opened);

            $this->memberships($workspace, $people);
            $this->currentWorkspace($workspace, $people);

            $tags = $this->tags($workspace, $opened);
            $fields = $this->customFields($workspace, $opened);
            $projects = $this->projects($workspace, $people, $fields, $opened, $now);

            $tasks = $this->tasks($workspace, $projects, $tags, $fields, $opened, $now);

            $this->conversation($workspace, $tasks, $now);
            $this->history($workspace, $tasks);
            $this->files($workspace, $tasks, $now);
            $this->stars($projects, $people, $now);
        });

        $this->report();
    }

    /**
     * The accounts, the first of which is the one a developer logs in as.
     *
     * @return list<SeedPerson>
     */
    private function people(CarbonImmutable $opened, CarbonImmutable $now): array
    {
        $people = [[
            'id' => $this->primary()->id,
            'name' => 'Jakub Hrnčíř',
            'role' => WorkspaceRole::Owner,
            'status' => WorkspaceMembershipStatus::Active,
            'joined' => $opened,
        ]];

        $password = bcrypt(self::PASSWORD);
        $rows = [];
        $joinings = [];

        for ($index = 1; $index < $this->peopleCount; $index++) {
            $name = fake()->unique()->name();
            $email = Str::slug($name, '.').'.'.$index.'@'.self::MAIL_DOMAIN;

            // People arrive over the year rather than all on the first day, and nobody is
            // hired in the last fortnight — a member with no history behind them reads as a
            // gap in the data rather than as somebody who has just started.
            $joined = $this->between($opened, $now->subWeeks(2));
            $joinings[$email] = $joined;

            $rows[] = [
                'name' => $name,
                'email' => $email,
                'email_verified_at' => $joined,
                'password' => $password,
                'remember_token' => Str::random(10),
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'current_workspace_id' => null,
                'created_at' => $joined,
                'updated_at' => $joined,
            ];
        }

        $this->insert('users', $rows);

        /** @var array<string, int> $ids */
        $ids = DB::table('users')->whereIn('email', array_keys($joinings))->pluck('id', 'email')->all();

        $index = 1;

        foreach ($rows as $row) {
            $email = (string) $row['email'];

            $people[] = [
                'id' => $ids[$email],
                'name' => (string) $row['name'],
                'role' => $this->roleAt($index),
                'status' => $this->statusAt($index),
                'joined' => $joinings[$email],
            ];

            $index++;
        }

        return $people;
    }

    /**
     * The account the developer already logs in with, so this workspace appears beside
     * whatever `DevelopmentSeeder` left rather than behind a second password.
     */
    private function primary(): User
    {
        $user = User::query()->where('email', self::PRIMARY_EMAIL)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = new User;
        $user->name = 'Jakub Hrnčíř';
        $user->email = self::PRIMARY_EMAIL;
        $user->password = bcrypt(self::PASSWORD);
        $user->save();
        $user->markEmailAsVerified();

        return $user;
    }

    private function roleAt(int $index): WorkspaceRole
    {
        return match (true) {
            $index <= 2 => WorkspaceRole::Admin,
            $index % 9 === 0 => WorkspaceRole::Guest,
            default => WorkspaceRole::Member,
        };
    }

    /**
     * Most people accepted. A handful did not, and one invitation is still open — the states
     * the members screen has to draw, and the ones a query that forgets `status` gets wrong.
     */
    private function statusAt(int $index): WorkspaceMembershipStatus
    {
        return match ($index % 17) {
            5 => WorkspaceMembershipStatus::Invited,
            11 => WorkspaceMembershipStatus::Declined,
            16 => WorkspaceMembershipStatus::Revoked,
            default => WorkspaceMembershipStatus::Active,
        };
    }

    private function workspace(int $ownerId, CarbonImmutable $opened): string
    {
        $id = (string) Str::uuid7($opened);

        DB::table('workspaces')->insert([
            'id' => $id,
            'owner_id' => $ownerId,
            'name' => self::WORKSPACE_NAME,
            'slug' => Workspace::slugFor(self::WORKSPACE_NAME),
            'timezone' => 'Europe/Prague',
            'settings' => json_encode(new stdClass),
            'created_at' => $opened,
            'updated_at' => $opened,
        ]);

        return $id;
    }

    /**
     * @param  list<SeedPerson>  $people
     */
    private function memberships(string $workspace, array $people): void
    {
        $rows = [];

        foreach ($people as $person) {
            $accepted = $person['status'] === WorkspaceMembershipStatus::Active;

            $rows[] = [
                'id' => (string) Str::uuid7($person['joined']),
                'workspace_id' => $workspace,
                'user_id' => $person['id'],
                'role' => $person['role']->value,
                'status' => $person['status']->value,
                'joined_at' => $accepted ? $person['joined'] : null,
                'expires_at' => $person['status'] === WorkspaceMembershipStatus::Invited
                    ? $person['joined']->addDays(14)
                    : null,
                'invited_by' => $person['id'] === $people[0]['id'] ? null : $people[0]['id'],
                'created_at' => $person['joined'],
                'updated_at' => $person['joined'],
            ];
        }

        $this->insert('workspace_memberships', $rows);
    }

    /**
     * @param  list<SeedPerson>  $people
     */
    private function currentWorkspace(string $workspace, array $people): void
    {
        DB::table('users')
            ->whereIn('id', $this->idsOf($this->active($people)))
            ->update(['current_workspace_id' => $workspace]);
    }

    /**
     * @return list<string>
     */
    private function tags(string $workspace, CarbonImmutable $opened): array
    {
        $names = [
            'bug', 'regression', 'design', 'copy', 'infrastructure', 'security', 'billing',
            'onboarding', 'performance', 'accessibility', 'mobile', 'api', 'analytics',
            'documentation', 'support', 'tech-debt', 'research', 'compliance', 'growth',
            'localisation', 'quick-win', 'blocked', 'needs-review', 'customer-request',
        ];

        $colors = ProjectColor::cases();
        $ids = [];
        $rows = [];

        foreach ($names as $index => $name) {
            $id = (string) Str::uuid7($opened);
            $ids[] = $id;

            $rows[] = [
                'id' => $id,
                'workspace_id' => $workspace,
                'name' => $name,
                'color' => $colors[$index % count($colors)]->value,
                'created_at' => $opened,
                'updated_at' => $opened,
            ];
        }

        $this->insert('tags', $rows);

        return $ids;
    }

    /**
     * @return list<SeedField>
     */
    private function customFields(string $workspace, CarbonImmutable $opened): array
    {
        $definitions = [
            ['Client', CustomFieldType::Text, []],
            ['Estimate', CustomFieldType::Number, []],
            ['Ship date', CustomFieldType::Date, []],
            ['Customer facing', CustomFieldType::Boolean, []],
            ['Stage', CustomFieldType::Select, ['Discovery', 'Scoping', 'Building', 'Verifying', 'Shipped']],
        ];

        $fields = [];
        $fieldRows = [];
        $optionRows = [];
        $colors = ProjectColor::cases();

        foreach ($definitions as [$name, $type, $labels]) {
            $id = (string) Str::uuid7($opened);
            $options = [];

            foreach ($labels as $position => $label) {
                $optionId = (string) Str::uuid7($opened);
                $options[] = $optionId;

                $optionRows[] = [
                    'id' => $optionId,
                    'custom_field_id' => $id,
                    'label' => $label,
                    'color' => $colors[$position % count($colors)]->value,
                    'position' => $position,
                    'created_at' => $opened,
                    'updated_at' => $opened,
                ];
            }

            $fieldRows[] = [
                'id' => $id,
                'workspace_id' => $workspace,
                'name' => $name,
                'type' => $type->value,
                'created_at' => $opened,
                'updated_at' => $opened,
            ];

            $fields[] = ['id' => $id, 'type' => $type, 'options' => $options];
        }

        $this->insert('custom_fields', $fieldRows);
        $this->insert('custom_field_options', $optionRows);

        return $fields;
    }

    /**
     * Fifty projects, their columns, their people and the fields they show.
     *
     * @param  list<SeedPerson>  $people
     * @param  list<SeedField>  $fields
     * @return list<SeedProject>
     */
    private function projects(string $workspace, array $people, array $fields, CarbonImmutable $opened, CarbonImmutable $now): array
    {
        $active = $this->active($people);
        $staff = array_values(array_filter($active, fn (array $person): bool => ! $person['role']->isGuest()));
        $guests = array_values(array_filter($active, fn (array $person): bool => $person['role']->isGuest()));

        $colors = ProjectColor::cases();
        $icons = ProjectIcon::cases();
        $names = $this->projectNames();
        $slugs = [];

        $projects = [];
        $projectRows = [];
        $sectionRows = [];
        $membershipRows = [];
        $fieldRows = [];

        $span = (int) $opened->diffInSeconds($now->subWeeks(2), absolute: true);

        for ($index = 0; $index < $this->projectCount; $index++) {
            $name = $names[$index % count($names)];

            if ($index >= count($names)) {
                $name .= ' '.(intdiv($index, count($names)) + 1);
            }

            $slug = Str::slug($name);
            $suffix = 1;

            while (in_array($slug, $slugs, true)) {
                $slug = Str::slug($name).'-'.++$suffix;
            }

            $slugs[] = $slug;

            // Projects are opened across the year rather than at random, so the workspace
            // reads as one that grew: the oldest carry a year of history, the newest a week.
            $created = $opened->addSeconds((int) round($span * $index / max(1, $this->projectCount)))
                ->addHours(mt_rand(9, 18));

            $id = (string) Str::uuid7($created);
            $owner = $this->pick($staff);

            $columns = $this->columnsFor($id, $created, $sectionRows);
            [$editors, $commenters] = $this->projectPeople($id, $created, $owner, $staff, $guests, $membershipRows);

            $archived = $index % 8 === 3 && $created->lessThan($now->subMonths(6))
                ? $this->between($created->addMonths(2), $now->subMonth())
                : null;

            $projectRows[] = [
                'id' => $id,
                'workspace_id' => $workspace,
                'name' => $name,
                'slug' => $slug,
                'description' => $this->chance(70) ? fake()->sentence(mt_rand(8, 16)) : null,
                'color' => $this->chance(85) ? $this->pick($colors)->value : null,
                'icon' => $this->chance(85) ? $this->pick($icons)->value : null,
                'owner_id' => $owner['id'],
                'created_by' => $owner['id'],
                'default_view' => $this->defaultView()->value,
                'visibility' => ($this->chance(20) ? ProjectVisibility::Private : ProjectVisibility::Workspace)->value,
                'start_date' => $this->chance(60) ? $created->toDateString() : null,
                'due_date' => $this->chance(45) ? $created->addDays(mt_rand(40, 260))->toDateString() : null,
                'archived_at' => $archived,
                'created_at' => $created,
                'updated_at' => $archived ?? $created,
                'deleted_at' => null,
            ];

            $shown = $this->chance(40) ? $this->sample($fields, mt_rand(1, 3)) : [];
            $position = 0;

            foreach ($shown as $field) {
                $fieldRows[] = [
                    'id' => (string) Str::uuid7($created),
                    'project_id' => $id,
                    'custom_field_id' => $field['id'],
                    'position' => $position++,
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
            }

            $projects[] = [
                'id' => $id,
                'name' => $name,
                'created' => $created,
                'columns' => $columns,
                'owner' => $owner['id'],
                'editors' => $editors,
                'commenters' => $commenters,
                'fields' => array_keys($shown),
            ];
        }

        $this->insert('projects', $projectRows);
        $this->insert('sections', $sectionRows);
        $this->insert('project_memberships', $membershipRows);
        $this->insert('project_custom_fields', $fieldRows);

        return $projects;
    }

    /**
     * A project's columns. Most carry the three a board settles into; the rest have been
     * reshaped further by the people using them, which is what a year does to a board.
     *
     * @param  list<array<string, mixed>>  $sectionRows
     * @return list<SeedColumn>
     */
    private function columnsFor(string $project, CarbonImmutable $created, array &$sectionRows): array
    {
        $names = $this->chance(55)
            ? ['Backlog', 'In progress', 'Done']
            : $this->pick([
                ['Backlog', 'Ready', 'In progress', 'Done'],
                ['Inbox', 'This week', 'In progress', 'In review', 'Done'],
                ['Triage', 'In progress', 'Blocked', 'Done'],
                ['Discovery', 'Design', 'Build', 'Verify', 'Done'],
            ]);

        $positions = SparsePosition::spread(count($names));
        $columns = [];

        foreach ($names as $index => $name) {
            $id = (string) Str::uuid7($created);
            $columns[] = ['id' => $id, 'name' => $name];

            $sectionRows[] = [
                'id' => $id,
                'project_id' => $project,
                'name' => $name,
                'color' => null,
                'position' => $positions[$index],
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        return $columns;
    }

    /**
     * The project's memberships: its creator as owner, a few people who may change it, and
     * sometimes a guest who may only read and comment.
     *
     * @param  SeedPerson  $owner
     * @param  list<SeedPerson>  $staff
     * @param  list<SeedPerson>  $guests
     * @param  list<array<string, mixed>>  $membershipRows
     * @return array{0: list<int>, 1: list<int>}
     */
    private function projectPeople(string $project, CarbonImmutable $created, array $owner, array $staff, array $guests, array &$membershipRows): array
    {
        $editors = [$owner['id']];
        $commenters = [$owner['id']];

        $membershipRows[] = [
            'id' => (string) Str::uuid7($created),
            'project_id' => $project,
            'user_id' => $owner['id'],
            'access_level' => ProjectAccessLevel::Owner->value,
            'created_at' => $created,
            'updated_at' => $created,
        ];

        foreach ($this->sample($staff, mt_rand(3, 9)) as $person) {
            if ($person['id'] === $owner['id']) {
                continue;
            }

            $level = $this->chance(70) ? ProjectAccessLevel::Editor : ProjectAccessLevel::Commenter;

            if ($level->canEdit()) {
                $editors[] = $person['id'];
            }

            $commenters[] = $person['id'];

            $membershipRows[] = [
                'id' => (string) Str::uuid7($created),
                'project_id' => $project,
                'user_id' => $person['id'],
                'access_level' => $level->value,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        if ($guests !== [] && $this->chance(25)) {
            $guest = $this->pick($guests);
            $level = $this->chance(50) ? ProjectAccessLevel::Commenter : ProjectAccessLevel::Viewer;

            if ($level->canComment()) {
                $commenters[] = $guest['id'];
            }

            $membershipRows[] = [
                'id' => (string) Str::uuid7($created),
                'project_id' => $project,
                'user_id' => $guest['id'],
                'access_level' => $level->value,
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }

        return [array_values(array_unique($editors)), array_values(array_unique($commenters))];
    }

    private function defaultView(): ProjectDefaultView
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 45 => ProjectDefaultView::List,
            $roll <= 85 => ProjectDefaultView::Board,
            default => ProjectDefaultView::Calendar,
        };
    }

    /**
     * The work itself: tasks, where each one sits, what it is tagged with and what its
     * project's custom fields say about it.
     *
     * @param  list<SeedProject>  $projects
     * @param  list<string>  $tags
     * @param  list<SeedField>  $fields
     * @return list<SeedTask>
     */
    private function tasks(string $workspace, array $projects, array $tags, array $fields, CarbonImmutable $opened, CarbonImmutable $now): array
    {
        $shares = $this->shares(count($projects));

        $tasks = [];
        $taskRows = [];
        $placementRows = [];
        $tagRows = [];
        $valueRows = [];

        /** @var array<string, int> $slots */
        $slots = [];

        foreach ($projects as $index => $project) {
            $roots = [];

            for ($n = 0; $n < $shares[$index]; $n++) {
                $created = $this->between($project['created'], $now);
                $id = (string) Str::uuid7($created);

                $creator = $this->pick($project['editors']);
                $assignee = $this->chance(78) ? $this->pick($project['editors']) : null;

                /*
                 * Older work is mostly finished and this week's is mostly not, which is what
                 * makes a year look like a year: a workspace where completion is a flat coin
                 * toss has no history in it, only rows.
                 */
                $age = (int) round(100 * $this->ratio($opened, $created, $now));
                $completed = $this->chance(25 + intdiv($age * 7, 10))
                    ? $this->between($created, $now)
                    : null;

                $parent = $roots !== [] && $this->chance(12) ? $this->pick($roots) : null;
                $deleted = $this->chance(1) ? $this->between($created, $now) : null;

                $taskRows[] = [
                    'id' => $id,
                    'workspace_id' => $workspace,
                    'parent_id' => $parent,
                    'title' => $this->title(),
                    'description' => $this->chance(45) ? $this->description() : null,
                    'priority' => $this->priority()->value,
                    'due_at' => $this->chance(55) ? $created->addDays(mt_rand(2, 45))->setTime(17, 0) : null,
                    'completed_at' => $completed,
                    'completed_by' => $completed === null ? null : ($assignee ?? $creator),
                    'assignee_id' => $assignee,
                    'created_by' => $creator,
                    'created_at' => $created,
                    'updated_at' => $completed ?? $created,
                    'deleted_at' => $deleted,
                ];

                if ($parent === null) {
                    $roots[] = $id;
                }

                // A subtask is not necessarily on the board: half of them live under their
                // parent and nowhere else, which is the shape the task panel has to draw.
                if ($parent === null || $this->chance(50)) {
                    $placementRows[] = $this->placement($id, $project, $completed !== null, $created, $slots);
                }

                /*
                 * ADR-0003 in the data: a task is owned by its workspace and may appear in
                 * more than one project. Rare, because it is rare — but never zero, or the
                 * one query that assumes a task has a single project passes every test.
                 */
                if ($this->chance(4) && count($projects) > 1) {
                    $second = $projects[mt_rand(0, count($projects) - 1)];

                    if ($second['id'] !== $project['id'] && $second['created']->lessThan($created)) {
                        $placementRows[] = $this->placement($id, $second, false, $created, $slots);
                    }
                }

                foreach ($this->sample($tags, $this->chance(45) ? mt_rand(1, 3) : 0) as $tag) {
                    $tagRows[] = ['task_id' => $id, 'tag_id' => $tag];
                }

                foreach ($project['fields'] as $field) {
                    if ($this->chance(55)) {
                        $valueRows[] = $this->fieldValue($id, $fields[$field], $created);
                    }
                }

                $tasks[] = [
                    'id' => $id,
                    'created' => $created,
                    'creator' => $creator,
                    'assignee' => $assignee,
                    'completed' => $completed,
                    'editors' => $project['editors'],
                    'commenters' => $project['commenters'],
                ];
            }
        }

        $this->insert('tasks', $taskRows);
        $this->insert('task_project_memberships', $placementRows);
        $this->insert('task_tag', $tagRows);
        $this->insert('task_custom_field_values', $valueRows);

        return $tasks;
    }

    /**
     * One card, in one column, at the end of it.
     *
     * The counters are what keep this honest: `UNIQUE(project_id, section_id, position)`
     * and its ungrouped twin refuse two cards in one slot, so positions are handed out per
     * column exactly as `SparsePosition::append()` hands them out at runtime.
     *
     * @param  SeedProject  $project
     * @param  array<string, int>  $slots
     * @return array<string, mixed>
     */
    private function placement(string $task, array $project, bool $completed, CarbonImmutable $created, array &$slots): array
    {
        $columns = $project['columns'];
        $done = $columns[count($columns) - 1];

        $section = match (true) {
            // Completion is a column on the task, not a column on the board (ADR-0004) —
            // but somebody who finished a card did usually drag it to Done as well.
            $completed => $this->chance(85) ? $done : null,
            $this->chance(8) => null,
            default => $columns[mt_rand(0, count($columns) - 2)],
        };

        $key = $project['id'].'|'.($section['id'] ?? '');
        $last = $slots[$key] ?? null;
        $position = SparsePosition::append($last);
        $slots[$key] = $position;

        return [
            'id' => (string) Str::uuid7($created),
            'task_id' => $task,
            'project_id' => $project['id'],
            'section_id' => $section['id'] ?? null,
            'position' => $position,
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  SeedField  $field
     * @return array<string, mixed>
     */
    private function fieldValue(string $task, array $field, CarbonImmutable $created): array
    {
        $row = [
            'id' => (string) Str::uuid7($created),
            'task_id' => $task,
            'custom_field_id' => $field['id'],
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            'value_option_id' => null,
            'created_at' => $created,
            'updated_at' => $created,
        ];

        // One column per row, which is what `task_custom_field_values_one_value_check`
        // enforces: the type decides which one, exactly as `CustomFieldType::column()` does.
        $row[$field['type']->column()] = match ($field['type']) {
            CustomFieldType::Text => fake()->company(),
            CustomFieldType::Number => mt_rand(1, 40) / 2,
            CustomFieldType::Date => $created->addDays(mt_rand(5, 90))->toDateString(),
            CustomFieldType::Boolean => $this->chance(40),
            CustomFieldType::Select => $field['options'] === [] ? null : $this->pick($field['options']),
        };

        return $row;
    }

    /**
     * What was said about the work, who is listening, and what reached an inbox.
     *
     * @param  list<SeedTask>  $tasks
     */
    private function conversation(string $workspace, array $tasks, CarbonImmutable $now): void
    {
        $commentRows = [];
        $followerRows = [];
        $notificationRows = [];

        /** @var array<string, true> $following */
        $following = [];
        /** @var array<string, true> $notified */
        $notified = [];

        foreach ($tasks as $task) {
            $watchers = [$task['creator']];

            /*
             * Assigning a task follows it — `FollowAssignedTask` does that at runtime, and a
             * dataset without it would make the followers list look like a feature nobody
             * uses.
             */
            if ($task['assignee'] !== null) {
                $watchers[] = $task['assignee'];
            }

            $count = $this->chance(38) ? mt_rand(1, 6) : 0;

            for ($n = 0; $n < $count; $n++) {
                $author = $this->pick($task['commenters']);
                $written = $this->between($task['created'], $now);
                $edited = $this->chance(8) ? $written->addMinutes(mt_rand(1, 90)) : null;
                $id = (string) Str::uuid7($written);

                $watchers[] = $author;

                $commentRows[] = [
                    'id' => $id,
                    'workspace_id' => $workspace,
                    'commentable_type' => 'task',
                    'commentable_id' => $task['id'],
                    'author_id' => $author,
                    'body' => $this->commentBody(),
                    'edited_at' => $edited,
                    'created_at' => $written,
                    'updated_at' => $edited ?? $written,
                    'deleted_at' => null,
                ];

                if ($task['assignee'] !== null && $task['assignee'] !== $author && $written->greaterThan($now->subMonths(2))) {
                    $notificationRows[] = $this->notification(
                        CommentPostedNotification::class,
                        'comment.posted:'.$id,
                        $workspace,
                        $task['assignee'],
                        ['comment_id' => $id, 'task_id' => $task['id'], 'author_id' => $author],
                        $written,
                        $notified,
                    );
                }
            }

            foreach (array_unique($watchers) as $watcher) {
                $key = $task['id'].'|'.$watcher;

                if (isset($following[$key])) {
                    continue;
                }

                $following[$key] = true;

                $followerRows[] = [
                    'id' => (string) Str::uuid7($task['created']),
                    'task_id' => $task['id'],
                    'user_id' => $watcher,
                    'created_at' => $task['created'],
                ];
            }

            if ($task['assignee'] !== null && $task['assignee'] !== $task['creator'] && $task['created']->greaterThan($now->subMonths(2))) {
                $notificationRows[] = $this->notification(
                    TaskAssignedNotification::class,
                    'task.assigned:'.$task['id'].':'.$task['creator'],
                    $workspace,
                    $task['assignee'],
                    ['task_id' => $task['id'], 'assigned_by_id' => $task['creator']],
                    $task['created'],
                    $notified,
                );
            }
        }

        $this->insert('comments', $commentRows);
        $this->insert('task_followers', $followerRows);
        $this->insert('notifications', array_values(array_filter($notificationRows)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, true>  $notified
     * @return array<string, mixed>|null
     */
    private function notification(string $type, string $dedupe, string $workspace, int $user, array $data, CarbonImmutable $at, array &$notified): ?array
    {
        $key = $dedupe.'|'.$user;

        // `notifications_dedupe_unique` says the same sentence reaches one inbox once. The
        // channel enforces it at runtime; here the set does, rather than a failed insert.
        if (isset($notified[$key])) {
            return null;
        }

        $notified[$key] = true;

        return [
            'id' => (string) Str::uuid7($at),
            'workspace_id' => $workspace,
            'notifiable_type' => 'user',
            'notifiable_id' => $user,
            'type' => $type,
            'dedupe_key' => $dedupe,
            'data' => json_encode($data),
            'read_at' => $this->chance(60) ? $at->addHours(mt_rand(1, 72)) : null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * The history the listeners would have written: created for everything, assigned and
     * completed where those happened, and the edits somebody made in between.
     *
     * @param  list<SeedTask>  $tasks
     */
    private function history(string $workspace, array $tasks): void
    {
        $rows = [];

        foreach ($tasks as $task) {
            $rows[] = $this->activity($workspace, $task['id'], $task['creator'], ActivityType::TaskCreated, [], $task['created']);

            if ($task['assignee'] !== null) {
                $rows[] = $this->activity(
                    $workspace,
                    $task['id'],
                    $task['creator'],
                    ActivityType::TaskAssigned,
                    ['assignee_id' => $task['assignee']],
                    $task['created']->addMinutes(mt_rand(1, 240)),
                );
            }

            foreach ($this->sample(['title', 'description', 'priority', 'due_at'], $this->chance(35) ? mt_rand(1, 2) : 0) as $field) {
                $rows[] = $this->activity(
                    $workspace,
                    $task['id'],
                    $this->pick($task['editors']),
                    ActivityType::TaskUpdated,
                    ['changed' => [$field]],
                    $this->between($task['created'], $task['completed'] ?? $task['created']->addDays(30)),
                );
            }

            if ($task['completed'] !== null) {
                $rows[] = $this->activity(
                    $workspace,
                    $task['id'],
                    $task['assignee'] ?? $task['creator'],
                    ActivityType::TaskCompleted,
                    [],
                    $task['completed'],
                );
            }
        }

        $this->insert('activities', $rows);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function activity(string $workspace, string $task, int $actor, ActivityType $type, array $properties, CarbonImmutable $at): array
    {
        return [
            'id' => (string) Str::uuid7($at),
            'workspace_id' => $workspace,
            'subject_type' => 'task',
            'subject_id' => $task,
            'actor_id' => $actor,
            'type' => $type->value,
            'properties' => json_encode($properties === [] ? new stdClass : $properties),
            'created_at' => $at,
        ];
    }

    /**
     * Attachments, as rows. **Nothing is written to the disk**: these describe uploads that
     * never happened, so the files listing and its sorting have something to draw and a
     * download will 404. Seeding real bytes would put megabytes of noise in `storage/`.
     *
     * @param  list<SeedTask>  $tasks
     */
    private function files(string $workspace, array $tasks, CarbonImmutable $now): void
    {
        $kinds = [
            ['brief.pdf', 'application/pdf', 'pdf', 240_000],
            ['screenshot.png', 'image/png', 'png', 820_000],
            ['handoff.fig', 'application/octet-stream', 'fig', 1_400_000],
            ['numbers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx', 96_000],
            ['notes.md', 'text/markdown', 'md', 4_200],
            ['walkthrough.mp4', 'video/mp4', 'mp4', 18_400_000],
            ['export.csv', 'text/csv', 'csv', 61_000],
            ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx', 3_100_000],
        ];

        $disk = (string) config('filesystems.attachments');
        $fileRows = [];
        $attachmentRows = [];

        foreach ($tasks as $task) {
            if (! $this->chance(6)) {
                continue;
            }

            [$name, $mime, $extension, $size] = $this->pick($kinds);

            $uploaded = $this->between($task['created'], $now);
            $id = (string) Str::uuid7($uploaded);

            $fileRows[] = [
                'id' => $id,
                'workspace_id' => $workspace,
                'uploaded_by' => $this->pick($task['editors']),
                'disk' => $disk,
                'path' => 'workspaces/'.$workspace.'/'.$id,
                'original_name' => $name,
                'mime_type' => $mime,
                'extension' => $extension,
                'size' => (int) round($size * mt_rand(50, 150) / 100),
                'checksum' => hash('sha256', $id),
                'metadata' => json_encode(new stdClass),
                'created_at' => $uploaded,
                'updated_at' => $uploaded,
                'deleted_at' => null,
            ];

            $attachmentRows[] = [
                'id' => (string) Str::uuid7($uploaded),
                'file_id' => $id,
                'attachable_type' => 'task',
                'attachable_id' => $task['id'],
                'created_at' => $uploaded,
                'updated_at' => $uploaded,
            ];
        }

        $this->insert('files', $fileRows);
        $this->insert('attachments', $attachmentRows);
    }

    /**
     * @param  list<SeedProject>  $projects
     * @param  list<SeedPerson>  $people
     */
    private function stars(array $projects, array $people, CarbonImmutable $now): void
    {
        $rows = [];

        foreach ($this->active($people) as $person) {
            foreach ($this->sample($projects, mt_rand(0, 6)) as $project) {
                $starred = $this->between($project['created'], $now);

                $rows[] = [
                    'id' => (string) Str::uuid7($starred),
                    'project_id' => $project['id'],
                    'user_id' => $person['id'],
                    'created_at' => $starred,
                ];
            }
        }

        $this->insert('project_stars', $rows);
    }

    private function report(): void
    {
        $tables = ['users', 'projects', 'sections', 'tasks', 'task_project_memberships', 'comments', 'activities', 'notifications', 'files', 'task_followers'];

        $this->command->table(
            ['Table', 'Rows'],
            array_map(fn (string $table): array => [$table, number_format(DB::table($table)->count())], $tables),
        );

        $this->command->info('Seeded '.self::WORKSPACE_NAME.' — log in as '.self::PRIMARY_EMAIL.' with the password "'.self::PASSWORD.'".');
        $this->command->info('Rows were written directly, so the search engine knows nothing about them yet: run `php artisan scout:import "App\Domain\Task\Models\Task"` (and the same for Project and User) to index them.');
    }

    /**
     * How many tasks each project gets. Uneven on purpose: a workspace where every project
     * holds the same 120 cards is a workspace whose listing queries are never asked a hard
     * question.
     *
     * @return list<int>
     */
    private function shares(int $projects): array
    {
        $weights = [];

        for ($index = 0; $index < $projects; $index++) {
            $weights[] = mt_rand(2, 10) + ($index % 7 === 0 ? mt_rand(10, 25) : 0);
        }

        $total = max(1, array_sum($weights));

        return array_map(
            fn (int $weight): int => max(3, (int) round($this->taskCount * $weight / $total)),
            $weights,
        );
    }

    private function title(): string
    {
        $verbs = ['Fix', 'Ship', 'Draft', 'Review', 'Refactor', 'Investigate', 'Migrate', 'Document', 'Automate', 'Rewrite', 'Prototype', 'Audit', 'Polish', 'Rebuild', 'Deprecate', 'Instrument', 'Translate', 'Benchmark', 'Package', 'Roll out', 'Split', 'Cache', 'Simplify', 'Restore'];

        $subjects = ['the onboarding flow', 'the billing webhook', 'the search index', 'the invoice export', 'the mobile navigation', 'the sign-up form', 'the audit log', 'the pricing page', 'the seat limit', 'the import wizard', 'the retry policy', 'the empty states', 'the keyboard shortcuts', 'the notification digest', 'the CSV parser', 'the permission matrix', 'the release checklist', 'the error page', 'the file uploader', 'the timezone handling', 'the API rate limit', 'the session timeout', 'the board drag handles', 'the weekly report', 'the password reset', 'the webhook signatures', 'the seed data', 'the deploy script'];

        $qualifiers = ['', '', '', ' before the release', ' for the mobile client', ' on staging', ' after the migration', ' for the pilot customers', ' behind a flag', ' in the German locale'];

        return $this->pick($verbs).' '.$this->pick($subjects).$this->pick($qualifiers);
    }

    private function description(): string
    {
        $body = '<p>'.fake()->sentence(mt_rand(10, 22)).'</p>';

        if ($this->chance(35)) {
            $body .= '<ul><li>'.fake()->sentence(6).'</li><li>'.fake()->sentence(8).'</li></ul>';
        }

        return $body;
    }

    private function commentBody(): string
    {
        $openers = [
            'Picked this up, should have something to look at tomorrow.',
            'This is blocked on the API change landing first.',
            'Reproduced it on staging — same stack trace.',
            'Copy looks good to me, one small change in the second paragraph.',
            'Moving this to next week, the pilot took priority.',
            'Fixed in the last deploy, leaving it open until QA confirms.',
            'Do we still want this, or has the customer moved on?',
            'Numbers are in the sheet attached to the parent task.',
        ];

        return $this->chance(60) ? $this->pick($openers) : fake()->sentence(mt_rand(6, 18));
    }

    private function priority(): TaskPriority
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $roll <= 20 => TaskPriority::Low,
            $roll <= 65 => TaskPriority::Medium,
            $roll <= 90 => TaskPriority::High,
            default => TaskPriority::Urgent,
        };
    }

    /**
     * @return list<string>
     */
    private function projectNames(): array
    {
        return [
            'Website Redesign', 'Mobile App v2', 'Customer Onboarding', 'Billing Migration',
            'Design System', 'Marketing Site', 'Support Playbook', 'Data Warehouse',
            'Q3 Roadmap', 'Q4 Roadmap', 'Security Review', 'Accessibility Pass',
            'Search Relevance', 'Notification Overhaul', 'Public API', 'Partner Portal',
            'Analytics Pipeline', 'Localisation', 'Payment Providers', 'Infrastructure Costs',
            'Hiring', 'Internal Tools', 'Documentation', 'Developer Experience',
            'Pricing Experiment', 'Churn Investigation', 'Mobile Web', 'Desktop App',
            'Release Automation', 'Incident Response', 'Data Retention', 'Reporting',
            'Admin Console', 'Single Sign-On', 'Audit Trail', 'Import & Export',
            'Performance Budget', 'Email Templates', 'Brand Refresh', 'Community',
            'Beta Programme', 'Enterprise Readiness', 'Compliance', 'Sales Enablement',
            'Growth Experiments', 'Customer Interviews', 'Product Analytics', 'Content Calendar',
            'Legacy Cleanup', 'Platform Upgrade',
        ];
    }

    /**
     * @param  list<SeedPerson>  $people
     * @return list<SeedPerson>
     */
    private function active(array $people): array
    {
        return array_values(array_filter(
            $people,
            fn (array $person): bool => $person['status']->grantsAccess(),
        ));
    }

    /**
     * @param  list<SeedPerson>  $people
     * @return list<int>
     */
    private function idsOf(array $people): array
    {
        return array_map(fn (array $person): int => $person['id'], $people);
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return array<int, TValue>
     */
    private function sample(array $values, int $count): array
    {
        if ($values === [] || $count < 1) {
            return [];
        }

        $keys = (array) array_rand($values, min($count, count($values)));

        $picked = [];

        foreach ($keys as $key) {
            $picked[(int) $key] = $values[(int) $key];
        }

        return $picked;
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>|non-empty-array<int, TValue>  $values
     * @return TValue
     */
    private function pick(array $values)
    {
        return $values[array_rand($values)];
    }

    private function chance(int $percent): bool
    {
        return mt_rand(1, 100) <= $percent;
    }

    private function between(CarbonImmutable $from, CarbonImmutable $to): CarbonImmutable
    {
        $seconds = (int) $from->diffInSeconds($to, absolute: true);

        return $seconds < 1 ? $from : $from->addSeconds(mt_rand(0, $seconds));
    }

    /**
     * How far through the year a moment sits, as 1 for the oldest and 0 for the newest —
     * the number that decides how much of a task's life has already happened.
     */
    private function ratio(CarbonImmutable $opened, CarbonImmutable $at, CarbonImmutable $now): float
    {
        $span = (int) $opened->diffInSeconds($now, absolute: true);

        if ($span < 1) {
            return 0.0;
        }

        return 1 - min(1, (int) $opened->diffInSeconds($at, absolute: true) / $span);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}

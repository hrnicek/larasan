<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Concerns\OpensTaskPanel;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Page\Queries\ProjectPagesQuery;
use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Actions\UpdateProject;
use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Data\ListColumns;
use App\Domain\Project\Data\UpdateProjectData;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectMembership;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Project\Queries\ProjectCalendarQuery;
use App\Domain\Project\Queries\ProjectFilesQuery;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Project\ShowProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InertiaUI\Modal\Modal;

class ProjectController extends Controller
{
    use OpensTaskPanel;

    public function index(Request $request, VisibleProjectsForUser $visibleProjects): Response
    {
        $workspace = $this->currentWorkspace($request);
        $user = $this->actor($request);

        return Inertia::render('projects/Index', [
            // Named apart from the shared `projects` prop the sidebar reads: a page prop of the
            // same name replaces it, and this list is unbounded where the sidebar's is capped.
            'allProjects' => $visibleProjects($workspace, $user)
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'color' => $project->color?->value,
                    'icon' => $project->icon?->value,
                    'visibility' => $project->visibility->value,
                ])
                ->all(),
            'can' => [
                'create' => $user->can(Capability::ProjectCreate->value, $workspace),
            ],
        ]);
    }

    /**
     * Creating a project is a modal with an address of its own.
     *
     * Entering `/projects/create` directly renders the project list underneath it, so the
     * screen behind the dialog is never blank; opening it from inside the application keeps
     * whichever page the person was already on, because the package prefers the referer over
     * the base route declared here.
     */
    public function create(Request $request): Modal
    {
        Gate::authorize(Capability::ProjectCreate->value, $this->currentWorkspace($request));

        return Inertia::modal('projects/Create', [
            /*
             * The access levels come from the server, the way the settings form takes them:
             * a case added later reaches both screens without a list in the client to
             * remember it. The palette and the icon library do not — those are drawn from
             * `lib/accentColor.ts` and `lib/projectIcon.ts`, where the class names live.
             */
            'options' => [
                'visibilities' => array_column(ProjectVisibility::cases(), 'value'),
            ],
        ])->baseRoute('projects.index');
    }

    public function store(StoreProjectRequest $request, CreateProject $createProject): RedirectResponse
    {
        $project = $createProject->handle(
            $this->currentWorkspace($request),
            $this->actor($request),
            CreateProjectData::fromRequest($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('projects.edit', $project);
    }

    /**
     * The project itself: its list, its board, its month or its files, whichever this request
     * asked for.
     *
     * `projects.default_view` is the project's own answer, and a `view` parameter overrides
     * it for this request — the URL is the state, so a reload and a shared link both show
     * what the sender saw. An unknown value is a validation error rather than a quiet
     * fallback: a typo that silently renders the list looks like the switcher is broken.
     */
    public function show(
        ShowProjectRequest $request,
        Project $project,
        ProjectListQuery $list,
        ProjectBoardQuery $board,
        ProjectCalendarQuery $calendar,
        ProjectFilesQuery $files,
        ProjectPagesQuery $pages,
        TaskDetailQuery $detail,
    ): Response {
        Gate::authorize('view', $project);

        $view = $request->view($project);
        $actor = $this->actor($request);

        $this->rememberOpening($project->workspace, $actor, $project);

        /*
         * The project's people, read once: the header draws five faces and says how many there
         * are, and a project's membership list is the people rather than the work, so reading it
         * whole costs one query instead of two.
         */
        $people = $project->members()->orderBy('name')->get(PersonSummary::faceColumns('users'));

        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
                'archived' => $project->isArchived(),
                // What the header's appearance picker renders itself on: a control nobody
                // may use is a control that should not be drawn. The endpoint authorizes
                // regardless of what the header decided to show.
                'canUpdate' => $actor->can('update', $project),
                // This reader's own shortcut, not a property of the project: the header's menu
                // draws either *Add to starred* or *Remove from starred* from it.
                'starred' => $project->stars()->where('user_id', $actor->id)->exists(),
                /*
                 * Whether the header draws *Customize* at all. A drawer that can only be read is
                 * a control that promises something, so it is not offered to somebody who cannot
                 * change what the project records (ADR-0010).
                 */
                'canCustomize' => $actor->can(Capability::CustomFieldManage->value, $project->workspace),
                /*
                 * The faces in the header. Five and a number rather than everybody: past that a
                 * stack stops being a glance and becomes a queue, and the dialog behind it is
                 * where the whole list belongs.
                 */
                'members' => array_values($people
                    ->take(5)
                    ->map(PersonSummary::face(...))
                    ->all()),
                'memberCount' => $people->count(),
            ],
            'view' => $view->value,
            /*
             * One screen, five views, and only the payload the view asked for. Sending more
             * than one would read the same placements twice for a reader who can see one of them.
             */
            ...match ($view) {
                ProjectView::Board => ['board' => $board($project, $actor, $request->expandedColumns(), $request->tags())],
                // The files table is the one view with no tags in it: a tag is a property of a
                // task, and narrowing a list of documents by one would answer a question about
                // the tasks rather than about the files.
                ProjectView::Files => ['files' => $files($project, $actor, $request->page(), ...$request->fileSort())],
                // The pages tree carries no tags and no sort either: a document is not a task,
                // and narrowing a list of documents by a tag would answer a different question.
                ProjectView::Pages => ['pages' => $pages($project, $actor)],
                ProjectView::Calendar => ['calendar' => $calendar(
                    $project,
                    $actor,
                    $request->month(),
                    $request->tags(),
                    $request->expandedDays(),
                )],
                ProjectView::List => ['list' => $list(
                    $project,
                    $actor,
                    $request->tags(),
                    $request->sort($project->customFields),
                    $request->fieldFilters(),
                )],
            },
            /*
             * What the server understood of the ordering, echoed back so the screen renders the
             * view it actually got rather than the one the client asked for.
             */
            'sort' => [
                'field' => $request->sort($project->customFields)?->field->id,
                'direction' => $request->sort($project->customFields)?->direction() ?? 'asc',
                'filters' => (object) $request->fieldFilters(),
            ],
            /*
             * The filter, echoed back, and the workspace's vocabulary to pick from. The screen
             * renders what the server understood rather than what the client thinks it asked
             * for — a stale tag id in a link matches nothing and is quietly dropped here.
             */
            'tags' => [
                'active' => $request->tags(),
                'available' => $project->workspace->tags()
                    ->orderBy('name')
                    ->get(['id', 'name', 'color'])
                    ->map(fn (Tag $tag): array => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                        'color' => $tag->color?->value,
                    ])
                    ->all(),
            ],
            'views' => array_column(ProjectView::cases(), 'value'),
            /*
             * What the *Share* dialog holds, asked for when it is opened. The faces above are on
             * every visit because the header draws them; the list, the levels and everybody who
             * could be added are not.
             */
            'share' => Inertia::optional(fn (): array => $this->share($project, $actor)),
            /*
             * What the *Customize* drawer holds, asked for when it is opened rather than sent to
             * everybody who opens a project: it is a control most visits never touch, and
             * `available` is a query of its own. `Inertia::optional` is v3's name for it.
             */
            'customize' => Inertia::optional(fn (): array => [
                'fields' => [
                    'attached' => $this->fields($project->customFields),
                    'available' => $this->fields(
                        $project->workspace->customFields()
                            ->whereNotIn('id', $project->customFields->modelKeys())
                            ->orderBy('name')
                            ->get(),
                    ),
                ],
                /*
                 * The list's columns in the order it draws them, sent from here as well as from
                 * `ProjectListQuery` — the drawer opens over the board and the calendar too, and
                 * the order is the project's rather than the list view's.
                 */
                'columns' => ListColumns::describe($project),
            ]),
            /*
             * The panel, the priorities its control offers and who a card can be handed to.
             * Four props, sent identically by every screen that can open a panel, from the one
             * place that knows what they are.
             */
            ...$this->taskPanelProps($request, $project->workspace, $actor, $detail),
        ]);
    }

    public function edit(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $user = $this->actor($request);

        return Inertia::render('projects/Settings', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'description' => $project->description,
                'color' => $project->color?->value,
                'icon' => $project->icon?->value,
                'default_view' => $project->default_view->value,
                'visibility' => $project->visibility->value,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'archived' => $project->isArchived(),
            ],
            /*
             * What this project records beyond a title and a due date, and what the workspace has
             * defined that it does not. Both lists whole: a workspace's fields are few, and
             * paginating a picker somebody opens once is machinery for nothing.
             */
            'customFields' => [
                'attached' => $this->fields($project->customFields),
                'available' => $this->fields(
                    $project->workspace->customFields()
                        ->whereNotIn('id', $project->customFields->modelKeys())
                        ->orderBy('name')
                        ->get(),
                ),
            ],
            /*
             * The enums the form offers come from the server, so a case added later
             * appears in the UI without a second list to remember.
             */
            'options' => [
                'colors' => array_column(ProjectColor::cases(), 'value'),
                'views' => array_column(ProjectDefaultView::cases(), 'value'),
                'visibilities' => array_column(ProjectVisibility::cases(), 'value'),
            ],
            'can' => [
                'update' => $user->can('update', $project),
                'archive' => $user->can('archive', $project),
                'delete' => $user->can('delete', $project),
                'manageMembers' => $user->can('manageMembers', $project),
                'createSection' => $user->can('createSection', $project),
                /*
                 * A column on everybody's board is a workspace decision (ADR-0010), so a project
                 * editor who is not an owner or an admin reads this card and changes nothing on
                 * it.
                 */
                'manageFields' => $user->can(Capability::CustomFieldManage->value, $project->workspace),
            ],
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project, UpdateProject $updateProject): RedirectResponse
    {
        $updateProject->handle($project, $this->actor($request), UpdateProjectData::fromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project updated.')]);

        return to_route('projects.edit', $project);
    }

    public function archive(Request $request, Project $project, ArchiveProject $archiveProject): RedirectResponse
    {
        Gate::authorize('archive', $project);

        $archiveProject->archive($project, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project archived.')]);

        return to_route('projects.edit', $project);
    }

    public function restore(Request $request, Project $project, ArchiveProject $archiveProject): RedirectResponse
    {
        Gate::authorize('archive', $project);

        $archiveProject->restore($project, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project restored.')]);

        return to_route('projects.edit', $project);
    }

    /**
     * The workspace the resolution middleware already resolved and proved membership for,
     * as `WorkspaceController` reads it. The route binding for `{project}` resolves the
     * same workspace, so a project outside it never reaches a controller method.
     */
    private function currentWorkspace(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * @param  Collection<int, CustomField>  $fields
     * @return list<array{id: string, name: string, type: string}>
     */
    private function fields(Collection $fields): array
    {
        return array_values($fields
            ->map(fn (CustomField $field): array => [
                'id' => $field->id,
                'name' => $field->name,
                'type' => $field->type->value,
            ])
            ->all());
    }

    /**
     * Who has access to this project, and who could be given it.
     *
     * `isLastOwner` is counted once rather than asked per row: managing a project needs an
     * explicit owner row, so the last one cannot be demoted or removed and the dialog should not
     * offer it — the endpoint refuses regardless.
     *
     * @return array{canManage: bool, visibility: string, accessLevels: list<string>, link: string, members: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    private function share(Project $project, User $actor): array
    {
        $canManage = $actor->can('manageMembers', $project);

        $owners = $project->memberships()
            ->where('access_level', ProjectAccessLevel::Owner->value)
            ->count();

        $memberships = $project->memberships()->with('user')->get();

        return [
            'canManage' => $canManage,
            'visibility' => $project->visibility->value,
            // Owner last: it is the level somebody is promoted to, not the one a form offers first.
            'accessLevels' => array_column(ProjectAccessLevel::cases(), 'value'),
            'link' => route('projects.show', $project),
            'members' => array_values($memberships
                ->sortBy(fn (ProjectMembership $membership): string => $membership->user->name)
                ->map(fn (ProjectMembership $membership): array => [
                    'membershipId' => $membership->id,
                    ...PersonSummary::from($membership->user),
                    'accessLevel' => $membership->access_level->value,
                    'isYou' => $membership->user_id === $actor->id,
                    'isLastOwner' => $membership->access_level->canManageProject() && $owners === 1,
                ])
                ->values()
                ->all()),
            /*
             * Everybody in the workspace who is not on the project yet. A guest is deliberately
             * included: a project membership is exactly how somebody outside the workspace's own
             * work is given a way in (ADR-0006).
             */
            'candidates' => $canManage
                ? array_values($project->workspace->members()
                    ->whereNotIn('users.id', $memberships->pluck('user_id')->all())
                    ->orderBy('name')
                    ->get(PersonSummary::columns('users'))
                    ->map(PersonSummary::from(...))
                    ->all())
                : [],
        ];
    }
}

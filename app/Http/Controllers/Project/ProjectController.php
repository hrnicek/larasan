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
            // Not `projects`: a page prop of that name would replace the shared, capped sidebar prop.
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

    public function create(Request $request): Modal
    {
        Gate::authorize(Capability::ProjectCreate->value, $this->currentWorkspace($request));

        return Inertia::modal('projects/Create', [
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

        if ($this->resolvesProp($request, 'project')) {
            $this->rememberOpening($project->workspace, $actor, $project);
        }

        // Closures, so a partial reload resolves only the props it names.
        return Inertia::render('projects/Show', [
            'project' => fn (): array => $this->heading($project, $actor),
            'view' => $view->value,
            ...match ($view) {
                ProjectView::Board => ['board' => fn (): array => $board($project, $actor, $request->expandedColumns(), $request->tags())],
                ProjectView::Files => ['files' => fn (): array => $files($project, $actor, $request->page(), ...$request->fileSort())],
                ProjectView::Pages => ['pages' => fn (): array => $pages($project, $actor)],
                ProjectView::Calendar => ['calendar' => fn (): array => $calendar(
                    $project,
                    $actor,
                    $request->month(),
                    $request->tags(),
                    $request->expandedDays(),
                )],
                ProjectView::List => ['list' => fn (): array => $list(
                    $project,
                    $actor,
                    $request->tags(),
                    $request->sort($project->customFields),
                    $request->fieldFilters(),
                )],
            },
            'sort' => function () use ($request, $project): array {
                $sort = $request->sort($project->customFields);

                return [
                    'field' => $sort?->field->id,
                    'direction' => $sort?->direction() ?? 'asc',
                    'filters' => (object) $request->fieldFilters(),
                ];
            },
            'tags' => fn (): array => [
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
            'share' => Inertia::optional(fn (): array => $this->share($project, $actor)),
            'customize' => Inertia::optional(fn (): array => [
                'fields' => $this->customFieldChoices($project),
                // Also sent by ProjectListQuery, because the drawer opens over every view.
                'columns' => ListColumns::describe($project),
            ]),
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
            'customFields' => $this->customFieldChoices($project),
            'options' => [
                'colors' => array_column(ProjectColor::cases(), 'value'),
                'views' => array_column(ProjectDefaultView::cases(), 'value'),
                'visibilities' => array_column(ProjectVisibility::cases(), 'value'),
            ],
            'can' => [
                'update' => $user->can('update', $project),
                'archive' => $user->can('archive', $project),
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
     * @return array<string, mixed>
     */
    private function heading(Project $project, User $actor): array
    {
        $people = $project->members()->orderBy('name')->get(PersonSummary::faceColumns('users'));

        return [
            'id' => $project->id,
            'name' => $project->name,
            'slug' => $project->slug,
            'color' => $project->color?->value,
            'icon' => $project->icon?->value,
            'archived' => $project->isArchived(),
            'canUpdate' => $actor->can('update', $project),
            'starred' => $project->stars()->where('user_id', $actor->id)->exists(),
            'canCustomize' => $actor->can(Capability::CustomFieldManage->value, $project->workspace),
            'members' => array_values($people
                ->take(5)
                ->map(PersonSummary::face(...))
                ->all()),
            'memberCount' => $people->count(),
        ];
    }

    private function currentWorkspace(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * @return array{attached: list<array{id: string, name: string, type: string}>, available: list<array{id: string, name: string, type: string}>}
     */
    private function customFieldChoices(Project $project): array
    {
        return [
            'attached' => $this->fields($project->customFields),
            'available' => $this->fields(
                $project->workspace->customFields()
                    ->whereNotIn('id', $project->customFields->modelKeys())
                    ->orderBy('name')
                    ->get(),
            ),
        ];
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
     * @return array{canManage: bool, visibility: string, accessLevels: list<string>, link: string, members: list<array<string, mixed>>, candidates: list<array<string, mixed>>}
     */
    private function share(Project $project, User $actor): array
    {
        $canManage = $actor->can('manageMembers', $project);
        $people = PersonSummary::for($project->workspace, $actor);

        $owners = $project->memberships()
            ->where('access_level', ProjectAccessLevel::Owner->value)
            ->count();

        $memberships = $project->memberships()->with('user')->get();

        return [
            'canManage' => $canManage,
            'visibility' => $project->visibility->value,
            'accessLevels' => array_column(ProjectAccessLevel::cases(), 'value'),
            'link' => route('projects.show', $project),
            'members' => array_values($memberships
                ->sortBy(fn (ProjectMembership $membership): string => $membership->user->name)
                ->map(fn (ProjectMembership $membership): array => [
                    'membershipId' => $membership->id,
                    ...$people->of($membership->user),
                    'accessLevel' => $membership->access_level->value,
                    'isYou' => $membership->user_id === $actor->id,
                    'isLastOwner' => $membership->access_level->canManageProject() && $owners === 1,
                ])
                ->values()
                ->all()),
            // Guests are included: a project membership is how a guest is given access. See ADR-0006.
            'candidates' => $canManage
                ? array_values($project->workspace->members()
                    ->whereNotIn('users.id', $memberships->pluck('user_id')->all())
                    ->orderBy('name')
                    ->get(PersonSummary::columns('users'))
                    ->map($people->of(...))
                    ->all())
                : [],
        ];
    }
}

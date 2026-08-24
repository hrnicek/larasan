<?php

declare(strict_types=1);

namespace App\Http\Controllers\Project;

use App\Concerns\OpensTaskPanel;
use App\Domain\Project\Actions\ArchiveProject;
use App\Domain\Project\Actions\CreateProject;
use App\Domain\Project\Actions\UpdateProject;
use App\Domain\Project\Data\CreateProjectData;
use App\Domain\Project\Data\UpdateProjectData;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Queries\ProjectBoardQuery;
use App\Domain\Project\Queries\ProjectListQuery;
use App\Domain\Project\Queries\VisibleProjectsForUser;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\ProjectColor;
use App\Domain\Shared\Enums\ProjectDefaultView;
use App\Domain\Shared\Enums\ProjectVisibility;
use App\Domain\Tag\Models\Tag;
use App\Domain\Task\Queries\TaskDetailQuery;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\Project\ShowProjectRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
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
            'projects' => $visibleProjects($workspace, $user)
                ->map(fn (Project $project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                    'slug' => $project->slug,
                    'color' => $project->color?->value,
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

        return Inertia::modal('projects/Create')->baseRoute('projects.index');
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
     * The project itself: its list or its board, whichever this request asked for.
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
        TaskDetailQuery $detail,
    ): Response {
        Gate::authorize('view', $project);

        $view = $request->view($project);
        $actor = $this->actor($request);

        return Inertia::render('projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'slug' => $project->slug,
                'color' => $project->color?->value,
                'icon' => $project->icon,
                'archived' => $project->isArchived(),
            ],
            'view' => $view->value,
            /*
             * One screen, two views, and only the payload the view asked for. Sending both
             * would read the same placements twice for a reader who can see one of them.
             */
            ...$view === ProjectDefaultView::Board
                ? ['board' => $board($project, $actor, $request->expandedColumns(), $request->tags())]
                : ['list' => $list(
                    $project,
                    $actor,
                    $request->tags(),
                    $request->sort($project->customFields),
                    $request->fieldFilters(),
                )],
            /*
             * The filter, echoed back, and the workspace's vocabulary to pick from. The screen
             * renders what the server understood rather than what the client thinks it asked
             * for — a stale tag id in a link matches nothing and is quietly dropped here.
             */
            /*
             * What the server understood of the ordering, echoed back so the screen renders the
             * view it actually got rather than the one the client asked for.
             */
            'sort' => [
                'field' => $request->sort($project->customFields)?->field->id,
                'direction' => $request->sort($project->customFields)?->direction() ?? 'asc',
                'filters' => (object) $request->fieldFilters(),
            ],
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
            'views' => array_column(ProjectDefaultView::cases(), 'value'),
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
                'icon' => $project->icon,
                'default_view' => $project->default_view->value,
                'visibility' => $project->visibility->value,
                'start_date' => $project->start_date?->toDateString(),
                'due_date' => $project->due_date?->toDateString(),
                'archived' => $project->isArchived(),
            ],
            /*
             * The columns, in order, with the abilities the actor has over them. Sections
             * are edited here until the board and the list screens exist (Phases 080 and
             * 090) — a project already has one screen, and columns nobody can reach are
             * columns nobody can fix.
             */
            'sections' => $project->sections()->get()->map(fn (Section $section): array => [
                'id' => $section->id,
                'name' => $section->name,
                'color' => $section->color?->value,
            ])->all(),
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
}

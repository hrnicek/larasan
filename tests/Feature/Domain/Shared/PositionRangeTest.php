<?php

declare(strict_types=1);

use App\Domain\File\Actions\AttachFile;
use App\Domain\File\Models\Attachment;
use App\Domain\File\Models\File;
use App\Domain\Page\Actions\CreatePage;
use App\Domain\Page\Data\CreatePageData;
use App\Domain\Page\Models\Page;
use App\Domain\Placement\Actions\AttachTaskToProject;
use App\Domain\Placement\Models\TaskProjectMembership;
use App\Domain\Section\Actions\CreateSection;
use App\Domain\Section\Data\CreateSectionData;
use App\Domain\Section\Models\Section;
use App\Domain\Shared\Enums\ProjectAccessLevel;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Ordering\SparsePosition;
use App\Domain\Task\Models\Task;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

const LARGEST_INT4 = 2_147_483_647;

it('appends a placement past the 32-bit range', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    TaskProjectMembership::factory()
        ->placing(Task::factory()->in($project->workspace)->create(), $project)
        ->at(LARGEST_INT4)
        ->create();

    $placement = app(AttachTaskToProject::class)->handle(Task::factory()->in($project->workspace)->create(), $project, $actor);

    expect($placement->refresh()->position)->toBe(LARGEST_INT4 + SparsePosition::GAP);
});

it('appends a section past the 32-bit range', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    Section::factory()->in($project)->at(LARGEST_INT4)->create();

    $section = app(CreateSection::class)->handle($project, $actor, new CreateSectionData(name: 'Later'));

    expect($section->refresh()->position)->toBe(LARGEST_INT4 + SparsePosition::GAP);
});

it('appends a page past the 32-bit range', function (): void {
    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    Page::factory()->in($project)->at(LARGEST_INT4)->create();

    $page = app(CreatePage::class)->handle($project, $actor, CreatePageData::titled('Later'));

    expect($page->refresh()->position)->toBe(LARGEST_INT4 + SparsePosition::GAP);
});

it('appends an attachment past the 32-bit range', function (): void {
    Storage::fake(config('filesystems.attachments'));

    [$project, $actor] = projectFor(WorkspaceRole::Member, ProjectAccessLevel::Editor);
    $task = Task::factory()->in($project->workspace)->create();
    TaskProjectMembership::factory()->placing($task, $project)->create();

    Attachment::factory()
        ->attaching(File::factory()->in($project->workspace)->create(), $task)
        ->create(['position' => LARGEST_INT4]);

    $attachment = app(AttachFile::class)->handle($task, $actor, UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'));

    expect($attachment->refresh()->position)->toBe(LARGEST_INT4 + SparsePosition::GAP);
});

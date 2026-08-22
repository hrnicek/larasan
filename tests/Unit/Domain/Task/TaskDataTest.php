<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\TaskPriority;
use App\Domain\Task\Data\CreateTaskData;
use App\Domain\Task\Data\UpdateTaskData;
use Carbon\CarbonImmutable;

it('defaults a new task to medium priority and nothing else', function (): void {
    $data = new CreateTaskData(title: 'Write the Action');

    expect($data->priority)->toBe(TaskPriority::Medium)
        ->and($data->description)->toBeNull()
        ->and($data->dueAt)->toBeNull()
        ->and($data->parentId)->toBeNull()
        ->and($data->assigneeId)->toBeNull();
});

it('carries no workspace and no creator', function (): void {
    // Both are arguments to the Action: a field here is a field a form can send.
    $fields = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(CreateTaskData::class))->getProperties(),
    );

    expect($fields)->not->toContain('workspaceId')
        ->and($fields)->not->toContain('createdById')
        ->and($fields)->not->toContain('projectId')
        ->and($fields)->not->toContain('sectionId');
});

it('keeps completion and assignment out of an update', function (): void {
    $fields = array_map(
        fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(UpdateTaskData::class))->getProperties(),
    );

    expect($fields)->not->toContain('completedAt')
        ->and($fields)->not->toContain('assigneeId');
});

it('lets an update carry the values it does describe', function (): void {
    $due = CarbonImmutable::parse('2026-05-01 12:00:00');

    $data = new UpdateTaskData(
        title: 'Renamed',
        description: 'Why it matters',
        priority: TaskPriority::High,
        dueAt: $due,
    );

    expect($data->title)->toBe('Renamed')
        ->and($data->priority)->toBe(TaskPriority::High)
        ->and($data->dueAt?->equalTo($due))->toBeTrue();
});

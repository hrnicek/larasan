<?php

declare(strict_types=1);

use App\Domain\Page\Models\Page;
use App\Domain\Page\Queries\PageSubtree;
use App\Domain\Project\Models\Project;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('never walks into a page of another project that names this one as its parent', function (): void {
    $project = Project::factory()->create();
    $page = Page::factory()->in($project)->create();
    $child = Page::factory()->under($page)->create();

    $elsewhere = Project::factory()->create();
    $stray = Page::factory()->in($elsewhere)->create();
    DB::table('pages')->where('id', $stray->id)->update(['parent_id' => $child->id]);

    $subtree = app(PageSubtree::class);

    expect($subtree->idsUnder($page))->toBe([$child->id])
        ->and($subtree->heightOf($page))->toBe(2);
});

it('constrains every level of the walk to the project', function (): void {
    $project = Project::factory()->create();
    $page = Page::factory()->in($project)->create();
    Page::factory()->under(Page::factory()->under($page)->create())->create();

    $walks = [];
    DB::listen(function (QueryExecuted $query) use (&$walks): void {
        if (str_contains($query->sql, '"parent_id" in')) {
            $walks[] = $query;
        }
    });

    app(PageSubtree::class)->idsUnder($page);

    expect($walks)->not->toBeEmpty();

    foreach ($walks as $walk) {
        expect($walk->sql)->toContain('"project_id" = ?')
            ->and($walk->bindings)->toContain($project->id);
    }
});

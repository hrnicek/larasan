<?php

declare(strict_types=1);

use App\Domain\Search\Actions\SaveSearch;
use App\Domain\Search\Models\SavedSearch;
use App\Domain\Search\Policies\SavedSearchPolicy;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;

it('keeps a search, with its kind and its filters', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->from(route('search.index'))
        ->post(route('search.saved.store'), [
            'name' => 'Open invoices',
            'term' => 'invoice',
            'kind' => 'tasks',
            'completed' => false,
        ])
        ->assertRedirect(route('search.index'));

    $search = SavedSearch::query()->sole();

    expect($search->name)->toBe('Open invoices')
        ->and($search->term)->toBe('invoice')
        ->and($search->kind?->value)->toBe('tasks')
        ->and($search->filters)->toBe(['completed' => false])
        ->and($search->user_id)->toBe($actor->id)
        ->and($search->workspace_id)->toBe($workspace->id);
})->with([
    'a saved search is a term, a kind and the filters that were on when it was kept',
]);

it('refuses a second search with the same name', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    SavedSearch::factory()->ownedBy($actor)->in($workspace)->create(['name' => 'Open invoices']);

    $this->actingAs($actor)
        ->from(route('search.index'))
        ->post(route('search.saved.store'), ['name' => 'Open invoices', 'term' => 'invoice'])
        ->assertSessionHasErrors('name');

    expect(SavedSearch::query()->count())->toBe(1);
})->with([
    'two chips reading "Overdue" are two chips nobody can tell apart',
]);

it('lets the database refuse the duplicate the check cannot', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    SavedSearch::factory()->ownedBy($actor)->in($workspace)->create(['name' => 'Open invoices']);

    // Validation loses to concurrency; the unique index enforces the rule.
    expect(fn () => DB::transaction(fn () => SavedSearch::factory()
        ->ownedBy($actor)
        ->in($workspace)
        ->create(['name' => 'Open invoices'])))->toThrow(QueryException::class);

    expect(SavedSearch::query()->count())->toBe(1);
});

it('stops at the number of chips a row can hold', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    SavedSearch::factory()->count(SaveSearch::LIMIT)->ownedBy($actor)->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('search.index'))
        ->post(route('search.saved.store'), ['name' => 'One too many', 'term' => 'invoice'])
        ->assertSessionHasErrors('name');

    expect(SavedSearch::query()->count())->toBe(SaveSearch::LIMIT);
});

it('refuses a search with nothing to search for', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);

    $this->actingAs($actor)
        ->from(route('search.index'))
        ->post(route('search.saved.store'), ['name' => 'Nothing', 'term' => '  '])
        ->assertSessionHasErrors();

    expect(SavedSearch::query()->count())->toBe(0);
});

it('forgets a search its owner asks it to', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $search = SavedSearch::factory()->ownedBy($actor)->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('search.index'))
        ->delete(route('search.saved.destroy', $search))
        ->assertRedirect(route('search.index'));

    expect(SavedSearch::query()->count())->toBe(0);
});

it('refuses to forget somebody else’s search', function (): void {
    $workspace = Workspace::factory()->create();
    $owner = memberOf($workspace);
    $other = memberOf($workspace);
    $search = SavedSearch::factory()->ownedBy($owner)->in($workspace)->create();

    $this->actingAs($other)
        ->delete(route('search.saved.destroy', $search))
        ->assertForbidden();

    expect(SavedSearch::query()->count())->toBe(1);
})->with([
    'a saved search is a bookmark: it belongs to the person who kept it and to nobody else',
]);

it('resolves the policy the framework has to find on its own', function (): void {
    expect(Gate::getPolicyFor(SavedSearch::class))->toBeInstanceOf(SavedSearchPolicy::class);
})->with([
    'app/Domain/<Context>/Policies is not the layout the framework documents, so a namespace move
    would stop authorizing in silence',
]);

it('offers the chips on the empty field and not beside every keystroke', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    SavedSearch::factory()->ownedBy($actor)->in($workspace)->create(['name' => 'Open invoices']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions'))
        ->assertOk()
        ->assertJsonCount(1, 'saved')
        ->assertJsonPath('saved.0.name', 'Open invoices');

    $this->actingAs($actor)
        ->getJson(route('search.suggestions', ['q' => 'invoice']))
        ->assertOk()
        ->assertJsonCount(0, 'saved');
});

it('never shows a search kept in another workspace', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $elsewhere = Workspace::factory()->create();
    memberOf($elsewhere, user: $actor);
    SavedSearch::factory()->ownedBy($actor)->in($elsewhere)->create(['name' => 'Elsewhere']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions'))
        ->assertOk()
        ->assertJsonCount(0, 'saved');
});

it('never shows a search kept by somebody else', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace);
    $colleague = memberOf($workspace);
    SavedSearch::factory()->ownedBy($colleague)->in($workspace)->create(['name' => 'Theirs']);

    $this->actingAs($actor)
        ->getJson(route('search.suggestions'))
        ->assertOk()
        ->assertJsonCount(0, 'saved');
});

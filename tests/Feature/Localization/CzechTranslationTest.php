<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\Locale;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Task\Models\Task;
use App\Domain\Workspace\Actions\InviteWorkspaceMember;
use App\Domain\Workspace\Data\InviteWorkspaceMemberData;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Inertia\Support\SessionKey;

it('tells a Czech reader why the domain refused, in Czech', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, WorkspaceRole::Owner, user: User::factory()->create(['locale' => Locale::Czech]));
    $guest = memberOf($workspace, WorkspaceRole::Guest);
    $task = Task::factory()->in($workspace)->create();

    $this->actingAs($actor)
        ->from(route('dashboard'))
        ->put(route('tasks.assign', $task), ['assignee_id' => $guest->id])
        ->assertSessionHasErrors(['refusal' => 'Tato osoba k tomuto úkolu nemá přístup.']);

    /** @var array<string, array<string, string>> $flashed */
    $flashed = session()->get(SessionKey::FLASH_DATA, []);

    expect($flashed['toast'])->toBe(['type' => 'error', 'message' => 'Tato osoba k tomuto úkolu nemá přístup.']);
});

it('keeps the name inside a translated refusal', function (): void {
    $workspace = Workspace::factory()->create();
    $actor = memberOf($workspace, user: User::factory()->create(['locale' => Locale::Czech]));
    $search = ['name' => 'Moje chyby', 'term' => 'bug'];

    $this->actingAs($actor)->post(route('search.saved.store'), $search)->assertSessionHasNoErrors();

    $this->actingAs($actor)
        ->post(route('search.saved.store'), $search)
        ->assertSessionHasErrors(['name' => 'Uložené hledání s názvem „Moje chyby“ už máte.']);
});

it('words a validation error and its field name in Czech', function (): void {
    $this->actingAs(User::factory()->create(['locale' => Locale::Czech]))
        ->post(route('workspaces.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'Pole název je povinné.']);
});

it('still answers an English reader in English', function (): void {
    $this->actingAs(User::factory()->create(['locale' => Locale::English]))
        ->post(route('workspaces.store'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);
});

it('mails each invitee in the language they read the app in', function (): void {
    $workspace = Workspace::factory()->create(['name' => 'Northwind']);
    $inviter = memberOf($workspace, WorkspaceRole::Owner, user: User::factory()->create(['name' => 'Ada Admin']));
    User::factory()->create(['email' => 'jana@example.com', 'locale' => Locale::Czech]);
    User::factory()->create(['email' => 'nora@example.com', 'locale' => Locale::English]);

    $subjects = [];
    Event::listen(MessageSending::class, function (MessageSending $sending) use (&$subjects): void {
        $subjects[$sending->message->getTo()[0]->getAddress()] = $sending->message->getSubject();
    });

    $invite = app(InviteWorkspaceMember::class);
    $invite->handle($workspace, $inviter, new InviteWorkspaceMemberData('jana@example.com', WorkspaceRole::Member));
    $invite->handle($workspace, $inviter, new InviteWorkspaceMemberData('nora@example.com', WorkspaceRole::Member));

    expect($subjects)->toBe([
        'jana@example.com' => 'Pozvánka do pracovního prostoru Northwind',
        'nora@example.com' => 'You have been invited to Northwind',
    ]);
});

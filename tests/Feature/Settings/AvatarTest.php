<?php

use App\Domain\Account\Actions\ChooseAvatarPreset;
use App\Domain\Account\Actions\UploadAvatar;
use App\Domain\Account\Exceptions\AccountException;
use App\Domain\Account\Support\AvatarPresets;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Shared\Payloads\PersonSummary;
use App\Domain\Workspace\Models\Workspace;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('avatars');
});

/**
 * Gives `$user` an uploaded picture through the Action and answers with where it was stored.
 */
function uploadedAvatarOf(User $user): string
{
    app(UploadAvatar::class)->handle($user, UploadedFile::fake()->image('me.png', 64, 64));

    return $user->avatar_path ?? throw new LogicException('The upload stored nothing.');
}

function avatarUrlFor(User $user, string $path): string
{
    return route('users.avatar', ['user' => $user->id, 'v' => pathinfo($path, PATHINFO_FILENAME)]);
}

test('the profile screen offers every illustration and says which one is worn', function () {
    $user = User::factory()->withAvatarPreset(4)->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Profile')
            ->where('avatar', ['preset' => 4, 'uploaded' => false])
            ->has('avatarPresets', AvatarPresets::COUNT)
            ->where('avatarPresets.0', ['id' => 1, 'url' => asset('img/avatars/1.svg')])
            ->where('auth.user.avatar', asset('img/avatars/4.svg')));
});

test('an illustration can be chosen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->put(route('avatar.update'), ['preset' => 7])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->avatar_preset)->toBe(7)
        ->and($user->avatar_path)->toBeNull();
});

test('an illustration that is not on offer is refused', function (mixed $preset) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put(route('avatar.update'), ['preset' => $preset])
        ->assertSessionHasErrors('preset');

    expect($user->refresh()->avatar_preset)->toBeNull();
})->with([0, AvatarPresets::COUNT + 1, 'seven', null]);

test('the action refuses an illustration that is not on offer for every caller', function () {
    $user = User::factory()->create();

    expect(fn () => app(ChooseAvatarPreset::class)->handle($user, AvatarPresets::COUNT + 1))
        ->toThrow(AccountException::class);

    expect($user->refresh()->avatar_preset)->toBeNull();
});

test('a picture can be uploaded, and it replaces an illustration', function () {
    $user = User::factory()->withAvatarPreset(3)->create();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('me.png', 200, 200)])
        ->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->avatar_preset)->toBeNull()
        ->and($user->avatar_path)->toStartWith($user->id.'/')->toEndWith('.png')
        ->and(Storage::disk('avatars')->allFiles())->toBe([$user->avatar_path]);
});

test('the stored name comes from what the file is, not from what it was called', function () {
    $user = User::factory()->create();
    // Held in a variable: a fake file deletes its temporary copy once nothing refers to it.
    $source = UploadedFile::fake()->image('source.png', 64, 64);
    $png = (string) file_get_contents($source->getRealPath());

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->createWithContent('../../portrait.jpg', $png)])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->avatar_path)
        ->toMatch('#^'.$user->id.'/[0-9a-f-]{36}\.png$#');
});

test('a new picture deletes the one it replaces', function () {
    $user = User::factory()->create();
    $old = uploadedAvatarOf($user);

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => UploadedFile::fake()->image('new.jpg')])
        ->assertSessionHasNoErrors();

    Storage::disk('avatars')->assertMissing($old);
    expect(Storage::disk('avatars')->allFiles())->toBe([$user->refresh()->avatar_path]);
});

test('choosing an illustration deletes an uploaded picture', function () {
    $user = User::factory()->create();
    $uploaded = uploadedAvatarOf($user);

    $this->actingAs($user)->put(route('avatar.update'), ['preset' => 2])->assertSessionHasNoErrors();

    expect($user->refresh()->avatar_path)->toBeNull();
    Storage::disk('avatars')->assertMissing($uploaded);
});

test('the picture can be removed, and initials come back', function () {
    $user = User::factory()->create();
    $uploaded = uploadedAvatarOf($user);

    $this->actingAs($user)->delete(route('avatar.destroy'))->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->avatar_path)->toBeNull()
        ->and($user->avatar_preset)->toBeNull()
        ->and(PersonSummary::from($user)['avatar'])->toBeNull();
    Storage::disk('avatars')->assertMissing($uploaded);
});

test('what is not a JPEG, PNG or WebP picture of a sensible size is refused', function (Closure $file) {
    $user = User::factory()->withAvatarPreset(5)->create();

    $this->actingAs($user)
        ->post(route('avatar.store'), ['avatar' => $file()])
        ->assertSessionHasErrors('avatar');

    expect($user->refresh()->avatar_preset)->toBe(5);
    expect(Storage::disk('avatars')->allFiles())->toBeEmpty();
})->with([
    'an SVG' => fn () => UploadedFile::fake()->createWithContent(
        'face.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    ),
    'a page named like a picture' => fn () => UploadedFile::fake()->createWithContent(
        'face.png',
        '<html><script>alert(1)</script></html>',
    ),
    'a picture over the size limit' => fn () => UploadedFile::fake()->image('big.png')->size(3000),
    'nothing' => fn () => null,
]);

test('the action refuses what is not a picture for every caller', function () {
    $user = User::factory()->create();

    expect(fn () => app(UploadAvatar::class)->handle(
        $user,
        UploadedFile::fake()->createWithContent('face.png', '<html></html>'),
    ))->toThrow(AccountException::class);

    expect($user->refresh()->avatar_path)->toBeNull();
    expect(Storage::disk('avatars')->allFiles())->toBeEmpty();
});

test('deleting the account deletes its picture', function () {
    $user = User::factory()->create();
    $uploaded = uploadedAvatarOf($user);

    $this->actingAs($user)
        ->delete(route('profile.destroy'), ['password' => 'password'])
        ->assertRedirect('/');

    Storage::disk('avatars')->assertMissing($uploaded);
});

test('a guest can neither change a picture nor see one', function () {
    $person = User::factory()->create();

    $this->put(route('avatar.update'), ['preset' => 1])->assertRedirect(route('login'));
    $this->post(route('avatar.store'))->assertRedirect(route('login'));
    $this->delete(route('avatar.destroy'))->assertRedirect(route('login'));
    $this->get(route('users.avatar', $person))->assertRedirect(route('login'));
});

test('an uploaded picture is drawn for its owner and for a colleague', function () {
    $workspace = Workspace::factory()->create();
    $person = memberOf($workspace);
    $colleague = memberOf($workspace, WorkspaceRole::Guest);
    $url = avatarUrlFor($person, uploadedAvatarOf($person));

    expect(PersonSummary::from($person)['avatar'])->toBe($url)
        ->and(PersonSummary::face($person)['avatar'])->toBe($url);

    foreach ([$person, $colleague] as $viewer) {
        $this->actingAs($viewer)
            ->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

test('an uploaded picture is not drawn for somebody who shares no workspace', function (Closure $stranger) {
    $workspace = Workspace::factory()->create();
    $person = memberOf($workspace);
    $url = avatarUrlFor($person, uploadedAvatarOf($person));

    $this->actingAs($stranger($workspace))->get($url)->assertNotFound();
})->with([
    'a member of another workspace' => fn (Workspace $workspace) => memberOf(Workspace::factory()->create()),
    'an invitee who has not answered' => fn (Workspace $workspace) => memberOf(
        $workspace,
        WorkspaceRole::Member,
        WorkspaceMembershipStatus::Invited,
    ),
    'somebody whose membership was revoked' => fn (Workspace $workspace) => memberOf(
        $workspace,
        WorkspaceRole::Member,
        WorkspaceMembershipStatus::Revoked,
    ),
]);

test('a person with no uploaded picture has nothing at the address', function () {
    $person = User::factory()->withAvatarPreset(9)->create();

    $this->actingAs($person)->get(route('users.avatar', $person))->assertNotFound();
});

test('the database refuses a face from two sources, and an illustration that does not exist', function (array $attributes) {
    $user = User::factory()->create();

    expect(fn () => DB::transaction(fn () => DB::table('users')->where('id', $user->id)->update($attributes)))
        ->toThrow(QueryException::class);

    expect($user->refresh()->avatar_preset)->toBeNull();
})->with([
    'both at once' => [['avatar_preset' => 1, 'avatar_path' => '1/face.png']],
    'an illustration past the last' => [['avatar_preset' => AvatarPresets::COUNT + 1]],
    'an illustration before the first' => [['avatar_preset' => 0]],
]);

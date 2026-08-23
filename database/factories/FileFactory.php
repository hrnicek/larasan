<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\File\Models\File;
use App\Domain\Shared\Enums\WorkspaceMembershipStatus;
use App\Domain\Shared\Enums\WorkspaceRole;
use App\Domain\Workspace\Models\Workspace;
use App\Domain\Workspace\Models\WorkspaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /**
     * The path is generated here as the Action generates it (ADR-0007) — never from the
     * original name — so a test cannot accidentally depend on a shape the domain will not
     * produce.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'uploaded_by' => fn (array $attributes): int => $this->member((string) $attributes['workspace_id'])->id,
            'disk' => config('filesystems.attachments'),
            'path' => fn (array $attributes): string => "workspaces/{$attributes['workspace_id']}/".Str::uuid7(),
            'original_name' => 'quarterly plan.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 42_000,
            'checksum' => hash('sha256', (string) Str::uuid7()),
            'metadata' => [],
        ];
    }

    private function member(string $workspaceId): User
    {
        $user = User::factory()->create();

        WorkspaceMembership::query()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Member,
            'status' => WorkspaceMembershipStatus::Active,
            'joined_at' => now(),
        ]);

        return $user;
    }

    public function in(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function by(User $uploader): self
    {
        return $this->state(fn (): array => ['uploaded_by' => $uploader->id]);
    }
}

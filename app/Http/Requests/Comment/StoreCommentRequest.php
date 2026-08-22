<?php

declare(strict_types=1);

namespace App\Http\Requests\Comment;

use App\Domain\Comment\Models\Commentable;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    /**
     * The same two questions the Action asks, so the answer arrives as a 403 rather than as a
     * refusal rendered from a thrown exception: the capability in the **subject's** workspace,
     * and the subject's own policy saying the actor can reach it.
     */
    public function authorize(): bool
    {
        $subject = $this->subject();
        $user = $this->user();

        if ($subject === null || $user === null) {
            return false;
        }

        $workspace = Workspace::query()->find($subject->workspaceId());

        return $workspace?->membershipFor($user)?->allows(Capability::CommentCreate) === true
            && $user->can('view', $subject);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // A comment is prose, not a document: long enough for a paragraph of reasoning and
            // short enough that the feed stays readable and a single row stays bounded.
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * The subject is whatever the route bound that can be commented on. Anything else is not a
     * comment route, and a request that cannot name its subject authorizes nothing.
     */
    public function subject(): (Model&Commentable)|null
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter instanceof Commentable) {
                return $parameter;
            }
        }

        return null;
    }
}

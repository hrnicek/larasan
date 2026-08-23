<?php

declare(strict_types=1);

namespace App\Http\Controllers\Comment;

use App\Domain\Comment\Actions\CreateComment;
use App\Domain\Comment\Actions\DeleteComment;
use App\Domain\Comment\Actions\UpdateComment;
use App\Domain\Comment\Data\CreateCommentData;
use App\Domain\Comment\Data\UpdateCommentData;
use App\Domain\Comment\Models\Comment;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\Comment\StoreCommentRequest;
use App\Http\Requests\Comment\UpdateCommentRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * A thread is read inside whatever screen it belongs to, so every method answers with a
 * redirect back rather than rendering anything of its own.
 *
 * The requests have already asked the questions these Actions ask again; that repetition is
 * deliberate, because the console and the future API arrive without a FormRequest.
 */
class CommentController extends Controller
{
    public function store(StoreCommentRequest $request, Task $task, CreateComment $createComment): RedirectResponse
    {
        $createComment->handle($task, $this->actor($request), CreateCommentData::fromRequest($request));

        return back();
    }

    public function update(UpdateCommentRequest $request, Comment $comment, UpdateComment $updateComment): RedirectResponse
    {
        $updateComment->handle($comment, $this->actor($request), UpdateCommentData::fromRequest($request));

        return back();
    }

    public function destroy(Request $request, Comment $comment, DeleteComment $deleteComment): RedirectResponse
    {
        Gate::authorize('delete', $comment);

        $deleteComment->handle($comment, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Comment removed.')]);

        return back();
    }
}

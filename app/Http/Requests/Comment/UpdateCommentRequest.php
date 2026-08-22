<?php

declare(strict_types=1);

namespace App\Http\Requests\Comment;

use App\Domain\Comment\Models\Comment;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $comment = $this->route('comment');

        return $comment instanceof Comment
            && $this->user()?->can('update', $comment) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The same bound as writing one: an edit that could grow past what a comment may be is
        // a way around the rule rather than an exception to it.
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}

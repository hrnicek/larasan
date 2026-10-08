<?php

declare(strict_types=1);

namespace App\Http\Requests\Comment;

use App\Domain\Comment\Models\Commentable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();
        $user = $this->user();

        if ($subject === null || $user === null) {
            return false;
        }

        return $user->can('comment', $subject);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

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

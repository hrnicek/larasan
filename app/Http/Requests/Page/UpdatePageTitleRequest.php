<?php

declare(strict_types=1);

namespace App\Http\Requests\Page;

use App\Domain\Page\Models\Page;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePageTitleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $page = $this->route('page');

        return $page instanceof Page && $this->user()?->can('update', $page) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // Present but allowed to be blank: clearing the title is how a page goes back to
        // being Untitled, and the Action is what decides what that means.
        return ['title' => ['present', 'nullable', 'string', 'max:255']];
    }
}

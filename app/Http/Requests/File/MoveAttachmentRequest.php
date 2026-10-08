<?php

declare(strict_types=1);

namespace App\Http\Requests\File;

use App\Domain\File\Models\Attachment;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $attachment = $this->attachment();

        return $attachment instanceof Attachment
            && $this->user()?->can('move', $attachment) === true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $attachment = $this->attachment();

        return [
            'after' => [
                'nullable', 'uuid',
                Rule::exists('attachments', 'id')->where(
                    fn (Builder $query): Builder => $query
                        ->where('attachable_type', $attachment?->attachable_type)
                        ->where('attachable_id', $attachment?->attachable_id),
                ),
                Rule::notIn([$attachment?->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'after.exists' => __('That file is attached to something else.'),
            'after.not_in' => __('A file cannot be placed after itself.'),
        ];
    }

    private function attachment(): ?Attachment
    {
        $attachment = $this->route('attachment');

        return $attachment instanceof Attachment ? $attachment : null;
    }
}

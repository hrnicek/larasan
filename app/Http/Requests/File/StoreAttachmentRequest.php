<?php

declare(strict_types=1);

namespace App\Http\Requests\File;

use App\Domain\File\Actions\AttachFile;
use App\Domain\File\Models\Attachable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $subject = $this->subject();
        $user = $this->user();

        if ($subject === null || $user === null) {
            return false;
        }

        return $user->can('attach', $subject);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'files' => [
                'required',
                'array',
                'min:1',
                'max:'.config('attachments.max_files'),
            ],
            'files.*' => [
                'file',
                'max:'.config('attachments.max_kilobytes'),
                'mimetypes:'.implode(',', (array) config('attachments.mime_types')),
                function (string $attribute, mixed $upload, Closure $fail): void {
                    if (! $upload instanceof UploadedFile) {
                        return;
                    }

                    // The name itself is left out of this message: it is the part that is too long to show.
                    if (mb_strlen($upload->getClientOriginalName()) > AttachFile::MAX_NAME_LENGTH) {
                        $fail(__('A file name is longer than :length characters.', ['length' => AttachFile::MAX_NAME_LENGTH]));

                        return;
                    }

                    if (mb_strlen($upload->getClientOriginalExtension()) > AttachFile::MAX_EXTENSION_LENGTH) {
                        $fail(__(':attribute has an extension longer than :length characters.', [
                            'length' => AttachFile::MAX_EXTENSION_LENGTH,
                        ]));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.required' => __('Choose a file to attach.'),
            'files.max' => __('No more than :count files at a time.', [
                'count' => (int) config('attachments.max_files'),
            ]),
            'files.*.max' => __(':attribute is larger than :size MB.', [
                'size' => round(((int) config('attachments.max_kilobytes')) / 1024),
            ]),
            'files.*.mimetypes' => __(':attribute cannot be attached here.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $names = [];

        foreach (array_values((array) $this->file('files', [])) as $index => $upload) {
            $names["files.{$index}"] = $upload instanceof UploadedFile
                ? $upload->getClientOriginalName()
                : __('That file');
        }

        return $names;
    }

    /**
     * @return list<UploadedFile>
     */
    public function uploads(): array
    {
        return array_values(array_filter(
            (array) $this->file('files', []),
            fn (mixed $upload): bool => $upload instanceof UploadedFile,
        ));
    }

    public function subject(): (Model&Attachable)|null
    {
        foreach ($this->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model && $parameter instanceof Attachable) {
                return $parameter;
            }
        }

        return null;
    }
}

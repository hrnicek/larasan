<?php

declare(strict_types=1);

namespace App\Http\Requests\File;

use App\Domain\File\Models\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Handed to the subject's own policy, so the answer arrives as a 403 rather than as a
     * refusal rendered from a thrown exception. `file.upload` is only the workspace half of
     * it: a project the actor may read but not change is not one they may attach to
     * (TASK-260-001).
     */
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
     * Always a list, never a single file: one upload and twenty are the same request with a
     * different number of members, and a rule set that special-cases the first one only earns
     * two paths through the same validation.
     *
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
                /*
                 * An allow-list rather than a deny-list, and `mimetypes` rather than `mimes`:
                 * the first reads the file, the second believes the extension. A deny-list is
                 * a promise to have thought of every dangerous type in advance.
                 */
                'mimetypes:'.implode(',', (array) config('attachments.mime_types')),
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
     * A batch is refused by name rather than by index: *contract.exe cannot be attached here* is
     * something the person choosing can act on, where *files.3* is a position in a list they
     * never saw written down.
     *
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
     * The subject is whatever the route bound that can be attached to. Anything else is not an
     * upload route, and a request that cannot name its subject authorizes nothing.
     */
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

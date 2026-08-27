<?php

declare(strict_types=1);

namespace App\Http\Requests\File;

use App\Domain\File\Models\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
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
            'file.max' => __('That file is larger than :size MB.', [
                'size' => round(((int) config('attachments.max_kilobytes')) / 1024),
            ]),
            'file.mimetypes' => __('That kind of file cannot be attached here.'),
        ];
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

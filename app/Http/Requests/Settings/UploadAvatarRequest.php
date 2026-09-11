<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Account\Support\AvatarFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class UploadAvatarRequest extends FormRequest
{
    /**
     * `mimetypes` rather than `mimes`, as for attachments: the first reads the file, the second
     * believes the extension.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'max:'.AvatarFiles::MAX_KILOBYTES,
                'mimetypes:'.implode(',', array_keys(AvatarFiles::TYPES)),
                'dimensions:max_width=4096,max_height=4096',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => __('Choose a picture to upload.'),
            'avatar.max' => __('The picture is larger than :size MB.', [
                'size' => AvatarFiles::MAX_KILOBYTES / 1024,
            ]),
            'avatar.mimetypes' => __('A profile picture must be a JPEG, PNG or WebP image.'),
            'avatar.dimensions' => __('The picture is larger than 4096 pixels on a side.'),
        ];
    }

    public function avatar(): UploadedFile
    {
        $upload = $this->file('avatar');

        return $upload instanceof UploadedFile ? $upload : abort(422);
    }
}

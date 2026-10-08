<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Account\Support\AvatarPresets;
use Illuminate\Foundation\Http\FormRequest;

class ChooseAvatarPresetRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', 'integer', 'between:1,'.AvatarPresets::COUNT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preset.*' => __('Choose one of the pictures on offer.'),
        ];
    }
}

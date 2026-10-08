<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Domain\Shared\Enums\UiTheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppearanceRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'ui_theme' => ['required', Rule::enum(UiTheme::class)],
        ];
    }
}

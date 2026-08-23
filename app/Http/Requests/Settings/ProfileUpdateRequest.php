<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->user();

        /*
         * The id the unique rule ignores. `profileRules()` already takes `?int` for the case
         * where there is nobody to ignore — this route is behind `auth`, so that case is the
         * type system's rather than the application's.
         */
        return $this->profileRules($user instanceof User ? $user->id : null);
    }
}

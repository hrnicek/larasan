<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Domain\Workspace\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * `workspaces.owner_id` restricts on delete, so owners are refused here rather than by the database.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $owned = Workspace::query()
                    ->where('owner_id', $this->user()?->id)
                    ->orderBy('name')
                    ->pluck('name');

                if ($owned->isEmpty()) {
                    return;
                }

                $validator->errors()->add('password', __('Transfer or delete these workspaces first: :workspaces', [
                    'workspaces' => $owned->join(', ', ' and '),
                ]));
            },
        ];
    }
}

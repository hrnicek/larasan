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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * `workspaces.owner_id` restricts on delete, so an owner's account cannot be removed
     * while the workspace exists — without this the request reaches the database and
     * answers 500. Ownership transfer is a deliberate operation, not something an account
     * deletion should perform on the owner's behalf, so the request names the workspaces
     * and stops.
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

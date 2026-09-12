<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\DefineCustomField;
use App\Domain\CustomField\Actions\DeleteCustomField;
use App\Domain\CustomField\Actions\RenameCustomField;
use App\Domain\CustomField\Exceptions\CustomFieldException;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\CustomField\Models\CustomFieldOption;
use App\Domain\Shared\Enums\Capability;
use App\Domain\Shared\Enums\CustomFieldType;
use App\Domain\Workspace\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Requests\CustomField\StoreCustomFieldRequest;
use App\Http\Requests\CustomField\UpdateCustomFieldRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->current($request);

        Gate::authorize('view', $workspace);

        return Inertia::render('settings/Fields', [
            'fields' => $workspace->customFields()
                ->with('options')
                ->withCount(['projects', 'values'])
                ->orderBy('name')
                ->get()
                ->map(fn (CustomField $field): array => [
                    'id' => $field->id,
                    'name' => $field->name,
                    'type' => $field->type->value,
                    'options' => $field->options
                        ->map(fn (CustomFieldOption $option): array => [
                            'id' => $option->id,
                            'label' => $option->label,
                        ])
                        ->all(),
                    'projectCount' => (int) $field->getAttribute('projects_count'),
                    'valueCount' => (int) $field->getAttribute('values_count'),
                ])
                ->all(),
            'types' => array_column(CustomFieldType::cases(), 'value'),
            'can' => [
                'manage' => $request->user()?->can(Capability::CustomFieldManage->value, $workspace) ?? false,
            ],
        ]);
    }

    public function store(StoreCustomFieldRequest $request, DefineCustomField $defineField): RedirectResponse
    {
        $workspace = $this->current($request);

        $this->translating(fn () => $defineField->handle(
            $workspace,
            $this->actor($request),
            (string) $request->string('name'),
            CustomFieldType::from((string) $request->string('type')),
            $this->options($request),
        ));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field created.')]);

        return back();
    }

    public function update(
        UpdateCustomFieldRequest $request,
        CustomField $field,
        RenameCustomField $renameField,
    ): RedirectResponse {
        $this->translating(fn () => $renameField->handle(
            $field,
            $this->actor($request),
            (string) $request->string('name'),
        ));

        return back();
    }

    public function destroy(Request $request, CustomField $field, DeleteCustomField $deleteField): RedirectResponse
    {
        Gate::authorize(Capability::CustomFieldManage->value, $field->workspace);

        $deleteField->handle($field, $this->actor($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Field deleted.')]);

        return back();
    }

    /**
     * @return list<string>
     */
    private function options(Request $request): array
    {
        /** @var list<string> $options */
        $options = array_values(array_filter(
            $request->input('options', []),
            is_string(...),
        ));

        return $options;
    }

    private function current(Request $request): Workspace
    {
        return ResolveCurrentWorkspace::from($request) ?? abort(404);
    }

    /**
     * The unique index enforces names, since a FormRequest pre-check would race concurrent requests.
     */
    private function translating(callable $operation): void
    {
        try {
            $operation();
        } catch (CustomFieldException $exception) {
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }
    }
}

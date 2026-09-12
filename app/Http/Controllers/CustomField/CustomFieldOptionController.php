<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\UpdateCustomFieldOptions;
use App\Domain\CustomField\Models\CustomField;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomField\UpdateCustomFieldOptionsRequest;
use Illuminate\Http\RedirectResponse;

class CustomFieldOptionController extends Controller
{
    public function update(
        UpdateCustomFieldOptionsRequest $request,
        CustomField $field,
        UpdateCustomFieldOptions $updateOptions,
    ): RedirectResponse {
        /** @var list<array{id: ?string, label: string}> $options */
        $options = $request->validated('options');

        $updateOptions->handle($field, $this->actor($request), $options);

        return back();
    }
}

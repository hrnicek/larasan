<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\UpdateCustomFieldOptions;
use App\Domain\CustomField\Models\CustomField;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomField\UpdateCustomFieldOptionsRequest;
use Illuminate\Http\RedirectResponse;

/**
 * What a choice field offers.
 *
 * A `PUT` of the whole list rather than three endpoints: an option list is short, is read as one
 * thing and is reordered whole, so a request that could apply half of it is a request nobody wants
 * to have sent.
 */
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

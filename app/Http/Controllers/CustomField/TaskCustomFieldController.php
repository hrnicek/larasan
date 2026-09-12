<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\SetTaskCustomFieldValue;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomField\SetCustomFieldValueRequest;
use Illuminate\Http\RedirectResponse;

class TaskCustomFieldController extends Controller
{
    public function update(
        SetCustomFieldValueRequest $request,
        Task $task,
        CustomField $field,
        SetTaskCustomFieldValue $setValue,
    ): RedirectResponse {
        $setValue->handle($task, $field, $this->actor($request), $request->input('value'));

        return back();
    }
}

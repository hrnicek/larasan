<?php

declare(strict_types=1);

namespace App\Http\Controllers\CustomField;

use App\Domain\CustomField\Actions\SetTaskCustomFieldValue;
use App\Domain\CustomField\Models\CustomField;
use App\Domain\Task\Models\Task;
use App\Http\Controllers\Controller;
use App\Http\Requests\CustomField\SetCustomFieldValueRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One field's answer on one task. A `PUT` because setting a value twice is the same request
 * twice — and clearing it is the same request with nothing in it, rather than a `DELETE` nobody
 * would think to send.
 */
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

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}

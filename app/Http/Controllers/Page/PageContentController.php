<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\SavePageContent;
use App\Domain\Page\Data\SavePageContentData;
use App\Domain\Page\Exceptions\PageException;
use App\Domain\Page\Models\Page;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\SavePageContentRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autosave.
 *
 * The one endpoint in the application that answers with JSON rather than a redirect, because
 * the caller is not a form: it is an editor somebody is still typing into, and re-rendering
 * the page under them is exactly what must not happen.
 *
 * A stale save is a 409 rather than the 422 a domain refusal usually becomes. The distinction
 * is worth the code: 422 says "what you sent is wrong", and what the writer sent is fine — the
 * page simply moved on without them, and the client has to reload rather than retry.
 */
class PageContentController extends Controller
{
    public function update(SavePageContentRequest $request, Page $page, SavePageContent $savePage): JsonResponse
    {
        /** @var array<string, mixed> $content */
        $content = $request->array('content');

        try {
            $saved = $savePage->handle($page, $this->actor($request), new SavePageContentData(
                content: $content,
                version: $request->integer('version'),
            ));
        } catch (PageException $refusal) {
            if ($refusal->getMessage() !== PageException::changedElsewhere()->getMessage()) {
                throw $refusal;
            }

            return response()->json([
                'message' => $refusal->getMessage(),
                'version' => $page->fresh()?->version,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'version' => $saved->version,
            'savedAt' => $saved->updated_at?->toIso8601String(),
        ]);
    }
}

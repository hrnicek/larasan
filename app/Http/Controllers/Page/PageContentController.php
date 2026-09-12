<?php

declare(strict_types=1);

namespace App\Http\Controllers\Page;

use App\Domain\Page\Actions\SavePageContent;
use App\Domain\Page\Data\SavePageContentData;
use App\Domain\Page\Exceptions\PageChangedElsewhere;
use App\Domain\Page\Models\Page;
use App\Http\Controllers\Controller;
use App\Http\Requests\Page\SavePageContentRequest;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A stale save answers 409 rather than 422, so the editor reloads instead of retrying.
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
        } catch (PageChangedElsewhere $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'version' => $page->fresh()?->version,
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'version' => $saved->version,
            'savedAt' => $saved->updated_at?->toIso8601String(),
        ]);
    }
}

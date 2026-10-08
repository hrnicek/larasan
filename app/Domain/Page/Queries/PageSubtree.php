<?php

declare(strict_types=1);

namespace App\Domain\Page\Queries;

use App\Domain\Page\Models\Page;

final readonly class PageSubtree
{
    /**
     * @return list<string>
     */
    public function idsUnder(Page $page): array
    {
        $found = [];
        $level = [$page->id];

        for ($depth = 0; $depth < Page::MAX_DEPTH && $level !== []; $depth++) {
            /** @var list<string> $level */
            $level = Page::query()
                ->where('project_id', $page->project_id)
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->all();

            foreach ($level as $id) {
                $found[] = $id;
            }
        }

        return $found;
    }

    /**
     * Counts the page itself as one.
     */
    public function heightOf(Page $page): int
    {
        $height = 1;
        $level = [$page->id];

        for ($depth = 0; $depth < Page::MAX_DEPTH && $level !== []; $depth++) {
            /** @var list<string> $level */
            $level = Page::query()
                ->where('project_id', $page->project_id)
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->all();

            if ($level !== []) {
                $height++;
            }
        }

        return $height;
    }

    /**
     * Counts the root as one.
     */
    public function depthOf(?Page $page): int
    {
        $depth = 0;

        while ($page !== null && $depth <= Page::MAX_DEPTH) {
            $depth++;
            $page = $page->parent;
        }

        return $depth;
    }
}

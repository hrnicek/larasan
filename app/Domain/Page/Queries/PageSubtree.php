<?php

declare(strict_types=1);

namespace App\Domain\Page\Queries;

use App\Domain\Page\Models\Page;

/**
 * The pages underneath a page.
 *
 * Read level by level rather than with a recursive CTE: `Page::MAX_DEPTH` bounds the tree at
 * five, so this is at most five queries and it stays a query the schema builder can express.
 * A CTE would be the right answer if pages could nest without limit; they cannot.
 */
final readonly class PageSubtree
{
    /**
     * Every descendant of the page, deepest level last, excluding the page itself.
     *
     * @return list<string>
     */
    public function idsUnder(Page $page): array
    {
        $found = [];
        $level = [$page->id];

        for ($depth = 0; $depth < Page::MAX_DEPTH && $level !== []; $depth++) {
            /** @var list<string> $level */
            $level = Page::query()
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
     * How tall the page's own subtree is, counting the page itself as one. What a move needs
     * to know: dropping a two-level page under a page that already sits at four would put its
     * deepest child past `Page::MAX_DEPTH`.
     */
    public function heightOf(Page $page): int
    {
        $height = 1;
        $level = [$page->id];

        for ($depth = 0; $depth < Page::MAX_DEPTH && $level !== []; $depth++) {
            /** @var list<string> $level */
            $level = Page::query()
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
     * How deep the page sits, counting the root as one.
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

/**
 * What the server calls a page nobody has named — `App\Domain\Page\Models\Page::UNTITLED`.
 *
 * The title field shows it as a placeholder rather than as text, so somebody who starts typing
 * does not have to delete a word they never wrote. Kept here because more than one component
 * compares against it, and a second spelling would be a bug nobody notices.
 */
export const UNTITLED = 'Untitled';

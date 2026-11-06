import { t } from '@/lib/i18n';

// Mirrors `App\Domain\Page\Models\Page::UNTITLED`.
export const UNTITLED = 'Untitled';

/** The stored title stays English for everyone; only its display follows the reader's language. */
export function pageTitle(title: string): string {
    return title === UNTITLED ? t('Untitled') : title;
}

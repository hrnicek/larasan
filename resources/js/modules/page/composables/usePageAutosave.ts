import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import PageContentController from '@/actions/App/Http/Controllers/Page/PageContentController';
import type { PageDocument } from '@/modules/page/types';

/**
 * What the person writing is told about their own words.
 *
 * `conflict` is the one that matters: the page moved on without them, and the honest answer is
 * to stop saving and say so rather than to retry — a retry would overwrite whatever the other
 * person wrote (ADR-0017).
 */
export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'conflict' | 'failed';

/** How long after the last keystroke a save goes out. Long enough to be a sentence, not a word. */
const QUIET_PERIOD = 900;

/**
 * Saving a page while somebody writes in it.
 *
 * Debounced rather than per keystroke, and never two in flight: a save that arrives while one is
 * running is held and sent afterwards, because the version the second one carries is the one the
 * first is about to change.
 */
export function usePageAutosave(pageId: string, initialVersion: number) {
    const version = ref(initialVersion);
    const state = ref<SaveState>('idle');

    let timer: ReturnType<typeof setTimeout> | null = null;
    let queued: PageDocument | null = null;
    let inFlight = false;

    const send = async (document: PageDocument): Promise<void> => {
        inFlight = true;
        state.value = 'saving';

        try {
            /*
             * Inertia's own XHR client rather than a visit: this endpoint answers with JSON, and
             * a visit would try to render the reply over the editor somebody is typing in. The
             * client is used directly rather than through `useHttp`, whose form typing cannot
             * describe a document that nests arbitrarily.
             */
            const answer = await http.getClient().request({
                method: 'put',
                url: PageContentController.update.url(pageId),
                data: { content: document, version: version.value },
                headers: { Accept: 'application/json' },
            });

            version.value = (JSON.parse(answer.data) as { version: number }).version;
            state.value = 'saved';
        } catch (failure: unknown) {
            /*
             * 409 is the page having changed elsewhere, and it is the end of this editor's
             * usefulness until it is reloaded — anything else is a save that may be worth
             * trying again, so the queue is kept and the state says so.
             */
            const status = (failure as { response?: { status?: number } })?.response?.status;

            state.value = status === 409 ? 'conflict' : 'failed';

            if (state.value === 'conflict') {
                queued = null;
            }
        } finally {
            inFlight = false;
        }

        const next = queued;
        queued = null;

        if (next !== null && state.value !== 'conflict') {
            await send(next);
        }
    };

    /** Write this document, once the typing stops. */
    const save = (document: PageDocument): void => {
        if (state.value === 'conflict') {
            return;
        }

        state.value = 'pending';

        if (timer !== null) {
            clearTimeout(timer);
        }

        timer = setTimeout(() => {
            timer = null;

            if (inFlight) {
                queued = document;

                return;
            }

            void send(document);
        }, QUIET_PERIOD);
    };

    /** Whether anything is written that the server has not been told about. */
    const unsaved = (): boolean => state.value === 'pending' || state.value === 'saving';

    /*
     * A page with a save still waiting is a page with words only this browser knows. The prompt
     * is the browser's own — there is no other honest way to hold a tab open — and it appears
     * only while something is genuinely outstanding.
     */
    const guard = (event: BeforeUnloadEvent): void => {
        if (unsaved()) {
            event.preventDefault();
        }
    };

    window.addEventListener('beforeunload', guard);

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', guard);

        if (timer !== null) {
            clearTimeout(timer);
        }
    });

    return { state, version, save, unsaved };
}

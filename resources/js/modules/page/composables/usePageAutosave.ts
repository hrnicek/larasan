import { http } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';
import PageContentController from '@/actions/App/Http/Controllers/Page/PageContentController';
import type { PageDocument } from '@/modules/page/types';

// On `conflict` saving stops rather than retrying, which would overwrite the other writer. See ADR-0017.
export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'conflict' | 'failed';

/** Milliseconds after the last keystroke before a save goes out. */
const QUIET_PERIOD = 900;

// Never two saves in flight: the next one must carry the version the running one returns.
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
            // Not a visit, since the endpoint answers with JSON; not `useHttp`, whose form typing
            // cannot describe an arbitrarily nested document.
            const answer = await http.getClient().request({
                method: 'put',
                url: PageContentController.update.url(pageId),
                data: { content: document, version: version.value },
                headers: { Accept: 'application/json' },
            });

            version.value = (JSON.parse(answer.data) as { version: number }).version;
            state.value = 'saved';
        } catch (failure: unknown) {
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

    const unsaved = (): boolean => state.value === 'pending' || state.value === 'saving';

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

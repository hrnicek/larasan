import { http, router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { onBeforeUnmount, ref } from 'vue';
import PageContentController from '@/actions/App/Http/Controllers/Page/PageContentController';
import type { PageDocument } from '@/modules/page/types';

// On `conflict` saving stops rather than retrying, which would overwrite the other writer. See ADR-0017.
export type SaveState = 'idle' | 'pending' | 'saving' | 'saved' | 'conflict' | 'failed';

/** Milliseconds after the last keystroke before a save goes out. */
const QUIET_PERIOD = 900;

const RETRY_DELAYS = [1_000, 2_000, 5_000, 10_000, 30_000];

// Bound to one page and version: the owning component must be keyed by page id to get a fresh instance per page.
// Never two saves in flight: the next one must carry the version the running one returns.
export function usePageAutosave(pageId: string, initialVersion: number): {
    state: Ref<SaveState>;
    save: (document: PageDocument) => void;
} {
    const state = ref<SaveState>('idle');

    let version = initialVersion;
    let unsent: PageDocument | null = null;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let inFlight = false;
    let failures = 0;

    const stopTimer = (): void => {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }
    };

    const retryLater = (): void => {
        if (failures > RETRY_DELAYS.length) {
            return;
        }

        timer = setTimeout(() => {
            timer = null;
            void send();
        }, RETRY_DELAYS[failures - 1]);
    };

    const send = async (): Promise<void> => {
        if (inFlight || unsent === null || state.value === 'conflict') {
            return;
        }

        const document = unsent;
        let outcome: 'saved' | 'conflict' | 'failed';

        unsent = null;
        inFlight = true;
        state.value = 'saving';

        try {
            // Not a visit, since the endpoint answers with JSON; not `useHttp`, whose form typing
            // cannot describe an arbitrarily nested document.
            const answer = await http.getClient().request({
                method: 'put',
                url: PageContentController.update.url(pageId),
                data: { content: document, version },
                headers: { Accept: 'application/json' },
            });

            version = (JSON.parse(answer.data) as { version: number }).version;
            outcome = 'saved';
        } catch (failure: unknown) {
            const status = (failure as { response?: { status?: number } })?.response?.status;

            outcome = status === 409 ? 'conflict' : 'failed';
        } finally {
            inFlight = false;
        }

        if (outcome === 'conflict') {
            stopTimer();
            unsent = null;
            state.value = 'conflict';

            return;
        }

        failures = outcome === 'failed' ? failures + 1 : 0;

        if (unsent !== null) {
            state.value = 'pending';

            if (timer === null) {
                void send();
            }

            return;
        }

        if (outcome === 'saved') {
            state.value = 'saved';

            return;
        }

        unsent = document;
        state.value = 'failed';
        retryLater();
    };

    const save = (document: PageDocument): void => {
        if (state.value === 'conflict') {
            return;
        }

        unsent = document;
        failures = 0;
        state.value = 'pending';

        stopTimer();

        timer = setTimeout(() => {
            timer = null;
            void send();
        }, QUIET_PERIOD);
    };

    const flush = (): void => {
        if (unsent === null) {
            return;
        }

        stopTimer();
        void send();
    };

    const unsaved = (): boolean => ['pending', 'saving', 'failed'].includes(state.value);

    const guard = (event: BeforeUnloadEvent): void => {
        if (unsaved()) {
            event.preventDefault();
        }
    };

    window.addEventListener('beforeunload', guard);

    // `beforeunload` never fires for an Inertia visit; partial reloads stay on the page and keep the quiet period.
    const stopListening = router.on('before', ({ detail: { visit } }) => {
        if (!visit.prefetch && visit.only.length === 0 && visit.except.length === 0) {
            flush();
        }
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', guard);
        stopListening();
        flush();
    });

    return { state, save };
}

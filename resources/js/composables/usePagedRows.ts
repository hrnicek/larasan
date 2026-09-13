import { router } from '@inertiajs/vue3';
import type { Ref } from 'vue';
import { ref, watch } from 'vue';

type Row = { id: string };

export type PagedRowsOptions<T extends Row> = {
    rows: () => T[];
    meta: () => { page: number; hasMore: boolean };
    url: (page: number) => string;
    only: string[];
    /**
     * `stable`: a row keeps the place it was first drawn in, even once a re-rendered page no longer lists it.
     * `server`: each loaded page shows exactly the rows the server last sent for it, in its order.
     */
    placement: 'stable' | 'server';
    /** A change, such as another tab or search term, starts the list over from the incoming page. */
    scope?: () => unknown;
    onAdded?: (added: T[], page: number) => void;
};

export type PagedRows<T extends Row> = {
    rows: Ref<T[]>;
    hasMore: Readonly<Ref<boolean>>;
    loading: Readonly<Ref<boolean>>;
    loadFailed: Readonly<Ref<boolean>>;
    loadMore: () => void;
};

export function usePagedRows<T extends Row>(
    options: PagedRowsOptions<T>,
): PagedRows<T> {
    const rows = ref([...options.rows()]) as Ref<T[]>;
    const pageOf = new Map<string, number>();

    // Not meta.page: a write re-renders the first page after Load more has fetched later ones.
    const loadedPage = ref(options.meta().page);
    const hasMore = ref(options.meta().hasMore);
    const loading = ref(false);
    const loadFailed = ref(false);

    const remember = (incoming: T[], page: number): void => {
        incoming.forEach((row) => pageOf.set(row.id, page));
    };

    remember(rows.value, loadedPage.value);

    const inPlace = (incoming: T[], added: T[], page: number): T[] => {
        const fresh = new Map(incoming.map((row) => [row.id, row]));
        const kept = rows.value.map((row) => fresh.get(row.id) ?? row);

        return page > 1 ? [...kept, ...added] : [...added, ...kept];
    };

    const byPage = (incoming: T[], page: number): T[] => {
        const fresh = new Set(incoming.map((row) => row.id));
        const others = rows.value.filter(
            (row) => !fresh.has(row.id) && pageOf.get(row.id) !== page,
        );

        return [
            ...others.filter((row) => (pageOf.get(row.id) ?? 0) < page),
            ...incoming,
            ...others.filter((row) => (pageOf.get(row.id) ?? 0) > page),
        ];
    };

    watch(
        [options.rows, () => options.scope?.()],
        ([incoming, scope], [, previousScope]) => {
            const meta = options.meta();

            if (scope !== previousScope) {
                pageOf.clear();
                remember(incoming, meta.page);
                rows.value = [...incoming];
                loadedPage.value = meta.page;
                hasMore.value = meta.hasMore;
                loadFailed.value = false;

                return;
            }

            const known = new Set(rows.value.map((row) => row.id));
            const added = incoming.filter((row) => !known.has(row.id));

            if (meta.page >= loadedPage.value) {
                loadedPage.value = meta.page;
                hasMore.value = meta.hasMore;
            }

            rows.value =
                options.placement === 'stable'
                    ? inPlace(incoming, added, meta.page)
                    : byPage(incoming, meta.page);
            remember(incoming, meta.page);

            options.onAdded?.(added, meta.page);
        },
    );

    const loadMore = (): void => {
        if (!hasMore.value || loading.value) {
            return;
        }

        loading.value = true;
        loadFailed.value = false;

        router.get(
            options.url(loadedPage.value + 1),
            {},
            {
                only: options.only,
                preserveScroll: true,
                preserveState: true,
                // A write re-renders the page named in the URL, so the URL must keep naming the first one.
                preserveUrl: true,
                onHttpException: () => {
                    loadFailed.value = true;

                    return false;
                },
                onNetworkError: () => {
                    loadFailed.value = true;
                },
                onFinish: () => {
                    loading.value = false;
                },
            },
        );
    };

    return { rows, hasMore, loading, loadFailed, loadMore };
}

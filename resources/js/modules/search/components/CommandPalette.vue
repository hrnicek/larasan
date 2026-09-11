<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { Bookmark, CheckCircle2, Clipboard, MessageSquare, Search as SearchIcon, User as UserIcon, X } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import TaskController from '@/actions/App/Http/Controllers/Task/TaskController';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import UserAvatar from '@/components/UserAvatar.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import { useCommandPalette } from '@/modules/search/composables/useCommandPalette';
import type { RecentItem, SavedSearch, SearchAnswer, SearchKind } from '@/modules/search/types';
import { index as searchIndex, suggestions } from '@/routes/search';
import { destroy as forgetSaved } from '@/routes/search/saved';

/**
 * The way anywhere: `⌘K`, a term, and the four kinds of thing this application holds.
 *
 * It is an overlay rather than a screen because it opens over whatever somebody was doing and
 * has to be able to leave without changing it. That is also why it asks a JSON endpoint instead
 * of visiting: an Inertia visit would replace the page underneath.
 *
 * One request per settled keystroke, and the one before it is cancelled — a palette that answers
 * out of order shows the results for a word somebody has already finished typing over.
 */
const { open, kind, hide } = useCommandPalette();

const KINDS: { value: SearchKind; label: string; icon: typeof CheckCircle2 }[] = [
    { value: 'tasks', label: 'Tasks', icon: CheckCircle2 },
    { value: 'projects', label: 'Projects', icon: Clipboard },
    { value: 'people', label: 'People', icon: UserIcon },
    { value: 'messages', label: 'Messages', icon: MessageSquare },
];

const DEBOUNCE = 180;

const term = ref('');
const answer = ref<SearchAnswer | null>(null);
const searching = ref(false);
const active = ref(0);
const field = ref<HTMLInputElement | null>(null);
const list = ref<HTMLElement | null>(null);

/*
 * No data on the request: `useHttp` serialises its own state into the query string for a GET,
 * and a `null` kind goes over the wire as `kind=`, which is not a kind. The term and the kind
 * are in the URL this builds instead.
 */
const http = useHttp<Record<string, never>, SearchAnswer>({});

let pending: ReturnType<typeof setTimeout> | null = null;

type Row =
    | { kind: 'tasks'; id: string; title: string; url: string; projects: { id: string; name: string; color: string | null; icon: string | null }[]; done: boolean }
    | { kind: 'projects'; id: string; title: string; url: string; color: string | null; icon: string | null; archived: boolean }
    | { kind: 'people'; id: string; title: string; url: string; email: string; avatar: string | null; role: string | null }
    | { kind: 'messages'; id: string; title: string; url: string; task: string | null; author: string | null };

/**
 * Every result as one walkable list, in the kinds' own order. Ranking happens inside a kind; a
 * task and a person have nothing to be ranked against each other by (ADR-0016).
 */
const rows = computed<Row[]>(() => {
    const results = answer.value?.results ?? {};

    return [
        ...(results.tasks ?? []).map((task): Row => ({
            kind: 'tasks',
            id: task.id,
            title: task.title,
            url: TaskController.show.url({ task: task.id }),
            projects: task.projects,
            done: task.completedAt !== null,
        })),
        ...(results.projects ?? []).map((project): Row => ({
            kind: 'projects',
            id: project.id,
            title: project.name,
            url: ProjectController.show.url({ project: project.id }),
            color: project.color,
            icon: project.icon,
            archived: project.archived,
        })),
        ...(results.people ?? []).map((person): Row => ({
            kind: 'people',
            id: String(person.id),
            title: person.name,
            // A person is not a screen in this application; what somebody wants from finding one
            // is their work, which is a search the assignee filter already answers.
            url: searchIndex.url({ query: { assignee: person.id } }),
            email: person.email,
            avatar: person.avatar,
            role: person.role,
        })),
        ...(results.messages ?? []).map((message): Row => ({
            kind: 'messages',
            id: message.id,
            title: message.excerpt,
            url: message.task ? TaskController.show.url({ task: message.task.id }) : '#',
            task: message.task?.title ?? null,
            author: message.author?.name ?? null,
        })),
    ];
});

const groups = computed(() =>
    KINDS.map((entry) => ({
        ...entry,
        rows: rows.value.filter((row) => row.kind === entry.value),
    })).filter((group) => group.rows.length > 0),
);

const nothingFound = computed(
    () => term.value.trim() !== '' && !searching.value && rows.value.length === 0,
);

const degraded = computed(() => answer.value?.meta.degraded === true);

const saved = computed<SavedSearch[]>(() => answer.value?.saved ?? []);
const recents = computed<RecentItem[]>(() => answer.value?.recents ?? []);

function ask(): void {
    const asked = term.value.trim();

    searching.value = true;
    http.cancel();

    http.get(suggestions.url({ query: { q: asked, kind: kind.value ?? undefined } }), {
        onSuccess: (response: SearchAnswer) => {
            answer.value = response;
            active.value = 0;
        },
        onFinish: () => {
            searching.value = false;
        },
    });
}

function schedule(): void {
    if (pending !== null) {
        clearTimeout(pending);
    }

    pending = setTimeout(ask, DEBOUNCE);
}

function move(by: number): void {
    if (rows.value.length === 0) {
        return;
    }

    active.value = (active.value + by + rows.value.length) % rows.value.length;

    nextTick(() => {
        list.value?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    });
}

function openRow(row: Row | undefined): void {
    if (row === undefined || row.url === '#') {
        return;
    }

    hide();
    router.visit(row.url);
}

function chooseKind(next: SearchKind | null): void {
    kind.value = kind.value === next ? null : next;
    active.value = 0;
    ask();
}

/**
 * A saved search is a link, not a palette state: it opens the search screen, with the term and
 * the filters it was kept with, so it can be shared, reloaded and paged.
 */
function openSaved(search: SavedSearch): void {
    hide();

    router.visit(
        searchIndex.url({
            query: {
                q: search.term,
                project: search.filters.project,
                assignee: search.filters.assignee,
                completed: search.filters.completed,
            },
        }),
    );
}

function openRecent(item: RecentItem): void {
    hide();

    router.visit(
        item.kind === 'tasks'
            ? TaskController.show.url({ task: item.id })
            : ProjectController.show.url({ project: item.id }),
    );
}

function forget(search: SavedSearch): void {
    // No confirmation: a bookmark is a few seconds to make again, and a dialog per chip would
    // cost more than the mistake it prevents (TASK-200-011 is for what cannot be undone).
    router.delete(forgetSaved.url({ savedSearch: search.id }), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => ask(),
    });
}

watch(term, schedule);
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    term.value = '';
    answer.value = null;
    active.value = 0;
    // The empty field is where the saved searches are, and this is the request that fetches
    // them.
    ask();
    nextTick(() => field.value?.focus());
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="top-[12%] max-w-2xl translate-y-0 gap-0 overflow-hidden p-0"
            :show-close-button="false"
            @keydown.down.prevent="move(1)"
            @keydown.up.prevent="move(-1)"
            @keydown.enter.prevent="openRow(rows[active])"
        >
            <DialogTitle class="sr-only">Search</DialogTitle>
            <DialogDescription class="sr-only">
                Search this workspace's tasks, projects, people and messages.
            </DialogDescription>

            <div class="flex items-center gap-2 border-b border-border px-4">
                <SearchIcon class="size-4 shrink-0 text-muted-foreground" />
                <input
                    ref="field"
                    v-model="term"
                    type="text"
                    class="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                    placeholder="Search tasks, projects, people and messages"
                    aria-label="Search"
                    autocomplete="off"
                />
                <Spinner v-if="searching" class="size-4 shrink-0 text-muted-foreground" />
            </div>

            <div class="flex flex-wrap items-center gap-1.5 border-b border-border px-3 py-2">
                <button
                    v-for="entry in KINDS"
                    :key="entry.value"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-colors"
                    :class="
                        kind === entry.value
                            ? 'border-primary bg-primary/10 text-foreground'
                            : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    :aria-pressed="kind === entry.value"
                    @click="chooseKind(entry.value)"
                >
                    <component :is="entry.icon" class="size-3.5" />
                    {{ entry.label }}
                </button>
            </div>

            <p v-if="degraded" class="border-b border-border bg-muted/40 px-4 py-2 text-xs text-muted-foreground">
                The search engine is unavailable, so these are tasks only, matched by whole words.
            </p>

            <div ref="list" class="max-h-[22rem] overflow-y-auto p-2">
                <template v-if="term.trim() === ''">
                    <div v-if="recents.length > 0" class="mb-2">
                        <p class="px-2 py-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Recents
                        </p>

                        <button
                            v-for="item in recents"
                            :key="`${item.kind}-${item.id}`"
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-accent"
                            @click="openRecent(item)"
                        >
                            <template v-if="item.kind === 'tasks'">
                                <CheckCircle2 class="size-4 shrink-0" :class="item.completed ? 'text-primary' : 'text-muted-foreground'" />
                                <span class="min-w-0 flex-1 truncate" :class="item.completed ? 'text-muted-foreground line-through' : ''">
                                    {{ item.title }}
                                </span>
                            </template>

                            <template v-else>
                                <ProjectTile :name="item.title" :color="item.color" :icon="item.icon" size="sm" />
                                <span class="min-w-0 flex-1 truncate">{{ item.title }}</span>
                                <span v-if="item.archived" class="shrink-0 text-xs text-muted-foreground">Archived</span>
                            </template>
                        </button>
                    </div>

                    <div v-if="saved.length > 0" class="mb-1">
                        <p class="px-2 py-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Saved searches
                        </p>

                        <div class="flex flex-wrap gap-1.5 px-2 py-1">
                            <span
                                v-for="search in saved"
                                :key="search.id"
                                class="group inline-flex items-center gap-1.5 rounded-full border border-border py-1 pr-1 pl-2.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                            >
                                <button type="button" class="inline-flex items-center gap-1.5" @click="openSaved(search)">
                                    <Bookmark class="size-3.5" />
                                    {{ search.name }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-full p-0.5 opacity-0 transition-opacity group-hover:opacity-100 hover:bg-muted"
                                    :aria-label="`Forget ${search.name}`"
                                    @click="forget(search)"
                                >
                                    <X class="size-3" />
                                </button>
                            </span>
                        </div>
                    </div>

                    <p
                        v-if="recents.length === 0 && saved.length === 0"
                        class="px-2 py-6 text-center text-sm text-muted-foreground"
                    >
                        Type to search this workspace.
                    </p>
                </template>

                <p v-if="nothingFound" class="px-2 py-6 text-center text-sm text-muted-foreground">
                    Nothing matched “{{ term.trim() }}”.
                </p>

                <div v-for="group in groups" :key="group.value" class="mb-2 last:mb-0">
                    <p class="px-2 py-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                        {{ group.label }}
                    </p>

                    <button
                        v-for="row in group.rows"
                        :key="`${row.kind}-${row.id}`"
                        type="button"
                        class="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left text-sm transition-colors hover:bg-accent"
                        :class="rows[active] === row ? 'bg-accent' : ''"
                        :data-active="rows[active] === row"
                        @mousemove="active = rows.indexOf(row)"
                        @click="openRow(row)"
                    >
                        <template v-if="row.kind === 'tasks'">
                            <CheckCircle2 class="size-4 shrink-0" :class="row.done ? 'text-primary' : 'text-muted-foreground'" />
                            <span class="min-w-0 flex-1 truncate" :class="row.done ? 'text-muted-foreground line-through' : ''">
                                {{ row.title }}
                            </span>
                            <span v-if="row.projects.length > 0" class="shrink-0 truncate text-xs text-muted-foreground">
                                {{ row.projects[0].name }}
                            </span>
                        </template>

                        <template v-else-if="row.kind === 'projects'">
                            <ProjectTile :name="row.title" :color="row.color" :icon="row.icon" size="sm" />
                            <span class="min-w-0 flex-1 truncate">{{ row.title }}</span>
                            <span v-if="row.archived" class="shrink-0 text-xs text-muted-foreground">Archived</span>
                        </template>

                        <template v-else-if="row.kind === 'people'">
                            <UserAvatar :user="{ name: row.title, avatar: row.avatar }" size="sm" />
                            <span class="min-w-0 flex-1 truncate">{{ row.title }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ row.email }}</span>
                        </template>

                        <template v-else>
                            <MessageSquare class="size-4 shrink-0 text-muted-foreground" />
                            <span class="min-w-0 flex-1 truncate">{{ row.title }}</span>
                            <span v-if="row.task" class="shrink-0 truncate text-xs text-muted-foreground">
                                {{ row.task }}
                            </span>
                        </template>
                    </button>
                </div>
            </div>

            <div class="flex items-center gap-3 border-t border-border px-4 py-2 text-[11px] text-muted-foreground">
                <span><kbd class="rounded border border-border px-1">↑</kbd><kbd class="ml-0.5 rounded border border-border px-1">↓</kbd> to move</span>
                <span><kbd class="rounded border border-border px-1">↵</kbd> to open</span>
                <span><kbd class="rounded border border-border px-1">esc</kbd> to close</span>
            </div>
        </DialogContent>
    </Dialog>
</template>

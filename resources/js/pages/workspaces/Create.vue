<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import {
    Bell,
    CheckSquare,
    ChevronsUpDown,
    FolderKanban,
    Home,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import WorkspaceController from '@/actions/App/Http/Controllers/Workspace/WorkspaceController';
import InputError from '@/components/InputError.vue';
import ModalShell from '@/components/ModalShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import UserAvatar from '@/components/UserAvatar.vue';
import TimezonePicker from '@/modules/workspace/components/TimezonePicker.vue';

/**
 * The screen that makes a workspace, with the workspace beside it — the same arrangement as the
 * screen that makes a project.
 *
 * Two answers are asked for: what the workspace is called and whose clock it keeps. Its address
 * is derived from the name and can be changed in settings, and its people are invited once it
 * exists, because an invitation needs a workspace to be addressed from.
 *
 * The panel on the right is the sidebar as it will read after the redirect, and the local time
 * in the zone being chosen. It is decorative and marked as such.
 */
const props = defineProps<{
    options: { timezones: string[] };
}>();

const user = computed(() => usePage().props.auth.user);

const browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone;

/*
 * The browser's zone when the server recognises it. A browser can report a legacy alias
 * (`Asia/Calcutta`) that the `timezone` rule refuses, and preselecting a value the form would
 * then reject is worse than preselecting UTC.
 */
const detected = props.options.timezones.includes(browserTimezone)
    ? browserTimezone
    : null;

const name = ref('');
const timezone = ref(detected ?? 'UTC');

const previewName = computed(() => name.value.trim() || 'Untitled workspace');

/*
 * An approximation of `Str::slug` for the preview only. The server derives the real address and
 * suffixes it when it is taken, so this line can differ from the result by a `-2`.
 */
const previewSlug = computed(
    () =>
        name.value
            .normalize('NFD')
            .replace(/\p{Diacritic}/gu, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'workspace',
);

const now = ref(new Date());
let ticker: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    ticker = setInterval(() => (now.value = new Date()), 15_000);
});

onBeforeUnmount(() => clearInterval(ticker));

const localTime = computed(() => {
    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: timezone.value,
            hour: '2-digit',
            minute: '2-digit',
        }).format(now.value);
    } catch {
        return '';
    }
});

const localDay = computed(() => {
    try {
        return new Intl.DateTimeFormat(undefined, {
            timeZone: timezone.value,
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        }).format(now.value);
    } catch {
        return '';
    }
});

const zoneCity = computed(() =>
    (timezone.value.split('/').pop() ?? timezone.value).replaceAll('_', ' '),
);

const navigation = [
    { label: 'Home', icon: Home },
    { label: 'My Tasks', icon: CheckSquare },
    { label: 'Inbox', icon: Bell },
    { label: 'Projects', icon: FolderKanban },
];
</script>

<template>
    <ModalShell
        v-slot="{ close }"
        title="New workspace"
        description="Projects, tasks and members all live inside a workspace"
        max-width="4xl"
    >
        <Form
            v-bind="WorkspaceController.store.form()"
            v-slot="{ errors, processing }"
        >
            <div
                class="grid gap-8 lg:grid-cols-[minmax(0,19rem)_minmax(0,1fr)]"
            >
                <div class="flex flex-col gap-5">
                    <div class="grid gap-2">
                        <Label for="name">Workspace name</Label>
                        <Input
                            id="name"
                            v-model="name"
                            name="name"
                            required
                            autofocus
                            maxlength="255"
                            autocomplete="organization"
                            placeholder="Acme Industries"
                            :aria-invalid="errors.name ? true : undefined"
                        />
                        <p class="text-xs text-muted-foreground">
                            Usually your company or team. You can rename it
                            later.
                        </p>
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label id="timezone-label">Time zone</Label>
                        <TimezonePicker
                            v-model="timezone"
                            labelledby="timezone-label"
                            :timezones="props.options.timezones"
                            :invalid="Boolean(errors.timezone)"
                        />
                        <input
                            type="hidden"
                            name="timezone"
                            :value="timezone"
                        />
                        <p class="text-xs text-muted-foreground">
                            <template
                                v-if="
                                    detected !== null && timezone === detected
                                "
                                >Preselected from this browser's
                                clock.</template
                            >
                            <template v-else
                                >The clock this workspace keeps.</template
                            >
                        </p>
                        <InputError :message="errors.timezone" />
                    </div>

                    <div class="mt-auto flex justify-end gap-2 pt-1">
                        <Button type="button" variant="ghost" @click="close"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="processing"
                            >Create workspace</Button
                        >
                    </div>
                </div>

                <!-- Decorative: every line of it is drawn again for real once the workspace exists,
                     and a screen reader that read it would be reading the future. -->
                <aside
                    class="hidden min-h-80 overflow-hidden rounded-xl border border-border lg:grid lg:grid-cols-[12.5rem_minmax(0,1fr)]"
                    aria-hidden="true"
                >
                    <div class="flex flex-col gap-3 bg-chrome p-2">
                        <div
                            class="flex h-11 items-center gap-2 rounded-md bg-chrome-accent px-2"
                        >
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-md bg-chrome-primary text-[13px] font-bold text-chrome-primary-foreground"
                            >
                                {{ previewName.charAt(0).toUpperCase() }}
                            </span>
                            <span class="grid min-w-0 flex-1 leading-tight">
                                <span
                                    class="truncate text-[13px] font-semibold text-chrome-foreground"
                                    >{{ previewName }}</span
                                >
                                <span
                                    class="truncate text-[11px] text-chrome-muted-foreground"
                                    >{{ previewSlug }}</span
                                >
                            </span>
                            <ChevronsUpDown
                                class="size-3.5 shrink-0 text-chrome-muted-foreground"
                            />
                        </div>

                        <div class="flex flex-col">
                            <span
                                v-for="(item, index) in navigation"
                                :key="item.label"
                                class="flex h-8 items-center gap-2.5 rounded-md px-2 text-[13px] font-medium"
                                :class="
                                    index === 0
                                        ? 'bg-chrome-accent text-chrome-foreground'
                                        : 'text-chrome-muted-foreground'
                                "
                            >
                                <component
                                    :is="item.icon"
                                    class="size-4 shrink-0"
                                />
                                {{ item.label }}
                            </span>
                        </div>

                        <div class="border-t border-chrome-border pt-3">
                            <p
                                class="px-2 text-[11px] font-semibold tracking-wide text-chrome-muted-foreground uppercase"
                            >
                                Projects
                            </p>
                            <p
                                class="mt-2 rounded-md border border-dashed border-chrome-border px-2 py-2.5 text-[12px] text-chrome-muted-foreground"
                            >
                                No projects yet
                            </p>
                        </div>

                        <div
                            v-if="user"
                            class="mt-auto flex items-center gap-2 border-t border-chrome-border px-1 pt-2"
                        >
                            <UserAvatar :user="user" size="sm" />
                            <span class="grid min-w-0 leading-tight">
                                <span
                                    class="truncate text-[12px] font-medium text-chrome-foreground"
                                    >{{ user.name }}</span
                                >
                                <span
                                    class="text-[11px] text-chrome-muted-foreground"
                                    >Owner</span
                                >
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-5 bg-muted/30 p-5">
                        <div class="space-y-2">
                            <span
                                class="block h-2.5 w-24 rounded-full bg-muted-foreground/20"
                            />
                            <span
                                class="block h-2 w-36 rounded-full bg-muted-foreground/15"
                            />
                        </div>

                        <div
                            class="rounded-lg border border-border bg-background px-4 py-3.5"
                        >
                            <p
                                class="text-3xl font-semibold tracking-tight tabular-nums"
                            >
                                {{ localTime }}
                            </p>
                            <p
                                class="mt-1 truncate text-xs text-muted-foreground"
                            >
                                {{ localDay }} · {{ zoneCity }}
                            </p>
                        </div>

                        <p class="mt-auto text-xs text-muted-foreground">
                            A new workspace starts empty, with you as its owner.
                            Invite people and start a project once it exists.
                        </p>
                    </div>
                </aside>
            </div>
        </Form>
    </ModalShell>
</template>

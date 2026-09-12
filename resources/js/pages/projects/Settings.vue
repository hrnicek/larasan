<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ArrowLeft,
    CalendarDays,
    CalendarRange,
    Check,
    Columns3,
    Eye,
    Kanban,
    List,
    ListChecks,
    Lock,
    Palette,
    TriangleAlert,
    Type,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ProjectFieldManager from '@/modules/custom-field/components/ProjectFieldManager.vue';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';
import ProjectIconGrid from '@/modules/project/components/ProjectIconGrid.vue';
import ProjectSettingsNav from '@/modules/project/components/ProjectSettingsNav.vue';
import ProjectSettingsSection from '@/modules/project/components/ProjectSettingsSection.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';
import type {
    ProjectAbilities,
    ProjectCustomFields,
    ProjectOptions,
    ProjectSettings,
    ProjectSettingsNavGroup,
} from '@/modules/project/types';
import { show } from '@/routes/projects';

const props = defineProps<{
    project: ProjectSettings;
    options: ProjectOptions;
    customFields: ProjectCustomFields;
    can: ProjectAbilities;
}>();

const color = ref<string>(props.project.color ?? '');
const icon = ref<string>(props.project.icon ?? '');
const visibility = ref<string>(props.project.visibility);
const defaultView = ref<string>(props.project.default_view);
const startDate = ref<string>(props.project.start_date ?? '');
const dueDate = ref<string>(props.project.due_date ?? '');
const confirmation = ref('');

const confirmed = computed(() => confirmation.value.trim() === props.project.name);

type Choice = { label: string; hint: string; icon: Component };

const visibilities: Record<string, Choice> = {
    workspace: {
        label: 'Everyone in the workspace',
        hint: 'Any member can find it, open it and work in it.',
        icon: Users,
    },
    private: {
        label: 'Only invited members',
        hint: 'It stays out of the sidebar and out of search for everybody else.',
        icon: Lock,
    },
};

const views: Record<string, Choice> = {
    list: { label: 'List', hint: 'Rows grouped by section', icon: List },
    board: { label: 'Board', hint: 'A column per section', icon: Kanban },
    calendar: { label: 'Calendar', hint: 'Tasks by due date', icon: CalendarDays },
};

function choice(known: Record<string, Choice>, option: string): Choice {
    return known[option] ?? { label: option, hint: '', icon: Eye };
}

const days = computed<number | null>(() => {
    if (!startDate.value || !dueDate.value) {
        return null;
    }

    return Math.round((Date.parse(dueDate.value) - Date.parse(startDate.value)) / 86_400_000);
});

// `<Form>` derives dirtiness from input events, which hidden fields written by a picker never fire.
const appearanceChanged = computed(
    () => color.value !== (props.project.color ?? '') || icon.value !== (props.project.icon ?? ''),
);

function hasChanges(formDirty: boolean): boolean {
    return formDirty || appearanceChanged.value;
}

const navGroups = computed<ProjectSettingsNavGroup[]>(() => [
    ...(props.can.update
        ? [
              {
                  label: 'Project',
                  items: [
                      { id: 'general', label: 'General', icon: Type },
                      { id: 'appearance', label: 'Appearance', icon: Palette },
                  ],
              },
              {
                  label: 'Behaviour',
                  items: [
                      { id: 'access', label: 'Access', icon: Users },
                      { id: 'views', label: 'Default view', icon: Columns3 },
                      { id: 'timeline', label: 'Timeline', icon: CalendarRange },
                  ],
              },
          ]
        : []),
    {
        label: 'Structure',
        items: [
            { id: 'fields', label: 'Fields', icon: ListChecks },
        ],
    },
    ...(props.can.archive
        ? [
              {
                  label: 'Danger zone',
                  items: [
                      {
                          id: 'danger',
                          label: props.project.archived ? 'Restore' : 'Archive',
                          icon: TriangleAlert,
                      },
                  ],
              },
          ]
        : []),
]);

// `<Form>` reset only restores DOM-owned inputs; the local drafts would otherwise be resubmitted via hidden inputs.
function discard(reset: () => void): void {
    reset();

    color.value = props.project.color ?? '';
    icon.value = props.project.icon ?? '';
    visibility.value = props.project.visibility;
    defaultView.value = props.project.default_view;
    startDate.value = props.project.start_date ?? '';
    dueDate.value = props.project.due_date ?? '';
}
</script>

<template>
    <div class="flex flex-col">
        <Head :title="`${props.project.name} settings`" />

        <header class="border-b border-border">
            <div class="flex flex-wrap items-center gap-3 px-4 py-4 md:px-6">
                <ProjectTile
                    :name="props.project.name"
                    :color="props.project.color"
                    :icon="props.project.icon"
                    size="lg"
                />

                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight">{{ props.project.name }}</h1>
                    <p class="mt-0.5 text-sm text-muted-foreground">Project settings</p>
                </div>

                <span v-if="props.project.archived" class="rounded-md bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                    Archived
                </span>

                <Link
                    :href="show(props.project.id).url"
                    component="projects/Show"
                    prefetch="click"
                    class="ml-auto inline-flex h-8 shrink-0 items-center gap-1.5 rounded-md border border-input px-2.5 text-[13px] font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                >
                    <ArrowLeft class="size-4" />
                    Back to project
                </Link>
            </div>
        </header>

        <div class="mx-auto w-full max-w-5xl px-4 py-8 md:px-6">
            <div class="flex flex-col gap-8 lg:flex-row lg:gap-12">
                <aside class="lg:w-44 lg:shrink-0">
                    <div class="lg:sticky lg:top-6">
                        <ProjectSettingsNav :groups="navGroups" />
                    </div>
                </aside>

                <div class="min-w-0 flex-1 space-y-10">
                    <Form
                        v-if="props.can.update"
                        v-bind="ProjectController.update.form(props.project.id)"
                        class="space-y-10"
                        set-defaults-on-success
                        v-slot="{ errors, processing, isDirty, reset }"
                    >
                        <input type="hidden" name="id" :value="props.project.id" />
                        <!-- `projects.update` treats an absent nullable field as cleared, so these must always be sent. -->
                        <input type="hidden" name="color" :value="color" />
                        <input type="hidden" name="icon" :value="icon" />

                        <section class="space-y-4">
                            <h2 class="px-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                Project
                            </h2>

                            <ProjectSettingsSection
                                id="general"
                                title="General"
                                description="What this project is called and what it is for"
                            >
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="name">Name</Label>
                                        <Input id="name" name="name" required :default-value="props.project.name" />
                                        <InputError :message="errors.name" />
                                        <InputError :message="errors.id" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="slug">Address</Label>
                                        <Input id="slug" name="slug" :default-value="props.project.slug" />
                                        <p class="text-xs text-muted-foreground">
                                            A short handle, unique in this workspace. Search matches it as well as
                                            the name.
                                        </p>
                                        <InputError :message="errors.slug" />
                                    </div>

                                    <div class="grid gap-2 sm:col-span-2">
                                        <Label for="description">Description</Label>
                                        <textarea
                                            id="description"
                                            name="description"
                                            rows="3"
                                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                                            :value="props.project.description ?? ''"
                                        />
                                        <p class="text-xs text-muted-foreground">
                                            Shown beside the project in a list of them, and searched along with its
                                            tasks.
                                        </p>
                                        <InputError :message="errors.description" />
                                    </div>
                                </div>
                            </ProjectSettingsSection>

                            <ProjectSettingsSection
                                id="appearance"
                                title="Appearance"
                                description="How this project is recognised in the sidebar and in a list of them"
                            >
                                <template #aside>
                                    <div class="flex items-center gap-2 rounded-lg border border-border px-3 py-2">
                                        <ProjectTile
                                            :name="props.project.name"
                                            :color="color || null"
                                            :icon="icon || null"
                                        />
                                        <span class="max-w-40 truncate text-sm font-medium">
                                            {{ props.project.name }}
                                        </span>
                                    </div>
                                </template>

                                <div class="grid gap-6 sm:grid-cols-[auto_1fr] sm:gap-10">
                                    <AccentColorGrid
                                        size="md"
                                        :model-value="color || null"
                                        @update:model-value="color = $event ?? ''"
                                    />

                                    <ProjectIconGrid
                                        size="md"
                                        :model-value="icon || null"
                                        @update:model-value="icon = $event ?? ''"
                                    />
                                </div>

                                <InputError class="mt-2" :message="errors.color" />
                                <InputError :message="errors.icon" />
                            </ProjectSettingsSection>
                        </section>

                        <section class="space-y-4">
                            <h2 class="px-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                                Behaviour
                            </h2>

                            <ProjectSettingsSection
                                id="access"
                                title="Access"
                                description="Who can reach this project without being invited to it"
                            >
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label
                                        v-for="option in props.options.visibilities"
                                        :key="option"
                                        class="group relative flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-accent/50 has-[:checked]:border-primary-ring has-[:checked]:bg-primary-subtle has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-ring"
                                    >
                                        <input
                                            v-model="visibility"
                                            type="radio"
                                            name="visibility"
                                            class="sr-only"
                                            :value="option"
                                        />

                                        <component
                                            :is="choice(visibilities, option).icon"
                                            class="mt-0.5 size-4 shrink-0 text-muted-foreground group-has-[:checked]:text-primary-subtle-foreground"
                                        />

                                        <span class="min-w-0">
                                            <span
                                                class="block text-sm font-medium group-has-[:checked]:text-primary-subtle-foreground"
                                            >
                                                {{ choice(visibilities, option).label }}
                                            </span>
                                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                                {{ choice(visibilities, option).hint }}
                                            </span>
                                        </span>

                                        <Check
                                            class="ml-auto size-4 shrink-0 text-primary-subtle-foreground opacity-0 group-has-[:checked]:opacity-100"
                                        />
                                    </label>
                                </div>

                                <InputError class="mt-2" :message="errors.visibility" />
                            </ProjectSettingsSection>

                            <ProjectSettingsSection
                                id="views"
                                title="Default view"
                                description="Which view opens when the project is reached without one named"
                            >
                                <div class="flex flex-wrap gap-3">
                                    <label
                                        v-for="option in props.options.views"
                                        :key="option"
                                        class="group relative flex min-w-40 flex-1 cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-accent/50 has-[:checked]:border-primary-ring has-[:checked]:bg-primary-subtle has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary-ring"
                                    >
                                        <input
                                            v-model="defaultView"
                                            type="radio"
                                            name="default_view"
                                            class="sr-only"
                                            :value="option"
                                        />

                                        <component
                                            :is="choice(views, option).icon"
                                            class="mt-0.5 size-4 shrink-0 text-muted-foreground group-has-[:checked]:text-primary-subtle-foreground"
                                        />

                                        <span class="min-w-0">
                                            <span
                                                class="block text-sm font-medium group-has-[:checked]:text-primary-subtle-foreground"
                                            >
                                                {{ choice(views, option).label }}
                                            </span>
                                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                                {{ choice(views, option).hint }}
                                            </span>
                                        </span>
                                    </label>
                                </div>

                                <InputError class="mt-2" :message="errors.default_view" />
                            </ProjectSettingsSection>

                            <ProjectSettingsSection
                                id="timeline"
                                title="Timeline"
                                description="When this project runs. Both dates are optional."
                            >
                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div class="grid gap-2">
                                        <Label for="start_date">Starts</Label>
                                        <Input id="start_date" v-model="startDate" name="start_date" type="date" />
                                        <InputError :message="errors.start_date" />
                                    </div>

                                    <div class="grid gap-2">
                                        <Label for="due_date">Due</Label>
                                        <Input id="due_date" v-model="dueDate" name="due_date" type="date" />
                                        <InputError :message="errors.due_date" />
                                    </div>
                                </div>

                                <p v-if="days !== null && days >= 0" class="mt-3 text-xs text-muted-foreground">
                                    {{
                                        days === 0
                                            ? 'Both dates fall on the same day.'
                                            : `${days} days from start to due.`
                                    }}
                                </p>
                                <p v-else-if="days !== null" class="mt-3 text-xs text-destructive">
                                    The due date falls before the start date.
                                </p>
                            </ProjectSettingsSection>
                        </section>

                        <div
                            v-if="hasChanges(isDirty)"
                            class="sticky bottom-4 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card/95 px-4 py-3 shadow-md backdrop-blur"
                        >
                            <p class="text-sm font-medium">Unsaved changes</p>

                            <div class="ml-auto flex items-center gap-2">
                                <Button type="button" variant="ghost" :disabled="processing" @click="discard(reset)">
                                    Discard
                                </Button>

                                <Button type="submit" :disabled="processing">Save changes</Button>
                            </div>
                        </div>
                    </Form>

                    <p v-else class="rounded-xl border border-border bg-card px-6 py-4 text-sm text-muted-foreground">
                        You can open this project but not change its settings.
                    </p>

                    <section class="space-y-4">
                        <h2 class="px-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Structure
                        </h2>

                        <ProjectSettingsSection
                            id="fields"
                            title="Fields"
                            description="What this project records about a task beyond its title and dates"
                        >
                            <ProjectFieldManager
                                :project-id="props.project.id"
                                :attached="props.customFields.attached"
                                :available="props.customFields.available"
                                :can-manage="props.can.manageFields"
                            />
                        </ProjectSettingsSection>
                    </section>

                    <section v-if="props.can.archive" class="space-y-4">
                        <h2 class="px-1 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Danger zone
                        </h2>

                        <ProjectSettingsSection
                            id="danger"
                            tone="danger"
                            :title="props.project.archived ? 'Restore project' : 'Archive project'"
                            :icon="props.project.archived ? ArchiveRestore : Archive"
                            :description="
                                props.project.archived
                                    ? 'Put this project back in the sidebar for everyone who can see it'
                                    : 'Hide this project from the sidebar and from lists. Nothing is deleted.'
                            "
                        >
                            <div class="flex flex-wrap items-center gap-4">
                                <p class="min-w-0 flex-1 text-sm text-muted-foreground">
                                    {{
                                        props.project.archived
                                            ? 'Its tasks, sections and members are exactly where they were left.'
                                            : 'Its tasks, sections and members stay as they are, and you can restore it here.'
                                    }}
                                </p>

                                <Form
                                    v-if="props.project.archived"
                                    v-bind="ProjectController.restore.form(props.project.id)"
                                    v-slot="{ processing }"
                                >
                                    <Button type="submit" variant="outline" :disabled="processing">
                                        <ArchiveRestore />
                                        Restore project
                                    </Button>
                                </Form>

                                <Dialog v-else>
                                    <DialogTrigger as-child>
                                        <Button variant="destructive">
                                            <Archive />
                                            Archive project
                                        </Button>
                                    </DialogTrigger>
                                    <DialogContent>
                                        <Form
                                            v-bind="ProjectController.archive.form(props.project.id)"
                                            class="space-y-6"
                                            v-slot="{ processing }"
                                        >
                                            <DialogHeader class="space-y-3">
                                                <DialogTitle>Archive {{ props.project.name }}?</DialogTitle>
                                                <DialogDescription>
                                                    Its tasks and members stay exactly as they are, and you can
                                                    restore it here. Type the project name to confirm.
                                                </DialogDescription>
                                            </DialogHeader>

                                            <div class="grid gap-2">
                                                <Label for="confirmation">Project name</Label>
                                                <Input
                                                    id="confirmation"
                                                    v-model="confirmation"
                                                    autocomplete="off"
                                                    :placeholder="props.project.name"
                                                />
                                            </div>

                                            <DialogFooter>
                                                <Button
                                                    type="submit"
                                                    variant="destructive"
                                                    :disabled="!confirmed || processing"
                                                >
                                                    Archive project
                                                </Button>
                                            </DialogFooter>
                                        </Form>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </ProjectSettingsSection>
                    </section>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import Heading from '@/components/Heading.vue';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { accentTextClass } from '@/lib/accentColor';
import type { ProjectAbilities, ProjectOptions, ProjectSettings } from '@/modules/project/types';
const props = defineProps<{
    project: ProjectSettings;
    options: ProjectOptions;
    can: ProjectAbilities;
}>();

const color = ref<string>(props.project.color ?? '');
const defaultView = ref<string>(props.project.default_view);
const visibility = ref<string>(props.project.visibility);
const confirmation = ref('');

/*
 * Typing the name is the confirmation step for a state change that removes the project
 * from everyone's sidebar. The server authorizes regardless of what this button does.
 */
const confirmed = computed(() => confirmation.value.trim() === props.project.name);

const visibilityLabels: Record<string, string> = {
    workspace: 'Everyone in the workspace',
    private: 'Only invited members',
};

const viewLabels: Record<string, string> = {
    list: 'List',
    board: 'Board',
};
</script>

<template>
    <div class="flex flex-col space-y-8">
        <Head :title="`${props.project.name} settings`" />

        <Heading
            variant="small"
            :title="props.project.name"
            description="Details, visibility and the view this project opens in"
        />

        <p v-if="!props.can.update" class="text-muted-foreground text-sm">
            You can open this project but not change its settings.
        </p>

        <Form
            v-else
            v-bind="ProjectController.update.form(props.project.id)"
            class="max-w-lg space-y-6"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="id" :value="props.project.id" />
            <input type="hidden" name="color" :value="color" />
            <input type="hidden" name="default_view" :value="defaultView" />
            <input type="hidden" name="visibility" :value="visibility" />

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" name="name" required :default-value="props.project.name" />
                <InputError :message="errors.name" />
                <InputError :message="errors.id" />
            </div>

            <div class="grid gap-2">
                <Label for="slug">Address</Label>
                <Input id="slug" name="slug" :default-value="props.project.slug" />
                <InputError :message="errors.slug" />
            </div>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <textarea
                    id="description"
                    name="description"
                    rows="3"
                    class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-1 focus-visible:outline-none"
                    :value="props.project.description ?? ''"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="color-trigger">Colour</Label>
                <div class="flex items-center gap-2">
                    <Select v-model="color">
                        <SelectTrigger id="color-trigger" class="w-56">
                            <SelectValue placeholder="No colour" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="option in props.options.colors" :key="option" :value="option">
                                <span :class="accentTextClass(option)">●</span>
                                {{ option }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <!-- A select cannot offer an empty option, so clearing gets its own control. -->
                    <Button v-if="color" type="button" variant="ghost" @click="color = ''">Clear</Button>
                </div>
                <InputError :message="errors.color" />
            </div>

            <div class="grid gap-2">
                <Label for="visibility-trigger">Visibility</Label>
                <Select v-model="visibility">
                    <SelectTrigger id="visibility-trigger" class="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="option in props.options.visibilities"
                            :key="option"
                            :value="option"
                        >
                            {{ visibilityLabels[option] ?? option }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.visibility" />
            </div>

            <div class="grid gap-2">
                <Label for="view-trigger">Opens in</Label>
                <Select v-model="defaultView">
                    <SelectTrigger id="view-trigger" class="w-56">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in props.options.views" :key="option" :value="option">
                            {{ viewLabels[option] ?? option }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="errors.default_view" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="start_date">Starts</Label>
                    <Input
                        id="start_date"
                        name="start_date"
                        type="date"
                        :default-value="props.project.start_date ?? ''"
                    />
                    <InputError :message="errors.start_date" />
                </div>

                <div class="grid gap-2">
                    <Label for="due_date">Due</Label>
                    <Input
                        id="due_date"
                        name="due_date"
                        type="date"
                        :default-value="props.project.due_date ?? ''"
                    />
                    <InputError :message="errors.due_date" />
                </div>
            </div>

            <Button type="submit" :disabled="processing">Save changes</Button>
        </Form>

        <section v-if="props.can.archive" class="space-y-4">
            <Heading
                variant="small"
                :title="props.project.archived ? 'Restore project' : 'Archive project'"
                :description="
                    props.project.archived
                        ? 'Put this project back in the sidebar for everyone who can see it'
                        : 'Hide this project from the sidebar and lists. Nothing is deleted.'
                "
            />

            <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <Form
                    v-if="props.project.archived"
                    v-bind="ProjectController.restore.form(props.project.id)"
                    v-slot="{ processing }"
                >
                    <Button type="submit" variant="outline" :disabled="processing">Restore project</Button>
                </Form>

                <Dialog v-else>
                    <DialogTrigger as-child>
                        <Button variant="destructive">Archive project</Button>
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
                                    Its tasks and members stay exactly as they are, and you can restore
                                    it here. Type the project name to confirm.
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
                                <Button type="submit" variant="destructive" :disabled="!confirmed || processing">
                                    Archive project
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </section>
    </div>
</template>

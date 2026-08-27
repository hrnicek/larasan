<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import ProjectTaskController from '@/actions/App/Http/Controllers/Project/ProjectTaskController';
import InputError from '@/components/InputError.vue';
import ModalShell from '@/components/ModalShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { accentDotClass, accentVars } from '@/lib/accentColor';

/**
 * Adding a task from somewhere that is not a row in a list.
 *
 * The three entry points differ only in how much they already know: the topbar knows nothing, a
 * project's *Add task* knows the project, and a section's `+` knows both. What they know is
 * **prefilled and still editable** — a hidden prefill is a field somebody fights when it turns
 * out to be wrong.
 */
const props = defineProps<{
    /** The projects this person may add work to. Not the sidebar's `projects` — see the controller. */
    targetProjects: { id: string; name: string; color: string | null }[];
    project: string | null;
    sections: { id: string; name: string }[];
    section: string | null;
}>();

const chosenProject = ref<string | null>(props.project);
const chosenSection = ref<string | null>(props.section);

const form = useForm<{ title: string; section: string | null }>({
    title: '',
    section: props.section,
});

const selected = computed(() => props.targetProjects.find((project) => project.id === chosenProject.value) ?? null);

/*
 * The sections belong to the chosen project, so choosing a different one asks the server again
 * rather than the client guessing. Partial, because nothing else on this modal changes.
 */
watch(chosenProject, (id) => {
    chosenSection.value = null;
    form.section = null;

    if (id === null) {
        return;
    }

    router.reload({ only: ['sections', 'project'], data: { project: id } });
});

watch(chosenSection, (id) => (form.section = id));

function submit(): void {
    if (chosenProject.value === null) {
        return;
    }

    form.post(ProjectTaskController.store.url(chosenProject.value), { preserveScroll: true });
}
</script>

<template>
    <ModalShell title="Add a task" description="Where it goes decides who sees it" v-slot="{ close }">
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="task-title">Task name</Label>
                <Input id="task-title" v-model="form.title" required autofocus placeholder="Draft the new home page" />
                <InputError :message="form.errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="task-project">Project</Label>

                <Select v-model="chosenProject">
                    <SelectTrigger id="task-project" class="w-full">
                        <SelectValue placeholder="Choose a project" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in targetProjects" :key="option.id" :value="option.id">
                            <span class="flex items-center gap-2">
                                <span class="size-2.5 shrink-0 rounded-[3px]" :class="accentDotClass(option.color)" :style="accentVars(option.color)" />
                                {{ option.name }}
                            </span>
                        </SelectItem>
                    </SelectContent>
                </Select>

                <!-- Required, and said so before the form is submitted rather than after. A task
                     with no project is reachable only from My Tasks and from search, which is a
                     thing this application allows but not a thing to do by accident. -->
                <p v-if="targetProjects.length === 0" class="text-sm text-muted-foreground">
                    There is no project here you can add to yet.
                </p>
            </div>

            <div v-if="selected && sections.length > 0" class="grid gap-2">
                <Label for="task-section">Section</Label>
                <Select v-model="chosenSection">
                    <SelectTrigger id="task-section" class="w-full">
                        <SelectValue placeholder="No section" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in sections" :key="option.id" :value="option.id">
                            {{ option.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.section" />
            </div>

            <div class="flex justify-end gap-2 pt-1">
                <Button type="button" variant="ghost" @click="close">Cancel</Button>
                <Button type="submit" :disabled="form.processing || chosenProject === null">Add task</Button>
            </div>
        </form>
    </ModalShell>
</template>

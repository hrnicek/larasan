<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { Check, Lock, Plus, Users } from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import ProjectController from '@/actions/App/Http/Controllers/Project/ProjectController';
import InputError from '@/components/InputError.vue';
import ModalShell from '@/components/ModalShell.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AccentColorGrid from '@/modules/project/components/AccentColorGrid.vue';
import ProjectIconGrid from '@/modules/project/components/ProjectIconGrid.vue';
import ProjectTile from '@/modules/project/components/ProjectTile.vue';

const props = defineProps<{
    options: { visibilities: string[] };
}>();

const workspace = computed(() => usePage().props.workspace);

const name = ref('');
const color = ref<string | null>(null);
const icon = ref<string | null>(null);
const visibility = ref(props.options.visibilities[0] ?? 'workspace');

const previewName = computed(() => name.value.trim() || 'Untitled project');

type Choice = { label: string; hint: string; icon: Component };

const visibilities = computed<Record<string, Choice>>(() => ({
    workspace: {
        label: workspace.value?.name ?? 'Everyone in the workspace',
        hint: 'Everyone in your workspace can find and open this project.',
        icon: Users,
    },
    private: {
        label: 'Private',
        hint: 'Only invited members can find it. Until you invite somebody, that is you.',
        icon: Lock,
    },
}));

function choice(option: string): Choice {
    return (
        visibilities.value[option] ?? { label: option, hint: '', icon: Users }
    );
}
</script>

<template>
    <ModalShell
        v-slot="{ close }"
        title="New project"
        description="Tasks, sections and members live inside a project"
        max-width="4xl"
    >
        <Form
            v-bind="ProjectController.store.form()"
            v-slot="{ errors, processing }"
        >
            <div
                class="grid gap-8 lg:grid-cols-[minmax(0,19rem)_minmax(0,1fr)]"
            >
                <div class="space-y-5">
                    <div class="grid gap-2">
                        <Label for="name">Project name</Label>
                        <Input
                            id="name"
                            v-model="name"
                            name="name"
                            required
                            autofocus
                            placeholder="Web redesign"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Project access</Label>

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
                                :is="choice(option).icon"
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground group-has-[:checked]:text-primary-subtle-foreground"
                            />

                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-medium group-has-[:checked]:text-primary-subtle-foreground"
                                >
                                    {{ choice(option).label }}
                                </span>
                                <span
                                    class="mt-0.5 block text-xs text-muted-foreground"
                                    >{{ choice(option).hint }}</span
                                >
                            </span>

                            <Check
                                class="ml-auto size-4 shrink-0 text-primary-subtle-foreground opacity-0 group-has-[:checked]:opacity-100"
                            />
                        </label>

                        <InputError :message="errors.visibility" />
                    </div>

                    <div class="grid gap-4 rounded-lg border border-border p-3">
                        <AccentColorGrid v-model="color" />
                        <ProjectIconGrid v-model="icon" />
                    </div>

                    <input type="hidden" name="color" :value="color ?? ''" />
                    <input type="hidden" name="icon" :value="icon ?? ''" />

                    <InputError :message="errors.color" />
                    <InputError :message="errors.icon" />

                    <div class="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="ghost" @click="close"
                            >Cancel</Button
                        >
                        <Button type="submit" :disabled="processing"
                            >Create project</Button
                        >
                    </div>
                </div>

                <aside
                    class="hidden overflow-hidden rounded-xl border border-border bg-muted/30 lg:block"
                    aria-hidden="true"
                >
                    <div
                        class="flex items-center gap-3 border-b border-border bg-background/60 px-5 py-4"
                    >
                        <ProjectTile
                            :name="previewName"
                            :color="color"
                            :icon="icon"
                            size="lg"
                        />

                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-semibold tracking-tight"
                            >
                                {{ previewName }}
                            </p>

                            <div class="mt-2 flex gap-2">
                                <span
                                    v-for="width in [
                                        'w-10',
                                        'w-12',
                                        'w-9',
                                        'w-14',
                                    ]"
                                    :key="width"
                                    class="h-2 rounded-full bg-muted-foreground/20"
                                    :class="width"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="space-y-3 p-5">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-semibold tracking-tight"
                                >Untitled section</span
                            >
                            <span class="text-xs text-muted-foreground">0</span>
                        </div>

                        <div
                            class="flex items-center gap-2 rounded-lg border border-dashed border-border px-3 py-2.5 text-sm text-muted-foreground"
                        >
                            <Plus class="size-4" />
                            Add task
                        </div>

                        <p class="pt-1 text-xs text-muted-foreground">
                            A new project opens with one section. Rename it, or
                            add more, once there is something to put in them.
                        </p>
                    </div>
                </aside>
            </div>
        </Form>
    </ModalShell>
</template>

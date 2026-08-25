<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ChevronDown, ChevronUp, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import SectionController from '@/actions/App/Http/Controllers/Section/SectionController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { accentTextClass } from '@/lib/accentColor';
import type { ProjectSection } from '@/modules/project/types';

const props = defineProps<{
    projectId: string;
    sections: ProjectSection[];
    can: { update: boolean; createSection: boolean };
}>();

const renaming = ref<string | null>(null);

/**
 * A move is expressed as "place this after that one" — the server never takes a position
 * (ADR-0009) — so moving up means following the section two places above, and the first
 * position is `null`.
 */
function anchorAbove(index: number): string | null {
    return index >= 2 ? props.sections[index - 2].id : null;
}

function anchorBelow(index: number): string | null {
    return props.sections[index + 1]?.id ?? null;
}

const hasSections = computed(() => props.sections.length > 0);
</script>

<template>
    <div class="space-y-4">
        <ul v-if="hasSections" class="divide-y rounded-lg border">
            <li v-for="(section, index) in props.sections" :key="section.id" class="flex items-center gap-2 px-3 py-1.5">
                <span :class="accentTextClass(section.color)" aria-hidden="true">●</span>

                <Form
                    v-if="renaming === section.id"
                    v-bind="SectionController.update.form(section.id)"
                    class="flex flex-1 items-center gap-2"
                    @success="renaming = null"
                    v-slot="{ errors, processing }"
                >
                    <div class="flex-1">
                        <Label :for="`name-${section.id}`" class="sr-only">Section name</Label>
                        <Input :id="`name-${section.id}`" name="name" :default-value="section.name" autofocus />
                        <InputError :message="errors.name" />
                    </div>
                    <input type="hidden" name="color" :value="section.color ?? ''" />
                    <Button type="submit" size="sm" :disabled="processing">Save</Button>
                    <Button type="button" size="sm" variant="ghost" @click="renaming = null">Cancel</Button>
                </Form>

                <template v-else>
                    <span class="flex-1 text-sm">{{ section.name }}</span>

                    <!-- The controls sit as one dense cluster rather than spread across the row:
                         four evenly spaced buttons read as four unrelated choices. -->
                    <div v-if="props.can.update" class="flex items-center gap-0.5 text-muted-foreground">
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            :aria-label="`Rename ${section.name}`"
                            @click="renaming = section.id"
                        >
                            <Pencil />
                        </Button>

                        <Form v-bind="SectionController.move.form(section.id)" v-slot="{ processing }">
                            <input type="hidden" name="after" :value="anchorAbove(index) ?? ''" />
                            <Button
                                type="submit"
                                size="icon-sm"
                                variant="ghost"
                                :disabled="index === 0 || processing"
                                aria-label="Move up"
                            >
                                <ChevronUp />
                            </Button>
                        </Form>

                        <Form v-bind="SectionController.move.form(section.id)" v-slot="{ processing }">
                            <input type="hidden" name="after" :value="anchorBelow(index) ?? ''" />
                            <Button
                                type="submit"
                                size="icon-sm"
                                variant="ghost"
                                :disabled="index === props.sections.length - 1 || processing"
                                aria-label="Move down"
                            >
                                <ChevronDown />
                            </Button>
                        </Form>

                        <Form v-bind="SectionController.destroy.form(section.id)" v-slot="{ processing }">
                            <Button
                                type="submit"
                                size="icon-sm"
                                variant="ghost"
                                class="hover:text-destructive"
                                :disabled="processing"
                                :aria-label="`Delete ${section.name}`"
                            >
                                <Trash2 />
                            </Button>
                        </Form>
                    </div>
                </template>
            </li>
        </ul>

        <p v-else class="text-muted-foreground rounded-lg border border-dashed px-3 py-6 text-center text-sm">
            No sections yet. Tasks in this project sit in one ungrouped list until you add one.
        </p>

        <Form
            v-if="props.can.createSection"
            v-bind="SectionController.store.form(props.projectId)"
            class="flex items-start gap-2"
            reset-on-success
            v-slot="{ errors, processing }"
        >
            <!-- `relative` because the label is `sr-only`, which is `position: absolute`: with no
                 positioned ancestor it is laid out against the document at its static position,
                 which grows the page past the shell and scrolls the application out of view. -->
            <div class="relative flex-1">
                <Label for="new-section" class="sr-only">Add a section</Label>
                <Input id="new-section" name="name" placeholder="Add a section — In review" required />
                <InputError :message="errors.name" />
            </div>
            <Button type="submit" variant="outline" :disabled="processing">Add section</Button>
        </Form>
    </div>
</template>

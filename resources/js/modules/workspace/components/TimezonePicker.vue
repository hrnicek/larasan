<script setup lang="ts">
import { Check, ChevronsUpDown, Globe } from '@lucide/vue';
import { computed, nextTick, ref, useId, watch } from 'vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

// Zones come from the server's `DateTimeZone::listIdentifiers()`, so every option passes the `timezone` rule.
const props = defineProps<{
    timezones: string[];
    /** reka-ui sets its own `id` on the trigger, so a `<label for>` cannot target it. */
    labelledby?: string;
    invalid?: boolean;
}>();

const valueId = useId();

const model = defineModel<string>({ required: true });

type Zone = {
    id: string;
    city: string;
    region: string;
    offset: string;
    haystack: string;
};

const open = ref(false);
const opened = ref(false);
const query = ref('');
const highlighted = ref(0);
const input = ref<HTMLInputElement | null>(null);
const list = ref<HTMLUListElement | null>(null);
const listId = useId();

const flatten = (value: string): string =>
    value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();

function offsetOf(zone: string): string {
    try {
        const part = new Intl.DateTimeFormat('en-US', {
            timeZone: zone,
            timeZoneName: 'shortOffset',
        })
            .formatToParts(new Date())
            .find((token) => token.type === 'timeZoneName');

        return part?.value.replace('GMT', 'UTC') ?? '';
    } catch {
        return '';
    }
}

function describe(zone: string): Zone {
    const segments = zone.split('/');
    const city = (segments.pop() ?? zone).replaceAll('_', ' ');
    const region = segments.join(' / ').replaceAll('_', ' ');
    const offset = offsetOf(zone);

    return {
        id: zone,
        city,
        region,
        offset,
        haystack: flatten(`${zone} ${city} ${offset}`),
    };
}

// Built on first open rather than on mount, since it creates a formatter per zone.
const zones = computed<Zone[]>(() =>
    opened.value ? props.timezones.map(describe) : [],
);

const selected = computed<Zone>(() => describe(model.value));

const matches = computed<Zone[]>(() => {
    const term = flatten(query.value.trim());

    return term === ''
        ? zones.value
        : zones.value.filter((zone) => zone.haystack.includes(term));
});

watch(matches, () => (highlighted.value = 0));

watch(open, (isOpen) => {
    if (!isOpen) {
        query.value = '';

        return;
    }

    opened.value = true;

    void nextTick(() => {
        highlighted.value = Math.max(
            matches.value.findIndex((zone) => zone.id === model.value),
            0,
        );
        reveal();
        input.value?.focus();
    });
});

function reveal(): void {
    list.value?.children[highlighted.value]?.scrollIntoView({
        block: 'nearest',
    });
}

function choose(zone: Zone): void {
    model.value = zone.id;
    open.value = false;
}

function move(step: number): void {
    const count = Math.max(matches.value.length, 1);

    highlighted.value = (highlighted.value + step + count) % count;
    void nextTick(reveal);
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        move(event.key === 'ArrowDown' ? 1 : -1);

        return;
    }

    if (event.key === 'Enter') {
        event.preventDefault();

        const chosen = matches.value[highlighted.value];

        if (chosen !== undefined) {
            choose(chosen);
        }
    }
}
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger
            type="button"
            :aria-labelledby="
                labelledby ? `${labelledby} ${valueId}` : undefined
            "
            :aria-invalid="invalid || undefined"
            class="flex h-9 w-full min-w-0 items-center gap-2 rounded-md border border-input bg-transparent px-3 text-left text-sm shadow-xs transition-[color,box-shadow] outline-none hover:bg-accent/40 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 data-[state=open]:border-ring dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
        >
            <Globe class="size-4 shrink-0 text-muted-foreground" />
            <span :id="valueId" class="min-w-0 flex-1 truncate">
                {{ selected.city }}
                <span v-if="selected.region" class="text-muted-foreground"
                    >· {{ selected.region }}</span
                >
            </span>
            <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{
                selected.offset
            }}</span>
            <ChevronsUpDown class="size-3.5 shrink-0 text-muted-foreground" />
        </PopoverTrigger>

        <!-- Inside a native <dialog>, Escape would also cancel the dialog, so it closes only the list. -->
        <PopoverContent
            align="start"
            class="w-(--reka-popover-trigger-width) min-w-72 p-0"
            @escape-key-down="
                (event: KeyboardEvent) => {
                    event.preventDefault();
                    open = false;
                }
            "
        >
            <div class="border-b border-border p-2">
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    :aria-controls="listId"
                    aria-label="Search time zones by city, region or offset"
                    placeholder="City, region or UTC+2"
                    class="h-8 w-full rounded-md bg-transparent px-2 text-sm outline-none placeholder:text-muted-foreground"
                    @keydown="onKeydown"
                />
            </div>

            <ul
                :id="listId"
                ref="list"
                role="listbox"
                class="max-h-64 overflow-y-auto p-1"
            >
                <li
                    v-for="(zone, index) in matches"
                    :key="zone.id"
                    role="option"
                    :aria-selected="zone.id === model"
                    class="relative"
                >
                    <button
                        type="button"
                        class="flex w-full items-center gap-2 rounded-md py-1.5 pr-2 pl-3 text-left transition-colors"
                        :class="
                            index === highlighted
                                ? 'bg-accent'
                                : 'hover:bg-accent/60'
                        "
                        @click="choose(zone)"
                        @mousemove="highlighted = index"
                    >
                        <span
                            v-if="index === highlighted"
                            class="absolute inset-y-1 left-0 w-0.5 rounded-full bg-primary"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1 truncate text-sm">
                            {{ zone.city }}
                            <span
                                v-if="zone.region"
                                class="text-xs text-muted-foreground"
                                >{{ zone.region }}</span
                            >
                        </span>
                        <span
                            class="shrink-0 text-xs text-muted-foreground tabular-nums"
                            >{{ zone.offset }}</span
                        >
                        <Check
                            class="size-4 shrink-0 text-primary"
                            :class="
                                zone.id === model ? 'opacity-100' : 'opacity-0'
                            "
                        />
                    </button>
                </li>

                <li
                    v-if="matches.length === 0"
                    class="px-3 py-6 text-center text-sm text-muted-foreground"
                >
                    No time zone matches “{{ query }}”.
                </li>
            </ul>
        </PopoverContent>
    </Popover>
</template>

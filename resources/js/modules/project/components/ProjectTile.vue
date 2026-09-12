<script setup lang="ts">
import { computed } from 'vue';
import { accentContentTileClass, accentTileClass, accentVars } from '@/lib/accentColor';
import { projectIconComponent } from '@/lib/projectIcon';

const props = withDefaults(
    defineProps<{
        name: string;
        color: string | null;
        icon: string | null;
        size?: 'sm' | 'md' | 'lg';
        /** `chrome` is the sidebar, which stays dark in both themes. */
        surface?: 'content' | 'chrome';
    }>(),
    { size: 'md', surface: 'content' },
);

const sizes = {
    sm: { box: 'size-6 rounded-md text-[11px] font-semibold', glyph: 'size-3.5' },
    md: { box: 'size-7 rounded-md text-xs font-bold', glyph: 'size-4' },
    lg: { box: 'size-9 rounded-lg text-sm font-bold', glyph: 'size-[18px]' },
} as const;

const glyph = computed(() => projectIconComponent(props.icon));

const tint = computed(() =>
    props.surface === 'chrome' ? accentTileClass(props.color) : accentContentTileClass(props.color),
);

const tintVars = computed(() => accentVars(props.color));
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center"
        :class="[sizes[props.size].box, tint]"
        :style="tintVars"
        aria-hidden="true"
    >
        <component :is="glyph" v-if="glyph" :class="sizes[props.size].glyph" />
        <template v-else>{{ props.name.charAt(0).toUpperCase() }}</template>
    </span>
</template>

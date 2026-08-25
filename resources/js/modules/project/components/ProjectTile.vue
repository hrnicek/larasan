<script setup lang="ts">
import { computed } from 'vue';
import { accentContentTileClass, accentTileClass } from '@/lib/accentColor';
import { projectIconComponent } from '@/lib/projectIcon';

/**
 * A project, as a tinted square: its icon, or the first letter of its name when it has none.
 *
 * One component because four places draw it — the project header, the sidebar's collapsed rail,
 * the project list and the appearance picker's own trigger — and an icon that appeared in three
 * of them would read as three different projects.
 */
const props = withDefaults(
    defineProps<{
        name: string;
        color: string | null;
        icon: string | null;
        size?: 'sm' | 'md' | 'lg';
        /**
         * Which surface this is drawn on. The sidebar rail stays dark in both themes and needs
         * the light-on-tint pair; a page needs the pair that follows the theme.
         */
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
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center"
        :class="[sizes[props.size].box, tint]"
        aria-hidden="true"
    >
        <component :is="glyph" v-if="glyph" :class="sizes[props.size].glyph" />
        <template v-else>{{ props.name.charAt(0).toUpperCase() }}</template>
    </span>
</template>

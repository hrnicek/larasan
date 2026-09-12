<script setup lang="ts">
import {
    Bold,
    Check,
    Code,
    Columns3,
    Italic,
    Link2,
    Link2Off,
    Rows3,
    Strikethrough,
    Trash2,
    Underline as UnderlineIcon,
    X,
} from '@lucide/vue';
import type { Editor } from '@tiptap/vue-3';
import type { Component } from 'vue';
import { computed, nextTick, ref, watch } from 'vue';

// Buttons prevent the default on `mousedown`, so taking focus does not clear the editor's selection.
const props = defineProps<{
    editor: Editor;
    /** Viewport coordinates. */
    anchor: { top: number; left: number } | null;
    mode: 'marks' | 'code' | 'table';
}>();

const linking = ref(false);
const href = ref('');
const hrefField = ref<HTMLInputElement | null>(null);

type Mark = { key: string; label: string; icon: Component; run: () => void; active: () => boolean };

const marks = computed<Mark[]>(() => [
    {
        key: 'bold',
        label: 'Bold',
        icon: Bold,
        run: () => props.editor.chain().focus().toggleBold().run(),
        active: () => props.editor.isActive('bold'),
    },
    {
        key: 'italic',
        label: 'Italic',
        icon: Italic,
        run: () => props.editor.chain().focus().toggleItalic().run(),
        active: () => props.editor.isActive('italic'),
    },
    {
        key: 'underline',
        label: 'Underline',
        icon: UnderlineIcon,
        run: () => props.editor.chain().focus().toggleUnderline().run(),
        active: () => props.editor.isActive('underline'),
    },
    {
        key: 'strike',
        label: 'Strikethrough',
        icon: Strikethrough,
        run: () => props.editor.chain().focus().toggleStrike().run(),
        active: () => props.editor.isActive('strike'),
    },
    {
        key: 'code',
        label: 'Code',
        icon: Code,
        run: () => props.editor.chain().focus().toggleCode().run(),
        active: () => props.editor.isActive('code'),
    },
]);

// The server accepts any short language token, so a language is added here only.
const languages: { value: string; label: string }[] = [
    { value: '', label: 'Plain text' },
    { value: 'bash', label: 'Shell' },
    { value: 'css', label: 'CSS' },
    { value: 'html', label: 'HTML' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'json', label: 'JSON' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'php', label: 'PHP' },
    { value: 'sql', label: 'SQL' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'vue', label: 'Vue' },
    { value: 'yaml', label: 'YAML' },
];

const language = computed<string>(() => (props.editor.getAttributes('codeBlock').language as string) ?? '');

const setLanguage = (value: string): void => {
    props.editor.chain().focus().updateAttributes('codeBlock', { language: value === '' ? null : value }).run();
};

const tableActions: { key: string; label: string; icon: Component; run: () => void; destructive?: boolean }[] = [
    { key: 'row', label: 'Add row below', icon: Rows3, run: () => props.editor.chain().focus().addRowAfter().run() },
    {
        key: 'column',
        label: 'Add column to the right',
        icon: Columns3,
        run: () => props.editor.chain().focus().addColumnAfter().run(),
    },
    {
        key: 'header',
        label: 'Toggle header row',
        icon: Check,
        run: () => props.editor.chain().focus().toggleHeaderRow().run(),
    },
    {
        key: 'delete-row',
        label: 'Delete row',
        icon: Rows3,
        run: () => props.editor.chain().focus().deleteRow().run(),
        destructive: true,
    },
    {
        key: 'delete-column',
        label: 'Delete column',
        icon: Columns3,
        run: () => props.editor.chain().focus().deleteColumn().run(),
        destructive: true,
    },
    {
        key: 'delete-table',
        label: 'Delete table',
        icon: Trash2,
        run: () => props.editor.chain().focus().deleteTable().run(),
        destructive: true,
    },
];

const openLink = async (): Promise<void> => {
    href.value = (props.editor.getAttributes('link').href as string) ?? '';
    linking.value = true;

    await nextTick();
    hrefField.value?.focus();
};

// Mirrors the schemes the server keeps, so a refused link is not silently dropped on save.
const allowed = (candidate: string): boolean => {
    const trimmed = candidate.trim();
    const scheme = /^([a-zA-Z][a-zA-Z0-9+.-]*):/.exec(trimmed)?.[1]?.toLowerCase();

    return trimmed !== '' && (scheme === undefined || ['http', 'https', 'mailto'].includes(scheme));
};

const applyLink = (): void => {
    const candidate = href.value.trim();

    if (!allowed(candidate)) {
        return;
    }

    props.editor.chain().focus().extendMarkRange('link').setLink({ href: candidate }).run();
    linking.value = false;
};

const removeLink = (): void => {
    props.editor.chain().focus().extendMarkRange('link').unsetLink().run();
    linking.value = false;
};

watch(
    () => [props.anchor, props.mode],
    () => {
        if (props.anchor === null) {
            linking.value = false;
        }
    },
);
</script>

<template>
    <div
        v-if="anchor"
        class="fixed z-50 flex items-center gap-0.5 rounded-md border border-border bg-popover p-1 shadow-md"
        :style="{ top: `${anchor.top}px`, left: `${anchor.left}px` }"
        role="toolbar"
        aria-label="Formatting"
    >
        <template v-if="mode === 'marks'">
            <template v-if="!linking">
                <button
                    v-for="mark in marks"
                    :key="mark.key"
                    type="button"
                    class="grid size-7 place-items-center rounded transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :class="mark.active() ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    :aria-label="mark.label"
                    :aria-pressed="mark.active()"
                    @mousedown.prevent
                    @click="mark.run()"
                >
                    <component :is="mark.icon" class="size-4" />
                </button>

                <span class="mx-0.5 h-5 w-px bg-border" aria-hidden="true" />

                <button
                    type="button"
                    class="grid size-7 place-items-center rounded transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    :class="editor.isActive('link') ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground'"
                    :aria-label="editor.isActive('link') ? 'Edit link' : 'Add link'"
                    @mousedown.prevent
                    @click="openLink()"
                >
                    <Link2 class="size-4" />
                </button>
            </template>

            <template v-else>
                <input
                    ref="hrefField"
                    v-model="href"
                    type="url"
                    inputmode="url"
                    placeholder="https://example.com"
                    aria-label="Link address"
                    class="h-7 w-64 rounded border border-input bg-background px-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    @keydown.enter.prevent="applyLink()"
                    @keydown.esc.prevent="linking = false"
                />

                <button
                    type="button"
                    class="grid size-7 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground disabled:opacity-40 focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    aria-label="Apply link"
                    :disabled="!allowed(href)"
                    @mousedown.prevent
                    @click="applyLink()"
                >
                    <Check class="size-4" />
                </button>

                <button
                    v-if="editor.isActive('link')"
                    type="button"
                    class="grid size-7 place-items-center rounded text-muted-foreground transition-colors hover:text-destructive focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    aria-label="Remove link"
                    @mousedown.prevent
                    @click="removeLink()"
                >
                    <Link2Off class="size-4" />
                </button>

                <button
                    type="button"
                    class="grid size-7 place-items-center rounded text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                    aria-label="Cancel"
                    @mousedown.prevent
                    @click="linking = false"
                >
                    <X class="size-4" />
                </button>
            </template>
        </template>

        <template v-else-if="mode === 'code'">
            <label class="sr-only" for="page-code-language">Code language</label>

            <select
                id="page-code-language"
                class="h-7 rounded border border-input bg-background px-2 text-sm text-foreground focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :value="language"
                @change="setLanguage(($event.target as HTMLSelectElement).value)"
            >
                <option v-for="option in languages" :key="option.value" :value="option.value">
                    {{ option.label }}
                </option>
            </select>
        </template>

        <template v-else>
            <button
                v-for="action in tableActions"
                :key="action.key"
                type="button"
                class="grid size-7 place-items-center rounded text-muted-foreground transition-colors focus-visible:ring-2 focus-visible:ring-primary-ring focus-visible:outline-none"
                :class="action.destructive ? 'hover:text-destructive' : 'hover:text-foreground'"
                :aria-label="action.label"
                :title="action.label"
                @mousedown.prevent
                @click="action.run()"
            >
                <component :is="action.icon" class="size-4" />
            </button>
        </template>
    </div>
</template>

<script setup lang="ts">
import Suggestion from '@tiptap/suggestion';
import { Editor, EditorContent, Extension } from '@tiptap/vue-3';
import type { Range } from '@tiptap/vue-3';
import { onBeforeUnmount, ref, shallowRef, toRaw, watch } from 'vue';
import PageSelectionToolbar from '@/modules/page/components/PageSelectionToolbar.vue';
import PageSlashMenu from '@/modules/page/components/PageSlashMenu.vue';
import type { SlashCommand } from '@/modules/page/components/PageSlashMenu.vue';
import { pageExtensions } from '@/modules/page/lib/extensions';
import { matchingCommands, slashCommands } from '@/modules/page/lib/slashCommands';
import type { PageDocument } from '@/modules/page/types';
import '../../../../css/page-editor.css';

// The document is exchanged as JSON, never markup. See ADR-0017.
const props = withDefaults(
    defineProps<{
        modelValue: PageDocument;
        editable?: boolean;
        placeholder?: string;
    }>(),
    { editable: true, placeholder: 'Write, or press / for blocks' },
);

const emit = defineEmits<{ 'update:modelValue': [document: PageDocument] }>();

const toolbar = ref<{ mode: 'marks' | 'code' | 'table'; anchor: { top: number; left: number } } | null>(null);

const menu = ref<{
    commands: SlashCommand[];
    selected: number;
    position: { top: number; left: number };
    range: Range;
} | null>(null);

const editor = shallowRef<Editor>();

let emitted: PageDocument | null = null;

const insert = (command: SlashCommand, over?: Range): void => {
    const chosen = slashCommands.find((candidate) => candidate.key === command.key);
    const range = over ?? menu.value?.range;

    if (chosen && editor.value && range) {
        chosen.run(editor.value, range);
    }

    menu.value = null;
};

// An extension rather than a keymap, so inserting a block replaces the typed `/` range.
const slashMenu = Extension.create({
    name: 'pageSlashMenu',

    addProseMirrorPlugins() {
        return [
            Suggestion({
                editor: this.editor,
                char: '/',
                startOfLine: false,
                allowSpaces: false,
                command: ({ range, props: chosen }) => insert(chosen as SlashCommand, range),
                items: ({ query }) => matchingCommands(query),
                render: () => ({
                    onStart: (state) => {
                        const rect = state.clientRect?.();

                        if (!rect) {
                            return;
                        }

                        menu.value = {
                            commands: state.items as SlashCommand[],
                            selected: 0,
                            // Above the caret when the list would overflow the window.
                            position: {
                                top: rect.bottom + 300 > window.innerHeight ? rect.top - 296 : rect.bottom + 6,
                                left: Math.min(rect.left, window.innerWidth - 272),
                            },
                            range: state.range,
                        };
                    },
                    onUpdate: (state) => {
                        if (menu.value) {
                            menu.value.commands = state.items as SlashCommand[];
                            menu.value.selected = 0;
                            menu.value.range = state.range;
                        }
                    },
                    onKeyDown: (state) => {
                        if (menu.value === null) {
                            return false;
                        }

                        const count = menu.value.commands.length;

                        if (state.event.key === 'Escape') {
                            menu.value = null;

                            return true;
                        }

                        if (state.event.key === 'ArrowDown' && count) {
                            menu.value.selected = (menu.value.selected + 1) % count;

                            return true;
                        }

                        if (state.event.key === 'ArrowUp' && count) {
                            menu.value.selected = (menu.value.selected - 1 + count) % count;

                            return true;
                        }

                        if (state.event.key === 'Enter' && count) {
                            insert(menu.value.commands[menu.value.selected]);

                            return true;
                        }

                        return false;
                    },
                    onExit: () => {
                        menu.value = null;
                    },
                }),
            }),
        ];
    },
});

// ProseMirror coordinates rather than a DOM range, since a selection can span nodes the editor drew.
const placeToolbar = (mode: 'marks' | 'code' | 'table'): void => {
    const instance = editor.value;

    if (!instance) {
        return;
    }

    const { from } = instance.state.selection;
    const start = instance.view.coordsAtPos(from);

    toolbar.value = {
        mode,
        anchor: {
            top: Math.max(8, start.top - 44),
            left: Math.min(Math.max(8, start.left), window.innerWidth - 320),
        },
    };
};

const readSelection = (): void => {
    const instance = editor.value;

    if (!instance || !instance.isEditable) {
        toolbar.value = null;

        return;
    }

    if (instance.isActive('table')) {
        placeToolbar('table');

        return;
    }

    if (instance.isActive('codeBlock')) {
        placeToolbar('code');

        return;
    }

    if (instance.state.selection.empty) {
        toolbar.value = null;

        return;
    }

    placeToolbar('marks');
};

editor.value = new Editor({
    content: props.modelValue,
    editable: props.editable,
    extensions: [...pageExtensions(props.placeholder), slashMenu],
    editorProps: {
        attributes: {
            class: 'page-document outline-none',
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': 'Page content',
        },
    },
    onUpdate: ({ editor: instance }) => {
        emitted = instance.getJSON() as PageDocument;
        emit('update:modelValue', emitted);
    },
    onSelectionUpdate: readSelection,
    onBlur: ({ event }) => {
        // Clicking a toolbar control blurs the editor, so the toolbar must survive that blur.
        const moved = event.relatedTarget;

        if (!(moved instanceof HTMLElement) || moved.closest('[role="toolbar"]') === null) {
            toolbar.value = null;
        }
    },
});

// Applied only when it differs, so an echoed value does not move the caret while typing.
watch(
    () => props.modelValue,
    (document) => {
        if (!editor.value || toRaw(document) === emitted) {
            return;
        }

        if (JSON.stringify(editor.value.getJSON()) !== JSON.stringify(document)) {
            editor.value.commands.setContent(document, { emitUpdate: false });
        }
    },
);

// The listbox is announced through the focused `.ProseMirror` textbox, not EditorContent's wrapper.
watch(
    () => menu.value?.commands[menu.value.selected]?.key ?? null,
    (active) => {
        const textbox = editor.value && !editor.value.isDestroyed ? editor.value.view.dom : null;

        if (!textbox) {
            return;
        }

        if (active === null) {
            textbox.removeAttribute('aria-activedescendant');
            textbox.removeAttribute('aria-controls');

            return;
        }

        textbox.setAttribute('aria-activedescendant', `page-slash-${active}`);
        textbox.setAttribute('aria-controls', 'page-slash-menu');
    },
    { flush: 'post' },
);

watch(
    () => props.editable,
    (editable) => {
        editor.value?.setEditable(editable);

        if (!editable) {
            toolbar.value = null;
        }
    },
);

onBeforeUnmount(() => editor.value?.destroy());

defineExpose({
    focus: (): void => {
        editor.value?.commands.focus('start');
    },
});
</script>

<template>
    <div class="relative">
        <EditorContent :editor="editor" />

        <div
            v-if="editable"
            class="min-h-32 cursor-text"
            aria-hidden="true"
            @click="editor?.commands.focus('end')"
        />

        <PageSelectionToolbar
            v-if="toolbar && editor && editable"
            :editor="editor"
            :anchor="toolbar.anchor"
            :mode="toolbar.mode"
        />

        <PageSlashMenu
            v-if="menu && editable"
            :commands="menu.commands"
            :selected="menu.selected"
            :position="menu.position"
            @select="insert"
        />
    </div>
</template>

<script setup lang="ts">
import Suggestion from '@tiptap/suggestion';
import { Editor, EditorContent, Extension } from '@tiptap/vue-3';
import type { Range } from '@tiptap/vue-3';
import { onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import PageSlashMenu from '@/modules/page/components/PageSlashMenu.vue';
import type { SlashCommand } from '@/modules/page/components/PageSlashMenu.vue';
import { pageExtensions } from '@/modules/page/lib/extensions';
import { matchingCommands, slashCommands } from '@/modules/page/lib/slashCommands';
import type { PageDocument } from '@/modules/page/types';
import '../../../../css/page-editor.css';

/**
 * The page, written.
 *
 * What goes in and what comes out is **the document as JSON**, never markup (ADR-0017). The
 * server reduces it to a known vocabulary on the way in and hands the same shape back, so no
 * raw markup is ever handed to the renderer here and the allowlist is one list rather than two
 * that drift. (`MarkupTest` looks for the directive by name, which is why this sentence does not
 * spell it.)
 *
 * The component is loaded asynchronously by whoever renders it: Tiptap and ProseMirror together
 * are a large chunk, and a person reading a list of pages has no use for them.
 */
const props = withDefaults(
    defineProps<{
        modelValue: PageDocument;
        editable?: boolean;
        placeholder?: string;
    }>(),
    { editable: true, placeholder: 'Write, or press / for blocks' },
);

const emit = defineEmits<{ 'update:modelValue': [document: PageDocument] }>();

/** The menu a slash opens, and where the caret was when it did. */
const menu = ref<{
    commands: SlashCommand[];
    selected: number;
    position: { top: number; left: number };
    range: Range;
} | null>(null);

/**
 * `shallowRef`, because an Editor is a large object graph with its own reactivity; making Vue
 * walk it would be expensive and would achieve nothing — the editor tells us when it changed.
 */
const editor = shallowRef<Editor>();

const insert = (command: SlashCommand, over?: Range): void => {
    const chosen = slashCommands.find((candidate) => candidate.key === command.key);
    const range = over ?? menu.value?.range;

    if (chosen && editor.value && range) {
        chosen.run(editor.value, range);
    }

    menu.value = null;
};

/**
 * `/` opens the block list. Registered as an extension rather than as a keymap so it carries the
 * range it was typed over: inserting a block replaces what was typed, instead of leaving a stray
 * slash on the line.
 */
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
                            // Below the caret, unless the caret is low enough that the list
                            // would leave the window — then above it.
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

editor.value = new Editor({
    content: props.modelValue,
    editable: props.editable,
    extensions: [...pageExtensions(props.placeholder), slashMenu],
    editorProps: {
        attributes: {
            class: 'page-document min-h-64 outline-none',
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': 'Page content',
        },
    },
    onUpdate: ({ editor: instance }) => emit('update:modelValue', instance.getJSON() as PageDocument),
});

/*
 * The server is authoritative, and it is also the source of what is on the screen right now.
 * Replacing the content on every incoming value would move the caret while somebody is typing,
 * so a document that matches what the editor already holds is not applied.
 */
watch(
    () => props.modelValue,
    (document) => {
        if (editor.value && JSON.stringify(editor.value.getJSON()) !== JSON.stringify(document)) {
            editor.value.commands.setContent(document, { emitUpdate: false });
        }
    },
);

watch(
    () => props.editable,
    (editable) => editor.value?.setEditable(editable),
);

onBeforeUnmount(() => editor.value?.destroy());
</script>

<template>
    <div class="relative">
        <EditorContent
            :editor="editor"
            :aria-activedescendant="menu ? `page-slash-${menu.commands[menu.selected]?.key}` : undefined"
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

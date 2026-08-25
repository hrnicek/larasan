<script setup lang="ts">
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import '../../css/quill.css';

/**
 * Quill, wrapped thinly enough that the rest of the application never imports it directly.
 *
 * Loaded on demand — the editor is a hundred kilobytes that a person reading a list of tasks has
 * no use for, so whatever renders this does so behind `defineAsyncComponent`.
 *
 * The toolbar is the set of things a task description actually needs. Quill offers colours,
 * fonts and sizes; a description written in three typefaces is not a better description, and the
 * server's allowlist would drop them on the way in regardless (`RichText`).
 */
defineProps<{ placeholder?: string }>();

/**
 * The editor is mounted the moment somebody asks to write, so the caret belongs in it. Done on
 * Quill's own `ready` rather than on mount: this component is loaded on demand, and there is no
 * tick at which the caller can know the editor exists.
 */
const focusOnReady = (quill: { focus: () => void }): void => quill.focus();

const content = defineModel<string>({ required: true });

const toolbar = [
    ['bold', 'italic', 'underline', 'strike'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['blockquote', 'code-block'],
    ['link'],
    ['clean'],
];
</script>

<template>
    <QuillEditor
        v-model:content="content"
        content-type="html"
        theme="snow"
        :toolbar="toolbar"
        :placeholder="placeholder"
        @ready="focusOnReady"
    />
</template>

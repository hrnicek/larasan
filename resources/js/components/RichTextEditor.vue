<script setup lang="ts">
import { QuillEditor } from '@vueup/vue-quill';
import '@vueup/vue-quill/dist/vue-quill.snow.css';
import '../../css/quill.css';

// Render behind defineAsyncComponent to keep Quill out of the main bundle; the toolbar matches the server's RichText allowlist.
defineProps<{ placeholder?: string }>();

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

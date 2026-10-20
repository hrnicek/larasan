import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import vm from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import { createRenderer, nextTick, reactive } from 'vue';

const require = createRequire(import.meta.url);
const source = readFileSync(
    new URL(
        '../../resources/js/modules/task/components/TaskDescriptionField.vue',
        import.meta.url,
    ),
    'utf8',
);
const descriptor = parse(source, {
    filename: 'TaskDescriptionField.vue',
}).descriptor;
const script = compileScript(descriptor, { id: 'task-description' }).content;
const compiled = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
        esModuleInterop: true,
    },
}).outputText;

function mountDescription(initial = '<p>Old</p>') {
    const requests = [];
    let beforeVisit;
    let draft;
    const props = reactive({
        taskId: 'task-1',
        value: initial,
        editable: true,
    });
    const router = {
        put: (url, data, options) => requests.push({ url, data, options }),
        on: (_event, callback) => {
            beforeVisit = callback;

            return () => {};
        },
    };
    const window = {
        location: { href: 'https://example.test/tasks/task-1' },
        confirm: () => false,
        addEventListener: () => {},
        removeEventListener: () => {},
    };
    const module = { exports: {} };
    const imports = (name) => {
        if (name === '@inertiajs/vue3') {
return { router };
}

        if (name === '@lucide/vue' || name === '@/components/ui/skeleton') {
return {};
}

        if (name.startsWith('@/actions/')) {
            return {
                __esModule: true,
                default: { update: { url: (id) => `/tasks/${id}` } },
            };
        }

        return require(name);
    };

    vm.runInNewContext(compiled, {
        module,
        exports: module.exports,
        require: imports,
        window,
    });

    const component = module.exports.default;
    const setup = component.setup;
    component.setup = (incomingProps, context) => {
        draft = setup(incomingProps, context);

        return draft;
    };
    component.render = () => null;

    const renderer = createRenderer({
        createElement: () => ({ children: [] }),
        createText: () => ({}),
        createComment: () => ({}),
        setText: () => {},
        setElementText: () => {},
        patchProp: () => {},
        insert: () => {},
        remove: () => {},
        parentNode: () => null,
        nextSibling: () => null,
    });
    const root = { children: [] };
    renderer.createApp(component, props).mount(root);

    return {
        draft,
        props,
        requests,
        beforeVisit,
        unmount: () => renderer.render(null, root),
    };
}

test('keeps edits made while a save is in flight and sends them after it finishes', async () => {
    const editor = mountDescription();
    editor.draft.start();
    editor.draft.draft.value = '<p>First</p>';
    await nextTick();
    editor.draft.save();

    assert.equal(editor.requests.length, 1);
    assert.equal(editor.requests[0].data.description_original, '<p>Old</p>');
    editor.draft.draft.value = '<p>Second</p>';
    editor.props.value = '<p>First</p>';
    await nextTick();
    editor.requests[0].options.onSuccess();
    editor.requests[0].options.onFinish();

    assert.equal(editor.requests.length, 2);
    assert.equal(editor.requests[1].data.description, '<p>Second</p>');
    assert.equal(editor.requests[1].data.description_original, '<p>First</p>');
    assert.equal(editor.draft.editing.value, true);

    editor.props.value = '<p>Second</p>';
    await nextTick();
    editor.requests[1].options.onSuccess();
    editor.requests[1].options.onFinish();

    assert.equal(editor.draft.editing.value, false);
    assert.equal(editor.draft.pending.value, false);
    editor.unmount();
});

test('keeps a rejected draft editable and blocks navigation away from it', async () => {
    const editor = mountDescription();
    editor.draft.start();
    editor.draft.draft.value = '<p>Unsent</p>';
    await nextTick();
    editor.draft.save();
    editor.requests[0].options.onError({
        description: 'This description changed elsewhere.',
        description_conflict: '1',
    });
    editor.requests[0].options.onFinish();

    assert.equal(
        editor.draft.error.value,
        'This description changed elsewhere.',
    );
    assert.equal(editor.draft.draft.value, '<p>Unsent</p>');
    assert.equal(editor.draft.pending.value, true);
    assert.equal(editor.draft.conflict.value, true);
    assert.equal(
        editor.beforeVisit({
            detail: {
                visit: {
                    prefetch: false,
                    method: 'get',
                    url: new URL('https://example.test/tasks/another'),
                },
            },
        }),
        false,
    );
    editor.unmount();
});

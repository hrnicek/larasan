import type { InjectionKey, Ref } from 'vue';
import { inject, provide, ref } from 'vue';

/**
 * Where a floating primitive inside a modal must render.
 *
 * Inertia Modal opens a native `<dialog>` with `showModal()`, which puts it in the browser's
 * top layer and makes the rest of the document inert. A popover or a select portalled to
 * `body` — which is what reka-ui does by default — then lands underneath the dialog, and the
 * clicks never reach it: `document.elementFromPoint()` over such a control returns the dialog.
 *
 * `ModalShell` publishes the dialog it is inside, and the `ui/` wrappers teleport into it when
 * there is one. Outside a modal nothing is provided, the target stays `null`, and the
 * primitives keep their own default.
 */
const modalPortalTarget: InjectionKey<Ref<HTMLElement | null>> = Symbol('modalPortalTarget');

export function provideModalPortalTarget(): Ref<HTMLElement | null> {
    const target = ref<HTMLElement | null>(null);

    provide(modalPortalTarget, target);

    return target;
}

export function useModalPortalTarget(): Ref<HTMLElement | null> {
    return inject(modalPortalTarget, ref(null));
}

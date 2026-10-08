import type { InjectionKey, Ref } from 'vue';
import { inject, provide, ref } from 'vue';

// A modal is a native `<dialog>` in the top layer, so floating primitives portalled to `body` would
// render beneath it and be unclickable. `null` outside a modal keeps the primitive's default.
const modalPortalTarget: InjectionKey<Ref<HTMLElement | null>> =
    Symbol('modalPortalTarget');

export function provideModalPortalTarget(): Ref<HTMLElement | null> {
    const target = ref<HTMLElement | null>(null);

    provide(modalPortalTarget, target);

    return target;
}

export function useModalPortalTarget(): Ref<HTMLElement | null> {
    return inject(modalPortalTarget, ref(null));
}

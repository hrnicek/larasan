import { putConfig, withInertiaModal } from '@inertiaui/modal-vue';
import type { App } from 'vue';

/**
 * The package ships `bg-white` as its panel colour, which is a light-mode-only answer on an
 * application that renders in both themes. Every surface below is a token, so a modal is the
 * same material as a card and follows the same appearance setting.
 *
 * `navigate: false` keeps the base page on screen when a modal opens from inside the
 * application; the modal still carries its own URL, and entering that URL directly renders the
 * base page underneath it from the server's `baseRoute`.
 */
export function configureModalLayer(): void {
    putConfig({
        type: 'modal',
        navigate: false,
        useNativeDialog: true,
        appElement: '#app',
        modal: {
            closeButton: true,
            closeExplicitly: false,
            closeOnClickOutside: true,
            maxWidth: 'xl',
            paddingClasses: 'p-6',
            panelClasses: 'bg-card text-card-foreground rounded-xl border border-border shadow-2xl',
            position: 'center',
        },
        slideover: {
            closeButton: false,
            closeExplicitly: false,
            closeOnClickOutside: true,
            maxWidth: '3xl',
            paddingClasses: '',
            panelClasses: 'bg-background text-foreground min-h-screen border-l border-border shadow-2xl',
            position: 'right',
        },
    });
}

/**
 * Wrap the application in the modal root so an open modal survives a page change.
 *
 * The cast is the one place this file needs it. `withInertiaModal` replaces
 * `app._component.render` and declares that field as `() => VNode`, while Vue types the same
 * field as `Function` on `ConcreteComponent`. The wider type is the one Vue really has, so the
 * two are compatible at runtime and only the declarations disagree.
 */
export function applyModalLayer(app: App): void {
    withInertiaModal(app as unknown as Parameters<typeof withInertiaModal>[0]);
}


import { putConfig, withInertiaModal } from '@inertiaui/modal-vue';
import type { App } from 'vue';

export function configureModalLayer(): void {
    putConfig({
        type: 'modal',
        // Keeps the base page mounted; a modal URL opened directly renders its `baseRoute` underneath.
        navigate: false,
        useNativeDialog: true,
        appElement: '#app',
        modal: {
            closeButton: true,
            closeExplicitly: false,
            closeOnClickOutside: true,
            maxWidth: 'xl',
            paddingClasses: 'p-6',
            panelClasses:
                'bg-card text-card-foreground rounded-xl border border-border shadow-2xl',
            position: 'center',
        },
        slideover: {
            closeButton: false,
            closeExplicitly: false,
            closeOnClickOutside: true,
            maxWidth: '3xl',
            paddingClasses: '',
            panelClasses:
                'bg-background text-foreground min-h-screen border-l border-border shadow-2xl',
            position: 'right',
        },
    });
}

// The package types `app._component.render` as `() => VNode` where Vue declares `Function`.
export function applyModalLayer(app: App): void {
    withInertiaModal(app as unknown as Parameters<typeof withInertiaModal>[0]);
}

import { onScopeDispose } from 'vue';
import { perFrame } from '@/lib/perFrame';

/** Pixels of pointer travel below which a press is a click, not a drag. */
const THRESHOLD = 4;

export type PointerDragHandlers = {
    /** Runs once, when the pointer first travels past the threshold. */
    start: () => void;
    /** Runs at most once per animation frame while dragging. */
    move: (x: number, y: number) => void;
    drop: (x: number, y: number) => void;
    /** The drag was abandoned: `pointercancel`, or the owning scope was disposed. */
    cancel: () => void;
};

export type BeginPointerDrag = (event: PointerEvent, handlers: PointerDragHandlers) => void;

// A released drag still dispatches `click` on the element under the pointer, in the same task as `pointerup`.
const swallowNextClick = (): void => {
    const swallow = (click: MouseEvent): void => {
        click.preventDefault();
        click.stopPropagation();
    };

    window.addEventListener('click', swallow, { capture: true, once: true });
    window.setTimeout(() => window.removeEventListener('click', swallow, { capture: true }));
};

export function usePointerDrag(): BeginPointerDrag {
    let abandon: (() => void) | null = null;

    onScopeDispose(() => abandon?.(), true);

    return (event: PointerEvent, handlers: PointerDragHandlers): void => {
        if (event.button !== 0) {
            return;
        }

        abandon?.();

        const { pointerId, clientX: startX, clientY: startY } = event;
        const track = perFrame(handlers.move);
        let dragging = false;

        const stop = (): void => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.removeEventListener('pointercancel', onCancel);
            track.cancel();
            abandon = null;
        };

        const onMove = (moved: PointerEvent): void => {
            if (moved.pointerId !== pointerId) {
                return;
            }

            if (!dragging) {
                if (Math.hypot(moved.clientX - startX, moved.clientY - startY) < THRESHOLD) {
                    return;
                }

                dragging = true;
                handlers.start();
            }

            track.call(moved.clientX, moved.clientY);
        };

        const onUp = (up: PointerEvent): void => {
            if (up.pointerId !== pointerId) {
                return;
            }

            stop();

            if (!dragging) {
                return;
            }

            swallowNextClick();
            handlers.drop(up.clientX, up.clientY);
        };

        const cancel = (): void => {
            stop();

            if (dragging) {
                handlers.cancel();
            }
        };

        const onCancel = (cancelled: PointerEvent): void => {
            if (cancelled.pointerId === pointerId) {
                cancel();
            }
        };

        abandon = cancel;

        document.addEventListener('pointermove', onMove, { passive: true });
        document.addEventListener('pointerup', onUp, { passive: true });
        document.addEventListener('pointercancel', onCancel, { passive: true });
    };
}

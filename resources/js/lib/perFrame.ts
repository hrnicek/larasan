/**
 * A handler that runs at most once per frame, with the most recent arguments it was given.
 *
 * Written for the drags. A pointer reports every few milliseconds — faster on a trackpad than a
 * screen can draw — and deciding where a card would land reads the layout: `elementFromPoint`,
 * then a box per candidate in the column. Doing that per event forces the browser to lay the page
 * out several times between two frames, and the drag it is meant to make precise is what stutters.
 *
 * Once a frame is as often as the answer can be seen.
 */
export type FramedCall<T extends unknown[]> = {
    call: (...args: T) => void;
    /** Drops a frame that has not run yet — for a drag that has ended and must not be answered. */
    cancel: () => void;
};

export function perFrame<T extends unknown[]>(run: (...args: T) => void): FramedCall<T> {
    let frame: number | null = null;
    let latest: T | null = null;

    return {
        call(...args: T): void {
            latest = args;

            if (frame !== null) {
                return;
            }

            frame = requestAnimationFrame(() => {
                frame = null;

                if (latest !== null) {
                    run(...latest);
                }
            });
        },

        cancel(): void {
            if (frame !== null) {
                cancelAnimationFrame(frame);
                frame = null;
            }

            latest = null;
        },
    };
}

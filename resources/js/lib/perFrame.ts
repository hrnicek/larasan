export type FramedCall<T extends unknown[]> = {
    call: (...args: T) => void;
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

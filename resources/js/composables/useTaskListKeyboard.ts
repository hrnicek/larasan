/**
 * Walking the list with the keyboard.
 *
 * The rows are the only focus targets: `↑` and `↓` move between them, `n` opens the inline
 * input of the column the focus is in. Everything else — completing with `Space`, opening a
 * picker, `Esc` to cancel — belongs to the control that has focus, because a list-wide
 * handler that guessed would fight the popovers.
 */
export function useTaskListKeyboard(container: () => HTMLElement | null): {
    onKeydown: (event: KeyboardEvent) => void;
} {
    const rowsOf = (root: HTMLElement): HTMLElement[] =>
        Array.from(root.querySelectorAll<HTMLElement>('[data-task-row]'));

    return {
        onKeydown(event: KeyboardEvent): void {
            const root = container();

            if (root === null) {
                return;
            }

            const rows = rowsOf(root);
            const current = (event.target as HTMLElement).closest<HTMLElement>('[data-task-row]');

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (rows.length === 0) {
                    return;
                }

                event.preventDefault();

                const index = current === null ? -1 : rows.indexOf(current);
                const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

                // Stops at the ends rather than wrapping: a list that jumps from the last row
                // to the first reads as a bug the first time somebody holds the key down.
                rows[Math.min(Math.max(next, 0), rows.length - 1)]?.focus();

                return;
            }

            if (event.key === 'n' && current !== null) {
                const column = current.closest<HTMLElement>('[data-task-section]');
                const add = column?.querySelector<HTMLElement>('[data-add-task]');

                if (add !== null && add !== undefined) {
                    event.preventDefault();
                    add.click();
                }
            }
        },
    };
}

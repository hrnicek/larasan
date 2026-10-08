export function useTaskListKeyboard(container: () => HTMLElement | null): {
    onKeydown: (event: KeyboardEvent) => void;
} {
    const rowsOf = (root: HTMLElement): HTMLElement[] =>
        Array.from(root.querySelectorAll<HTMLElement>('[data-task-row]'));

    const isTyping = (target: EventTarget | null): boolean =>
        target instanceof HTMLElement &&
        (target.isContentEditable ||
            ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

    return {
        onKeydown(event: KeyboardEvent): void {
            const root = container();

            if (root === null || isTyping(event.target)) {
                return;
            }

            const rows = rowsOf(root);
            const current = (event.target as HTMLElement).closest<HTMLElement>(
                '[data-task-row]',
            );

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (rows.length === 0) {
                    return;
                }

                event.preventDefault();

                const index = current === null ? -1 : rows.indexOf(current);
                const next = event.key === 'ArrowDown' ? index + 1 : index - 1;

                rows[Math.min(Math.max(next, 0), rows.length - 1)]?.focus();

                return;
            }

            if (event.key === 'n' && current !== null) {
                const column = current.closest<HTMLElement>(
                    '[data-task-section]',
                );
                const add =
                    column?.querySelector<HTMLElement>('[data-add-task]');

                if (add !== null && add !== undefined) {
                    event.preventDefault();
                    add.click();
                }
            }
        },
    };
}

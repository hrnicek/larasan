/**
 * Which view a project's address opens on, as this browser last saw it.
 *
 * The view an address without `?view=` draws is the project's own default, which only the server
 * knows. The screen writes down what it drew, so the next visit's skeleton has the shape of the
 * screen that arrives rather than a list that turns into a board. It is a hint for a placeholder,
 * never a choice: the server still decides what the page draws.
 */
const storageKey = (address: string): string => `landing-view:${address}`;

export function rememberLandingView(address: string, view: string): void {
    try {
        window.localStorage.setItem(storageKey(address), view);
    } catch {
        // Storage refused: the next skeleton is a list, and nothing else changes.
    }
}

export function landingView(address: string): string | null {
    try {
        return window.localStorage.getItem(storageKey(address));
    } catch {
        return null;
    }
}

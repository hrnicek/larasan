// Only shapes the loading skeleton; the server still decides which view a project opens on.
const storageKey = (address: string): string => `landing-view:${address}`;

export function rememberLandingView(address: string, view: string): void {
    try {
        window.localStorage.setItem(storageKey(address), view);
    } catch {
        // Storage unavailable; the skeleton falls back to the list.
    }
}

export function landingView(address: string): string | null {
    try {
        return window.localStorage.getItem(storageKey(address));
    } catch {
        return null;
    }
}

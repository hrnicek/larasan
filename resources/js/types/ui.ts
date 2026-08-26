export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

/**
 * A surface scheme (ADR-0019). The server sends the list; the client never derives it, and no
 * colour crosses this boundary — the swatch is drawn from the theme's own tokens.
 */
export type UiTheme = 'slate' | 'paper' | 'carbon';

export type UiThemeOption = {
    value: UiTheme;
    label: string;
    description: string;
};

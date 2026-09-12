export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type UiTheme = 'slate' | 'meridian' | 'ember' | 'nocturne' | 'moss';

export type UiThemeOption = {
    value: UiTheme;
    label: string;
    description: string;
};

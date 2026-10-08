export type AvatarPreset = {
    id: number;
    url: string;
};

export type AvatarChoice = {
    preset: number | null;
    uploaded: boolean;
};

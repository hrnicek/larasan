/** One of the illustrations somebody can wear instead of uploading a picture. */
export type AvatarPreset = {
    id: number;
    url: string;
};

/** What the signed-in person wears now: an illustration, an upload, or neither (initials). */
export type AvatarChoice = {
    preset: number | null;
    uploaded: boolean;
};

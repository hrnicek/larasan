/**
 * What a workspace has decided it records about its work.
 *
 * The type is stated as the union the server's enum produces rather than as `string`, so a case
 * added on one side and forgotten on the other is a type error instead of a control that draws
 * nothing.
 */
export type CustomFieldType = 'text' | 'number' | 'date' | 'boolean' | 'select';

export type CustomFieldOption = {
    id: string;
    label: string;
};

/**
 * One field on the settings screen.
 *
 * `projectCount` and `valueCount` are what the deletion confirmation is made of: a field taken
 * away takes the answers with it, and the reader is owed the number before they agree to it.
 */
export type WorkspaceCustomField = {
    id: string;
    name: string;
    type: CustomFieldType;
    options: CustomFieldOption[];
    projectCount: number;
    valueCount: number;
};

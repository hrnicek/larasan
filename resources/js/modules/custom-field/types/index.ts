export type CustomFieldType =
    | 'text'
    | 'number'
    | 'date'
    | 'boolean'
    | 'select'
    | 'email'
    | 'phone'
    | 'link';

export type CustomFieldOption = {
    id: string;
    label: string;
};

export type WorkspaceCustomField = {
    id: string;
    name: string;
    type: CustomFieldType;
    options: CustomFieldOption[];
    projectCount: number;
    valueCount: number;
};

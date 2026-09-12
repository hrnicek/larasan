import type { CustomFieldType } from '@/modules/custom-field/types';

export const typeLabels: Record<CustomFieldType, string> = {
    text: 'Text',
    number: 'Number',
    date: 'Date',
    boolean: 'Yes / no',
    select: 'Choice',
    email: 'Email',
    phone: 'Phone',
    link: 'Link',
};

export const typeHints: Record<CustomFieldType, string> = {
    text: 'A short line of text',
    number: 'A number, sortable',
    date: 'A single date',
    boolean: 'A box that is ticked or not',
    select: 'One of a list you write',
    email: 'An address, checked for shape',
    phone: 'A number to call, written however your colleagues will recognise it',
    link: 'A web address',
};

export function inputTypeFor(type: CustomFieldType): string {
    switch (type) {
        case 'number':
            return 'number';
        case 'date':
            return 'date';
        case 'email':
            return 'email';
        case 'phone':
            return 'tel';
        case 'link':
            return 'url';
        default:
            return 'text';
    }
}

import type { CustomFieldType } from '@/modules/custom-field/types';

/**
 * What each field type is called to a reader, and what it is for.
 *
 * The picker's *contents* come from the server, so a case added to the enum offers itself without
 * a second list to remember. These maps only name the cases — and because they are keyed by the
 * union, a case missing from one of them is a type error rather than a blank option.
 *
 * Written once because three screens draw the same words: the fields settings screen, the card on
 * project settings and the drawer in the project header.
 */
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

/**
 * The `<input type>` a field's answer is written with.
 *
 * `email`, `phone` and `link` are stored in the same column as `text`; what makes them worth
 * having is exactly this — the keyboard a phone raises, the address a browser offers to fill in,
 * and the shape the server refuses before the value is ever stored.
 */
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

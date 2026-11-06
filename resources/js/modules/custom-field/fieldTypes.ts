import { t } from '@/lib/i18n';
import type { CustomFieldType } from '@/modules/custom-field/types';

/* Getters, so each label is translated when read rather than when this module is imported. */
export const typeLabels: Readonly<Record<CustomFieldType, string>> = {
    get text() {
        return t('Text');
    },
    get number() {
        return t('Number');
    },
    get date() {
        return t('Date');
    },
    get boolean() {
        return t('Yes / no');
    },
    get select() {
        return t('Choice');
    },
    get email() {
        return t('Email');
    },
    get phone() {
        return t('Phone');
    },
    get link() {
        return t('Link');
    },
};

export const typeHints: Readonly<Record<CustomFieldType, string>> = {
    get text() {
        return t('A short line of text');
    },
    get number() {
        return t('A number, sortable');
    },
    get date() {
        return t('A single date');
    },
    get boolean() {
        return t('A box that is ticked or not');
    },
    get select() {
        return t('One of a list you write');
    },
    get email() {
        return t('An address, checked for shape');
    },
    get phone() {
        return t(
            'A number to call, written however your colleagues will recognise it',
        );
    },
    get link() {
        return t('A web address');
    },
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

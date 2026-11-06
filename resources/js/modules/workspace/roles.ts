import { t } from '@/lib/i18n';

/** Roles travel as their enum value; this is only how one reads on screen. */
export function roleName(role: string): string {
    switch (role) {
        case 'owner':
            return t('owner');
        case 'admin':
            return t('admin');
        case 'member':
            return t('member');
        case 'guest':
            return t('guest');
        default:
            return role;
    }
}

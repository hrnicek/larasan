import { currentLocale } from '@/lib/i18n';

const oneDecimal = (value: number): string =>
    new Intl.NumberFormat(currentLocale(), {
        minimumFractionDigits: 1,
        maximumFractionDigits: 1,
    }).format(value);

export function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return bytes < 1024 * 1024 * 1024
        ? `${oneDecimal(bytes / (1024 * 1024))} MB`
        : `${oneDecimal(bytes / (1024 * 1024 * 1024))} GB`;
}

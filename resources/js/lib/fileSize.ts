/**
 * A number of bytes, written the way a person reads it.
 *
 * Powers of 1024 with the short unit names, which is what every desktop this application is used
 * from already shows for the same file — a table that disagreed with the operating system about
 * how big something is would be answering a question nobody asked.
 */
export function formatFileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return bytes < 1024 * 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
}

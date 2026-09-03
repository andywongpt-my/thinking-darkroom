/** Shared human-readable time presentation for workspace surfaces. */
export function relativeTime(iso: string | null | undefined, now = Date.now()): string {
    if (!iso) return 'not recorded';

    const timestamp = new Date(iso).getTime();
    if (!Number.isFinite(timestamp)) return 'not recorded';

    const seconds = Math.max(0, Math.floor((now - timestamp) / 1000));
    if (seconds < 60) return 'just now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;

    const days = Math.floor(hours / 24);
    if (days === 1) return 'yesterday';
    if (days < 30) return `${days} day${days === 1 ? '' : 's'} ago`;

    const months = Math.floor(days / 30);
    if (months < 12) return `${months} month${months === 1 ? '' : 's'} ago`;

    const years = Math.floor(months / 12);
    return `${years} year${years === 1 ? '' : 's'} ago`;
}

/** Full local timestamp retained as the accessible/title detail. */
export function localTime(iso: string | null | undefined): string {
    if (!iso) return 'not recorded';

    const date = new Date(iso);
    return Number.isFinite(date.getTime()) ? date.toLocaleString() : 'not recorded';
}

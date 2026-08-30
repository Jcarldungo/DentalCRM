export function formatDateTime(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function formatDate(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function formatTime(iso) {
    if (!iso) return '—';

    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

export function formatPeso(amount) {
    return `₱${Number(amount).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

/**
 * A calendar-day difference in whole days, from `iso` to now. Positive
 * means in the past. Used for "waiting 12 minutes" and "3 days overdue",
 * so it must not be affected by the time of day.
 */
export function daysAgo(iso) {
    const then = new Date(iso);
    const startOfThen = new Date(then.getFullYear(), then.getMonth(), then.getDate());
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    return Math.round((startOfToday - startOfThen) / 86_400_000);
}

/** "Today", "Tomorrow", "Yesterday", or a short date. */
export function relativeDay(iso) {
    if (!iso) return '—';

    const diff = daysAgo(iso);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    if (diff === -1) return 'Tomorrow';

    return formatDate(iso);
}

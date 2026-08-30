import { SOLID_TONES, TONES } from './statuses';

/**
 * A status pill. Always carries its label as text — status is never
 * communicated by colour alone anywhere in this app.
 *
 * Takes a `{ label, tone }` from statuses.js rather than a raw string, so
 * a page cannot invent its own colour for a status that already has one.
 */
export default function StatusBadge({ status, solid = false, className = '', children }) {
    const { label, tone } = status ?? { label: children, tone: 'neutral' };
    const palette = solid ? SOLID_TONES[tone] : `ring-1 ring-inset ${TONES[tone]}`;

    return (
        <span
            className={`inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ${palette} ${className}`}
        >
            {children ?? label}
        </span>
    );
}

/** A neutral count chip — used for column and section counts, which are not statuses. */
export function CountBadge({ count, className = '' }) {
    return (
        <span
            className={`tabular inline-flex min-w-[1.5rem] items-center justify-center rounded-full bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600 ${className}`}
        >
            {count}
        </span>
    );
}

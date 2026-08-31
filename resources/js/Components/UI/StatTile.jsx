import { Link } from '@inertiajs/react';

/**
 * A headline number.
 *
 * These used to be tinted, shadowed, icon-bearing cards — four of them in
 * a row across the top of a page, which is the stock admin-dashboard
 * opening and read as one. The number was `text-2xl`, smaller than the
 * page title above it, so the thing the page exists to tell you was the
 * fourth most prominent element on screen.
 *
 * Now the number *is* the tile: `text-4xl`, and the only colour on it.
 * Colour is the status tone from statuses.js applied to the figure
 * itself, so it reads as data rather than as a decorative wash behind
 * data. Structure comes from the hairlines StatRow draws, not from a box
 * per number.
 */
const VALUE_TONES = {
    neutral: 'text-slate-900',
    info: 'text-brand-700',
    progress: 'text-violet-700',
    success: 'text-emerald-700',
    warning: 'text-amber-700',
    danger: 'text-rose-700',
    muted: 'text-slate-400',
};

/**
 * The rule grid the stats sit in.
 *
 * `gap-px` over a slate background rather than `divide-x`: these wrap to
 * two columns on a phone, and `divide-x` puts a stray rule down the left
 * of every wrapped row. The gap technique is correct at any column count
 * and any number of children.
 */
const COLUMNS = {
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-3',
    4: 'sm:grid-cols-4',
};

export function StatRow({ columns = 4, className = '', children }) {
    return (
        // One contained band with hairline divisions, not four boxes and
        // not four naked columns. Bare rules over a white page left the
        // numbers floating with nothing holding them together; a single
        // bordered object with internal rules gives the row an edge
        // without giving every figure its own card.
        <div
            className={`grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 ${
                COLUMNS[columns] ?? COLUMNS[4]
            } ${className}`}
        >
            {children}
        </div>
    );
}

export default function StatTile({
    label,
    value,
    sub,
    tone = 'neutral',
    href,
    className = '',
    ...props
}) {
    // A stat that links somewhere is the entry point to the list it
    // counts, so the whole cell is the target.
    const Tag = href ? Link : 'div';

    return (
        <Tag
            {...props}
            {...(href ? { href } : {})}
            className={`bg-white px-5 py-5 ${
                href
                    ? 'block transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500'
                    : ''
            } ${className}`}
        >
            <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                {label}
            </p>
            {/* 48px. At 36 the figure was the same visual weight as the
                page title two inches above it, so nothing on the screen
                claimed to be the thing the page is for. A headline number
                has to actually behave like a headline. */}
            <p
                className={`tabular mt-2.5 text-5xl font-semibold leading-none tracking-tight ${
                    VALUE_TONES[tone] ?? VALUE_TONES.neutral
                }`}
            >
                {value}
            </p>
            {sub && <p className="mt-2.5 truncate text-xs text-slate-500">{sub}</p>}
        </Tag>
    );
}

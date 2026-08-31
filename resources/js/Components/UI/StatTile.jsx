import { Link } from '@inertiajs/react';

/**
 * A headline number.
 *
 * The one tinted surface in the staff app. Everything else is white on
 * `slate-50`, which is what lets four soft washes of colour at the top of
 * a page read as "these are the numbers" instead of as decoration — and
 * what stops the tint being reached for anywhere else.
 *
 * `tone` is chosen for what the number *means*, never for variety: the
 * five here are the same semantic set as the status tones in statuses.js,
 * so a count of things in treatment is `success` on the dashboard for the
 * same reason the status pill is.
 *
 * The Dashboard and Reports both used to draw their own version of this;
 * a tile is one component so the two pages cannot drift apart again.
 */
const TONES = {
    neutral: {
        surface: 'border-slate-200 bg-white',
        chip: 'bg-slate-100 text-slate-500',
        value: 'text-slate-900',
    },
    info: {
        surface: 'border-brand-100 bg-brand-50',
        chip: 'bg-brand-100 text-brand-700',
        value: 'text-brand-950',
    },
    progress: {
        surface: 'border-violet-100 bg-violet-50',
        chip: 'bg-violet-100 text-violet-700',
        value: 'text-violet-950',
    },
    success: {
        surface: 'border-emerald-100 bg-emerald-50',
        chip: 'bg-emerald-100 text-emerald-700',
        value: 'text-emerald-950',
    },
    warning: {
        surface: 'border-amber-100 bg-amber-50',
        chip: 'bg-amber-100 text-amber-800',
        value: 'text-amber-950',
    },
    danger: {
        surface: 'border-rose-100 bg-rose-50',
        chip: 'bg-rose-100 text-rose-700',
        value: 'text-rose-950',
    },
};

export default function StatTile({
    label,
    value,
    sub,
    icon: Icon,
    tone = 'neutral',
    href,
    className = '',
    ...props
}) {
    const palette = TONES[tone] ?? TONES.neutral;

    // A tile that links somewhere is the entry point to the list it
    // counts, so the whole tile is the target rather than the number
    // carrying a separate link inside it.
    const Tag = href ? Link : 'div';

    return (
        <Tag
            {...props}
            {...(href ? { href } : {})}
            className={`rounded-2xl border p-4 shadow-card ${palette.surface} ${
                href
                    ? 'block transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2'
                    : ''
            } ${className}`}
        >
            <div className="flex items-start justify-between gap-3">
                <p className="min-w-0 truncate text-xs font-medium text-slate-600">{label}</p>
                {Icon && (
                    <span
                        className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${palette.chip}`}
                    >
                        <Icon className="h-4 w-4" aria-hidden="true" />
                    </span>
                )}
            </div>
            <p className={`tabular mt-3 text-2xl font-semibold leading-none ${palette.value}`}>
                {value}
            </p>
            {sub && <p className="mt-1.5 truncate text-xs text-slate-500">{sub}</p>}
        </Tag>
    );
}

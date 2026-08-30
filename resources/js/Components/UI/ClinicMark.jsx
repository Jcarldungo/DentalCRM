/**
 * The staff app's mark.
 *
 * A tooth glyph plus the clinic's own name, replacing the Laravel logo
 * the scaffold shipped with. The name comes from the shared `clinic`
 * prop, so it is swappable with the rest of clinic identity rather than
 * being another place a real customer's name would have to be found and
 * edited.
 */
export default function ClinicMark({ name, compact = false }) {
    return (
        <span className="flex min-w-0 items-center gap-2.5">
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white">
                <ToothGlyph className="h-[18px] w-[18px]" />
            </span>
            {!compact && (
                <span className="min-w-0 truncate text-sm font-semibold leading-tight text-slate-900">
                    {name}
                </span>
            )}
        </span>
    );
}

export function ToothGlyph({ className = '' }) {
    return (
        <svg viewBox="0 0 24 24" fill="none" className={className} aria-hidden="true">
            <path
                d="M7.2 2.5C5 2.5 3.4 4.2 3.4 6.6c0 1.5.3 2.6.8 3.9.4 1 .6 1.9.7 3.2.2 2.1.5 3.9 1 5.2.4 1.1 1 1.7 1.8 1.7.9 0 1.4-.7 1.7-2 .3-1.2.5-2.4.7-3.4.2-1.1.6-1.7 1.4-1.7.8 0 1.2.6 1.4 1.7.2 1 .4 2.2.7 3.4.3 1.3.8 2 1.7 2 .8 0 1.4-.6 1.8-1.7.5-1.3.8-3.1 1-5.2.1-1.3.3-2.2.7-3.2.5-1.3.8-2.4.8-3.9 0-2.4-1.6-4.1-3.8-4.1-1.4 0-2.4.4-3.2.7-.6.2-1 .4-1.4.4-.4 0-.8-.2-1.4-.4-.8-.3-1.8-.7-3.2-.7Z"
                fill="currentColor"
            />
        </svg>
    );
}

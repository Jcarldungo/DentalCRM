/**
 * The staff app's one surface.
 *
 * A hairline border carries the edge and `shadow-card` lifts it off the
 * `slate-50` page — deliberately two very shallow layers rather than one
 * soft drop shadow, because clinical pages stack surfaces close together
 * and at that density a real shadow turns the gap between two cards into
 * grey haze while the 1px line stays readable.
 */
export default function Card({ as: Tag = 'div', className = '', children, ...props }) {
    return (
        <Tag
            {...props}
            className={`rounded-2xl border border-slate-200 bg-white shadow-card ${className}`}
        >
            {children}
        </Tag>
    );
}

export function CardHeader({ title, description, actions, icon: Icon, className = '', children }) {
    return (
        <div
            className={`flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5 sm:px-5 ${className}`}
        >
            <div className="flex min-w-0 items-start gap-2.5">
                {Icon && (
                    <Icon className="mt-0.5 h-4 w-4 shrink-0 text-slate-400" aria-hidden="true" />
                )}
                <div className="min-w-0">
                    {title && <h2 className="text-sm font-semibold text-slate-900">{title}</h2>}
                    {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
                    {children}
                </div>
            </div>
            {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}

export function CardBody({ className = '', children }) {
    return <div className={`px-4 py-4 sm:px-5 ${className}`}>{children}</div>;
}

/** A row list inside a Card — the pattern the patient, provider, and inquiry lists all use. */
export function CardList({ className = '', children }) {
    return <ul className={`divide-y divide-slate-100 ${className}`}>{children}</ul>;
}

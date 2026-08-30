/**
 * The staff app's one surface.
 *
 * A hairline border rather than a drop shadow: clinical pages stack a lot
 * of surfaces close together, and at that density shadows blur into each
 * other while a 1px slate line stays readable.
 */
export default function Card({ as: Tag = 'div', className = '', children, ...props }) {
    return (
        <Tag
            {...props}
            className={`rounded-xl border border-slate-200 bg-white ${className}`}
        >
            {children}
        </Tag>
    );
}

export function CardHeader({ title, description, actions, className = '', children }) {
    return (
        <div
            className={`flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-4 py-3 sm:px-5 ${className}`}
        >
            <div className="min-w-0">
                {title && <h2 className="text-sm font-semibold text-slate-900">{title}</h2>}
                {description && <p className="mt-0.5 text-xs text-slate-500">{description}</p>}
                {children}
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
    return <ul className={`divide-y divide-slate-200 ${className}`}>{children}</ul>;
}

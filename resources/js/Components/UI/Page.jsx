/**
 * Page scaffolding.
 *
 * One container width and one padding rule, applied here rather than
 * repeated per page: the staff pages previously used four different
 * `max-w-*` values, and all of them wrote `sm:px-6 lg:px-8` with no base
 * `px-4`, so every page ran edge to edge on a phone.
 */
export function PageContainer({ className = '', children }) {
    return (
        <div className={`mx-auto w-full max-w-shell px-4 py-6 sm:px-6 lg:px-8 ${className}`}>
            {children}
        </div>
    );
}

export function PageHeader({ title, description, actions, children, className = '' }) {
    return (
        <div className={`mb-5 flex flex-wrap items-end justify-between gap-3 ${className}`}>
            <div className="min-w-0">
                <h1 className="truncate text-xl font-semibold tracking-tight text-slate-900">{title}</h1>
                {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
                {children}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

/** A section heading inside a page — one level below PageHeader. */
export function SectionHeading({ title, count, actions, className = '' }) {
    return (
        <div className={`mb-3 flex items-center justify-between gap-3 ${className}`}>
            <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                {title}
                {count !== undefined && (
                    <span className="tabular rounded-full bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-600">
                        {count}
                    </span>
                )}
            </h2>
            {actions}
        </div>
    );
}

/**
 * An empty state that says what would go here and how to put it there —
 * the pages used to render a bare "No patients yet." in grey.
 */
export function EmptyState({ icon: Icon, title, description, action, className = '' }) {
    return (
        <div className={`flex flex-col items-center px-6 py-10 text-center ${className}`}>
            {Icon && (
                <div className="mb-3 rounded-full bg-slate-100 p-2.5">
                    <Icon className="h-5 w-5 text-slate-400" aria-hidden="true" />
                </div>
            )}
            <p className="text-sm font-medium text-slate-900">{title}</p>
            {description && <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

/** A labelled value — the shape most of the app's read-only detail is in. */
export function DetailItem({ label, children, className = '' }) {
    return (
        <div className={className}>
            <dt className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className="mt-0.5 text-sm text-slate-900">{children ?? '—'}</dd>
        </div>
    );
}

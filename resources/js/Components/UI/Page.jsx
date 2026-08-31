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

/**
 * The page title.
 *
 * `text-3xl` with tight tracking. The app's whole type scale used to sit
 * between 12px and 24px, which is why every screen read as one flat
 * grey field — nothing on it was confident about being the most
 * important thing. The jump from 14px body to 30px title is the scale
 * doing its job.
 */
export function PageHeader({ title, description, actions, children, className = '' }) {
    return (
        <div className={`mb-8 flex flex-wrap items-end justify-between gap-3 ${className}`}>
            <div className="min-w-0">
                <h1 className="truncate text-3xl font-semibold tracking-tight text-slate-900">
                    {title}
                </h1>
                {description && <p className="mt-1.5 text-sm text-slate-500">{description}</p>}
                {children}
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

/**
 * The page's structural unit: a heading, a rule, and its content.
 *
 * This replaces reaching for a `Card` every time a page needs to group
 * something. A card is a box, and a page built entirely from boxes has
 * no hierarchy — the dashboard was nine white rectangles of equal
 * weight. A rule and generous space above the heading separate sections
 * without adding another edge to look at.
 *
 * More room above the heading than below it, so a section reads as
 * belonging to what follows rather than floating between two blocks.
 */
export function Section({ title, description, actions, children, className = '' }) {
    return (
        <section className={`mt-10 first:mt-0 ${className}`}>
            <div className="mb-4 flex flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b border-slate-200 pb-3">
                <div className="min-w-0">
                    <h2 className="text-lg font-semibold tracking-tight text-slate-900">{title}</h2>
                    {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
                </div>
                {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
            </div>
            {children}
        </section>
    );
}

/** A heading one level below Section, for a block inside one. */
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

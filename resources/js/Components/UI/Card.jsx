/**
 * A discrete object — an invoice, a patient in the queue, a form.
 *
 * Elevation is declared once: a hairline border and no shadow. Carrying
 * both meant neither read, and a 1px line under a soft shadow is the
 * stock admin card that made every page look the same weight as every
 * other. A card is now for something that genuinely is an object; the
 * page's own structure is `Section` — a heading and a rule, no box.
 */
export default function Card({ as: Tag = 'div', className = '', children, ...props }) {
    return (
        <Tag
            {...props}
            className={`rounded-2xl border border-slate-200 bg-white ${className}`}
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

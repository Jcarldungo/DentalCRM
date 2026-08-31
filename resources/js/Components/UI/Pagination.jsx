import { Link } from '@inertiajs/react';

/**
 * Laravel paginator links.
 *
 * Shared because three lists now paginate and they must agree — a page
 * control that looks different per list reads as a different control.
 * Renders nothing on a single page, so a caller can include it
 * unconditionally.
 */
export default function Pagination({ paginator, label = 'Pages', className = '' }) {
    if (!paginator || paginator.last_page <= 1) return null;

    return (
        <nav aria-label={label} className={`mt-4 flex flex-wrap items-center justify-between gap-3 ${className}`}>
            <p className="tabular text-xs text-slate-500">
                Showing {paginator.from}–{paginator.to} of {paginator.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {paginator.links.map((link, index) => (
                    <Link
                        key={index}
                        href={link.url ?? '#'}
                        preserveScroll
                        aria-current={link.active ? 'page' : undefined}
                        aria-disabled={!link.url}
                        tabIndex={link.url ? undefined : -1}
                        className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${
                            link.active
                                ? 'bg-brand-600 font-medium text-white'
                                : link.url
                                  ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                  : 'pointer-events-none border border-slate-200 text-slate-300'
                        }`}
                        // Laravel's labels carry &laquo;/&raquo; entities.
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </nav>
    );
}

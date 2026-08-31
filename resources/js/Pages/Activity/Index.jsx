import Card from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import Pagination from '@/Components/UI/Pagination';
import StatusBadge from '@/Components/UI/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { formatDateTime, formatPeso } from '@/Pages/Patients/format';

/**
 * Tone by what the action means, so a refused delete and a completed one
 * do not read the same at a glance.
 */
const TONES = {
    'patient.deleted': 'danger',
    'patient.delete_refused': 'warning',
    'provider.deleted': 'danger',
    'provider.delete_refused': 'warning',
    'account.deleted': 'danger',
    'invoice.voided': 'warning',
    'invoice.issued': 'info',
    'payment.recorded': 'success',
    'prescription.discontinued': 'warning',
    'inventory.archived': 'warning',
    'inventory.restored': 'info',
    'stock.recorded': 'neutral',
    'appointment.status_changed': 'progress',
};

/** Where the subject of an entry lives, when it still exists to link to. */
const SUBJECT_ROUTES = {
    Patient: (id) => route('patients.show', id),
    Invoice: (id) => route('invoices.show', id),
    InventoryItem: (id) => route('inventory.show', id),
};

/** The context is a small map chosen per action — render it as a sentence. */
function describeContext(entry) {
    const context = entry.context ?? {};

    if (entry.action === 'appointment.status_changed') {
        return `${String(context.from).replace('_', ' ')} → ${String(context.to).replace('_', ' ')}`;
    }
    if (entry.action === 'payment.recorded') {
        return `${formatPeso(context.amount)} · ${String(context.method).replace('_', ' ')}`;
    }
    if (entry.action === 'stock.recorded') {
        return `${context.type} · ${context.quantity > 0 ? '+' : ''}${context.quantity}`;
    }
    if (entry.action === 'invoice.issued' || entry.action === 'invoice.voided') {
        return context.total !== undefined ? formatPeso(context.total) : null;
    }

    return null;
}

export default function Index({ entries, actions, filters }) {
    function filterBy(action) {
        router.get(
            route('activity.index'),
            action ? { action } : {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout title="Activity">
            <Head title="Activity" />

            <PageContainer>
                <PageHeader
                    title="Activity"
                    description="Who did what, and when. Append-only — nothing here can be edited or removed."
                />

                {actions.length > 0 && (
                    <div role="group" aria-label="Filter activity by action" className="mb-4 flex flex-wrap gap-1.5">
                        <button
                            type="button"
                            onClick={() => filterBy(null)}
                            aria-pressed={!filters.action}
                            className={`h-9 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${
                                !filters.action
                                    ? 'bg-brand-600 text-white'
                                    : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                            }`}
                        >
                            All
                        </button>
                        {actions.map((action) => {
                            const active = filters.action === action.value;

                            return (
                                <button
                                    key={action.value}
                                    type="button"
                                    onClick={() => filterBy(action.value)}
                                    aria-pressed={active}
                                    className={`h-9 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${
                                        active
                                            ? 'bg-brand-600 text-white'
                                            : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                    }`}
                                >
                                    {action.label}
                                </button>
                            );
                        })}
                    </div>
                )}

                <Card className="overflow-hidden">
                    {entries.data.length === 0 ? (
                        <EmptyState
                            icon={History}
                            title="Nothing recorded yet"
                            description="Money moving, records being destroyed or refused, and clinical state changing are all recorded here as they happen."
                        />
                    ) : (
                        <ol className="divide-y divide-slate-200">
                            {entries.data.map((entry) => {
                                const href = SUBJECT_ROUTES[entry.subject_type]?.(entry.subject_id);
                                const detail = describeContext(entry);

                                return (
                                    <li
                                        key={entry.id}
                                        className="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-4 py-3 sm:px-5"
                                    >
                                        <StatusBadge
                                            status={{
                                                label: entry.action_label,
                                                tone: TONES[entry.action] ?? 'neutral',
                                            }}
                                        />

                                        <span className="min-w-0 flex-1 text-sm text-slate-800">
                                            {entry.subject_label && href ? (
                                                <Link
                                                    href={href}
                                                    className="font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                                >
                                                    {entry.subject_label}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">{entry.subject_label ?? '—'}</span>
                                            )}
                                            {detail && <span className="text-slate-500"> · {detail}</span>}
                                        </span>

                                        <span className="text-xs text-slate-500">
                                            {entry.actor_name ?? 'Deleted account'}
                                        </span>
                                        <span className="tabular w-40 shrink-0 text-end text-xs text-slate-400">
                                            {formatDateTime(entry.created_at)}
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    )}
                </Card>

                <Pagination paginator={entries} label="Activity pages" />
            </PageContainer>
        </AuthenticatedLayout>
    );
}

import { EmptyState, PageContainer, PageHeader, Section } from '@/Components/UI/Page';
import StatTile, { StatRow } from '@/Components/UI/StatTile';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ArrowRight, Inbox, Users } from 'lucide-react';
import { formatDate, formatPeso } from './Patients/format';

/*
 * The tones are the ones statuses.js already gives these four statuses,
 * not a decorative sweep of pastels — a waiting patient is violet here
 * for the same reason the pill on the queue board is violet. Picking
 * prettier colours would be the exact drift statuses.js exists to stop.
 */
const TILES = [
    { key: 'scheduled', label: 'Scheduled', tone: 'info' },
    { key: 'waiting', label: 'Waiting', tone: 'progress' },
    { key: 'in_treatment', label: 'In treatment', tone: 'success' },
    { key: 'completed', label: 'Completed', tone: 'muted' },
];

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function formatDay(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(y, m - 1, d).toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    });
}

function pluralise(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

/** A rule-separated block in the right-hand column. */
function Glance({ title, href, children }) {
    const body = (
        <>
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{title}</p>
            <div className="mt-2">{children}</div>
        </>
    );

    if (!href) {
        return <div className="py-4">{body}</div>;
    }

    return (
        <Link
            href={href}
            className="-mx-2 block rounded-lg px-2 py-4 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        >
            {body}
        </Link>
    );
}

export default function Dashboard({ today, requests, dueForRecall, outstanding, inventory }) {
    return (
        <AuthenticatedLayout title="Dashboard" navBadges={{ 'appointments.index': requests.count }}>
            <Head title="Dashboard" />

            <PageContainer>
                <PageHeader title="Dashboard" description={formatDay(today.date)} />

                {/* The day's numbers are the first thing the front desk
                    reads, and a rule grid says so more plainly than four
                    tinted boxes did. */}
                <StatRow columns={4}>
                    {TILES.map((tile) => (
                        <StatTile
                            key={tile.key}
                            label={tile.label}
                            value={today[tile.key]}
                            tone={tile.tone}
                            href={route('queue.index')}
                        />
                    ))}
                </StatRow>

                <div className="grid gap-x-10 lg:grid-cols-3">
                    <div className="lg:col-span-2">
                        <Section
                            title="Appointment requests"
                            description="Submitted from the public site — a guest is waiting on each of these."
                            actions={
                                requests.count > 0 && (
                                    <Link
                                        href={route('appointments.index')}
                                        className="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:text-brand-800"
                                    >
                                        Review
                                        <ArrowRight className="h-4 w-4" aria-hidden="true" />
                                    </Link>
                                )
                            }
                        >
                            {requests.count === 0 ? (
                                <EmptyState
                                    icon={Inbox}
                                    title="No pending requests"
                                    description="New requests from the public booking form land here."
                                />
                            ) : (
                                <>
                                    {requests.oldest_days >= 1 && (
                                        <p className="mb-3 flex items-center gap-2 text-xs font-medium text-amber-700">
                                            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                                            The oldest request has been waiting{' '}
                                            {pluralise(requests.oldest_days, 'day')}.
                                        </p>
                                    )}
                                    <ul className="divide-y divide-slate-100">
                                        {requests.items.map((request) => (
                                            <li
                                                key={request.id}
                                                className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-2.5"
                                            >
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-slate-900">
                                                        {request.patient_name}
                                                    </p>
                                                    <p className="truncate text-xs text-slate-500">
                                                        {request.service_interest}
                                                    </p>
                                                </div>
                                                <p className="text-xs text-slate-500">
                                                    Prefers {formatDate(request.preferred_date)} (
                                                    {request.preferred_time_of_day})
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                    {requests.count > requests.items.length && (
                                        <p className="pt-2.5 text-xs text-slate-500">
                                            and {requests.count - requests.items.length} more
                                        </p>
                                    )}
                                </>
                            )}
                        </Section>

                        <Section
                            title="Due for recall"
                            description="Patients past their cleaning interval."
                        >
                            {dueForRecall.length === 0 ? (
                                <EmptyState
                                    icon={Users}
                                    title="Nobody is overdue"
                                    description="Patients appear here once they pass their recall interval since their last completed cleaning."
                                />
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {dueForRecall.map((patient) => (
                                        <li key={patient.id}>
                                            <Link
                                                href={route('patients.show', patient.id)}
                                                className="-mx-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg px-2 py-2.5 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                            >
                                                <span className="truncate text-sm font-medium text-slate-900">
                                                    {patient.full_name}
                                                </span>
                                                <span className="text-xs text-slate-500">
                                                    Last cleaning {formatDate(patient.last_cleaning_at)} ·{' '}
                                                    <span className="font-medium text-amber-700">
                                                        {patient.overdue_days === 0
                                                            ? 'due today'
                                                            : `${pluralise(patient.overdue_days, 'day')} overdue`}
                                                    </span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>
                    </div>

                    {/* Three small summaries that used to be three separate
                        boxes. One panel of rule-separated rows: they stop
                        competing with the two lists beside them, but the
                        column keeps an edge — as bare text on a white page
                        it read as something that had failed to load. */}
                    <aside className="mt-10 h-fit rounded-xl border border-slate-200 p-5">
                        <h2 className="text-sm font-semibold tracking-tight text-slate-900">
                            At a glance
                        </h2>
                        <div className="mt-1 divide-y divide-slate-100">
                            <Glance title="Next appointment">
                                {today.next ? (
                                    <Link
                                        href={route('patients.show', today.next.patient_id)}
                                        className="block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                    >
                                        <p className="tabular text-2xl font-semibold tracking-tight text-slate-900">
                                            {formatTime(today.next.start_time)}
                                        </p>
                                        <p className="mt-1 text-sm font-medium text-brand-700">
                                            {today.next.patient_name}
                                        </p>
                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {today.next.type}
                                            {today.next.provider_name && ` · ${today.next.provider_name}`}
                                        </p>
                                    </Link>
                                ) : (
                                    <p className="text-sm text-slate-500">Nothing else scheduled today.</p>
                                )}
                            </Glance>

                            <Glance
                                title="Outstanding balances"
                                href={route('invoices.index', { status: 'outstanding' })}
                            >
                                {outstanding.count === 0 ? (
                                    <p className="text-sm text-slate-500">Nothing outstanding.</p>
                                ) : (
                                    <>
                                        <p className="tabular text-2xl font-semibold tracking-tight text-slate-900">
                                            {formatPeso(outstanding.total)}
                                        </p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            across {pluralise(outstanding.count, 'invoice')}
                                        </p>
                                    </>
                                )}
                            </Glance>

                            <Glance title="Inventory" href={route('inventory.index', { filter: 'low' })}>
                                {inventory.low_count === 0 && inventory.expiring_count === 0 ? (
                                    <p className="text-sm text-slate-500">Stock levels healthy.</p>
                                ) : (
                                    <dl className="space-y-1.5 text-sm">
                                        {inventory.low_count > 0 && (
                                            <div className="flex items-center justify-between gap-3">
                                                <dt className="text-slate-600">Low on stock</dt>
                                                <dd className="tabular font-semibold text-amber-700">
                                                    {inventory.low_count}
                                                </dd>
                                            </div>
                                        )}
                                        {inventory.expiring_count > 0 && (
                                            <div className="flex items-center justify-between gap-3">
                                                <dt className="text-slate-600">Expiring soon</dt>
                                                <dd className="tabular font-semibold text-rose-700">
                                                    {inventory.expiring_count}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                )}
                            </Glance>
                        </div>
                    </aside>
                </div>
            </PageContainer>
        </AuthenticatedLayout>
    );
}

import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatTile from '@/Components/UI/StatTile';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Boxes,
    CalendarClock,
    CheckCircle2,
    Clock,
    Inbox,
    Receipt,
    Stethoscope,
    Users,
} from 'lucide-react';
import { formatPeso } from './Patients/format';

/*
 * The tones are the ones statuses.js already gives these four statuses,
 * not a decorative sweep of pastels — a waiting patient is violet in the
 * tile for the same reason the pill on the queue board is violet. Picking
 * prettier colours here would be the exact drift statuses.js exists to
 * prevent.
 */
const TILES = [
    { key: 'scheduled', label: 'Scheduled', icon: CalendarClock, tone: 'info' },
    { key: 'waiting', label: 'Waiting', icon: Clock, tone: 'progress' },
    { key: 'in_treatment', label: 'In treatment', icon: Stethoscope, tone: 'success' },
    { key: 'completed', label: 'Completed', icon: CheckCircle2, tone: 'neutral' },
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

export default function Dashboard({ today, requests, dueForRecall, outstanding, inventory }) {
    return (
        <AuthenticatedLayout title="Dashboard" navBadges={{ 'appointments.index': requests.count }}>
            <Head title="Dashboard" />

            <PageContainer>
                <PageHeader
                    title="Dashboard"
                    description={formatDay(today.date)}
                />

                <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                    {TILES.map((tile) => (
                        <StatTile
                            key={tile.key}
                            label={tile.label}
                            value={today[tile.key]}
                            icon={tile.icon}
                            tone={tile.tone}
                            href={route('queue.index')}
                        />
                    ))}
                </div>

                <div className="mt-6 grid gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <Card>
                            <CardHeader
                                icon={Inbox}
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
                            />
                            {requests.count === 0 ? (
                                <EmptyState
                                    icon={Inbox}
                                    title="No pending requests"
                                    description="New requests from the public booking form land here."
                                />
                            ) : (
                                <>
                                    {requests.oldest_days >= 1 && (
                                        <div className="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs font-medium text-amber-800 sm:px-5">
                                            <AlertTriangle className="h-4 w-4 shrink-0" aria-hidden="true" />
                                            The oldest request has been waiting {pluralise(requests.oldest_days, 'day')}.
                                        </div>
                                    )}
                                    <ul className="divide-y divide-slate-200">
                                        {requests.items.map((request) => (
                                            <li
                                                key={request.id}
                                                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 sm:px-5"
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
                                                    Prefers {request.preferred_date} ({request.preferred_time_of_day})
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                    {requests.count > requests.items.length && (
                                        <div className="border-t border-slate-200 px-4 py-2.5 text-xs text-slate-500 sm:px-5">
                                            and {requests.count - requests.items.length} more
                                        </div>
                                    )}
                                </>
                            )}
                        </Card>

                        <Card>
                            <CardHeader
                                icon={Users}
                                title="Due for recall"
                                description="Patients past their cleaning interval."
                            />
                            {dueForRecall.length === 0 ? (
                                <EmptyState
                                    icon={Users}
                                    title="Nobody is overdue"
                                    description="Patients appear here once they pass their recall interval since their last completed cleaning."
                                />
                            ) : (
                                <ul className="divide-y divide-slate-200">
                                    {dueForRecall.map((patient) => (
                                        <li key={patient.id}>
                                            <Link
                                                href={route('patients.show', patient.id)}
                                                className="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-4 py-3 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500 sm:px-5"
                                            >
                                                <span className="truncate text-sm font-medium text-slate-900">
                                                    {patient.full_name}
                                                </span>
                                                <span className="text-xs text-slate-500">
                                                    Last cleaning {patient.last_cleaning_at} ·{' '}
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
                        </Card>
                    </div>

                    <div className="space-y-5">
                        <Card>
                            <CardHeader icon={CalendarClock} title="Next appointment" />
                            <CardBody>
                                {today.next ? (
                                    <Link
                                        href={route('patients.show', today.next.patient_id)}
                                        className="block rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                    >
                                        <p className="tabular text-2xl font-semibold text-slate-900">
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
                                    <p className="text-sm text-slate-500">
                                        Nothing else scheduled today.
                                    </p>
                                )}
                            </CardBody>
                        </Card>

                        <Link
                            href={route('invoices.index', { status: 'outstanding' })}
                            className="block rounded-2xl border border-slate-200 bg-white p-4 shadow-card transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 sm:p-5"
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Receipt className="h-4 w-4 text-slate-400" aria-hidden="true" />
                                Outstanding balances
                            </div>
                            {outstanding.count === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">Nothing outstanding.</p>
                            ) : (
                                <>
                                    <p className="tabular mt-2 text-2xl font-semibold text-slate-900">
                                        {formatPeso(outstanding.total)}
                                    </p>
                                    <p className="mt-0.5 text-xs text-slate-500">
                                        across {pluralise(outstanding.count, 'invoice')}
                                    </p>
                                </>
                            )}
                        </Link>

                        <Link
                            href={route('inventory.index', { filter: 'low' })}
                            className="block rounded-2xl border border-slate-200 bg-white p-4 shadow-card transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 sm:p-5"
                        >
                            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                <Boxes className="h-4 w-4 text-slate-400" aria-hidden="true" />
                                Inventory
                            </div>
                            {inventory.low_count === 0 && inventory.expiring_count === 0 ? (
                                <p className="mt-2 text-sm text-slate-500">Stock levels healthy.</p>
                            ) : (
                                <dl className="mt-3 space-y-1.5 text-sm">
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
                        </Link>
                    </div>
                </div>
            </PageContainer>
        </AuthenticatedLayout>
    );
}

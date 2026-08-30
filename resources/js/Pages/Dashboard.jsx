import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
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

const TILES = [
    { key: 'scheduled', label: 'Scheduled', icon: CalendarClock, tone: 'text-brand-600 bg-brand-50' },
    { key: 'waiting', label: 'Waiting', icon: Clock, tone: 'text-violet-600 bg-violet-50' },
    { key: 'in_treatment', label: 'In treatment', icon: Stethoscope, tone: 'text-emerald-600 bg-emerald-50' },
    { key: 'completed', label: 'Completed', icon: CheckCircle2, tone: 'text-slate-500 bg-slate-100' },
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

/** A count that links into the board it counts — the number is the entry point. */
function TodayTile({ tile, value }) {
    const Icon = tile.icon;

    return (
        <Link
            href={route('queue.index')}
            className="group flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
        >
            <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${tile.tone}`}>
                <Icon className="h-5 w-5" aria-hidden="true" />
            </span>
            <span className="min-w-0">
                <span className="tabular block text-2xl font-semibold leading-none text-slate-900">{value}</span>
                <span className="mt-1 block truncate text-xs font-medium text-slate-500">{tile.label}</span>
            </span>
        </Link>
    );
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

                <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                    {TILES.map((tile) => (
                        <TodayTile key={tile.key} tile={tile} value={today[tile.key]} />
                    ))}
                </div>

                <div className="mt-6 grid gap-5 lg:grid-cols-3">
                    <div className="space-y-5 lg:col-span-2">
                        <Card>
                            <CardHeader
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
                            <CardHeader title="Next appointment" />
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
                            className="block rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 sm:p-5"
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
                            className="block rounded-xl border border-slate-200 bg-white p-4 transition-colors hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 sm:p-5"
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

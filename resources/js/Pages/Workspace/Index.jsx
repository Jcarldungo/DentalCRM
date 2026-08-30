import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { appointmentStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { CalendarOff, ChevronLeft, ChevronRight } from 'lucide-react';
import { formatTime } from '../Patients/format';

function formatLongDate(ymd) {
    // ymd is 'YYYY-MM-DD'; parse as local, not UTC.
    const [y, m, d] = ymd.split('-').map(Number);

    return new Date(y, m - 1, d).toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
}

function shiftDate(ymd, days) {
    const [y, m, d] = ymd.split('-').map(Number);
    const date = new Date(y, m - 1, d);
    date.setDate(date.getDate() + days);

    return toYmd(date);
}

function toYmd(date) {
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function pluralise(count, word) {
    return `${count} ${word}${count === 1 ? '' : 's'}`;
}

export default function Index({ providers, selectedProviderId, date, appointments }) {
    function navigate(params) {
        router.get(
            route('workspace.index'),
            { provider_id: selectedProviderId ?? undefined, date, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const providerLabel = selectedProviderId
        ? (providers.find((provider) => provider.id === selectedProviderId)?.name ?? 'that provider')
        : 'all providers';

    return (
        <AuthenticatedLayout title="Workspace">
            <Head title="Workspace" />

            <PageContainer>
                <PageHeader
                    title="Workspace"
                    description={`${formatLongDate(date)} · ${pluralise(appointments.length, 'appointment')}`}
                />

                <div className="mb-5 flex flex-wrap items-center gap-2">
                    <select
                        aria-label="Filter by provider"
                        className="h-10 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        value={selectedProviderId ?? ''}
                        onChange={(e) => navigate({ provider_id: e.target.value || undefined })}
                    >
                        <option value="">All providers</option>
                        {selectedProviderId != null &&
                            !providers.some((provider) => provider.id === selectedProviderId) && (
                                <option value={selectedProviderId}>Inactive provider</option>
                            )}
                        {providers.map((provider) => (
                            <option key={provider.id} value={provider.id}>
                                {provider.name}
                            </option>
                        ))}
                    </select>

                    <input
                        type="date"
                        aria-label="Date"
                        className="h-10 rounded-lg border-slate-300 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500"
                        value={date}
                        onChange={(e) => e.target.value && navigate({ date: e.target.value })}
                    />

                    <div className="flex items-center gap-1">
                        <Button
                            variant="secondary"
                            size="md"
                            aria-label="Previous day"
                            onClick={() => navigate({ date: shiftDate(date, -1) })}
                            className="px-2.5"
                        >
                            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
                        </Button>
                        <Button variant="secondary" onClick={() => navigate({ date: toYmd(new Date()) })}>
                            Today
                        </Button>
                        <Button
                            variant="secondary"
                            size="md"
                            aria-label="Next day"
                            onClick={() => navigate({ date: shiftDate(date, 1) })}
                            className="px-2.5"
                        >
                            <ChevronRight className="h-4 w-4" aria-hidden="true" />
                        </Button>
                    </div>
                </div>

                {appointments.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={CalendarOff}
                            title="Nothing scheduled"
                            description={`No appointments for ${providerLabel} on ${formatLongDate(date)}.`}
                        />
                    </Card>
                ) : (
                    <ol className="space-y-2">
                        {appointments.map((appointment) => (
                            <Card as="li" key={appointment.id} className="overflow-hidden">
                                <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start">
                                    <div className="tabular w-full shrink-0 border-slate-200 sm:w-28 sm:border-e sm:pe-4">
                                        <p className="text-sm font-semibold text-slate-900">
                                            {formatTime(appointment.start_time)}
                                        </p>
                                        {appointment.end_time && (
                                            <p className="text-xs text-slate-500">
                                                until {formatTime(appointment.end_time)}
                                            </p>
                                        )}
                                    </div>

                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Link
                                                href={route('patients.show', appointment.patient_id)}
                                                className="text-sm font-semibold text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                            >
                                                {appointment.patient_name}
                                            </Link>
                                            {appointment.patient_age !== null && (
                                                <span className="tabular text-xs text-slate-500">
                                                    {appointment.patient_age} yrs
                                                </span>
                                            )}
                                            <StatusBadge status={appointmentStatus(appointment.status)} />
                                        </div>

                                        <p className="mt-0.5 text-xs text-slate-500">
                                            {appointment.type ?? '—'}
                                            {appointment.provider_name && ` · ${appointment.provider_name}`}
                                        </p>

                                        {(appointment.open_treatment_count > 0 ||
                                            appointment.active_prescription_count > 0) && (
                                            <div className="mt-2 flex flex-wrap gap-1.5">
                                                {appointment.open_treatment_count > 0 && (
                                                    <StatusBadge
                                                        status={{
                                                            label: pluralise(
                                                                appointment.open_treatment_count,
                                                                'open treatment',
                                                            ),
                                                            tone: 'warning',
                                                        }}
                                                    />
                                                )}
                                                {appointment.active_prescription_count > 0 && (
                                                    <StatusBadge
                                                        status={{
                                                            label: pluralise(
                                                                appointment.active_prescription_count,
                                                                'active Rx',
                                                            ),
                                                            tone: 'info',
                                                        }}
                                                    />
                                                )}
                                            </div>
                                        )}

                                        {appointment.notes && (
                                            <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm leading-relaxed text-slate-700">
                                                {appointment.notes}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </Card>
                        ))}
                    </ol>
                )}
            </PageContainer>
        </AuthenticatedLayout>
    );
}

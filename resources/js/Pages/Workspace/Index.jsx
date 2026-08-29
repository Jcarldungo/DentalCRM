import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const STATUS_BADGE = {
    scheduled: 'bg-gray-100 text-gray-700 border-gray-300',
    checked_in: 'bg-blue-100 text-blue-800 border-blue-300',
    in_treatment: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    completed: 'bg-green-100 text-green-800 border-green-300',
};

function formatTimeRange(startIso, endIso) {
    const opts = { hour: 'numeric', minute: '2-digit' };
    const start = new Date(startIso).toLocaleTimeString(undefined, opts);
    if (!endIso) return start;
    const end = new Date(endIso).toLocaleTimeString(undefined, opts);
    return `${start}–${end}`;
}

function formatLongDate(ymd) {
    // ymd is 'YYYY-MM-DD'; parse as local, not UTC
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
    const dt = new Date(y, m - 1, d);
    dt.setDate(dt.getDate() + days);
    const pad = (n) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

function todayYmd() {
    const dt = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${dt.getFullYear()}-${pad(dt.getMonth() + 1)}-${pad(dt.getDate())}`;
}

function pluralise(n, word) {
    return `${n} ${word}${n === 1 ? '' : 's'}`;
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
        ? providers.find((p) => p.id === selectedProviderId)?.name ?? 'that provider'
        : 'all providers';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Workspace</h2>}>
            <Head title="Workspace" />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <select
                        aria-label="Provider"
                        className="border rounded px-3 py-2 text-sm"
                        value={selectedProviderId ?? ''}
                        onChange={(e) => navigate({ provider_id: e.target.value || undefined })}
                    >
                        <option value="">All providers</option>
                        {selectedProviderId != null &&
                            !providers.some((p) => p.id === selectedProviderId) && (
                            <option value={selectedProviderId}>Inactive provider</option>
                        )}
                        {providers.map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                        ))}
                    </select>

                    <input
                        type="date"
                        aria-label="Date"
                        className="border rounded px-3 py-2 text-sm"
                        value={date}
                        onChange={(e) => e.target.value && navigate({ date: e.target.value })}
                    />

                    <div className="flex gap-1">
                        <button type="button" onClick={() => navigate({ date: shiftDate(date, -1) })} className="rounded border px-2 py-2 text-sm">
                            ‹ Prev
                        </button>
                        <button type="button" onClick={() => navigate({ date: todayYmd() })} className="rounded border px-3 py-2 text-sm">
                            Today
                        </button>
                        <button type="button" onClick={() => navigate({ date: shiftDate(date, 1) })} className="rounded border px-2 py-2 text-sm">
                            Next ›
                        </button>
                    </div>
                </div>

                <h3 className="mb-3 text-sm font-semibold text-gray-500">{formatLongDate(date)}</h3>

                <div className="space-y-2">
                    {appointments.map((appt) => (
                        <div key={appt.id} className="rounded border bg-white p-4 text-sm shadow-sm">
                            <div className="flex flex-wrap items-center gap-2 text-gray-500">
                                <span className="font-medium text-gray-900">{formatTimeRange(appt.start_time, appt.end_time)}</span>
                                <span className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[appt.status] ?? 'bg-gray-100 text-gray-700 border-gray-300'}`}>
                                    {appt.status.replace('_', ' ')}
                                </span>
                                {appt.type && <span>{appt.type}</span>}
                                {appt.provider_name && <span>· {appt.provider_name}</span>}
                            </div>

                            <div className="mt-1">
                                <Link href={`/patients/${appt.patient_id}`} className="font-medium text-blue-600">
                                    {appt.patient_name}
                                </Link>
                                {appt.patient_age !== null && <span className="text-gray-500"> ({appt.patient_age})</span>}
                            </div>

                            {(appt.open_treatment_count > 0 || appt.active_prescription_count > 0) && (
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {appt.open_treatment_count > 0 && (
                                        <span className="inline-block rounded border border-amber-300 bg-amber-100 px-2 py-0.5 text-xs text-amber-800">
                                            {pluralise(appt.open_treatment_count, 'open treatment')}
                                        </span>
                                    )}
                                    {appt.active_prescription_count > 0 && (
                                        <span className="inline-block rounded border border-blue-300 bg-blue-100 px-2 py-0.5 text-xs text-blue-800">
                                            {pluralise(appt.active_prescription_count, 'active Rx')}
                                        </span>
                                    )}
                                </div>
                            )}

                            {appt.notes && <p className="mt-2 text-gray-600">Notes: {appt.notes}</p>}
                        </div>
                    ))}

                    {appointments.length === 0 && (
                        <div className="rounded border bg-white p-4 text-sm text-gray-500 shadow-sm">
                            No appointments for {providerLabel} on {formatLongDate(date)}.
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

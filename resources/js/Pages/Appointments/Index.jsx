import { useRef, useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const STATUSES = ['requested', 'scheduled', 'checked_in', 'in_treatment', 'completed', 'cancelled', 'no_show', 'declined'];

export default function Index({ patients, providers, requests }) {
    const calendarRef = useRef(null);
    const [modal, setModal] = useState(null); // { mode: 'create'|'edit', ...fields }
    const { data, setData, post, patch, processing, errors, reset } = useForm({
        patient_id: '',
        provider_id: '',
        start_time: '',
        end_time: '',
        type: 'checkup',
        status: 'scheduled',
    });

    function refetch() {
        calendarRef.current?.getApi().refetchEvents();
    }

    function onSelect(selection) {
        reset();
        setData((d) => ({
            ...d,
            start_time: selection.startStr.slice(0, 16),
            end_time: selection.endStr.slice(0, 16),
        }));
        setModal({ mode: 'create' });
    }

    function onEventClick(clickInfo) {
        const props = clickInfo.event.extendedProps;
        setData({
            patient_id: '',
            provider_id: '',
            start_time: clickInfo.event.startStr.slice(0, 16),
            end_time: clickInfo.event.endStr.slice(0, 16),
            type: props.type,
            status: props.status,
        });
        setModal({ mode: 'edit', id: clickInfo.event.id });
    }

    function onEventDrop(dropInfo) {
        // Uses router.patch directly (not the shared useForm instance) so the
        // dragged event's new start/end are what actually gets sent — the
        // form's own data state belongs to the create/edit modal and may be
        // stale or empty at the time of a drag.
        router.patch(
            route('appointments.update', dropInfo.event.id),
            {
                start_time: dropInfo.event.startStr.slice(0, 19).replace('T', ' '),
                end_time: dropInfo.event.endStr.slice(0, 19).replace('T', ' '),
            },
            {
                onError: () => dropInfo.revert(),
            }
        );
    }

    function onConfirmRequest(request) {
        setData({
            patient_id: '',
            provider_id: '',
            start_time: '',
            end_time: '',
            type: 'checkup',
            status: 'scheduled',
        });
        setModal({ mode: 'confirm', id: request.id, request });
    }

    function onDeclineRequest(request) {
        router.patch(
            route('appointments.update', request.id),
            { status: 'declined' },
            { preserveScroll: true }
        );
    }

    function submit(e) {
        e.preventDefault();
        if (modal.mode === 'create') {
            post(route('appointments.store'), {
                onSuccess: () => {
                    setModal(null);
                    refetch();
                },
            });
        } else {
            patch(route('appointments.update', modal.id), {
                onSuccess: () => {
                    setModal(null);
                    refetch();
                },
            });
        }
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Appointments</h2>}>
            <Head title="Appointments" />

            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
                {requests.length > 0 && (
                    <div className="mb-6 bg-white shadow rounded">
                        <div className="border-b px-4 py-3">
                            <h3 className="font-semibold">
                                Appointment requests
                                <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                                    {requests.length} pending
                                </span>
                            </h3>
                            <p className="mt-1 text-sm text-gray-500">
                                Submitted from the public site. Confirming lets you set the real
                                appointment time — the date and time of day below are the patient&rsquo;s
                                preference.
                            </p>
                        </div>
                        <div className="divide-y">
                            {requests.map((request) => (
                                <div key={request.id} className="flex items-start justify-between gap-4 p-4">
                                    <div className="text-sm">
                                        <div className="font-medium">{request.patient_name}</div>
                                        <div className="text-gray-500">
                                            {request.patient_email}
                                            {request.patient_phone && ` · ${request.patient_phone}`}
                                        </div>
                                        <div className="mt-1">{request.service_interest}</div>
                                        <div className="text-gray-500">
                                            Prefers {request.preferred_date} ({request.preferred_time_of_day})
                                            {' · '}
                                            {request.dentist_preference ?? 'No dentist preference'}
                                        </div>
                                        {request.notes && (
                                            <p className="mt-1 text-gray-700">{request.notes}</p>
                                        )}
                                    </div>
                                    <div className="flex shrink-0 gap-2">
                                        <button
                                            type="button"
                                            onClick={() => onConfirmRequest(request)}
                                            className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                                        >
                                            Confirm
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => onDeclineRequest(request)}
                                            className="rounded border px-3 py-1.5 text-sm text-gray-700"
                                        >
                                            Decline
                                        </button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                <div className="bg-white shadow rounded p-4">
                    <FullCalendar
                        ref={calendarRef}
                        plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
                        initialView="timeGridWeek"
                        selectable
                        editable
                        select={onSelect}
                        eventClick={onEventClick}
                        eventDrop={onEventDrop}
                        events={(fetchInfo, successCallback, failureCallback) => {
                            fetch(
                                route('appointments.events', {
                                    start: fetchInfo.startStr,
                                    end: fetchInfo.endStr,
                                })
                            )
                                .then((res) => res.json())
                                .then(successCallback)
                                .catch(failureCallback);
                        }}
                    />
                </div>
            </div>

            {modal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4">
                    <form onSubmit={submit} className="bg-white rounded p-6 w-full max-w-sm space-y-4">
                        <h3 className="font-semibold">
                            {modal.mode === 'create' && 'New appointment'}
                            {modal.mode === 'edit' && 'Edit appointment'}
                            {modal.mode === 'confirm' && `Confirm request — ${modal.request.patient_name}`}
                        </h3>

                        {modal.mode === 'confirm' && (
                            <>
                                <p className="rounded bg-gray-50 p-3 text-sm text-gray-600">
                                    Requested {modal.request.service_interest} · prefers{' '}
                                    {modal.request.preferred_date} ({modal.request.preferred_time_of_day})
                                    {modal.request.dentist_preference && ` · ${modal.request.dentist_preference}`}
                                </p>
                                <div>
                                    <label className="block text-sm mb-1">Provider</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={data.provider_id}
                                        onChange={(e) => setData('provider_id', e.target.value)}
                                    >
                                        <option value="">Select a provider</option>
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                    {errors.provider_id && <p className="text-sm text-red-600">{errors.provider_id}</p>}
                                </div>
                            </>
                        )}

                        {modal.mode === 'create' && (
                            <>
                                <div>
                                    <label className="block text-sm mb-1">Patient</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={data.patient_id}
                                        onChange={(e) => setData('patient_id', e.target.value)}
                                    >
                                        <option value="">Select a patient</option>
                                        {patients.map((p) => (
                                            <option key={p.id} value={p.id}>{p.first_name} {p.last_name}</option>
                                        ))}
                                    </select>
                                    {errors.patient_id && <p className="text-sm text-red-600">{errors.patient_id}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm mb-1">Provider</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={data.provider_id}
                                        onChange={(e) => setData('provider_id', e.target.value)}
                                    >
                                        <option value="">Select a provider</option>
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                    {errors.provider_id && <p className="text-sm text-red-600">{errors.provider_id}</p>}
                                </div>
                            </>
                        )}

                        <div>
                            <label className="block text-sm mb-1">Type</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                            >
                                {TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                            </select>
                        </div>

                        {modal.mode === 'confirm' && (
                            <>
                                <div>
                                    <label className="block text-sm mb-1">Start</label>
                                    <input
                                        type="datetime-local"
                                        className="w-full border rounded px-3 py-2"
                                        value={data.start_time}
                                        onChange={(e) => setData('start_time', e.target.value)}
                                    />
                                    {errors.start_time && <p className="text-sm text-red-600">{errors.start_time}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm mb-1">End</label>
                                    <input
                                        type="datetime-local"
                                        className="w-full border rounded px-3 py-2"
                                        value={data.end_time}
                                        onChange={(e) => setData('end_time', e.target.value)}
                                    />
                                    {errors.end_time && <p className="text-sm text-red-600">{errors.end_time}</p>}
                                </div>
                            </>
                        )}

                        {modal.mode === 'edit' && (
                            <div>
                                <label className="block text-sm mb-1">Status</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                >
                                    {STATUSES.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </div>
                        )}

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setModal(null)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

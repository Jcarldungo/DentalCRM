import { useRef, useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

export default function Index({ patients, providers }) {
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
                            {modal.mode === 'create' ? 'New appointment' : 'Edit appointment'}
                        </h3>

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

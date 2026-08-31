import Button from '@/Components/UI/Button';
import Card, { CardHeader } from '@/Components/UI/Card';
import Field, { SelectField } from '@/Components/UI/Field';
import Modal, { ConfirmDialog } from '@/Components/UI/Modal';
import { PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { appointmentStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import dayGridPlugin from '@fullcalendar/daygrid';
import interactionPlugin from '@fullcalendar/interaction';
import FullCalendar from '@fullcalendar/react';
import timeGridPlugin from '@fullcalendar/timegrid';
import { Head, router, useForm } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { formatDate } from '../Patients/format';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const STATUSES = [
    'requested',
    'scheduled',
    'checked_in',
    'in_treatment',
    'completed',
    'cancelled',
    'no_show',
    'declined',
];

/**
 * Event colours, by status.
 *
 * Every event used to render in FullCalendar's default blue regardless of
 * status, so a cancelled slot looked exactly like a booked one — a slot a
 * receptionist would decline to fill because it appeared taken. These
 * mirror the tones in Components/UI/statuses.js.
 */
const EVENT_COLOURS = {
    scheduled: { bg: '#2a54a0', border: '#244683' },
    checked_in: { bg: '#7c3aed', border: '#6d28d9' },
    in_treatment: { bg: '#059669', border: '#047857' },
    completed: { bg: '#94a3b8', border: '#64748b' },
    cancelled: { bg: '#cbd5e1', border: '#94a3b8' },
    declined: { bg: '#cbd5e1', border: '#94a3b8' },
    no_show: { bg: '#e11d48', border: '#be123c' },
    requested: { bg: '#f59e0b', border: '#d97706' },
};

export default function Index({ patients, providers, requests }) {
    const calendarRef = useRef(null);
    const [modal, setModal] = useState(null);
    const [declining, setDeclining] = useState(null);
    const [feedError, setFeedError] = useState(null);
    const { data, setData, post, patch, transform, processing, errors, reset, clearErrors } = useForm({
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
        clearErrors();
        setData((current) => ({
            ...current,
            start_time: selection.startStr.slice(0, 16),
            end_time: selection.endStr.slice(0, 16),
        }));
        setModal({ mode: 'create' });
    }

    function onEventClick(info) {
        const props = info.event.extendedProps;
        clearErrors();
        setData({
            patient_id: '',
            provider_id: '',
            start_time: info.event.startStr.slice(0, 16),
            end_time: info.event.endStr?.slice(0, 16) ?? '',
            type: props.type ?? 'checkup',
            status: props.status,
        });
        setModal({ mode: 'edit', id: info.event.id, patientName: props.patientName });
    }

    function onEventDrop(info) {
        // router.patch directly, not the shared useForm instance: the
        // form's data belongs to the create/edit modal and may be stale or
        // empty at the moment of a drag.
        router.patch(
            route('appointments.update', info.event.id),
            {
                start_time: info.event.startStr.slice(0, 19).replace('T', ' '),
                end_time: info.event.endStr.slice(0, 19).replace('T', ' '),
            },
            { onError: () => info.revert(), preserveScroll: true },
        );
    }

    function submit(event) {
        event.preventDefault();
        const done = {
            onSuccess: () => {
                setModal(null);
                refetch();
            },
        };

        // Send only the fields the open dialog actually shows. One useForm
        // instance backs all three modes, so in edit mode patient_id and
        // provider_id are blank strings — and a PATCH means "change the
        // fields I sent", so a blank is not a value to send. Create is
        // unaffected: its rules are plain `required`, which still fires on
        // an absent key.
        transform((payload) =>
            Object.fromEntries(
                Object.entries(payload).filter(([, value]) => value !== '' && value !== null),
            ),
        );

        if (modal.mode === 'create') {
            post(route('appointments.store'), done);
        } else {
            patch(route('appointments.update', modal.id), done);
        }
    }

    const modalTitle = {
        create: 'New appointment',
        edit: 'Edit appointment',
        confirm: `Confirm request — ${modal?.request?.patient_name ?? ''}`,
    }[modal?.mode];

    return (
        <AuthenticatedLayout
            title="Appointments"
            navBadges={{ 'appointments.index': requests.length }}
        >
            <Head title="Appointments" />

            <PageContainer>
                <PageHeader
                    title="Appointments"
                    description="Drag an event to move it, or drag on empty time to book."
                />

                {requests.length > 0 && (
                    <Card className="mb-5 border-amber-200">
                        <CardHeader
                            className="border-amber-200 bg-amber-50"
                            title={
                                <span className="flex items-center gap-2">
                                    Appointment requests
                                    <StatusBadge
                                        status={{ label: `${requests.length} pending`, tone: 'warning' }}
                                    />
                                </span>
                            }
                            description="From the public site. Confirming lets you set the real time and emails the patient; declining emails them too."
                        />
                        <ul className="divide-y divide-slate-200">
                            {requests.map((request) => (
                                <li
                                    key={request.id}
                                    className="flex flex-wrap items-start justify-between gap-3 px-4 py-3 sm:px-5"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold text-slate-900">
                                            {request.patient_name}
                                        </p>
                                        <p className="truncate text-xs text-slate-500">
                                            {request.patient_email}
                                            {request.patient_phone && ` · ${request.patient_phone}`}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-800">{request.service_interest}</p>
                                        <p className="text-xs text-slate-500">
                                            Prefers {formatDate(request.preferred_date)} (
                                            {request.preferred_time_of_day}) ·{' '}
                                            {request.dentist_preference ?? 'No dentist preference'}
                                        </p>
                                        {request.notes && (
                                            <p className="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                                {request.notes}
                                            </p>
                                        )}
                                    </div>

                                    <div className="flex shrink-0 gap-2">
                                        <Button
                                            size="sm"
                                            icon={Check}
                                            onClick={() => {
                                                clearErrors();
                                                setData({
                                                    patient_id: '',
                                                    provider_id: '',
                                                    start_time: '',
                                                    end_time: '',
                                                    type: 'checkup',
                                                    status: 'scheduled',
                                                });
                                                setModal({ mode: 'confirm', id: request.id, request });
                                            }}
                                        >
                                            Confirm
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            icon={X}
                                            onClick={() => setDeclining(request)}
                                        >
                                            Decline
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <Card className="overflow-hidden p-3 sm:p-4">
                    {feedError && (
                        <div
                            role="alert"
                            className="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"
                        >
                            <span>
                                The calendar could not load appointments ({feedError}). What is shown may be
                                out of date.
                            </span>
                            <Button variant="secondary" size="sm" onClick={refetch}>
                                Retry
                            </Button>
                        </div>
                    )}

                    <FullCalendar
                        ref={calendarRef}
                        plugins={[dayGridPlugin, timeGridPlugin, interactionPlugin]}
                        initialView="timeGridWeek"
                        headerToolbar={{
                            left: 'prev,next today',
                            center: 'title',
                            right: 'timeGridDay,timeGridWeek,dayGridMonth',
                        }}
                        height="auto"
                        // Clamped to clinic hours: the default 00:00–24:00
                        // window spent most of its height on time the clinic
                        // is shut, pushing the working day off-screen.
                        slotMinTime="08:00:00"
                        slotMaxTime="19:00:00"
                        expandRows
                        nowIndicator
                        allDaySlot={false}
                        stickyHeaderDates
                        selectable
                        editable
                        select={onSelect}
                        eventClick={onEventClick}
                        eventDrop={onEventDrop}
                        eventDidMount={(info) => {
                            const colour = EVENT_COLOURS[info.event.extendedProps.status];
                            if (!colour) return;
                            info.el.style.backgroundColor = colour.bg;
                            info.el.style.borderColor = colour.border;
                        }}
                        events={(fetchInfo, success, failure) => {
                            // Accept: application/json so a validation
                            // failure comes back as 422 JSON rather than a
                            // redirect to an HTML page, which would reach
                            // this callback as an unparseable body.
                            fetch(
                                route('appointments.events', {
                                    start: fetchInfo.startStr,
                                    end: fetchInfo.endStr,
                                }),
                                { headers: { Accept: 'application/json' } },
                            )
                                .then((response) => {
                                    if (!response.ok) throw new Error(`HTTP ${response.status}`);
                                    return response.json();
                                })
                                .then((events) => {
                                    setFeedError(null);
                                    success(events);
                                })
                                .catch((error) => {
                                    // Without this the calendar simply
                                    // renders empty, which is
                                    // indistinguishable from a clear day.
                                    setFeedError(error.message);
                                    failure(error);
                                });
                        }}
                    />

                    <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1.5 border-t border-slate-200 pt-3">
                        {['scheduled', 'checked_in', 'in_treatment', 'completed', 'cancelled', 'no_show'].map(
                            (status) => (
                                <li key={status} className="flex items-center gap-1.5 text-xs text-slate-600">
                                    <span
                                        className="inline-block h-2.5 w-2.5 rounded-sm"
                                        style={{ backgroundColor: EVENT_COLOURS[status].bg }}
                                        aria-hidden="true"
                                    />
                                    {appointmentStatus(status).label}
                                </li>
                            ),
                        )}
                    </ul>
                </Card>
            </PageContainer>

            <Modal
                as="form"
                onSubmit={submit}
                show={modal !== null}
                onClose={() => setModal(null)}
                closeable={!processing}
                title={modalTitle}
                width="lg"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModal(null)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : modal?.mode === 'confirm' ? 'Confirm & email' : 'Save'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    {modal?.mode === 'confirm' && (
                        <p className="rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
                            Requested {modal.request.service_interest} · prefers{' '}
                            {formatDate(modal.request.preferred_date)} ({modal.request.preferred_time_of_day})
                            {modal.request.dentist_preference && ` · ${modal.request.dentist_preference}`}
                        </p>
                    )}

                    {modal?.mode === 'create' && (
                        <SelectField
                            label="Patient"
                            required
                            value={data.patient_id}
                            onChange={(e) => setData('patient_id', e.target.value)}
                            error={errors.patient_id}
                        >
                            <option value="">Select a patient</option>
                            {patients.map((patient) => (
                                <option key={patient.id} value={patient.id}>
                                    {patient.last_name}, {patient.first_name}
                                </option>
                            ))}
                        </SelectField>
                    )}

                    {(modal?.mode === 'create' || modal?.mode === 'confirm') && (
                        <SelectField
                            label="Provider"
                            required
                            value={data.provider_id}
                            onChange={(e) => setData('provider_id', e.target.value)}
                            error={errors.provider_id}
                        >
                            <option value="">Select a provider</option>
                            {providers.map((provider) => (
                                <option key={provider.id} value={provider.id}>
                                    {provider.name}
                                </option>
                            ))}
                        </SelectField>
                    )}

                    <SelectField
                        label="Type"
                        value={data.type}
                        onChange={(e) => setData('type', e.target.value)}
                        error={errors.type}
                    >
                        {TYPES.map((type) => (
                            <option key={type} value={type}>
                                {type}
                            </option>
                        ))}
                    </SelectField>

                    {modal?.mode === 'confirm' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label="Start"
                                required
                                type="datetime-local"
                                value={data.start_time}
                                onChange={(e) => setData('start_time', e.target.value)}
                                error={errors.start_time}
                            />
                            <Field
                                label="End"
                                required
                                type="datetime-local"
                                value={data.end_time}
                                onChange={(e) => setData('end_time', e.target.value)}
                                error={errors.end_time}
                            />
                        </div>
                    )}

                    {modal?.mode === 'edit' && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Start"
                                    type="datetime-local"
                                    value={data.start_time}
                                    onChange={(e) => setData('start_time', e.target.value)}
                                    error={errors.start_time}
                                />
                                <Field
                                    label="End"
                                    type="datetime-local"
                                    value={data.end_time}
                                    onChange={(e) => setData('end_time', e.target.value)}
                                    error={errors.end_time}
                                />
                            </div>
                            <SelectField
                                label="Status"
                                value={data.status}
                                onChange={(e) => setData('status', e.target.value)}
                                error={errors.status}
                            >
                                {STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {appointmentStatus(status).label}
                                    </option>
                                ))}
                            </SelectField>
                        </>
                    )}
                </div>
            </Modal>

            <ConfirmDialog
                show={declining !== null}
                onClose={() => setDeclining(null)}
                onConfirm={() =>
                    router.patch(
                        route('appointments.update', declining.id),
                        { status: 'declined' },
                        {
                            preserveScroll: true,
                            onFinish: () => {
                                setDeclining(null);
                                refetch();
                            },
                        },
                    )
                }
                title={`Decline ${declining?.patient_name}'s request?`}
                confirmLabel="Decline and email"
                body="This emails the patient to tell them the request was not accepted, and cannot be undone — they would have to submit a new request."
            />
        </AuthenticatedLayout>
    );
}

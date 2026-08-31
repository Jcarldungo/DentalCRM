import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import { SelectField } from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { PageContainer, PageHeader } from '@/Components/UI/Page';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock, LogIn, Play, UserPlus, UserX } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { formatTime } from '../Patients/format';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const POLL_INTERVAL_MS = 15000;
const BOARD_PROPS = ['todaysSchedule', 'waiting', 'nowServing', 'completed'];

/**
 * Columns, in the order a visit moves through them. `emphasis` marks the
 * one column a front-desk member is actually watching: the board used to
 * give all four identical weight, so "who is in the chair right now" —
 * the single question the queue exists to answer — took as long to find
 * as "who finished an hour ago".
 */
const COLUMNS = [
    {
        key: 'todaysSchedule',
        title: 'Expected',
        hint: 'Scheduled, not yet arrived',
        accent: 'bg-slate-400',
    },
    {
        key: 'waiting',
        title: 'Waiting',
        hint: 'Checked in',
        accent: 'bg-violet-500',
        showWait: true,
    },
    {
        key: 'nowServing',
        title: 'Now serving',
        hint: 'In treatment',
        accent: 'bg-emerald-500',
        emphasis: true,
    },
    {
        key: 'completed',
        title: 'Completed',
        hint: 'Done for today',
        accent: 'bg-slate-300',
        muted: true,
    },
];

/**
 * How far past their appointment time this patient is.
 *
 * Measured from the scheduled start, not from check-in: there is no
 * checked_in_at column, and "how late are we running against what this
 * patient was told" is the number a front desk actually needs. A walk-in
 * is scheduled at the moment it is created, so this reads correctly for
 * those too.
 *
 * Recomputed on a tick rather than on render, so a board left open on a
 * front-desk screen keeps counting between the 15-second reloads.
 */
function waitedMinutes(startIso, now) {
    return Math.max(0, Math.round((now - new Date(startIso)) / 60000));
}

function formatWait(minutes) {
    if (minutes < 60) return `${minutes}m`;

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

function WaitChip({ minutes }) {
    const late = minutes >= 15;

    return (
        <span
            title={`${formatWait(minutes)} past their appointment time`}
            className={`tabular inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs font-medium ${
                late ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'
            }`}
        >
            <Clock className="h-3 w-3" aria-hidden="true" />
            <span className="sr-only">Waiting </span>
            {formatWait(minutes)}
            <span className="sr-only"> past their appointment time</span>
        </span>
    );
}

function QueueCard({ appointment, column, now, children }) {
    return (
        <Card
            as="li"
            className={`bg-white p-3 transition-colors ${
                column.emphasis ? 'border-emerald-200' : ''
            }`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <Link
                        href={route('patients.show', appointment.patient_id)}
                        className={`truncate text-sm font-semibold hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${
                            column.muted ? 'text-slate-500' : 'text-slate-900'
                        }`}
                    >
                        {appointment.patient_name}
                    </Link>
                    <p className="tabular mt-0.5 truncate text-xs text-slate-500">
                        {formatTime(appointment.start_time)} · {appointment.type ?? '—'}
                        {appointment.provider_name && ` · ${appointment.provider_name}`}
                    </p>
                </div>
                {column.showWait && <WaitChip minutes={waitedMinutes(appointment.start_time, now)} />}
            </div>

            {children && <div className="mt-2.5 flex flex-wrap gap-1.5">{children}</div>}
        </Card>
    );
}

function Column({ column, appointments, now, children }) {
    return (
        <section
            /* A board needs visible lanes. With no surface at all the four
               columns were four patches of white page and the cards had
               nothing to sit in; the tinted lane is what makes this read
               as a board rather than as four stacked lists. */
            className={`flex min-w-0 flex-col rounded-xl p-3 ${
                column.emphasis ? 'bg-emerald-50' : 'bg-slate-50'
            }`}
            aria-label={column.title}
        >
            <header
                className={`mb-3 flex items-center gap-2 border-b pb-2 ${
                    column.emphasis ? 'border-emerald-200' : 'border-slate-200'
                }`}
            >
                <span className={`h-2 w-2 shrink-0 rounded-full ${column.accent}`} aria-hidden="true" />
                <h2 className="text-sm font-semibold text-slate-900">{column.title}</h2>
                <span className="tabular ms-auto text-sm font-semibold text-slate-400">
                    {appointments.length}
                </span>
            </header>

            {appointments.length === 0 ? (
                <p className="py-8 text-center text-xs text-slate-400">{column.hint}</p>
            ) : (
                <ul className="space-y-2">{children}</ul>
            )}
        </section>
    );
}

export default function Index({ patients, providers, todaysSchedule, waiting, nowServing, completed }) {
    const [showWalkIn, setShowWalkIn] = useState(false);
    const [now, setNow] = useState(() => Date.now());
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        patient_id: '',
        provider_id: '',
        type: 'checkup',
    });

    const boards = { todaysSchedule, waiting, nowServing, completed };
    const pollRef = useRef(null);

    useEffect(() => {
        // Polling pauses while the tab is hidden: a board left open
        // overnight on a front-desk terminal otherwise issues ~5,700
        // requests before anyone touches it again.
        function tick() {
            if (document.visibilityState === 'visible') {
                router.reload({ only: BOARD_PROPS });
            }
            setNow(Date.now());
        }

        pollRef.current = setInterval(tick, POLL_INTERVAL_MS);
        document.addEventListener('visibilitychange', tick);

        return () => {
            clearInterval(pollRef.current);
            document.removeEventListener('visibilitychange', tick);
        };
    }, []);

    function setStatus(id, status) {
        router.patch(route('appointments.update', id), { status }, { preserveScroll: true });
    }

    function submitWalkIn(event) {
        event.preventDefault();
        post(route('queue.walkins.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowWalkIn(false);
            },
        });
    }

    const total = todaysSchedule.length + waiting.length + nowServing.length + completed.length;

    return (
        <AuthenticatedLayout
            title="Queue"
            actions={
                <Button size="sm" icon={UserPlus} onClick={() => { clearErrors(); setShowWalkIn(true); }}>
                    Walk-in
                </Button>
            }
        >
            <Head title="Queue" />

            <PageContainer>
                <PageHeader
                    title="Today's queue"
                    description={
                        total === 0
                            ? 'Nothing on the board today.'
                            : `${total} appointment${total === 1 ? '' : 's'} today · updates every 15 seconds`
                    }
                    actions={
                        <Button
                            icon={UserPlus}
                            onClick={() => { clearErrors(); setShowWalkIn(true); }}
                            className="hidden lg:inline-flex"
                        >
                            Add walk-in
                        </Button>
                    }
                />

                <div className="grid min-h-[16rem] gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {COLUMNS.map((column) => (
                        <Column key={column.key} column={column} appointments={boards[column.key]} now={now}>
                            {boards[column.key].map((appointment) => (
                                <QueueCard key={appointment.id} appointment={appointment} column={column} now={now}>
                                    {column.key === 'todaysSchedule' && (
                                        <>
                                            <Button
                                                size="xs"
                                                icon={LogIn}
                                                onClick={() => setStatus(appointment.id, 'checked_in')}
                                            >
                                                Check in
                                            </Button>
                                            <Button
                                                size="xs"
                                                variant="ghost"
                                                icon={UserX}
                                                onClick={() => setStatus(appointment.id, 'no_show')}
                                            >
                                                No-show
                                            </Button>
                                        </>
                                    )}
                                    {column.key === 'waiting' && (
                                        <Button
                                            size="xs"
                                            icon={Play}
                                            onClick={() => setStatus(appointment.id, 'in_treatment')}
                                        >
                                            Start treatment
                                        </Button>
                                    )}
                                    {column.key === 'nowServing' && (
                                        <Button
                                            size="xs"
                                            icon={CheckCircle2}
                                            onClick={() => setStatus(appointment.id, 'completed')}
                                        >
                                            Complete
                                        </Button>
                                    )}
                                </QueueCard>
                            ))}
                        </Column>
                    ))}
                </div>
            </PageContainer>

            <Modal
                as="form"
                onSubmit={submitWalkIn}
                show={showWalkIn}
                onClose={() => setShowWalkIn(false)}
                closeable={!processing}
                title="Add a walk-in"
                description="Lands straight in Waiting as a 30-minute block starting now."
                width="md"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowWalkIn(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Adding…' : 'Add to queue'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <SelectField
                        label="Patient"
                        required
                        value={data.patient_id}
                        onChange={(e) => setData('patient_id', e.target.value)}
                        error={errors.patient_id}
                        hint="Walk-ins must already have a patient record."
                    >
                        <option value="">Select a patient</option>
                        {patients.map((patient) => (
                            <option key={patient.id} value={patient.id}>
                                {patient.last_name}, {patient.first_name}
                            </option>
                        ))}
                    </SelectField>

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
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}

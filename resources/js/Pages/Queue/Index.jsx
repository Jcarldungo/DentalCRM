import { useEffect, useRef, useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['checkup', 'cleaning', 'procedure', 'other'];
const POLL_INTERVAL_MS = 15000;
const BOARD_PROPS = ['todaysSchedule', 'waiting', 'nowServing', 'completed'];

function formatTime(iso) {
    return new Date(iso).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
}

function Card({ appointment, children }) {
    return (
        <div className="rounded border bg-white p-3 text-sm shadow-sm">
            <div className="font-medium">{appointment.patient_name}</div>
            <div className="text-gray-500">
                {formatTime(appointment.start_time)} · {appointment.type} · {appointment.provider_name}
            </div>
            {children && <div className="mt-2 flex gap-2">{children}</div>}
        </div>
    );
}

function ActionButton({ onClick, children, variant = 'primary' }) {
    const className =
        variant === 'primary'
            ? 'rounded bg-gray-900 px-2 py-1 text-xs text-white'
            : 'rounded border px-2 py-1 text-xs text-gray-700';
    return (
        <button type="button" onClick={onClick} className={className}>
            {children}
        </button>
    );
}

function Column({ title, count, children }) {
    return (
        <div className="flex-1 min-w-[16rem]">
            <h3 className="mb-2 flex items-center gap-2 font-semibold">
                {title}
                <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                    {count}
                </span>
            </h3>
            <div className="space-y-2">{children}</div>
        </div>
    );
}

export default function Index({ patients, providers, todaysSchedule, waiting, nowServing, completed }) {
    const [showWalkInModal, setShowWalkInModal] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        patient_id: '',
        provider_id: '',
        type: 'checkup',
    });

    const pollRef = useRef(null);
    useEffect(() => {
        pollRef.current = setInterval(() => {
            router.reload({ only: BOARD_PROPS });
        }, POLL_INTERVAL_MS);

        return () => clearInterval(pollRef.current);
    }, []);

    function setStatus(id, status) {
        router.patch(route('appointments.update', id), { status }, { preserveScroll: true });
    }

    function submitWalkIn(e) {
        e.preventDefault();
        post(route('queue.walkins.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setShowWalkInModal(false);
            },
        });
    }

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold">Queue</h2>
                    <button
                        type="button"
                        onClick={() => setShowWalkInModal(true)}
                        className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                    >
                        Add Walk-in
                    </button>
                </div>
            }
        >
            <Head title="Queue" />

            <div className="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div className="flex flex-wrap gap-6">
                    <Column title="Today's Schedule" count={todaysSchedule.length}>
                        {todaysSchedule.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'checked_in')}>
                                    Check In
                                </ActionButton>
                                <ActionButton variant="secondary" onClick={() => setStatus(appointment.id, 'no_show')}>
                                    No-show
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Waiting" count={waiting.length}>
                        {waiting.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'in_treatment')}>
                                    Start Treatment
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Now Serving" count={nowServing.length}>
                        {nowServing.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment}>
                                <ActionButton onClick={() => setStatus(appointment.id, 'completed')}>
                                    Complete
                                </ActionButton>
                            </Card>
                        ))}
                    </Column>

                    <Column title="Completed" count={completed.length}>
                        {completed.map((appointment) => (
                            <Card key={appointment.id} appointment={appointment} />
                        ))}
                    </Column>
                </div>
            </div>

            {showWalkInModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4">
                    <form onSubmit={submitWalkIn} className="bg-white rounded p-6 w-full max-w-sm space-y-4">
                        <h3 className="font-semibold">Add walk-in</h3>

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

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowWalkInModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Add to queue
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

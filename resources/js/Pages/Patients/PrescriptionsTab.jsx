import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { formatDate, formatDateTime } from './format';

function medicationLine(rx) {
    return [
        `${rx.medication} ${rx.dosage}`,
        rx.frequency,
        rx.duration,
        rx.quantity,
    ]
        .filter(Boolean)
        .join(' · ');
}

function PrescriptionCard({ rx, onDiscontinue }) {
    const discontinued = rx.status === 'discontinued';

    return (
        <div className={`bg-white shadow rounded p-4 text-sm ${discontinued ? 'opacity-70' : ''}`}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <div className={`font-medium ${discontinued ? 'line-through' : ''}`}>
                        {medicationLine(rx)}
                    </div>
                    <div className="text-gray-500">
                        {rx.provider_name || '—'}
                        {rx.appointment_start_time && ` · linked to ${formatDateTime(rx.appointment_start_time)}`}
                    </div>
                </div>
                {!discontinued && (
                    <button type="button" onClick={onDiscontinue} className="text-sm text-blue-600 shrink-0">
                        Discontinue
                    </button>
                )}
            </div>
            {rx.instructions && <p className="mt-2">{rx.instructions}</p>}
            {discontinued && (
                <p className="mt-2 text-gray-600">
                    Discontinued on {formatDate(rx.discontinued_at)}
                    {rx.discontinued_reason && ` — ${rx.discontinued_reason}`}
                </p>
            )}
            <div className="mt-3 text-xs text-gray-400">
                Prescribed by {rx.creator_name} on {formatDate(rx.created_at)}
            </div>
        </div>
    );
}

export default function PrescriptionsTab({ patient, prescriptions, providers, appointments }) {
    const [showNewModal, setShowNewModal] = useState(false);
    const [discontinuingRx, setDiscontinuingRx] = useState(null);

    const newForm = useForm({
        medication: '',
        dosage: '',
        frequency: '',
        duration: '',
        quantity: '',
        provider_id: '',
        appointment_id: '',
        instructions: '',
    });

    const discontinueForm = useForm({
        discontinued_reason: '',
    });

    function openNew() {
        newForm.reset();
        newForm.clearErrors();
        setShowNewModal(true);
    }

    function submitNew(e) {
        e.preventDefault();
        newForm.post(route('prescriptions.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                newForm.reset();
                setShowNewModal(false);
            },
        });
    }

    function openDiscontinue(rx) {
        discontinueForm.reset();
        discontinueForm.clearErrors();
        setDiscontinuingRx(rx);
    }

    function submitDiscontinue(e) {
        e.preventDefault();
        discontinueForm.patch(route('prescriptions.update', { patient: patient.id, prescription: discontinuingRx.id }), {
            preserveScroll: true,
            onSuccess: () => {
                discontinueForm.reset();
                setDiscontinuingRx(null);
            },
        });
    }

    const active = prescriptions.filter((rx) => rx.status === 'active');
    const discontinued = prescriptions.filter((rx) => rx.status === 'discontinued');

    return (
        <div>
            <button
                type="button"
                onClick={openNew}
                className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
            >
                + New Prescription
            </button>

            <div className="space-y-6">
                <div>
                    <h4 className="mb-2 text-sm font-semibold text-gray-500">Active</h4>
                    <div className="space-y-3">
                        {active.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} onDiscontinue={() => openDiscontinue(rx)} />
                        ))}
                        {active.length === 0 && (
                            <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                No active prescriptions.
                            </div>
                        )}
                    </div>
                </div>
                <div>
                    <h4 className="mb-2 text-sm font-semibold text-gray-500">Discontinued</h4>
                    <div className="space-y-3">
                        {discontinued.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} onDiscontinue={() => {}} />
                        ))}
                        {discontinued.length === 0 && (
                            <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                No discontinued prescriptions.
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {showNewModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitNew} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">New prescription</h3>

                        <div>
                            <label className="block text-sm mb-1">Medication</label>
                            <input
                                type="text"
                                className="w-full border rounded px-3 py-2"
                                value={newForm.data.medication}
                                onChange={(e) => newForm.setData('medication', e.target.value)}
                            />
                            {newForm.errors.medication && <p className="text-sm text-red-600">{newForm.errors.medication}</p>}
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Dosage</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 500 mg"
                                    value={newForm.data.dosage}
                                    onChange={(e) => newForm.setData('dosage', e.target.value)}
                                />
                                {newForm.errors.dosage && <p className="text-sm text-red-600">{newForm.errors.dosage}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Frequency</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 3 times daily"
                                    value={newForm.data.frequency}
                                    onChange={(e) => newForm.setData('frequency', e.target.value)}
                                />
                                {newForm.errors.frequency && <p className="text-sm text-red-600">{newForm.errors.frequency}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Duration (optional)</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 7 days"
                                    value={newForm.data.duration}
                                    onChange={(e) => newForm.setData('duration', e.target.value)}
                                />
                                {newForm.errors.duration && <p className="text-sm text-red-600">{newForm.errors.duration}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Quantity (optional)</label>
                                <input
                                    type="text"
                                    className="w-full border rounded px-3 py-2"
                                    placeholder="e.g. 21 capsules"
                                    value={newForm.data.quantity}
                                    onChange={(e) => newForm.setData('quantity', e.target.value)}
                                />
                                {newForm.errors.quantity && <p className="text-sm text-red-600">{newForm.errors.quantity}</p>}
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Provider</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={newForm.data.provider_id}
                                    onChange={(e) => newForm.setData('provider_id', e.target.value)}
                                >
                                    <option value="">No provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                {newForm.errors.provider_id && <p className="text-sm text-red-600">{newForm.errors.provider_id}</p>}
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Link to appointment (optional)</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={newForm.data.appointment_id}
                                    onChange={(e) => newForm.setData('appointment_id', e.target.value)}
                                >
                                    <option value="">No linked appointment</option>
                                    {appointments.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                        </option>
                                    ))}
                                </select>
                                {newForm.errors.appointment_id && <p className="text-sm text-red-600">{newForm.errors.appointment_id}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Instructions (optional)</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={newForm.data.instructions}
                                onChange={(e) => newForm.setData('instructions', e.target.value)}
                            />
                            {newForm.errors.instructions && <p className="text-sm text-red-600">{newForm.errors.instructions}</p>}
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { newForm.clearErrors(); setShowNewModal(false); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={newForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {discontinuingRx && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitDiscontinue} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Discontinue: {discontinuingRx.medication}</h3>
                        <p className="text-sm text-gray-600">
                            The prescription stays on the record — this marks it no longer active.
                        </p>

                        <div>
                            <label className="block text-sm mb-1">Reason (optional)</label>
                            <input
                                type="text"
                                className="w-full border rounded px-3 py-2"
                                value={discontinueForm.data.discontinued_reason}
                                onChange={(e) => discontinueForm.setData('discontinued_reason', e.target.value)}
                            />
                            {discontinueForm.errors.discontinued_reason && (
                                <p className="text-sm text-red-600">{discontinueForm.errors.discontinued_reason}</p>
                            )}
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => { discontinueForm.clearErrors(); setDiscontinuingRx(null); }} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={discontinueForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Discontinue
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}

import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const TYPES = ['consultation', 'procedure', 'follow_up', 'other'];

function formatDateTime(iso) {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatDate(iso) {
    return new Date(iso).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

const TOOTH_CONDITIONS = ['healthy', 'caries', 'filling', 'crown', 'missing', 'extraction', 'root_canal', 'implant', 'other'];

const CONDITION_COLORS = {
    healthy: 'bg-green-100 text-green-800 border-green-300',
    caries: 'bg-red-100 text-red-800 border-red-300',
    filling: 'bg-blue-100 text-blue-800 border-blue-300',
    crown: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    missing: 'bg-gray-200 text-gray-500 border-gray-300',
    extraction: 'bg-gray-400 text-white border-gray-500',
    root_canal: 'bg-purple-100 text-purple-800 border-purple-300',
    implant: 'bg-teal-100 text-teal-800 border-teal-300',
    other: 'bg-orange-100 text-orange-800 border-orange-300',
};

const UNMARKED_COLOR = 'bg-white text-gray-400 border-gray-200';

const UPPER_TEETH = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16];
const LOWER_TEETH = [32, 31, 30, 29, 28, 27, 26, 25, 24, 23, 22, 21, 20, 19, 18, 17];

function currentConditionFor(toothConditions, toothNumber) {
    return toothConditions.find((c) => c.tooth_number === toothNumber) ?? null;
}

export default function Show({ patient, dentalRecords, toothConditions, providers, appointments }) {
    const [tab, setTab] = useState('overview');
    const [showEditModal, setShowEditModal] = useState(false);
    const [showRecordModal, setShowRecordModal] = useState(false);
    const [selectedTooth, setSelectedTooth] = useState(null);

    const patientForm = useForm({
        first_name: patient.first_name,
        last_name: patient.last_name,
        date_of_birth: patient.date_of_birth ?? '',
        phone: patient.phone ?? '',
        email: patient.email ?? '',
        emergency_contact_name: patient.emergency_contact_name ?? '',
        emergency_contact_phone: patient.emergency_contact_phone ?? '',
        notes: patient.notes ?? '',
        recall_interval_months: patient.recall_interval_months ?? '',
    });

    const recordForm = useForm({
        type: 'consultation',
        provider_id: '',
        appointment_id: '',
        examination: '',
        diagnosis: '',
        procedure: '',
        notes: '',
    });

    const toothForm = useForm({
        tooth_number: null,
        condition: 'healthy',
        notes: '',
        provider_id: '',
        appointment_id: '',
    });

    function openTooth(toothNumber) {
        toothForm.reset();
        toothForm.setData({
            tooth_number: toothNumber,
            condition: 'healthy',
            notes: '',
            provider_id: '',
            appointment_id: '',
        });
        setSelectedTooth(toothNumber);
    }

    function submitToothCondition(e) {
        e.preventDefault();
        toothForm.post(route('tooth-conditions.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                toothForm.reset();
                setSelectedTooth(null);
            },
        });
    }

    function submitPatientEdit(e) {
        e.preventDefault();
        patientForm.put(route('patients.update', patient.id), {
            onSuccess: () => setShowEditModal(false),
        });
    }

    function submitRecord(e) {
        e.preventDefault();
        recordForm.post(route('dental-records.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                recordForm.reset();
                setShowRecordModal(false);
            },
        });
    }

    const patientField = (label, name, type = 'text') => (
        <div>
            <label className="block text-sm mb-1">{label}</label>
            <input
                type={type}
                className="w-full border rounded px-3 py-2"
                value={patientForm.data[name]}
                onChange={(e) => patientForm.setData(name, e.target.value)}
            />
            {patientForm.errors[name] && <p className="text-sm text-red-600">{patientForm.errors[name]}</p>}
        </div>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{patient.first_name} {patient.last_name}</h2>}>
            <Head title={`${patient.first_name} ${patient.last_name}`} />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-6 flex gap-6 border-b">
                    <button
                        type="button"
                        onClick={() => setTab('overview')}
                        className={`pb-2 text-sm font-medium ${tab === 'overview' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Overview
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('records')}
                        className={`pb-2 text-sm font-medium ${tab === 'records' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Records
                    </button>
                    <button
                        type="button"
                        onClick={() => setTab('chart')}
                        className={`pb-2 text-sm font-medium ${tab === 'chart' ? 'border-b-2 border-gray-900 text-gray-900' : 'text-gray-500'}`}
                    >
                        Dental Chart
                    </button>
                </div>

                {tab === 'overview' && (
                    <div className="bg-white shadow rounded p-6">
                        <div className="mb-4 flex justify-end">
                            <button
                                type="button"
                                onClick={() => setShowEditModal(true)}
                                className="text-sm text-blue-600"
                            >
                                Edit
                            </button>
                        </div>
                        <dl className="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt className="text-gray-500">Date of birth</dt>
                                <dd>{patient.date_of_birth ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Phone</dt>
                                <dd>{patient.phone ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Email</dt>
                                <dd>{patient.email ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Recall interval (months)</dt>
                                <dd>{patient.recall_interval_months ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Emergency contact</dt>
                                <dd>{patient.emergency_contact_name ?? '—'}</dd>
                            </div>
                            <div>
                                <dt className="text-gray-500">Emergency contact phone</dt>
                                <dd>{patient.emergency_contact_phone ?? '—'}</dd>
                            </div>
                        </dl>
                        {patient.notes && (
                            <div className="mt-4">
                                <dt className="text-sm text-gray-500">Notes</dt>
                                <dd className="text-sm">{patient.notes}</dd>
                            </div>
                        )}
                    </div>
                )}

                {tab === 'records' && (
                    <div>
                        <button
                            type="button"
                            onClick={() => setShowRecordModal(true)}
                            className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
                        >
                            + New Record
                        </button>

                        <div className="space-y-3">
                            {dentalRecords.map((record) => (
                                <div key={record.id} className="bg-white shadow rounded p-4 text-sm">
                                    <div className="text-gray-500">
                                        {record.type.replace('_', ' ')}
                                        {record.provider_name && ` · ${record.provider_name}`}
                                        {record.appointment_start_time && ` · linked to ${formatDateTime(record.appointment_start_time)}`}
                                    </div>
                                    {record.examination && <p className="mt-2"><strong>Examination:</strong> {record.examination}</p>}
                                    {record.diagnosis && <p className="mt-2"><strong>Diagnosis:</strong> {record.diagnosis}</p>}
                                    {record.procedure && <p className="mt-2"><strong>Procedure:</strong> {record.procedure}</p>}
                                    {record.notes && <p className="mt-2"><strong>Notes:</strong> {record.notes}</p>}
                                    <div className="mt-3 text-xs text-gray-400">
                                        Logged by {record.creator_name} on {formatDate(record.created_at)}
                                    </div>
                                </div>
                            ))}
                            {dentalRecords.length === 0 && (
                                <div className="bg-white shadow rounded p-4 text-sm text-gray-500">
                                    No dental records yet.
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {tab === 'chart' && (
                    <div className="bg-white shadow rounded p-6">
                        <div className="mb-2 flex justify-center gap-1">
                            {UPPER_TEETH.map((n) => {
                                const current = currentConditionFor(toothConditions, n);
                                return (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => openTooth(n)}
                                        className={`w-9 h-9 rounded border text-xs font-medium ${current ? CONDITION_COLORS[current.condition] : UNMARKED_COLOR}`}
                                    >
                                        {n}
                                    </button>
                                );
                            })}
                        </div>
                        <div className="flex justify-center gap-1">
                            {LOWER_TEETH.map((n) => {
                                const current = currentConditionFor(toothConditions, n);
                                return (
                                    <button
                                        key={n}
                                        type="button"
                                        onClick={() => openTooth(n)}
                                        className={`w-9 h-9 rounded border text-xs font-medium ${current ? CONDITION_COLORS[current.condition] : UNMARKED_COLOR}`}
                                    >
                                        {n}
                                    </button>
                                );
                            })}
                        </div>

                        <div className="mt-6 flex flex-wrap justify-center gap-3 text-xs">
                            <div className="flex items-center gap-1">
                                <span className={`inline-block w-3 h-3 rounded border ${UNMARKED_COLOR}`} />
                                No history
                            </div>
                            {TOOTH_CONDITIONS.map((c) => (
                                <div key={c} className="flex items-center gap-1">
                                    <span className={`inline-block w-3 h-3 rounded border ${CONDITION_COLORS[c]}`} />
                                    {c.replace('_', ' ')}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>

            {showEditModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitPatientEdit} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Edit patient</h3>
                        <div className="grid grid-cols-2 gap-4">
                            {patientField('First name', 'first_name')}
                            {patientField('Last name', 'last_name')}
                            {patientField('Date of birth', 'date_of_birth', 'date')}
                            {patientField('Phone', 'phone')}
                            {patientField('Email', 'email', 'email')}
                            {patientField('Recall interval (months)', 'recall_interval_months', 'number')}
                            {patientField('Emergency contact name', 'emergency_contact_name')}
                            {patientField('Emergency contact phone', 'emergency_contact_phone')}
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={3}
                                value={patientForm.data.notes}
                                onChange={(e) => patientForm.setData('notes', e.target.value)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowEditModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={patientForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {showRecordModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submitRecord} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">New dental record</h3>

                        {recordForm.errors.clinical_content && (
                            <p className="text-sm text-red-600">{recordForm.errors.clinical_content}</p>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="block text-sm mb-1">Type</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={recordForm.data.type}
                                    onChange={(e) => recordForm.setData('type', e.target.value)}
                                >
                                    {TYPES.map((t) => <option key={t} value={t}>{t.replace('_', ' ')}</option>)}
                                </select>
                            </div>
                            <div>
                                <label className="block text-sm mb-1">Provider</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={recordForm.data.provider_id}
                                    onChange={(e) => recordForm.setData('provider_id', e.target.value)}
                                >
                                    <option value="">No provider</option>
                                    {providers.map((p) => (
                                        <option key={p.id} value={p.id}>{p.name}</option>
                                    ))}
                                </select>
                                {recordForm.errors.provider_id && <p className="text-sm text-red-600">{recordForm.errors.provider_id}</p>}
                            </div>
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Link to appointment (optional)</label>
                            <select
                                className="w-full border rounded px-3 py-2"
                                value={recordForm.data.appointment_id}
                                onChange={(e) => recordForm.setData('appointment_id', e.target.value)}
                            >
                                <option value="">No linked appointment</option>
                                {appointments.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                    </option>
                                ))}
                            </select>
                            {recordForm.errors.appointment_id && <p className="text-sm text-red-600">{recordForm.errors.appointment_id}</p>}
                        </div>

                        <div>
                            <label className="block text-sm mb-1">Examination</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.examination}
                                onChange={(e) => recordForm.setData('examination', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Diagnosis</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.diagnosis}
                                onChange={(e) => recordForm.setData('diagnosis', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Procedure</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.procedure}
                                onChange={(e) => recordForm.setData('procedure', e.target.value)}
                            />
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={2}
                                value={recordForm.data.notes}
                                onChange={(e) => recordForm.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowRecordModal(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button type="submit" disabled={recordForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            {selectedTooth !== null && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <div className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">Tooth {selectedTooth}</h3>

                        <div className="space-y-3">
                            {toothConditions.filter((c) => c.tooth_number === selectedTooth).map((c) => (
                                <div key={c.id} className="bg-gray-50 rounded p-3 text-sm">
                                    <div className="text-gray-500">
                                        {c.condition.replace('_', ' ')}
                                        {c.provider_name && ` · ${c.provider_name}`}
                                        {c.appointment_start_time && ` · linked to ${formatDateTime(c.appointment_start_time)}`}
                                    </div>
                                    {c.notes && <p className="mt-2">{c.notes}</p>}
                                    <div className="mt-2 text-xs text-gray-400">
                                        Logged by {c.creator_name} on {formatDate(c.created_at)}
                                    </div>
                                </div>
                            ))}
                            {toothConditions.filter((c) => c.tooth_number === selectedTooth).length === 0 && (
                                <div className="text-sm text-gray-500">No history for this tooth yet.</div>
                            )}
                        </div>

                        <form onSubmit={submitToothCondition} className="space-y-4 border-t pt-4">
                            <h4 className="text-sm font-semibold">+ Add entry</h4>
                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <label className="block text-sm mb-1">Condition</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={toothForm.data.condition}
                                        onChange={(e) => toothForm.setData('condition', e.target.value)}
                                    >
                                        {TOOTH_CONDITIONS.map((c) => <option key={c} value={c}>{c.replace('_', ' ')}</option>)}
                                    </select>
                                    {toothForm.errors.condition && <p className="text-sm text-red-600">{toothForm.errors.condition}</p>}
                                </div>
                                <div>
                                    <label className="block text-sm mb-1">Provider</label>
                                    <select
                                        className="w-full border rounded px-3 py-2"
                                        value={toothForm.data.provider_id}
                                        onChange={(e) => toothForm.setData('provider_id', e.target.value)}
                                    >
                                        <option value="">No provider</option>
                                        {providers.map((p) => (
                                            <option key={p.id} value={p.id}>{p.name}</option>
                                        ))}
                                    </select>
                                    {toothForm.errors.provider_id && <p className="text-sm text-red-600">{toothForm.errors.provider_id}</p>}
                                </div>
                            </div>

                            <div>
                                <label className="block text-sm mb-1">Link to appointment (optional)</label>
                                <select
                                    className="w-full border rounded px-3 py-2"
                                    value={toothForm.data.appointment_id}
                                    onChange={(e) => toothForm.setData('appointment_id', e.target.value)}
                                >
                                    <option value="">No linked appointment</option>
                                    {appointments.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.start_time ? formatDateTime(a.start_time) : 'Unscheduled'} — {a.type ?? 'request'}
                                        </option>
                                    ))}
                                </select>
                                {toothForm.errors.appointment_id && <p className="text-sm text-red-600">{toothForm.errors.appointment_id}</p>}
                            </div>

                            <div>
                                <label className="block text-sm mb-1">Notes</label>
                                <textarea
                                    className="w-full border rounded px-3 py-2"
                                    rows={2}
                                    value={toothForm.data.notes}
                                    onChange={(e) => toothForm.setData('notes', e.target.value)}
                                />
                            </div>

                            <div className="flex justify-end gap-2">
                                <button type="button" onClick={() => setSelectedTooth(null)} className="px-4 py-2 text-sm">
                                    Cancel
                                </button>
                                <button type="submit" disabled={toothForm.processing} className="rounded bg-gray-900 px-4 py-2 text-white text-sm">
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

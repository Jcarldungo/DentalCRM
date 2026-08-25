import { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

const emptyForm = {
    first_name: '',
    last_name: '',
    date_of_birth: '',
    phone: '',
    email: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    notes: '',
    recall_interval_months: '',
};

export default function Index({ patients }) {
    const [editing, setEditing] = useState(null);
    const [showModal, setShowModal] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm(emptyForm);

    function openCreate() {
        setEditing(null);
        reset();
        setData(emptyForm);
        setShowModal(true);
    }

    function openEdit(patient) {
        setEditing(patient);
        setData({
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
        setShowModal(true);
    }

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('patients.update', editing.id), { onSuccess: () => setShowModal(false) });
        } else {
            post(route('patients.store'), { onSuccess: () => setShowModal(false) });
        }
    }

    function destroy(patient) {
        if (confirm(`Remove ${patient.first_name} ${patient.last_name}?`)) {
            router.delete(route('patients.destroy', patient.id));
        }
    }

    const field = (label, name, type = 'text') => (
        <div>
            <label className="block text-sm mb-1">{label}</label>
            <input
                type={type}
                className="w-full border rounded px-3 py-2"
                value={data[name]}
                onChange={(e) => setData(name, e.target.value)}
            />
            {errors[name] && <p className="text-sm text-red-600">{errors[name]}</p>}
        </div>
    );

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Patients</h2>}>
            <Head title="Patients" />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <button onClick={openCreate} className="mb-4 rounded bg-gray-900 px-4 py-2 text-white">
                    Add patient
                </button>

                <div className="bg-white shadow rounded divide-y">
                    {patients.map((patient) => (
                        <div key={patient.id} className="flex items-center justify-between p-4">
                            <Link href={route('patients.show', patient.id)} className="hover:underline">
                                <div className="font-medium">{patient.first_name} {patient.last_name}</div>
                                <div className="text-sm text-gray-500">{patient.phone ?? patient.email ?? '—'}</div>
                            </Link>
                            <div className="flex items-center gap-3">
                                <button onClick={() => openEdit(patient)} className="text-sm text-blue-600">Edit</button>
                                <button onClick={() => destroy(patient)} className="text-sm text-red-600">Delete</button>
                            </div>
                        </div>
                    ))}
                    {patients.length === 0 && (
                        <div className="p-4 text-sm text-gray-500">No patients yet.</div>
                    )}
                </div>
            </div>

            {showModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4 overflow-y-auto">
                    <form onSubmit={submit} className="bg-white rounded p-6 w-full max-w-lg space-y-4 my-8">
                        <h3 className="font-semibold">{editing ? 'Edit patient' : 'Add patient'}</h3>
                        <div className="grid grid-cols-2 gap-4">
                            {field('First name', 'first_name')}
                            {field('Last name', 'last_name')}
                            {field('Date of birth', 'date_of_birth', 'date')}
                            {field('Phone', 'phone')}
                            {field('Email', 'email', 'email')}
                            {field('Recall interval (months)', 'recall_interval_months', 'number')}
                            {field('Emergency contact name', 'emergency_contact_name')}
                            {field('Emergency contact phone', 'emergency_contact_phone')}
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Notes</label>
                            <textarea
                                className="w-full border rounded px-3 py-2"
                                rows={3}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                            />
                        </div>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowModal(false)} className="px-4 py-2 text-sm">
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

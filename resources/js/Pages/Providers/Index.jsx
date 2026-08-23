import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Badge from '@/Components/Badge';

const emptyForm = { name: '', specialty: '', active: true };

export default function Index({ providers }) {
    const [editing, setEditing] = useState(null); // null = create mode, object = editing
    const [showModal, setShowModal] = useState(false);
    const { data, setData, post, put, processing, errors, reset } = useForm(emptyForm);

    function openCreate() {
        setEditing(null);
        reset();
        setData(emptyForm);
        setShowModal(true);
    }

    function openEdit(provider) {
        setEditing(provider);
        setData({ name: provider.name, specialty: provider.specialty ?? '', active: provider.active });
        setShowModal(true);
    }

    function submit(e) {
        e.preventDefault();
        if (editing) {
            put(route('providers.update', editing.id), { onSuccess: () => setShowModal(false) });
        } else {
            post(route('providers.store'), { onSuccess: () => setShowModal(false) });
        }
    }

    function destroy(provider) {
        if (confirm(`Remove ${provider.name}?`)) {
            router.delete(route('providers.destroy', provider.id));
        }
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Providers</h2>}>
            <Head title="Providers" />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
                <button onClick={openCreate} className="mb-4 rounded bg-gray-900 px-4 py-2 text-white">
                    Add provider
                </button>

                <div className="bg-white shadow rounded divide-y">
                    {providers.map((provider) => (
                        <div key={provider.id} className="flex items-center justify-between p-4">
                            <div>
                                <div className="font-medium">{provider.name}</div>
                                <div className="text-sm text-gray-500">{provider.specialty ?? '—'}</div>
                            </div>
                            <div className="flex items-center gap-3">
                                <Badge tone={provider.active ? 'ok' : 'muted'}>
                                    {provider.active ? 'Active' : 'Inactive'}
                                </Badge>
                                <button onClick={() => openEdit(provider)} className="text-sm text-blue-600">Edit</button>
                                <button onClick={() => destroy(provider)} className="text-sm text-red-600">Delete</button>
                            </div>
                        </div>
                    ))}
                    {providers.length === 0 && (
                        <div className="p-4 text-sm text-gray-500">No providers yet.</div>
                    )}
                </div>
            </div>

            {showModal && (
                <div className="fixed inset-0 bg-black/40 flex items-center justify-center p-4">
                    <form onSubmit={submit} className="bg-white rounded p-6 w-full max-w-sm space-y-4">
                        <h3 className="font-semibold">{editing ? 'Edit provider' : 'Add provider'}</h3>
                        <div>
                            <label className="block text-sm mb-1">Name</label>
                            <input
                                className="w-full border rounded px-3 py-2"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                            />
                            {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="block text-sm mb-1">Specialty</label>
                            <input
                                className="w-full border rounded px-3 py-2"
                                value={data.specialty}
                                onChange={(e) => setData('specialty', e.target.value)}
                            />
                        </div>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.active}
                                onChange={(e) => setData('active', e.target.checked)}
                            />
                            Active
                        </label>
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

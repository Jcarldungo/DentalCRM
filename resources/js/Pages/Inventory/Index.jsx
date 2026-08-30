import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate } from '@/Pages/Patients/format';

const FILTERS = [
    { key: 'all', label: 'All' },
    { key: 'low', label: 'Low stock' },
    { key: 'expiring', label: 'Expiring' },
    { key: 'archived', label: 'Archived' },
];

const CATEGORIES = ['consumable', 'instrument', 'ppe', 'medication', 'lab_material', 'office'];
const UNITS = ['box', 'piece', 'pair', 'pack', 'cartridge', 'bottle', 'tube', 'roll', 'ml'];

const STATUS_BADGE = {
    ok: 'bg-green-100 text-green-800 border-green-300',
    low: 'bg-amber-100 text-amber-800 border-amber-300',
    out: 'bg-red-100 text-red-800 border-red-300',
};

function categoryLabel(category) {
    return category.replace('_', ' ');
}

function emptyMessage(filter) {
    switch (filter) {
        case 'low':
            return 'No items are low on stock.';
        case 'expiring':
            return 'Nothing is expiring in the next 30 days.';
        case 'archived':
            return 'No archived items.';
        default:
            return 'No items yet. Add your first item to start tracking stock.';
    }
}

function Field({ label, error, children }) {
    return (
        <div>
            <label className="mb-1 block text-sm">{label}</label>
            {children}
            {error && <p className="text-sm text-red-600">{error}</p>}
        </div>
    );
}

export default function Index({ items, filters }) {
    const [showCreate, setShowCreate] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');

    function reload(next) {
        router.get(
            route('inventory.index'),
            { filter: next.filter ?? filters.filter, search: (next.search ?? search) || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function onSearch(value) {
        setSearch(value);
        reload({ search: value });
    }

    const form = useForm({
        name: '',
        category: 'consumable',
        unit: '',
        reorder_threshold: '',
        supplier: '',
        expiry_date: '',
        notes: '',
        opening_quantity: '',
    });

    function submitCreate(e) {
        e.preventDefault();
        form.post(route('inventory.store'), {
            onSuccess: () => {
                form.reset();
                setShowCreate(false);
            },
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inventory</h2>}>
            <Head title="Inventory" />

            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-2">
                        {FILTERS.map((f) => (
                            <button
                                key={f.key}
                                type="button"
                                onClick={() => reload({ filter: f.key })}
                                className={`rounded border px-3 py-1 text-sm ${
                                    filters.filter === f.key
                                        ? 'border-gray-900 bg-gray-900 text-white'
                                        : 'border-gray-300 text-gray-600'
                                }`}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowCreate(true)}
                        className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                    >
                        + New item
                    </button>
                </div>

                <input
                    type="search"
                    placeholder="Search by name"
                    value={search}
                    onChange={(e) => onSearch(e.target.value)}
                    className="w-full max-w-xs rounded border px-3 py-2 text-sm"
                />

                <div className="overflow-x-auto rounded border bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="border-b text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Item</th>
                                <th className="px-4 py-2">Category</th>
                                <th className="px-4 py-2 text-right">On hand</th>
                                <th className="px-4 py-2 text-right">Reorder at</th>
                                <th className="px-4 py-2">Status</th>
                                <th className="px-4 py-2">Expiry</th>
                                <th className="px-4 py-2">Supplier</th>
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.id} className="border-b last:border-0 hover:bg-gray-50">
                                    <td className="px-4 py-2">
                                        <Link href={route('inventory.show', item.id)} className="text-blue-600">
                                            {item.name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 capitalize text-gray-600">{categoryLabel(item.category)}</td>
                                    <td className="px-4 py-2 text-right">
                                        {item.on_hand} {item.unit}
                                    </td>
                                    <td className="px-4 py-2 text-right text-gray-500">{item.reorder_threshold}</td>
                                    <td className="px-4 py-2">
                                        <span
                                            className={`inline-block rounded border px-2 py-0.5 text-xs uppercase ${STATUS_BADGE[item.stock_status]}`}
                                        >
                                            {item.stock_status}
                                        </span>
                                    </td>
                                    <td className={`px-4 py-2 ${item.is_expiring_soon ? 'text-amber-700' : 'text-gray-500'}`}>
                                        {item.expiry_date ? formatDate(item.expiry_date) : '—'}
                                    </td>
                                    <td className="px-4 py-2 text-gray-500">{item.supplier ?? '—'}</td>
                                </tr>
                            ))}
                            {items.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-6 text-center text-gray-500">
                                        {emptyMessage(filters.filter)}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {showCreate && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitCreate} className="my-8 w-full max-w-lg space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">New item</h3>

                        <Field label="Name" error={form.errors.name}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Category" error={form.errors.category}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)}
                                >
                                    {CATEGORIES.map((c) => (
                                        <option key={c} value={c}>
                                            {categoryLabel(c)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Unit" error={form.errors.unit}>
                                <input
                                    type="text"
                                    list="inventory-units"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.unit}
                                    onChange={(e) => form.setData('unit', e.target.value)}
                                />
                                <datalist id="inventory-units">
                                    {UNITS.map((u) => (
                                        <option key={u} value={u} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Reorder threshold" error={form.errors.reorder_threshold}>
                                <input
                                    type="number"
                                    min="0"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.reorder_threshold}
                                    onChange={(e) => form.setData('reorder_threshold', e.target.value)}
                                />
                            </Field>
                            <Field label="Opening quantity" error={form.errors.opening_quantity}>
                                <input
                                    type="number"
                                    min="0"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.opening_quantity}
                                    onChange={(e) => form.setData('opening_quantity', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="Supplier (optional)" error={form.errors.supplier}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.supplier}
                                onChange={(e) => form.setData('supplier', e.target.value)}
                            />
                        </Field>

                        <Field label="Expiry date (optional)" error={form.errors.expiry_date}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={form.data.expiry_date}
                                onChange={(e) => form.setData('expiry_date', e.target.value)}
                            />
                        </Field>

                        <Field label="Notes (optional)" error={form.errors.notes}>
                            <textarea
                                rows={2}
                                className="w-full rounded border px-3 py-2"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    form.clearErrors();
                                    setShowCreate(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Create
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

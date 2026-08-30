import { Head, useForm, router } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatPeso } from '@/Pages/Patients/format';
import { CATEGORIES, UNITS, STATUS_BADGE, categoryLabel, Field, Dialog } from './shared';

const MOVEMENT_TYPES = ['received', 'consumed', 'adjustment', 'expired'];

const TYPE_BADGE = {
    received: 'bg-green-100 text-green-800',
    consumed: 'bg-gray-100 text-gray-700',
    adjustment: 'bg-blue-100 text-blue-800',
    expired: 'bg-red-100 text-red-800',
};

function signedQty(qty) {
    return qty > 0 ? `+${qty}` : `${qty}`;
}

export default function Show({ item }) {
    const [showEdit, setShowEdit] = useState(false);
    const [showMovement, setShowMovement] = useState(false);
    const [showArchive, setShowArchive] = useState(false);

    const editForm = useForm({
        name: item.name,
        category: item.category,
        unit: item.unit,
        reorder_threshold: String(item.reorder_threshold),
        supplier: item.supplier ?? '',
        expiry_date: item.expiry_date ?? '',
        notes: item.notes ?? '',
    });

    function submitEdit(e) {
        e.preventDefault();
        editForm.patch(route('inventory.update', item.id), {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    }

    const movementForm = useForm({
        type: 'received',
        quantity: '',
        direction: 'increase',
        unit_cost: '',
        occurred_on: '',
        reason: '',
    });

    function submitMovement(e) {
        e.preventDefault();
        movementForm.post(route('inventory-movements.store', item.id), {
            preserveScroll: true,
            onSuccess: () => {
                movementForm.reset();
                setShowMovement(false);
            },
        });
    }

    function setActive(active) {
        router.patch(
            route('inventory.update', item.id),
            { active },
            { preserveScroll: true, onSuccess: () => setShowArchive(false) },
        );
    }

    const isAdjustment = movementForm.data.type === 'adjustment';
    const isReceived = movementForm.data.type === 'received';

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{item.name}</h2>}>
            <Head title={item.name} />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                {!item.active && (
                    <div className="rounded border border-gray-300 bg-gray-100 p-3 text-sm text-gray-600">
                        This item is archived.
                    </div>
                )}

                <div className="rounded border bg-white p-4 shadow-sm">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-3xl font-semibold">
                                {item.on_hand} <span className="text-lg text-gray-500">{item.unit}</span>
                            </p>
                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                <span
                                    className={`inline-block rounded border px-2 py-0.5 text-xs uppercase ${STATUS_BADGE[item.stock_status]}`}
                                >
                                    {item.stock_status}
                                </span>
                                <span className="text-sm capitalize text-gray-500">
                                    {categoryLabel(item.category)} · reorder at {item.reorder_threshold}
                                </span>
                            </div>
                            {item.is_expiring_soon && item.expiry_date && (
                                <p className="mt-1 text-sm text-amber-700">Expiring {formatDate(item.expiry_date)}</p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={() => setShowMovement(true)}
                            className="rounded bg-gray-900 px-3 py-1.5 text-sm text-white"
                        >
                            Record movement
                        </button>
                    </div>
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Details</h3>
                        <button type="button" onClick={() => setShowEdit(true)} className="text-blue-600">
                            Edit details
                        </button>
                    </div>
                    <dl className="grid grid-cols-2 gap-2">
                        <div>
                            <dt className="text-gray-500">Supplier</dt>
                            <dd>{item.supplier ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Expiry date</dt>
                            <dd>{item.expiry_date ? formatDate(item.expiry_date) : '—'}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Reorder threshold</dt>
                            <dd>{item.reorder_threshold}</dd>
                        </div>
                        <div>
                            <dt className="text-gray-500">Created</dt>
                            <dd>
                                {formatDate(item.created_at)} by {item.creator_name}
                            </dd>
                        </div>
                    </dl>
                    {item.notes && <p className="mt-3 text-gray-600">Notes: {item.notes}</p>}
                    <div className="mt-3 border-t pt-3">
                        {item.active ? (
                            <button type="button" onClick={() => setShowArchive(true)} className="text-sm text-red-700">
                                Archive item
                            </button>
                        ) : (
                            <button type="button" onClick={() => setActive(true)} className="text-sm text-blue-600">
                                Restore item
                            </button>
                        )}
                    </div>
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <h3 className="mb-2 font-semibold">Movement history</h3>
                    {item.movements.length === 0 ? (
                        <p className="text-gray-500">No movements recorded.</p>
                    ) : (
                        <table className="w-full">
                            <thead className="text-left text-gray-500">
                                <tr>
                                    <th className="py-1">Date</th>
                                    <th className="py-1">Type</th>
                                    <th className="py-1 text-right">Qty</th>
                                    <th className="py-1 text-right">Unit cost</th>
                                    <th className="py-1">Reason</th>
                                    <th className="py-1">By</th>
                                </tr>
                            </thead>
                            <tbody>
                                {item.movements.map((movement) => (
                                    <tr key={movement.id} className="border-b last:border-0">
                                        <td className="py-2">{formatDate(movement.occurred_on)}</td>
                                        <td className="py-2">
                                            <span
                                                className={`inline-block rounded px-2 py-0.5 text-xs capitalize ${TYPE_BADGE[movement.type]}`}
                                            >
                                                {movement.type}
                                            </span>
                                        </td>
                                        <td
                                            className={`py-2 text-right ${movement.quantity < 0 ? 'text-red-700' : 'text-green-700'}`}
                                        >
                                            {signedQty(movement.quantity)}
                                        </td>
                                        <td className="py-2 text-right text-gray-500">
                                            {movement.unit_cost !== null ? formatPeso(movement.unit_cost) : '—'}
                                        </td>
                                        <td className="py-2 text-gray-500">{movement.reason ?? '—'}</td>
                                        <td className="py-2 text-gray-500">{movement.creator_name}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </div>
            </div>

            {showEdit && (
                <Dialog
                    onClose={() => {
                        editForm.clearErrors();
                        setShowEdit(false);
                    }}
                >
                    <form onSubmit={submitEdit} className="space-y-4">
                        <h3 className="font-semibold">Edit details</h3>
                        <Field label="Name" error={editForm.errors.name}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.name}
                                onChange={(e) => editForm.setData('name', e.target.value)}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Category" error={editForm.errors.category}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={editForm.data.category}
                                    onChange={(e) => editForm.setData('category', e.target.value)}
                                >
                                    {CATEGORIES.map((c) => (
                                        <option key={c} value={c}>
                                            {categoryLabel(c)}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Unit" error={editForm.errors.unit}>
                                <input
                                    type="text"
                                    list="inventory-units"
                                    className="w-full rounded border px-3 py-2"
                                    value={editForm.data.unit}
                                    onChange={(e) => editForm.setData('unit', e.target.value)}
                                />
                                <datalist id="inventory-units">
                                    {UNITS.map((u) => (
                                        <option key={u} value={u} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>
                        <Field label="Reorder threshold" error={editForm.errors.reorder_threshold}>
                            <input
                                type="number"
                                min="0"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.reorder_threshold}
                                onChange={(e) => editForm.setData('reorder_threshold', e.target.value)}
                            />
                        </Field>
                        <Field label="Supplier" error={editForm.errors.supplier}>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.supplier}
                                onChange={(e) => editForm.setData('supplier', e.target.value)}
                            />
                        </Field>
                        <Field label="Expiry date" error={editForm.errors.expiry_date}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.expiry_date}
                                onChange={(e) => editForm.setData('expiry_date', e.target.value)}
                            />
                        </Field>
                        <Field label="Notes" error={editForm.errors.notes}>
                            <textarea
                                rows={2}
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                            />
                        </Field>
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    editForm.clearErrors();
                                    setShowEdit(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={editForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Save
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}

            {showMovement && (
                <Dialog
                    onClose={() => {
                        movementForm.clearErrors();
                        setShowMovement(false);
                    }}
                >
                    <form onSubmit={submitMovement} className="space-y-4">
                        <h3 className="font-semibold">Record movement</h3>
                        <p className="text-sm text-gray-500">
                            On hand: {item.on_hand} {item.unit}
                        </p>

                        <div className="grid grid-cols-2 gap-4">
                            <Field label="Type" error={movementForm.errors.type}>
                                <select
                                    className="w-full rounded border px-3 py-2 capitalize"
                                    value={movementForm.data.type}
                                    onChange={(e) => movementForm.setData('type', e.target.value)}
                                >
                                    {MOVEMENT_TYPES.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Quantity" error={movementForm.errors.quantity}>
                                <input
                                    type="number"
                                    min="1"
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.quantity}
                                    onChange={(e) => movementForm.setData('quantity', e.target.value)}
                                />
                            </Field>
                        </div>

                        {isAdjustment && (
                            <Field label="Direction" error={movementForm.errors.direction}>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.direction}
                                    onChange={(e) => movementForm.setData('direction', e.target.value)}
                                >
                                    <option value="increase">Increase</option>
                                    <option value="decrease">Decrease</option>
                                </select>
                            </Field>
                        )}

                        {isReceived && (
                            <Field label="Unit cost (₱, optional)" error={movementForm.errors.unit_cost}>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={movementForm.data.unit_cost}
                                    onChange={(e) => movementForm.setData('unit_cost', e.target.value)}
                                />
                            </Field>
                        )}

                        <Field label="Date" error={movementForm.errors.occurred_on}>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={movementForm.data.occurred_on}
                                onChange={(e) => movementForm.setData('occurred_on', e.target.value)}
                            />
                        </Field>

                        <Field
                            label={isAdjustment ? 'Reason (required for adjustments)' : 'Reason (optional)'}
                            error={movementForm.errors.reason}
                        >
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={movementForm.data.reason}
                                onChange={(e) => movementForm.setData('reason', e.target.value)}
                            />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    movementForm.clearErrors();
                                    setShowMovement(false);
                                }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={movementForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Record
                            </button>
                        </div>
                    </form>
                </Dialog>
            )}

            {showArchive && (
                <Dialog onClose={() => setShowArchive(false)}>
                    <div className="space-y-4">
                        <h3 className="font-semibold">Archive this item?</h3>
                        <p className="text-sm text-gray-600">
                            It drops out of the active list and the dashboard alerts. Its history is kept and you can
                            restore it later.
                        </p>
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowArchive(false)} className="px-4 py-2 text-sm">
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={() => setActive(false)}
                                className="rounded border border-red-300 px-4 py-2 text-sm text-red-700"
                            >
                                Archive
                            </button>
                        </div>
                    </div>
                </Dialog>
            )}
        </AuthenticatedLayout>
    );
}

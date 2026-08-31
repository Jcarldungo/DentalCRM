import Button from '@/Components/UI/Button';
import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal, { ConfirmDialog } from '@/Components/UI/Modal';
import { DetailItem, PageContainer } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { movementType, stockStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { Archive, ArchiveRestore, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatPeso } from '@/Pages/Patients/format';
import { CATEGORIES, UNITS, categoryLabel } from './shared';

const MOVEMENT_TYPES = ['received', 'consumed', 'adjustment', 'expired'];

const signedQty = (quantity) => (quantity > 0 ? `+${quantity}` : `${quantity}`);

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

    const movementForm = useForm({
        type: 'received',
        quantity: '',
        direction: 'increase',
        unit_cost: '',
        occurred_on: '',
        reason: '',
    });

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
        <AuthenticatedLayout title={item.name}>
            <Head title={item.name} />

            <PageContainer className="max-w-4xl">
                {!item.active && (
                    <div
                        role="status"
                        className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-600"
                    >
                        <span>
                            This item is archived. It is hidden from the default list and dashboard alerts, and
                            stock can only be run down, not added.
                        </span>
                        <Button variant="secondary" size="sm" icon={ArchiveRestore} onClick={() => setActive(true)}>
                            Restore
                        </Button>
                    </div>
                )}

                <Card className="mb-5">
                    <div className="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5">
                        <div className="min-w-0">
                            <h1 className="text-xl font-semibold tracking-tight text-slate-900">{item.name}</h1>
                            <p className="mt-0.5 text-sm capitalize text-slate-500">
                                {categoryLabel(item.category)} · reorder at {item.reorder_threshold} {item.unit}
                            </p>
                        </div>

                        <div className="text-end">
                            <p className="tabular text-3xl font-semibold text-slate-900">
                                {item.on_hand} <span className="text-base font-normal text-slate-500">{item.unit}</span>
                            </p>
                            <div className="mt-1 flex flex-wrap justify-end gap-1.5">
                                <StatusBadge status={stockStatus(item.stock_status)} />
                                {item.is_expiring_soon && item.expiry_date && (
                                    <StatusBadge
                                        status={{ label: `Expires ${formatDate(item.expiry_date)}`, tone: 'warning' }}
                                    />
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                        <Button icon={Plus} onClick={() => { movementForm.clearErrors(); setShowMovement(true); }}>
                            Record movement
                        </Button>
                        <Button
                            variant="secondary"
                            icon={Pencil}
                            onClick={() => { editForm.clearErrors(); setShowEdit(true); }}
                        >
                            Edit details
                        </Button>
                        {item.active && (
                            <Button variant="danger" icon={Archive} className="ms-auto" onClick={() => setShowArchive(true)}>
                                Archive
                            </Button>
                        )}
                    </div>
                </Card>

                <div className="space-y-5">
                    <Card>
                        <CardHeader title="Details" />
                        <CardBody>
                            <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <DetailItem label="Supplier">{item.supplier}</DetailItem>
                                <DetailItem label="Expiry date">
                                    {item.expiry_date ? formatDate(item.expiry_date) : null}
                                </DetailItem>
                                <DetailItem label="Reorder threshold">
                                    <span className="tabular">
                                        {item.reorder_threshold} {item.unit}
                                    </span>
                                </DetailItem>
                                <DetailItem label="Created">
                                    {formatDate(item.created_at)} by {item.creator_name}
                                </DetailItem>
                            </dl>
                            {item.notes && (
                                <p className="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm leading-relaxed text-slate-700">
                                    {item.notes}
                                </p>
                            )}
                        </CardBody>
                    </Card>

                    <Card className="overflow-hidden">
                        <CardHeader
                            title="Movement history"
                            description="Append-only. On-hand is the signed sum of this ledger — it is never stored."
                        />
                        {item.movements.length === 0 ? (
                            <CardBody className="text-sm text-slate-500">No movements recorded.</CardBody>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[40rem] text-sm">
                                    <caption className="sr-only">Stock movements for {item.name}</caption>
                                    <thead>
                                        <tr className="border-b border-slate-200 text-left">
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600 sm:px-5">
                                                Date
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600">
                                                Type
                                            </th>
                                            <th scope="col" className="px-4 py-2 text-right font-medium text-slate-600">
                                                Qty
                                            </th>
                                            <th scope="col" className="px-4 py-2 text-right font-medium text-slate-600">
                                                Unit cost
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600">
                                                Reason
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600 sm:px-5">
                                                By
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {item.movements.map((movement) => (
                                            <tr key={movement.id}>
                                                <td className="tabular px-4 py-2.5 text-slate-700 sm:px-5">
                                                    {formatDate(movement.occurred_on)}
                                                </td>
                                                <td className="px-4 py-2.5">
                                                    <StatusBadge status={movementType(movement.type)} />
                                                </td>
                                                <td
                                                    className={`tabular px-4 py-2.5 text-right font-medium ${
                                                        movement.quantity < 0 ? 'text-rose-700' : 'text-emerald-700'
                                                    }`}
                                                >
                                                    {signedQty(movement.quantity)}
                                                </td>
                                                <td className="tabular px-4 py-2.5 text-right text-slate-500">
                                                    {movement.unit_cost !== null ? formatPeso(movement.unit_cost) : '—'}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-500">{movement.reason ?? '—'}</td>
                                                <td className="px-4 py-2.5 text-slate-500 sm:px-5">
                                                    {movement.creator_name}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Card>
                </div>
            </PageContainer>

            <Modal
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    editForm.patch(route('inventory.update', item.id), {
                        preserveScroll: true,
                        onSuccess: () => setShowEdit(false),
                    });
                }}
                show={showEdit}
                onClose={() => setShowEdit(false)}
                closeable={!editForm.processing}
                title="Edit item details"
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowEdit(false)} disabled={editForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={editForm.processing}>
                            {editForm.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field
                        label="Name"
                        required
                        value={editForm.data.name}
                        onChange={(e) => editForm.setData('name', e.target.value)}
                        error={editForm.errors.name}
                    />
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Category"
                            value={editForm.data.category}
                            onChange={(e) => editForm.setData('category', e.target.value)}
                            error={editForm.errors.category}
                        >
                            {CATEGORIES.map((category) => (
                                <option key={category} value={category}>
                                    {categoryLabel(category)}
                                </option>
                            ))}
                        </SelectField>
                        <div>
                            <Field
                                label="Unit"
                                required
                                list="inventory-units-edit"
                                value={editForm.data.unit}
                                onChange={(e) => editForm.setData('unit', e.target.value)}
                                error={editForm.errors.unit}
                            />
                            <datalist id="inventory-units-edit">
                                {UNITS.map((unit) => (
                                    <option key={unit} value={unit} />
                                ))}
                            </datalist>
                        </div>
                        <Field
                            label="Reorder threshold"
                            required
                            type="number"
                            min="0"
                            inputClassName="tabular"
                            value={editForm.data.reorder_threshold}
                            onChange={(e) => editForm.setData('reorder_threshold', e.target.value)}
                            error={editForm.errors.reorder_threshold}
                        />
                        <Field
                            label="Expiry date"
                            type="date"
                            value={editForm.data.expiry_date}
                            onChange={(e) => editForm.setData('expiry_date', e.target.value)}
                            error={editForm.errors.expiry_date}
                        />
                        <Field
                            label="Supplier"
                            className="sm:col-span-2"
                            value={editForm.data.supplier}
                            onChange={(e) => editForm.setData('supplier', e.target.value)}
                            error={editForm.errors.supplier}
                        />
                    </div>
                    <TextareaField
                        label="Notes"
                        rows={2}
                        value={editForm.data.notes}
                        onChange={(e) => editForm.setData('notes', e.target.value)}
                        error={editForm.errors.notes}
                    />
                </div>
            </Modal>

            <Modal
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    movementForm.post(route('inventory-movements.store', item.id), {
                        preserveScroll: true,
                        onSuccess: () => {
                            movementForm.reset();
                            setShowMovement(false);
                        },
                    });
                }}
                show={showMovement}
                onClose={() => setShowMovement(false)}
                closeable={!movementForm.processing}
                title="Record a stock movement"
                description={`${item.on_hand} ${item.unit} on hand. Movements are append-only and cannot drive stock below zero.`}
                width="lg"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setShowMovement(false)}
                            disabled={movementForm.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={movementForm.processing}>
                            {movementForm.processing ? 'Recording…' : 'Record movement'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Type"
                            value={movementForm.data.type}
                            onChange={(e) => movementForm.setData('type', e.target.value)}
                            error={movementForm.errors.type}
                        >
                            {MOVEMENT_TYPES.map((type) => (
                                <option key={type} value={type}>
                                    {movementType(type).label}
                                </option>
                            ))}
                        </SelectField>
                        <Field
                            label="Quantity"
                            required
                            type="number"
                            min="1"
                            inputClassName="tabular"
                            value={movementForm.data.quantity}
                            onChange={(e) => movementForm.setData('quantity', e.target.value)}
                            error={movementForm.errors.quantity}
                            hint={`Whole ${item.unit} only.`}
                        />

                        {isAdjustment && (
                            <SelectField
                                label="Direction"
                                value={movementForm.data.direction}
                                onChange={(e) => movementForm.setData('direction', e.target.value)}
                                error={movementForm.errors.direction}
                            >
                                <option value="increase">Increase</option>
                                <option value="decrease">Decrease</option>
                            </SelectField>
                        )}

                        {isReceived && (
                            <Field
                                label="Unit cost (₱)"
                                type="number"
                                min="0"
                                step="0.01"
                                inputClassName="tabular"
                                value={movementForm.data.unit_cost}
                                onChange={(e) => movementForm.setData('unit_cost', e.target.value)}
                                error={movementForm.errors.unit_cost}
                            />
                        )}

                        <Field
                            label="Date"
                            type="date"
                            value={movementForm.data.occurred_on}
                            onChange={(e) => movementForm.setData('occurred_on', e.target.value)}
                            error={movementForm.errors.occurred_on}
                            hint="Defaults to today. Cannot be in the future."
                        />
                    </div>

                    <Field
                        label="Reason"
                        required={isAdjustment}
                        value={movementForm.data.reason}
                        onChange={(e) => movementForm.setData('reason', e.target.value)}
                        error={movementForm.errors.reason}
                        hint={
                            isAdjustment
                                ? 'Required — an adjustment with no reason is unauditable.'
                                : 'Optional.'
                        }
                    />
                </div>
            </Modal>

            <ConfirmDialog
                show={showArchive}
                onClose={() => setShowArchive(false)}
                onConfirm={() => setActive(false)}
                title={`Archive ${item.name}?`}
                confirmLabel="Archive item"
                body="It drops out of the active list and the dashboard alerts, and no more stock can be added to it. Its ledger is kept, remaining stock can still be consumed or written off, and it can be restored at any time."
            />
        </AuthenticatedLayout>
    );
}

import { useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { formatDate, formatPeso } from './format';

// planned / scheduled / in_progress / completed — a treatment worth putting
// on a bill. Mirrors InvoiceController::linkableTreatmentItems() (see
// CLAUDE.md "Known gaps").
const BILLABLE_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress', 'completed'];

const STATUS_BADGE = {
    draft: 'bg-gray-100 text-gray-700 border-gray-300',
    issued: 'bg-blue-100 text-blue-800 border-blue-300',
    paid: 'bg-green-100 text-green-800 border-green-300',
    void: 'bg-gray-200 text-gray-500 border-gray-300 line-through',
};

function statusLabel(invoice) {
    if (invoice.is_paid) return 'paid';
    return invoice.status;
}

const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

export default function BillingTab({ patient, invoices, treatmentPlanItems }) {
    const [showNewModal, setShowNewModal] = useState(false);

    const billable = treatmentPlanItems.filter((tpi) =>
        BILLABLE_TREATMENT_STATUSES.includes(tpi.status),
    );

    const form = useForm({
        patient_id: patient.id,
        items: [{ ...BLANK_LINE }],
        discount_amount: '',
        notes: '',
    });

    function openNew() {
        form.reset();
        form.clearErrors();
        form.setData({
            patient_id: patient.id,
            items: [{ ...BLANK_LINE }],
            discount_amount: '',
            notes: '',
        });
        setShowNewModal(true);
    }

    function setLine(index, patch) {
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    }

    function linkTreatment(index, tpiId) {
        if (!tpiId) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }
        const tpi = billable.find((t) => String(t.id) === String(tpiId));
        setLine(index, {
            treatment_plan_item_id: tpiId,
            description: tpi ? tpi.treatment : form.data.items[index].description,
            amount: tpi ? tpi.estimated_cost : form.data.items[index].amount,
        });
    }

    function addLine() {
        form.setData('items', [...form.data.items, { ...BLANK_LINE }]);
    }

    function removeLine(index) {
        if (form.data.items.length === 1) return;
        form.setData('items', form.data.items.filter((_, i) => i !== index));
    }

    function submit(e) {
        e.preventDefault();
        form.post(route('invoices.store'), {
            onSuccess: () => setShowNewModal(false),
        });
    }

    const totalBilled = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.total, 0);
    const totalPaid = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.amount_paid, 0);
    const totalOutstanding = invoices
        .filter((i) => i.status !== 'void')
        .reduce((sum, i) => sum + i.balance, 0);

    return (
        <div>
            <div className="mb-4 flex flex-wrap gap-6 rounded bg-white p-4 text-sm shadow">
                <div>
                    <div className="text-gray-500">Billed</div>
                    <div className="font-medium">{formatPeso(totalBilled)}</div>
                </div>
                <div>
                    <div className="text-gray-500">Paid</div>
                    <div className="font-medium">{formatPeso(totalPaid)}</div>
                </div>
                <div>
                    <div className="text-gray-500">Outstanding</div>
                    <div className="font-medium">{formatPeso(totalOutstanding)}</div>
                </div>
            </div>

            <button
                type="button"
                onClick={openNew}
                className="mb-4 rounded bg-gray-900 px-4 py-2 text-white"
            >
                + New Invoice
            </button>

            <div className="space-y-2">
                {invoices.map((invoice) => (
                    <Link
                        key={invoice.id}
                        href={route('invoices.show', invoice.id)}
                        className="block rounded border bg-white p-4 text-sm shadow-sm hover:bg-gray-50"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="font-medium">{invoice.number}</span>
                            <span
                                className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[statusLabel(invoice)]}`}
                            >
                                {statusLabel(invoice)}
                            </span>
                        </div>
                        <div className="mt-1 flex flex-wrap gap-4 text-gray-500">
                            <span>{formatDate(invoice.created_at)}</span>
                            <span>Total {formatPeso(invoice.total)}</span>
                            <span>Balance {formatPeso(invoice.balance)}</span>
                        </div>
                    </Link>
                ))}
                {invoices.length === 0 && (
                    <div className="rounded border bg-white p-4 text-sm text-gray-500 shadow-sm">
                        No invoices for this patient yet.
                    </div>
                )}
            </div>

            {showNewModal && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submit} className="my-8 w-full max-w-2xl space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">New invoice</h3>

                        <div className="space-y-3">
                            {form.data.items.map((line, index) => (
                                <div key={index} className="rounded border p-3">
                                    <div className="mb-2">
                                        <label className="mb-1 block text-sm">Link to treatment (optional)</label>
                                        <select
                                            className="w-full rounded border px-3 py-2"
                                            value={line.treatment_plan_item_id}
                                            onChange={(e) => linkTreatment(index, e.target.value)}
                                        >
                                            <option value="">Not linked</option>
                                            {billable.map((tpi) => (
                                                <option key={tpi.id} value={tpi.id}>
                                                    {tpi.treatment}
                                                    {tpi.tooth_number ? ` · tooth ${tpi.tooth_number}` : ''}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="col-span-2">
                                            <label className="mb-1 block text-sm">Description</label>
                                            <input
                                                type="text"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.description}
                                                onChange={(e) => setLine(index, { description: e.target.value })}
                                            />
                                            {form.errors[`items.${index}.description`] && (
                                                <p className="text-sm text-red-600">
                                                    {form.errors[`items.${index}.description`]}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm">Amount (₱)</label>
                                            <input
                                                type="number"
                                                min="0"
                                                max="99999999.99"
                                                step="0.01"
                                                className="w-full rounded border px-3 py-2"
                                                value={line.amount}
                                                onChange={(e) => setLine(index, { amount: e.target.value })}
                                            />
                                            {form.errors[`items.${index}.amount`] && (
                                                <p className="text-sm text-red-600">
                                                    {form.errors[`items.${index}.amount`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    {form.data.items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() => removeLine(index)}
                                            className="mt-2 text-sm text-red-600"
                                        >
                                            Remove line
                                        </button>
                                    )}
                                </div>
                            ))}
                            {form.errors.items && <p className="text-sm text-red-600">{form.errors.items}</p>}
                            <button type="button" onClick={addLine} className="text-sm text-blue-600">
                                + Add line
                            </button>
                        </div>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1 block text-sm">Discount (₱)</label>
                                <input
                                    type="number"
                                    min="0"
                                    max="99999999.99"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={form.data.discount_amount}
                                    onChange={(e) => form.setData('discount_amount', e.target.value)}
                                />
                                {form.errors.discount_amount && (
                                    <p className="text-sm text-red-600">{form.errors.discount_amount}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Notes</label>
                            <textarea
                                className="w-full rounded border px-3 py-2"
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { form.clearErrors(); setShowNewModal(false); }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={form.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Create draft
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}

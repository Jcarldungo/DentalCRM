import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatDateTime, formatPeso } from '@/Pages/Patients/format';

const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other'];

const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

function methodLabel(method) {
    return method.replace('_', ' ');
}

export default function Show({ invoice, treatmentPlanItems }) {
    const [showEdit, setShowEdit] = useState(false);
    const [showPayment, setShowPayment] = useState(false);

    const isDraft = invoice.status === 'draft';
    const isIssued = invoice.status === 'issued';
    const isVoid = invoice.status === 'void';

    const transition = useForm({ status: '' });

    function move(status) {
        transition.transform(() => ({ status }));
        transition.patch(route('invoices.update', invoice.id), { preserveScroll: true });
    }

    const editForm = useForm({
        items: invoice.items.length
            ? invoice.items.map((item) => ({
                  description: item.description,
                  amount: item.amount,
                  treatment_plan_item_id: item.treatment_plan_item_id ?? '',
              }))
            : [{ ...BLANK_LINE }],
        discount_amount: invoice.discount_amount || '',
        notes: invoice.notes ?? '',
    });

    function setLine(index, patch) {
        editForm.setData(
            'items',
            editForm.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    }

    function linkTreatment(index, tpiId) {
        if (!tpiId) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }
        const tpi = treatmentPlanItems.find((t) => String(t.id) === String(tpiId));
        setLine(index, {
            treatment_plan_item_id: tpiId,
            description: tpi ? tpi.treatment : editForm.data.items[index].description,
            amount: tpi ? tpi.estimated_cost : editForm.data.items[index].amount,
        });
    }

    function submitEdit(e) {
        e.preventDefault();
        editForm.patch(route('invoices.update', invoice.id), {
            preserveScroll: true,
            onSuccess: () => setShowEdit(false),
        });
    }

    const paymentForm = useForm({
        amount: invoice.balance > 0 ? invoice.balance : '',
        method: 'cash',
        paid_on: '',
        reference: '',
        note: '',
    });

    function submitPayment(e) {
        e.preventDefault();
        paymentForm.post(route('invoice-payments.store', invoice.id), {
            preserveScroll: true,
            onSuccess: () => {
                paymentForm.reset();
                setShowPayment(false);
            },
        });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{invoice.number}</h2>}>
            <Head title={invoice.number} />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Link href={route('patients.show', invoice.patient.id)} className="font-medium text-blue-600">
                            {invoice.patient.full_name}
                        </Link>
                        <span className="text-gray-500">
                            {invoice.is_paid ? 'paid' : invoice.status}
                        </span>
                    </div>
                    <div className="mt-1 text-gray-500">
                        Created {formatDate(invoice.created_at)} by {invoice.creator_name}
                        {invoice.issued_at && ` · issued ${formatDateTime(invoice.issued_at)}`}
                        {invoice.voided_at && ` · voided ${formatDateTime(invoice.voided_at)}`}
                    </div>
                </div>

                {isVoid && (
                    <div className="rounded border border-gray-300 bg-gray-100 p-3 text-sm text-gray-600">
                        This invoice has been voided.
                    </div>
                )}
                {invoice.is_paid && (
                    <div className="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
                        Paid in full.
                    </div>
                )}

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Line items</h3>
                        {isDraft && (
                            <button type="button" onClick={() => setShowEdit(true)} className="text-sm text-blue-600">
                                Edit
                            </button>
                        )}
                    </div>
                    <table className="w-full">
                        <tbody>
                            {invoice.items.map((item) => (
                                <tr key={item.id} className="border-b last:border-0">
                                    <td className="py-2">
                                        {item.description}
                                        {item.treatment_plan_item_label && (
                                            <span className="text-gray-400"> · {item.treatment_plan_item_label}</span>
                                        )}
                                        {item.provider_name && (
                                            <span className="text-gray-400"> · {item.provider_name}</span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right">{formatPeso(item.amount)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <div className="mt-3 space-y-1 border-t pt-3 text-right">
                        <div>Subtotal: {formatPeso(invoice.subtotal)}</div>
                        {invoice.discount_amount > 0 && <div>Discount: −{formatPeso(invoice.discount_amount)}</div>}
                        <div className="font-semibold">Total: {formatPeso(invoice.total)}</div>
                    </div>
                    {invoice.notes && <p className="mt-3 text-gray-600">Notes: {invoice.notes}</p>}
                </div>

                <div className="rounded border bg-white p-4 text-sm shadow-sm">
                    <div className="mb-2 flex items-center justify-between">
                        <h3 className="font-semibold">Payments</h3>
                        {isIssued && invoice.balance > 0 && (
                            <button type="button" onClick={() => setShowPayment(true)} className="text-sm text-blue-600">
                                Record payment
                            </button>
                        )}
                    </div>
                    {invoice.payments.length === 0 ? (
                        <p className="text-gray-500">No payments recorded.</p>
                    ) : (
                        <table className="w-full">
                            <tbody>
                                {invoice.payments.map((payment) => (
                                    <tr key={payment.id} className="border-b last:border-0">
                                        <td className="py-2">{formatDate(payment.paid_on)}</td>
                                        <td className="py-2 capitalize">{methodLabel(payment.method)}</td>
                                        <td className="py-2 text-gray-400">{payment.reference}</td>
                                        <td className="py-2 text-right">{formatPeso(payment.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                    <div className="mt-3 space-y-1 border-t pt-3 text-right">
                        <div>Paid: {formatPeso(invoice.amount_paid)}</div>
                        <div className="font-semibold">Balance due: {formatPeso(invoice.balance)}</div>
                    </div>
                </div>

                {(isDraft || (isIssued && invoice.payments.length === 0)) && (
                    <div className="flex flex-wrap gap-2">
                        {isDraft && (
                            <button
                                type="button"
                                onClick={() => move('issued')}
                                disabled={transition.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Issue invoice
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={() => move('void')}
                            disabled={transition.processing}
                            className="rounded border border-red-300 px-4 py-2 text-sm text-red-700"
                        >
                            Void
                        </button>
                        {transition.errors.status && (
                            <p className="w-full text-sm text-red-600">{transition.errors.status}</p>
                        )}
                    </div>
                )}
            </div>

            {showEdit && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitEdit} className="my-8 w-full max-w-2xl space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">Edit invoice</h3>

                        <div className="space-y-3">
                            {editForm.data.items.map((line, index) => (
                                <div key={index} className="rounded border p-3">
                                    <div className="mb-2">
                                        <label className="mb-1 block text-sm">Link to treatment (optional)</label>
                                        <select
                                            className="w-full rounded border px-3 py-2"
                                            value={line.treatment_plan_item_id}
                                            onChange={(e) => linkTreatment(index, e.target.value)}
                                        >
                                            <option value="">Not linked</option>
                                            {treatmentPlanItems.map((tpi) => (
                                                <option key={tpi.id} value={tpi.id}>{tpi.label}</option>
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
                                            {editForm.errors[`items.${index}.description`] && (
                                                <p className="text-sm text-red-600">
                                                    {editForm.errors[`items.${index}.description`]}
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
                                            {editForm.errors[`items.${index}.amount`] && (
                                                <p className="text-sm text-red-600">
                                                    {editForm.errors[`items.${index}.amount`]}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    {editForm.data.items.length > 1 && (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                editForm.setData(
                                                    'items',
                                                    editForm.data.items.filter((_, i) => i !== index),
                                                )
                                            }
                                            className="mt-2 text-sm text-red-600"
                                        >
                                            Remove line
                                        </button>
                                    )}
                                </div>
                            ))}
                            {editForm.errors.items && <p className="text-sm text-red-600">{editForm.errors.items}</p>}
                            <button
                                type="button"
                                onClick={() => editForm.setData('items', [...editForm.data.items, { ...BLANK_LINE }])}
                                className="text-sm text-blue-600"
                            >
                                + Add line
                            </button>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Discount (₱)</label>
                            <input
                                type="number"
                                min="0"
                                max="99999999.99"
                                step="0.01"
                                className="w-full rounded border px-3 py-2"
                                value={editForm.data.discount_amount}
                                onChange={(e) => editForm.setData('discount_amount', e.target.value)}
                            />
                            {editForm.errors.discount_amount && (
                                <p className="text-sm text-red-600">{editForm.errors.discount_amount}</p>
                            )}
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Notes</label>
                            <textarea
                                className="w-full rounded border px-3 py-2"
                                rows={2}
                                value={editForm.data.notes}
                                onChange={(e) => editForm.setData('notes', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { editForm.clearErrors(); setShowEdit(false); }}
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
                </div>
            )}

            {showPayment && (
                <div className="fixed inset-0 flex items-center justify-center overflow-y-auto bg-black/40 p-4">
                    <form onSubmit={submitPayment} className="my-8 w-full max-w-md space-y-4 rounded bg-white p-6">
                        <h3 className="font-semibold">Record payment</h3>
                        <p className="text-sm text-gray-500">Balance due: {formatPeso(invoice.balance)}</p>

                        <div className="grid grid-cols-2 gap-4">
                            <div>
                                <label className="mb-1 block text-sm">Amount (₱)</label>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    className="w-full rounded border px-3 py-2"
                                    value={paymentForm.data.amount}
                                    onChange={(e) => paymentForm.setData('amount', e.target.value)}
                                />
                                {paymentForm.errors.amount && (
                                    <p className="text-sm text-red-600">{paymentForm.errors.amount}</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-1 block text-sm">Method</label>
                                <select
                                    className="w-full rounded border px-3 py-2"
                                    value={paymentForm.data.method}
                                    onChange={(e) => paymentForm.setData('method', e.target.value)}
                                >
                                    {PAYMENT_METHODS.map((method) => (
                                        <option key={method} value={method}>{methodLabel(method)}</option>
                                    ))}
                                </select>
                                {paymentForm.errors.method && (
                                    <p className="text-sm text-red-600">{paymentForm.errors.method}</p>
                                )}
                            </div>
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Date paid</label>
                            <input
                                type="date"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.paid_on}
                                onChange={(e) => paymentForm.setData('paid_on', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Reference (optional)</label>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.reference}
                                onChange={(e) => paymentForm.setData('reference', e.target.value)}
                            />
                        </div>

                        <div>
                            <label className="mb-1 block text-sm">Note (optional)</label>
                            <input
                                type="text"
                                className="w-full rounded border px-3 py-2"
                                value={paymentForm.data.note}
                                onChange={(e) => paymentForm.setData('note', e.target.value)}
                            />
                        </div>

                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => { paymentForm.clearErrors(); setShowPayment(false); }}
                                className="px-4 py-2 text-sm"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                disabled={paymentForm.processing}
                                className="rounded bg-gray-900 px-4 py-2 text-sm text-white"
                            >
                                Record
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AuthenticatedLayout>
    );
}

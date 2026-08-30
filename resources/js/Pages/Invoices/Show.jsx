import Button from '@/Components/UI/Button';
import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal, { ConfirmDialog } from '@/Components/UI/Modal';
import { PageContainer } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { invoiceDisplayStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Ban, CheckCircle2, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDateTime, formatPeso } from '@/Pages/Patients/format';

const PAYMENT_METHODS = ['cash', 'card', 'bank_transfer', 'check', 'other'];
const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

const methodLabel = (method) => method.replace('_', ' ');

export default function Show({ invoice, treatmentPlanItems }) {
    const [showEdit, setShowEdit] = useState(false);
    const [showPayment, setShowPayment] = useState(false);
    const [confirming, setConfirming] = useState(null);

    const isDraft = invoice.status === 'draft';
    const isIssued = invoice.status === 'issued';
    const isVoid = invoice.status === 'void';

    const transition = useForm({ status: '' });

    function move(status) {
        transition.transform(() => ({ status }));
        transition.patch(route('invoices.update', invoice.id), {
            preserveScroll: true,
            onFinish: () => setConfirming(null),
        });
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

    function linkTreatment(index, id) {
        if (!id) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }

        const item = treatmentPlanItems.find((candidate) => String(candidate.id) === String(id));

        setLine(index, {
            treatment_plan_item_id: id,
            description: item ? item.treatment : editForm.data.items[index].description,
            amount: item ? item.estimated_cost : editForm.data.items[index].amount,
        });
    }

    const paymentForm = useForm({
        amount: invoice.balance > 0 ? invoice.balance : '',
        method: 'cash',
        paid_on: '',
        reference: '',
        note: '',
    });

    return (
        <AuthenticatedLayout title={invoice.number}>
            <Head title={invoice.number} />

            <PageContainer className="max-w-4xl">
                {/* The invoice's own header: number, who it is for, and the
                    one number that matters — the balance. */}
                <Card className="mb-5">
                    <div className="flex flex-wrap items-start justify-between gap-4 p-4 sm:p-5">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="tabular text-xl font-semibold tracking-tight text-slate-900">
                                    {invoice.number}
                                </h1>
                                <StatusBadge status={invoiceDisplayStatus(invoice)} />
                            </div>
                            <Link
                                href={route('patients.show', invoice.patient.id)}
                                className="mt-1 inline-block text-sm font-medium text-brand-700 hover:text-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            >
                                {invoice.patient.full_name}
                            </Link>
                            <p className="mt-0.5 text-xs text-slate-500">
                                Created {formatDate(invoice.created_at)} by {invoice.creator_name}
                                {invoice.issued_at && ` · issued ${formatDateTime(invoice.issued_at)}`}
                                {invoice.voided_at && ` · voided ${formatDateTime(invoice.voided_at)}`}
                            </p>
                        </div>

                        <div className="text-end">
                            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">
                                {isVoid ? 'Voided total' : 'Balance due'}
                            </p>
                            <p
                                className={`tabular text-3xl font-semibold ${
                                    isVoid
                                        ? 'text-slate-400 line-through'
                                        : invoice.balance > 0
                                          ? 'text-amber-700'
                                          : 'text-emerald-700'
                                }`}
                            >
                                {formatPeso(isVoid ? invoice.total : invoice.balance)}
                            </p>
                            {!isVoid && invoice.amount_paid > 0 && (
                                <p className="tabular mt-0.5 text-xs text-slate-500">
                                    {formatPeso(invoice.amount_paid)} of {formatPeso(invoice.total)} paid
                                </p>
                            )}
                        </div>
                    </div>

                    {(isDraft || (isIssued && invoice.payments.length === 0) || (isIssued && invoice.balance > 0)) && (
                        <div className="flex flex-wrap items-center gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 sm:px-5">
                            {isDraft && (
                                <>
                                    <Button icon={Send} onClick={() => setConfirming('issue')} disabled={transition.processing}>
                                        Issue invoice
                                    </Button>
                                    <Button variant="secondary" icon={Pencil} onClick={() => setShowEdit(true)}>
                                        Edit lines
                                    </Button>
                                </>
                            )}
                            {isIssued && invoice.balance > 0 && (
                                <Button icon={Plus} onClick={() => setShowPayment(true)}>
                                    Record payment
                                </Button>
                            )}
                            {(isDraft || (isIssued && invoice.payments.length === 0)) && (
                                <Button
                                    variant="danger"
                                    icon={Ban}
                                    className="ms-auto"
                                    onClick={() => setConfirming('void')}
                                    disabled={transition.processing}
                                >
                                    Void
                                </Button>
                            )}
                        </div>
                    )}

                    {transition.errors.status && (
                        <p
                            role="alert"
                            className="border-t border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-medium text-rose-700 sm:px-5"
                        >
                            {transition.errors.status}
                        </p>
                    )}
                </Card>

                {isVoid && (
                    <div
                        role="status"
                        className="mb-5 rounded-xl border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-600"
                    >
                        This invoice has been voided. It stays on the record but is excluded from balances
                        and reports.
                    </div>
                )}
                {invoice.is_paid && (
                    <div
                        role="status"
                        className="mb-5 flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                    >
                        <CheckCircle2 className="h-4 w-4 shrink-0" aria-hidden="true" />
                        Paid in full.
                    </div>
                )}

                <div className="space-y-5">
                    <Card className="overflow-hidden">
                        <CardHeader
                            title="Line items"
                            description={isDraft ? 'Editable until the invoice is issued.' : 'Frozen at issue.'}
                        />
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[30rem] text-sm">
                                <caption className="sr-only">Invoice line items</caption>
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                                        <th scope="col" className="px-4 py-2 font-medium text-slate-600 sm:px-5">
                                            Description
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2 text-right font-medium text-slate-600 sm:px-5"
                                        >
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {invoice.items.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-4 py-2.5 sm:px-5">
                                                <span className="text-slate-800">{item.description}</span>
                                                {(item.treatment_plan_item_label || item.provider_name) && (
                                                    <span className="block text-xs text-slate-400">
                                                        {[item.treatment_plan_item_label, item.provider_name]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="tabular px-4 py-2.5 text-right text-slate-800 sm:px-5">
                                                {formatPeso(item.amount)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot className="border-t border-slate-200 bg-slate-50">
                                    <tr>
                                        <th scope="row" className="px-4 py-1.5 text-right font-normal text-slate-500 sm:px-5">
                                            Subtotal
                                        </th>
                                        <td className="tabular px-4 py-1.5 text-right text-slate-700 sm:px-5">
                                            {formatPeso(invoice.subtotal)}
                                        </td>
                                    </tr>
                                    {invoice.discount_amount > 0 && (
                                        <tr>
                                            <th
                                                scope="row"
                                                className="px-4 py-1.5 text-right font-normal text-slate-500 sm:px-5"
                                            >
                                                Discount
                                            </th>
                                            <td className="tabular px-4 py-1.5 text-right text-slate-700 sm:px-5">
                                                −{formatPeso(invoice.discount_amount)}
                                            </td>
                                        </tr>
                                    )}
                                    <tr>
                                        <th scope="row" className="px-4 pb-3 pt-1.5 text-right font-semibold text-slate-900 sm:px-5">
                                            Total
                                        </th>
                                        <td className="tabular px-4 pb-3 pt-1.5 text-right font-semibold text-slate-900 sm:px-5">
                                            {formatPeso(invoice.total)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        {invoice.notes && (
                            <CardBody className="border-t border-slate-200 text-sm text-slate-600">
                                <span className="font-medium text-slate-700">Notes: </span>
                                {invoice.notes}
                            </CardBody>
                        )}
                    </Card>

                    <Card className="overflow-hidden">
                        <CardHeader
                            title="Payments"
                            description="Append-only — a mistaken payment is corrected by a future refund, never edited."
                        />
                        {invoice.payments.length === 0 ? (
                            <CardBody className="text-sm text-slate-500">No payments recorded.</CardBody>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[32rem] text-sm">
                                    <caption className="sr-only">Payments recorded against this invoice</caption>
                                    <thead>
                                        <tr className="border-b border-slate-200 bg-slate-50 text-left">
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600 sm:px-5">
                                                Date
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600">
                                                Method
                                            </th>
                                            <th scope="col" className="px-4 py-2 font-medium text-slate-600">
                                                Reference
                                            </th>
                                            <th
                                                scope="col"
                                                className="px-4 py-2 text-right font-medium text-slate-600 sm:px-5"
                                            >
                                                Amount
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {invoice.payments.map((payment) => (
                                            <tr key={payment.id}>
                                                <td className="tabular px-4 py-2.5 text-slate-700 sm:px-5">
                                                    {formatDate(payment.paid_on)}
                                                </td>
                                                <td className="px-4 py-2.5 capitalize text-slate-700">
                                                    {methodLabel(payment.method)}
                                                </td>
                                                <td className="px-4 py-2.5 text-slate-400">
                                                    {payment.reference || '—'}
                                                </td>
                                                <td className="tabular px-4 py-2.5 text-right text-slate-800 sm:px-5">
                                                    {formatPeso(payment.amount)}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                    <tfoot className="border-t border-slate-200 bg-slate-50">
                                        <tr>
                                            <th
                                                scope="row"
                                                colSpan={3}
                                                className="px-4 py-1.5 text-right font-normal text-slate-500 sm:px-5"
                                            >
                                                Paid
                                            </th>
                                            <td className="tabular px-4 py-1.5 text-right text-slate-700 sm:px-5">
                                                {formatPeso(invoice.amount_paid)}
                                            </td>
                                        </tr>
                                        <tr>
                                            <th
                                                scope="row"
                                                colSpan={3}
                                                className="px-4 pb-3 pt-1.5 text-right font-semibold text-slate-900 sm:px-5"
                                            >
                                                Balance due
                                            </th>
                                            <td className="tabular px-4 pb-3 pt-1.5 text-right font-semibold text-slate-900 sm:px-5">
                                                {formatPeso(invoice.balance)}
                                            </td>
                                        </tr>
                                    </tfoot>
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
                    editForm.patch(route('invoices.update', invoice.id), {
                        preserveScroll: true,
                        onSuccess: () => setShowEdit(false),
                    });
                }}
                show={showEdit}
                onClose={() => setShowEdit(false)}
                closeable={!editForm.processing}
                title="Edit invoice"
                description="Only a draft can be edited. Issuing freezes these lines permanently."
                width="3xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowEdit(false)} disabled={editForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={editForm.processing}>
                            {editForm.processing ? 'Saving…' : 'Save draft'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    {editForm.data.items.map((line, index) => (
                        <div key={index} className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Line {index + 1}
                                </span>
                                {editForm.data.items.length > 1 && (
                                    <Button
                                        variant="ghost"
                                        size="xs"
                                        aria-label={`Remove line ${index + 1}`}
                                        onClick={() =>
                                            editForm.setData(
                                                'items',
                                                editForm.data.items.filter((_, i) => i !== index),
                                            )
                                        }
                                        className="text-slate-400 hover:bg-rose-50 hover:text-rose-700"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" aria-hidden="true" />
                                    </Button>
                                )}
                            </div>
                            <div className="grid gap-3 sm:grid-cols-6">
                                <SelectField
                                    label="Link to treatment"
                                    className="sm:col-span-6"
                                    value={line.treatment_plan_item_id}
                                    onChange={(e) => linkTreatment(index, e.target.value)}
                                >
                                    <option value="">Not linked</option>
                                    {treatmentPlanItems.map((item) => (
                                        <option key={item.id} value={item.id}>
                                            {item.label}
                                        </option>
                                    ))}
                                </SelectField>
                                <Field
                                    label="Description"
                                    required
                                    className="sm:col-span-4"
                                    value={line.description}
                                    onChange={(e) => setLine(index, { description: e.target.value })}
                                    error={editForm.errors[`items.${index}.description`]}
                                />
                                <Field
                                    label="Amount (₱)"
                                    required
                                    type="number"
                                    min="0"
                                    max="99999999.99"
                                    step="0.01"
                                    className="sm:col-span-2"
                                    inputClassName="tabular"
                                    value={line.amount}
                                    onChange={(e) => setLine(index, { amount: e.target.value })}
                                    error={editForm.errors[`items.${index}.amount`]}
                                />
                            </div>
                        </div>
                    ))}

                    {editForm.errors.items && (
                        <p className="text-xs font-medium text-rose-600">{editForm.errors.items}</p>
                    )}

                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Plus}
                        onClick={() => editForm.setData('items', [...editForm.data.items, { ...BLANK_LINE }])}
                    >
                        Add line
                    </Button>
                </div>

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Discount (₱)"
                        type="number"
                        min="0"
                        max="99999999.99"
                        step="0.01"
                        inputClassName="tabular"
                        value={editForm.data.discount_amount}
                        onChange={(e) => editForm.setData('discount_amount', e.target.value)}
                        error={editForm.errors.discount_amount}
                        hint="Cannot exceed the line-item subtotal."
                    />
                </div>

                <TextareaField
                    label="Notes"
                    className="mt-4"
                    rows={2}
                    value={editForm.data.notes}
                    onChange={(e) => editForm.setData('notes', e.target.value)}
                    error={editForm.errors.notes}
                />
            </Modal>

            <Modal
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    paymentForm.post(route('invoice-payments.store', invoice.id), {
                        preserveScroll: true,
                        onSuccess: () => {
                            paymentForm.reset();
                            setShowPayment(false);
                        },
                    });
                }}
                show={showPayment}
                onClose={() => setShowPayment(false)}
                closeable={!paymentForm.processing}
                title="Record payment"
                description={`Balance due ${formatPeso(invoice.balance)}. A payment cannot exceed it, and cannot be edited once saved.`}
                width="md"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setShowPayment(false)}
                            disabled={paymentForm.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={paymentForm.processing}>
                            {paymentForm.processing ? 'Recording…' : 'Record payment'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Amount (₱)"
                            required
                            type="number"
                            min="0"
                            step="0.01"
                            inputClassName="tabular"
                            value={paymentForm.data.amount}
                            onChange={(e) => paymentForm.setData('amount', e.target.value)}
                            error={paymentForm.errors.amount}
                        />
                        <SelectField
                            label="Method"
                            value={paymentForm.data.method}
                            onChange={(e) => paymentForm.setData('method', e.target.value)}
                            error={paymentForm.errors.method}
                        >
                            {PAYMENT_METHODS.map((method) => (
                                <option key={method} value={method}>
                                    {methodLabel(method)}
                                </option>
                            ))}
                        </SelectField>
                    </div>

                    <Field
                        label="Date paid"
                        type="date"
                        value={paymentForm.data.paid_on}
                        onChange={(e) => paymentForm.setData('paid_on', e.target.value)}
                        error={paymentForm.errors.paid_on}
                        hint="Defaults to today. Cannot be in the future."
                    />
                    <Field
                        label="Reference"
                        value={paymentForm.data.reference}
                        onChange={(e) => paymentForm.setData('reference', e.target.value)}
                        error={paymentForm.errors.reference}
                        hint="Transaction or receipt number, if there is one."
                    />
                    <Field
                        label="Note"
                        value={paymentForm.data.note}
                        onChange={(e) => paymentForm.setData('note', e.target.value)}
                        error={paymentForm.errors.note}
                    />
                </div>
            </Modal>

            <ConfirmDialog
                show={confirming === 'issue'}
                onClose={() => setConfirming(null)}
                onConfirm={() => move('issued')}
                processing={transition.processing}
                title={`Issue ${invoice.number}?`}
                confirmLabel="Issue invoice"
                variant="primary"
                body={`This freezes the line items and the ${formatPeso(invoice.total)} total permanently. An issued invoice can only be voided, and only while it has no payments.`}
            />

            <ConfirmDialog
                show={confirming === 'void'}
                onClose={() => setConfirming(null)}
                onConfirm={() => move('void')}
                processing={transition.processing}
                title={`Void ${invoice.number}?`}
                confirmLabel="Void invoice"
                body="The invoice stays on the record but is excluded from balances and reports. This cannot be undone — a voided invoice can never be reissued."
            />
        </AuthenticatedLayout>
    );
}

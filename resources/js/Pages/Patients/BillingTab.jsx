import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { EmptyState, SectionHeading } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { invoiceDisplayStatus } from '@/Components/UI/statuses';
import { Link, useForm } from '@inertiajs/react';
import { Plus, Receipt, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatPeso } from './format';

const BLANK_LINE = { description: '', amount: '', treatment_plan_item_id: '' };

function Money({ label, value, tone = 'text-slate-900' }) {
    return (
        <div>
            <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className={`tabular mt-0.5 text-lg font-semibold ${tone}`}>{formatPeso(value)}</dd>
        </div>
    );
}

export default function BillingTab({ patient, invoices, billableTreatmentItems }) {
    const [showNew, setShowNew] = useState(false);

    const form = useForm({
        patient_id: patient.id,
        items: [{ ...BLANK_LINE }],
        discount_amount: '',
        notes: '',
    });

    function openNew() {
        form.clearErrors();
        form.setData({
            patient_id: patient.id,
            items: [{ ...BLANK_LINE }],
            discount_amount: '',
            notes: '',
        });
        setShowNew(true);
    }

    function setLine(index, patch) {
        form.setData(
            'items',
            form.data.items.map((line, i) => (i === index ? { ...line, ...patch } : line)),
        );
    }

    /** Linking a treatment pre-fills the line from it; the copy is then editable. */
    function linkTreatment(index, id) {
        if (!id) {
            setLine(index, { treatment_plan_item_id: '' });
            return;
        }

        const item = billableTreatmentItems.find((candidate) => String(candidate.id) === String(id));

        setLine(index, {
            treatment_plan_item_id: id,
            description: item ? item.treatment : form.data.items[index].description,
            amount: item ? item.estimated_cost : form.data.items[index].amount,
        });
    }

    function submit(event) {
        event.preventDefault();
        form.post(route('invoices.store'), { onSuccess: () => setShowNew(false) });
    }

    const live = invoices.filter((invoice) => invoice.status !== 'void');
    const totals = {
        billed: live.reduce((sum, invoice) => sum + invoice.total, 0),
        paid: live.reduce((sum, invoice) => sum + invoice.amount_paid, 0),
        outstanding: live.reduce((sum, invoice) => sum + invoice.balance, 0),
    };

    const subtotal = form.data.items.reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
    const draftTotal = subtotal - (Number(form.data.discount_amount) || 0);

    return (
        <div className="space-y-5">
            <Card>
                <dl className="grid grid-cols-3 gap-4 p-4 sm:p-5">
                    <Money label="Billed" value={totals.billed} />
                    <Money label="Paid" value={totals.paid} tone="text-emerald-700" />
                    <Money
                        label="Outstanding"
                        value={totals.outstanding}
                        tone={totals.outstanding > 0 ? 'text-amber-700' : 'text-slate-900'}
                    />
                </dl>
            </Card>

            <div>
                <SectionHeading
                    title="Invoices"
                    count={invoices.length}
                    actions={
                        <Button size="sm" icon={Plus} onClick={openNew}>
                            New invoice
                        </Button>
                    }
                />

                {invoices.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={Receipt}
                            title="No invoices yet"
                            description="An invoice starts as a draft you can edit, then is issued to freeze its lines."
                            action={
                                <Button icon={Plus} onClick={openNew}>
                                    Create the first invoice
                                </Button>
                            }
                        />
                    </Card>
                ) : (
                    <Card>
                        <ul className="divide-y divide-slate-200">
                            {invoices.map((invoice) => (
                                <li key={invoice.id}>
                                    <Link
                                        href={route('invoices.show', invoice.id)}
                                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 transition-colors hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500 sm:px-5"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className="tabular text-sm font-medium text-slate-900">
                                                {invoice.number}
                                            </span>
                                            <StatusBadge status={invoiceDisplayStatus(invoice)} />
                                        </div>
                                        <div className="flex items-center gap-5 text-xs">
                                            <span className="text-slate-500">{formatDate(invoice.created_at)}</span>
                                            <span className="tabular text-slate-700">
                                                Total {formatPeso(invoice.total)}
                                            </span>
                                            <span
                                                className={`tabular font-medium ${
                                                    invoice.balance > 0 ? 'text-amber-700' : 'text-slate-500'
                                                }`}
                                            >
                                                Balance {formatPeso(invoice.balance)}
                                            </span>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}
            </div>

            <Modal
                as="form"
                onSubmit={submit}
                show={showNew}
                onClose={() => setShowNew(false)}
                closeable={!form.processing}
                title="New invoice"
                description={`Draft for ${patient.first_name} ${patient.last_name}. Lines stay editable until it is issued.`}
                width="3xl"
                footer={
                    <>
                        <span className="tabular me-auto text-sm text-slate-600">
                            Total <span className="font-semibold text-slate-900">{formatPeso(draftTotal)}</span>
                        </span>
                        <Button variant="secondary" onClick={() => setShowNew(false)} disabled={form.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? 'Creating…' : 'Create draft'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-3">
                    {form.data.items.map((line, index) => (
                        <div key={index} className="rounded-xl border border-slate-200 bg-slate-50/60 p-3">
                            <div className="mb-3 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">
                                    Line {index + 1}
                                </span>
                                {form.data.items.length > 1 && (
                                    <Button
                                        variant="ghost"
                                        size="xs"
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter((_, i) => i !== index),
                                            )
                                        }
                                        aria-label={`Remove line ${index + 1}`}
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
                                    hint="Pre-fills the description and amount, and records the provider on the line."
                                >
                                    <option value="">Not linked</option>
                                    {billableTreatmentItems.map((item) => (
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
                                    error={form.errors[`items.${index}.description`]}
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
                                    error={form.errors[`items.${index}.amount`]}
                                />
                            </div>
                        </div>
                    ))}

                    {form.errors.items && (
                        <p className="text-xs font-medium text-rose-600">{form.errors.items}</p>
                    )}

                    <Button
                        variant="secondary"
                        size="sm"
                        icon={Plus}
                        onClick={() => form.setData('items', [...form.data.items, { ...BLANK_LINE }])}
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
                        value={form.data.discount_amount}
                        onChange={(e) => form.setData('discount_amount', e.target.value)}
                        error={form.errors.discount_amount}
                        hint={`Applied to the whole invoice. Subtotal ${formatPeso(subtotal)}.`}
                    />
                </div>

                <TextareaField
                    label="Notes"
                    className="mt-4"
                    rows={2}
                    value={form.data.notes}
                    onChange={(e) => form.setData('notes', e.target.value)}
                    error={form.errors.notes}
                />
            </Modal>
        </div>
    );
}

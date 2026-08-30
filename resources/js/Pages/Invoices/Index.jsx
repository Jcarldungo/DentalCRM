import Card from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { invoiceDisplayStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Receipt } from 'lucide-react';
import { formatDate, formatPeso } from '@/Pages/Patients/format';

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'outstanding', label: 'Outstanding' },
    { value: 'paid', label: 'Paid' },
    { value: 'void', label: 'Void' },
];

const EMPTY_COPY = {
    all: 'Invoices are created from a patient’s Billing tab.',
    draft: 'Draft invoices — still editable, not yet issued — appear here.',
    outstanding: 'Nothing outstanding. Every issued invoice is paid in full.',
    paid: 'No fully-paid invoices in this list yet.',
    void: 'No invoices have been voided.',
};

export default function Index({ invoices, filters }) {
    function setFilter(status) {
        router.get(
            route('invoices.index'),
            status === 'all' ? {} : { status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const outstanding = invoices.reduce((sum, invoice) => sum + Math.max(invoice.balance, 0), 0);

    return (
        <AuthenticatedLayout title="Billing">
            <Head title="Billing" />

            <PageContainer>
                <PageHeader
                    title="Billing"
                    description={
                        invoices.length === 0
                            ? 'No invoices in this view.'
                            : `${invoices.length} invoice${invoices.length === 1 ? '' : 's'}${
                                  outstanding > 0 ? ` · ${formatPeso(outstanding)} outstanding` : ''
                              }`
                    }
                />

                <div
                    role="group"
                    aria-label="Filter invoices by status"
                    className="mb-4 flex flex-wrap gap-1.5"
                >
                    {FILTERS.map((filter) => {
                        const active = filters.status === filter.value;

                        return (
                            <button
                                key={filter.value}
                                type="button"
                                onClick={() => setFilter(filter.value)}
                                aria-pressed={active}
                                className={`h-9 rounded-lg px-3 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ${
                                    active
                                        ? 'bg-brand-600 text-white'
                                        : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {filter.label}
                            </button>
                        );
                    })}
                </div>

                <Card className="overflow-hidden">
                    {invoices.length === 0 ? (
                        <EmptyState
                            icon={Receipt}
                            title="No invoices here"
                            description={EMPTY_COPY[filters.status] ?? EMPTY_COPY.all}
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600 sm:px-5">
                                            Invoice
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600">
                                            Patient
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 font-medium text-slate-600">
                                            Date
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-right font-medium text-slate-600">
                                            Total
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2.5 text-right font-medium text-slate-600 sm:px-5"
                                        >
                                            Balance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {invoices.map((invoice) => (
                                        <tr key={invoice.id} className="transition-colors hover:bg-slate-50">
                                            <td className="px-4 py-2.5 sm:px-5">
                                                <Link
                                                    href={route('invoices.show', invoice.id)}
                                                    className="tabular inline-flex items-center gap-2 font-medium text-slate-900 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                                >
                                                    {invoice.number}
                                                    <StatusBadge status={invoiceDisplayStatus(invoice)} />
                                                </Link>
                                            </td>
                                            <td className="px-4 py-2.5">
                                                <Link
                                                    href={route('patients.show', invoice.patient_id)}
                                                    className="text-slate-700 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                                >
                                                    {invoice.patient_name}
                                                </Link>
                                            </td>
                                            <td className="tabular px-4 py-2.5 text-slate-500">
                                                {formatDate(invoice.created_at)}
                                            </td>
                                            <td className="tabular px-4 py-2.5 text-right text-slate-700">
                                                {formatPeso(invoice.total)}
                                            </td>
                                            <td
                                                className={`tabular px-4 py-2.5 text-right font-medium sm:px-5 ${
                                                    invoice.balance > 0 ? 'text-amber-700' : 'text-slate-500'
                                                }`}
                                            >
                                                {formatPeso(invoice.balance)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            </PageContainer>
        </AuthenticatedLayout>
    );
}

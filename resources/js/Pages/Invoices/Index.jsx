import Card from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import Pagination from '@/Components/UI/Pagination';
import StatusBadge from '@/Components/UI/StatusBadge';
import { invoiceDisplayStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Receipt, Search, X } from 'lucide-react';
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

export default function Index({ invoices, summary, filters }) {
    const [term, setTerm] = useState(filters.search ?? '');
    const first = useRef(true);

    function go(params) {
        router.get(
            route('invoices.index'),
            { status: filters.status === 'all' ? undefined : filters.status, search: term || undefined, ...params },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    // Debounced, replace-in-place, so typing doesn't stack history entries.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return undefined;
        }

        const timer = setTimeout(() => go({ page: undefined }), 300);

        return () => clearTimeout(timer);
    }, [term]);

    return (
        <AuthenticatedLayout title="Billing">
            <Head title="Billing" />

            <PageContainer>
                <PageHeader
                    title="Billing"
                    description={
                        summary.count === 0
                            ? 'No invoices in this view.'
                            : `${summary.count} invoice${summary.count === 1 ? '' : 's'}${
                                  summary.outstanding > 0
                                      ? ` · ${formatPeso(summary.outstanding)} outstanding`
                                      : ''
                              }`
                    }
                />

                <div className="mb-4 flex flex-wrap items-center gap-3">
                    <div role="group" aria-label="Filter invoices by status" className="flex flex-wrap gap-1.5">
                    {FILTERS.map((filter) => {
                        const active = filters.status === filter.value;

                        return (
                            <button
                                key={filter.value}
                                type="button"
                                onClick={() => go({ status: filter.value === 'all' ? undefined : filter.value, page: undefined })}
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

                    <div className="relative w-full max-w-xs">
                        <Search
                            className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            aria-label="Search invoices by patient or invoice number"
                            placeholder="Patient or invoice number…"
                            className="h-9 w-full rounded-lg border-slate-300 ps-9 pe-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                        />
                        {term && (
                            <button
                                type="button"
                                onClick={() => setTerm('')}
                                aria-label="Clear search"
                                className="absolute end-2 top-1/2 -translate-y-1/2 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            >
                                <X className="h-4 w-4" aria-hidden="true" />
                            </button>
                        )}
                    </div>
                </div>

                <Card className="overflow-hidden">
                    {invoices.data.length === 0 ? (
                        <EmptyState
                            icon={Receipt}
                            title="No invoices here"
                            description={
                                filters.search
                                    ? `Nothing matches \u201c${filters.search}\u201d in this view.`
                                    : (EMPTY_COPY[filters.status] ?? EMPTY_COPY.all)
                            }
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[40rem] text-sm">
                                <thead>
                                    <tr className="border-b border-slate-200 text-left">
                                        <th scope="col" className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-5">
                                            Invoice
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Patient
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Date
                                        </th>
                                        <th scope="col" className="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500">
                                            Total
                                        </th>
                                        <th
                                            scope="col"
                                            className="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-slate-500 sm:px-5"
                                        >
                                            Balance
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-200">
                                    {invoices.data.map((invoice) => (
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

                <Pagination paginator={invoices} label="Invoice list pages" />
            </PageContainer>
        </AuthenticatedLayout>
    );
}

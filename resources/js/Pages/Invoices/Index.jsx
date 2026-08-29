import { Head, Link, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatDate, formatPeso } from '@/Pages/Patients/format';

const FILTERS = ['all', 'draft', 'outstanding', 'paid', 'void'];

const STATUS_BADGE = {
    draft: 'bg-gray-100 text-gray-700 border-gray-300',
    issued: 'bg-blue-100 text-blue-800 border-blue-300',
    paid: 'bg-green-100 text-green-800 border-green-300',
    void: 'bg-gray-200 text-gray-500 border-gray-300 line-through',
};

function statusLabel(invoice) {
    return invoice.is_paid ? 'paid' : invoice.status;
}

export default function Index({ invoices, filters }) {
    function setFilter(status) {
        router.get(
            route('invoices.index'),
            status === 'all' ? {} : { status },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Billing</h2>}>
            <Head title="Billing" />

            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8">
                <div className="mb-4 flex flex-wrap gap-2">
                    {FILTERS.map((status) => (
                        <button
                            key={status}
                            type="button"
                            onClick={() => setFilter(status)}
                            className={`rounded border px-3 py-1 text-sm capitalize ${
                                filters.status === status
                                    ? 'border-gray-900 bg-gray-900 text-white'
                                    : 'border-gray-300 text-gray-600'
                            }`}
                        >
                            {status}
                        </button>
                    ))}
                </div>

                <div className="overflow-x-auto rounded border bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="border-b text-left text-gray-500">
                            <tr>
                                <th className="px-4 py-2">Invoice</th>
                                <th className="px-4 py-2">Patient</th>
                                <th className="px-4 py-2">Date</th>
                                <th className="px-4 py-2 text-right">Total</th>
                                <th className="px-4 py-2 text-right">Paid</th>
                                <th className="px-4 py-2 text-right">Balance</th>
                                <th className="px-4 py-2">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoices.map((invoice) => (
                                <tr key={invoice.id} className="border-b last:border-0 hover:bg-gray-50">
                                    <td className="px-4 py-2">
                                        <Link href={route('invoices.show', invoice.id)} className="text-blue-600">
                                            {invoice.number}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2">
                                        <Link href={route('patients.show', invoice.patient_id)} className="text-blue-600">
                                            {invoice.patient_name}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-2 text-gray-500">{formatDate(invoice.created_at)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.total)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.amount_paid)}</td>
                                    <td className="px-4 py-2 text-right">{formatPeso(invoice.balance)}</td>
                                    <td className="px-4 py-2">
                                        <span
                                            className={`inline-block rounded border px-2 py-0.5 text-xs ${STATUS_BADGE[statusLabel(invoice)]}`}
                                        >
                                            {statusLabel(invoice)}
                                        </span>
                                    </td>
                                </tr>
                            ))}
                            {invoices.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="px-4 py-6 text-center text-gray-500">
                                        No {filters.status === 'all' ? '' : filters.status} invoices.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

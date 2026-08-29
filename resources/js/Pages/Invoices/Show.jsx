import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Show({ invoice }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{invoice.number}</h2>}>
            <Head title={invoice.number} />
        </AuthenticatedLayout>
    );
}

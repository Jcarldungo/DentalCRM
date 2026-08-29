import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Index() {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Billing</h2>}>
            <Head title="Billing" />
        </AuthenticatedLayout>
    );
}

import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Index({ meta }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Reports</h2>}>
            <Head title="Reports" />
            <div className="py-8 max-w-5xl mx-auto sm:px-6 lg:px-8 text-sm text-gray-500">
                {meta.label}
            </div>
        </AuthenticatedLayout>
    );
}

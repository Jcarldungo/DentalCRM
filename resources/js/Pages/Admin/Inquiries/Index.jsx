import { Head, router } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Badge from '@/Components/Badge';

export default function Index({ inquiries }) {
    function markRead(inquiry) {
        router.patch(route('inquiries.update', inquiry.id), { read: true }, { preserveScroll: true });
    }

    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inquiries</h2>}>
            <Head title="Inquiries" />

            <div className="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white shadow rounded divide-y">
                    {inquiries.map((inquiry) => (
                        <div key={inquiry.id} className="flex items-start justify-between gap-4 p-4">
                            <div>
                                <div className="font-medium">{inquiry.name}</div>
                                <div className="text-sm text-gray-500">{inquiry.email}</div>
                                {inquiry.service_interest && (
                                    <div className="text-sm text-gray-500">{inquiry.service_interest}</div>
                                )}
                                <p className="mt-1 text-sm text-gray-700">{inquiry.message}</p>
                                <div className="mt-1 text-xs text-gray-400">{inquiry.created_at}</div>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <Badge tone={inquiry.read_at ? 'muted' : 'warn'}>
                                    {inquiry.read_at ? 'Read' : 'New'}
                                </Badge>
                                {!inquiry.read_at && (
                                    <button onClick={() => markRead(inquiry)} className="text-sm text-blue-600">
                                        Mark as read
                                    </button>
                                )}
                            </div>
                        </div>
                    ))}
                    {inquiries.length === 0 && (
                        <div className="p-4 text-sm text-gray-500">No inquiries yet.</div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

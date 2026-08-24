import { Head } from '@inertiajs/react';

export default function Index({ inquiries }) {
    return (
        <>
            <Head title="Inquiries" />
            <div className="p-6">
                <h1 className="text-2xl font-bold">Inquiries</h1>
                <div className="mt-6">
                    {inquiries && inquiries.length > 0 ? (
                        <ul>
                            {inquiries.map((inquiry) => (
                                <li key={inquiry.id} className="p-4 border-b">
                                    <p className="font-semibold">{inquiry.name}</p>
                                    <p className="text-sm text-gray-600">{inquiry.email}</p>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p>No inquiries yet.</p>
                    )}
                </div>
            </div>
        </>
    );
}

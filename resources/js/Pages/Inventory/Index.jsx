import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Index({ items, filters }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Inventory</h2>}>
            <Head title="Inventory" />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <h3 className="text-lg font-semibold mb-4">Inventory Items</h3>
                            {items.length === 0 ? (
                                <p className="text-gray-500">No inventory items</p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="min-w-full border-collapse border border-gray-300">
                                        <thead>
                                            <tr className="bg-gray-100">
                                                <th className="border border-gray-300 px-4 py-2 text-left">Name</th>
                                                <th className="border border-gray-300 px-4 py-2 text-left">Category</th>
                                                <th className="border border-gray-300 px-4 py-2 text-left">On Hand</th>
                                                <th className="border border-gray-300 px-4 py-2 text-left">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.map((item) => (
                                                <tr key={item.id}>
                                                    <td className="border border-gray-300 px-4 py-2">{item.name}</td>
                                                    <td className="border border-gray-300 px-4 py-2">{item.category}</td>
                                                    <td className="border border-gray-300 px-4 py-2">{item.on_hand}</td>
                                                    <td className="border border-gray-300 px-4 py-2">{item.stock_status}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

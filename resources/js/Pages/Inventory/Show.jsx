import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Show({ item }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">{item.name}</h2>}>
            <Head title={item.name} />
            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            <div className="mb-6">
                                <h3 className="text-lg font-semibold mb-4">Item Details</h3>
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-sm font-medium text-gray-600">Name</label>
                                        <p className="text-gray-900">{item.name}</p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-600">Category</label>
                                        <p className="text-gray-900">{item.category}</p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-600">On Hand</label>
                                        <p className="text-gray-900">{item.on_hand}</p>
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium text-gray-600">Status</label>
                                        <p className="text-gray-900">{item.stock_status}</p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 className="text-lg font-semibold mb-4">Stock Movements</h3>
                                {item.movements.length === 0 ? (
                                    <p className="text-gray-500">No movements</p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full border-collapse border border-gray-300">
                                            <thead>
                                                <tr className="bg-gray-100">
                                                    <th className="border border-gray-300 px-4 py-2 text-left">Type</th>
                                                    <th className="border border-gray-300 px-4 py-2 text-left">Quantity</th>
                                                    <th className="border border-gray-300 px-4 py-2 text-left">Occurred On</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {item.movements.map((movement) => (
                                                    <tr key={movement.id}>
                                                        <td className="border border-gray-300 px-4 py-2">{movement.type}</td>
                                                        <td className="border border-gray-300 px-4 py-2">{movement.quantity}</td>
                                                        <td className="border border-gray-300 px-4 py-2">{movement.occurred_on}</td>
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
            </div>
        </AuthenticatedLayout>
    );
}

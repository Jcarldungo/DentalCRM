import { Head } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';

export default function Dashboard({ dueForRecall }) {
    return (
        <AuthenticatedLayout header={<h2 className="text-xl font-semibold">Dashboard</h2>}>
            <Head title="Dashboard" />

            <div className="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
                <div className="bg-white shadow rounded p-4">
                    <h3 className="font-semibold mb-3">Due for recall</h3>

                    {dueForRecall.length === 0 && (
                        <p className="text-sm text-gray-500">No one is currently overdue for a cleaning.</p>
                    )}

                    <ul className="divide-y">
                        {dueForRecall.map((patient) => (
                            <li key={patient.id} className="py-2 flex justify-between text-sm">
                                <span>{patient.full_name}</span>
                                <span className="text-gray-500">
                                    Last cleaning {patient.last_cleaning_at} — due {patient.due_date}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

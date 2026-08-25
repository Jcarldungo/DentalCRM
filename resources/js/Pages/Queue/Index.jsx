import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';

export default function Index({ todaysSchedule, waiting, nowServing, completed }) {
    return (
        <AuthenticatedLayout
            header={<h2 className="font-semibold text-xl text-gray-800 leading-tight">Queue</h2>}
        >
            <Head title="Queue" />

            <div className="py-12">
                <div className="max-w-7xl mx-auto sm:px-6 lg:px-8">
                    <div className="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                        <div className="p-6 text-gray-900">
                            Queue board will be implemented here
                        </div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

import ClinicMark from '@/Components/UI/ClinicMark';
import { Link, usePage } from '@inertiajs/react';

/**
 * The sign-in shell. It carries the clinic's own mark rather than the
 * Laravel logo the scaffold shipped, and links back to the public site —
 * the previous version was a dead end for anyone who landed here by
 * mistake.
 */
export default function GuestLayout({ title, description, children }) {
    const { clinic } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-slate-50">
            <div className="flex flex-1 flex-col items-center justify-center px-4 py-10 sm:px-6">
                <Link
                    href={route('home')}
                    className="mb-6 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                >
                    <ClinicMark name={clinic?.name ?? 'Dental Clinic'} />
                </Link>

                <div className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
                    {title && (
                        <div className="mb-6">
                            <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
                            {description && (
                                <p className="mt-1 text-sm leading-relaxed text-slate-500">{description}</p>
                            )}
                        </div>
                    )}
                    {children}
                </div>

                <Link
                    href={route('home')}
                    className="mt-6 rounded-md text-sm text-slate-500 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    &larr; Back to the clinic site
                </Link>
            </div>
        </div>
    );
}

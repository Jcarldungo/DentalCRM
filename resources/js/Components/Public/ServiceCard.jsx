import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

export default function ServiceCard({ service }) {
    const Icon = service.icon;

    return (
        <div className="flex flex-col gap-4 rounded-lg border border-stone-200 bg-white p-6">
            <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                <Icon className="h-6 w-6" aria-hidden="true" />
            </div>
            <div>
                <h3 className="text-lg font-semibold text-stone-900">{service.name}</h3>
                <p className="mt-1 text-sm leading-relaxed text-stone-600">{service.description}</p>
            </div>
            <div className="mt-auto flex items-center justify-between pt-2 text-sm text-stone-500">
                <span>{service.duration}</span>
                <span className="font-medium text-stone-700">{service.price}</span>
            </div>
            <Link
                href={route('book', { service: service.name })}
                className="inline-flex items-center gap-1 text-sm font-medium text-teal-700 hover:text-teal-800"
            >
                Book this service
                <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </Link>
        </div>
    );
}

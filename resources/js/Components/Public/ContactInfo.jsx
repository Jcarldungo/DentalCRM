import { MapPin, Phone, Mail, Clock } from 'lucide-react';
import { CLINIC } from '@/Layouts/PublicLayout';

export default function ContactInfo() {
    return (
        <ul className="flex flex-col gap-3 text-stone-600">
            <li className="flex items-center gap-3">
                <MapPin className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.address}
            </li>
            <li className="flex items-center gap-3">
                <Phone className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.phone}
            </li>
            <li className="flex items-center gap-3">
                <Mail className="h-5 w-5 text-teal-700" aria-hidden="true" />
                {CLINIC.email}
            </li>
            <li className="flex items-start gap-3">
                <Clock className="mt-0.5 h-5 w-5 text-teal-700" aria-hidden="true" />
                <span>
                    {CLINIC.hours.map((h) => (
                        <span key={h.days} className="block">
                            {h.days}: {h.time}
                        </span>
                    ))}
                </span>
            </li>
        </ul>
    );
}

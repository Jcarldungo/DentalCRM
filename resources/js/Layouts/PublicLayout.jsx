import { useState } from 'react';
import { Link } from '@inertiajs/react';
import { Menu, X, MapPin, Phone, Mail, Clock } from 'lucide-react';
import { services } from '@/Data/services';

const NAV_LINKS = [
    { name: 'home', label: 'Home' },
    { name: 'services', label: 'Services' },
    { name: 'dentists', label: 'Dentists' },
    { name: 'about', label: 'About' },
    { name: 'contact', label: 'Contact' },
];

export const CLINIC = {
    name: 'Harborview Dental Clinic',
    address: '123 Harborview Ave, Makati City, Metro Manila',
    phone: '(02) 8123 4567',
    email: 'hello@harborviewdental.example',
    hours: [
        { days: 'Monday – Friday', time: '9:00 AM – 6:00 PM' },
        { days: 'Saturday', time: '9:00 AM – 3:00 PM' },
        { days: 'Sunday', time: 'Closed' },
    ],
};

export default function PublicLayout({ children }) {
    const [mobileOpen, setMobileOpen] = useState(false);

    return (
        <div className="flex min-h-screen flex-col bg-white text-stone-900">
            <header className="sticky top-0 z-40 border-b border-stone-200 bg-white/90 backdrop-blur">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <Link href={route('home')} className="text-lg font-semibold tracking-tight text-stone-900">
                        {CLINIC.name}
                    </Link>

                    <nav className="hidden items-center gap-8 md:flex">
                        {NAV_LINKS.filter((l) => l.name !== 'contact').map((link) => (
                            <Link
                                key={link.name}
                                href={route(link.name)}
                                className={`text-sm font-medium transition-colors ${
                                    route().current(link.name) ? 'text-teal-700' : 'text-stone-600 hover:text-teal-700'
                                }`}
                            >
                                {link.label}
                            </Link>
                        ))}
                        <Link
                            href={route('contact')}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-teal-800"
                        >
                            Contact
                        </Link>
                    </nav>

                    <button
                        type="button"
                        aria-expanded={mobileOpen}
                        aria-controls="mobile-nav"
                        aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
                        onClick={() => setMobileOpen((open) => !open)}
                        className="inline-flex items-center justify-center rounded-md p-2 text-stone-600 hover:bg-stone-100 md:hidden"
                    >
                        {mobileOpen ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
                    </button>
                </div>

                {mobileOpen && (
                    <nav id="mobile-nav" className="border-t border-stone-200 bg-white md:hidden">
                        <div className="space-y-1 px-4 py-3">
                            {NAV_LINKS.map((link) => (
                                <Link
                                    key={link.name}
                                    href={route(link.name)}
                                    onClick={() => setMobileOpen(false)}
                                    className={`block rounded-md px-3 py-2 text-base font-medium ${
                                        route().current(link.name) ? 'bg-teal-50 text-teal-700' : 'text-stone-700 hover:bg-stone-50'
                                    }`}
                                >
                                    {link.label}
                                </Link>
                            ))}
                        </div>
                    </nav>
                )}
            </header>

            <main className="flex-1">{children}</main>

            <footer className="border-t border-stone-200 bg-stone-50">
                <div className="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 md:grid-cols-4 lg:px-8">
                    <div>
                        <p className="text-lg font-semibold text-stone-900">{CLINIC.name}</p>
                        <p className="mt-2 text-sm leading-relaxed text-stone-600">
                            Modern, patient-centered dental care in a calm, welcoming environment.
                        </p>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Navigation</p>
                        <ul className="mt-3 space-y-2">
                            {NAV_LINKS.map((link) => (
                                <li key={link.name}>
                                    <Link href={route(link.name)} className="text-sm text-stone-600 hover:text-teal-700">
                                        {link.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Services</p>
                        <ul className="mt-3 space-y-2">
                            {services.slice(0, 5).map((service) => (
                                <li key={service.slug} className="text-sm text-stone-600">
                                    {service.name}
                                </li>
                            ))}
                        </ul>
                    </div>

                    <div>
                        <p className="text-sm font-semibold text-stone-900">Contact</p>
                        <ul className="mt-3 space-y-2 text-sm text-stone-600">
                            <li className="flex items-start gap-2">
                                <MapPin className="mt-0.5 h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.address}
                            </li>
                            <li className="flex items-center gap-2">
                                <Phone className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.phone}
                            </li>
                            <li className="flex items-center gap-2">
                                <Mail className="h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                {CLINIC.email}
                            </li>
                            <li className="flex items-start gap-2">
                                <Clock className="mt-0.5 h-4 w-4 shrink-0 text-teal-700" aria-hidden="true" />
                                <span>
                                    {CLINIC.hours.map((h) => (
                                        <span key={h.days} className="block">
                                            {h.days}: {h.time}
                                        </span>
                                    ))}
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>

                <div className="border-t border-stone-200 py-6 text-center text-sm text-stone-500">
                    &copy; {new Date().getFullYear()} {CLINIC.name}. All rights reserved.
                </div>
            </footer>
        </div>
    );
}

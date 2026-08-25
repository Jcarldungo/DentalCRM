import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ContactInfo from '@/Components/Public/ContactInfo';
import { services } from '@/Data/services';
import { dentists } from '@/Data/dentists';
import { CheckCircle2 } from 'lucide-react';

const inputClass =
    'mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-teal-700 focus:outline-none focus:ring-1 focus:ring-teal-700';

const TIMES_OF_DAY = [
    { value: 'morning', label: 'Morning' },
    { value: 'afternoon', label: 'Afternoon' },
];

function todayIsoDate() {
    const now = new Date();
    const offsetMinutes = now.getTimezoneOffset();

    return new Date(now.getTime() - offsetMinutes * 60_000).toISOString().slice(0, 10);
}

export default function Book({ initialService, closedDays }) {
    const [submitted, setSubmitted] = useState(false);
    const [dateWarning, setDateWarning] = useState(null);
    const { data, setData, post, processing, errors, reset } = useForm({
        service_interest: initialService ?? '',
        dentist_preference: '',
        preferred_date: '',
        preferred_time_of_day: 'morning',
        name: '',
        email: '',
        phone: '',
        notes: '',
    });

    function onDateChange(value) {
        setData('preferred_date', value);

        if (value && closedDays.includes(new Date(`${value}T00:00:00`).getDay())) {
            setDateWarning('The clinic is closed on that day. Please choose another date.');
        } else {
            setDateWarning(null);
        }
    }

    function submit(e) {
        e.preventDefault();
        post(route('bookings.store'), {
            onSuccess: () => {
                reset();
                setDateWarning(null);
                setSubmitted(true);
            },
        });
    }

    return (
        <PublicLayout>
            <Head title="Book an Appointment" />

            <section className="py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-8">
                        <SectionHeading
                            align="left"
                            eyebrow="Book"
                            title="Request an appointment"
                            subtitle="Tell us what you need and when suits you. Our clinic team will review your request and confirm a time with you."
                        />

                        <ContactInfo />

                        <p className="text-sm text-stone-600">
                            Already submitted a request?{' '}
                            <Link
                                href={route('appointments.lookup.create')}
                                className="font-medium text-teal-700 hover:text-teal-800"
                            >
                                Check its status
                            </Link>
                        </p>
                    </div>

                    <div className="rounded-lg border border-stone-200 bg-white p-8">
                        {submitted ? (
                            <div className="flex flex-col items-center gap-3 py-8 text-center">
                                <CheckCircle2 className="h-10 w-10 text-teal-700" aria-hidden="true" />
                                <h3 className="text-lg font-semibold text-stone-900">
                                    Appointment request submitted
                                </h3>
                                <p className="text-sm leading-relaxed text-stone-600">
                                    Thanks — our clinic team will review your request and get in touch to
                                    confirm a time. This is not a confirmed appointment yet.
                                </p>
                                <Link
                                    href={route('appointments.lookup.create')}
                                    className="text-sm font-medium text-teal-700 hover:text-teal-800"
                                >
                                    Check your appointment status
                                </Link>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
                                <div>
                                    <label htmlFor="service_interest" className="block text-sm font-medium text-stone-700">
                                        Service
                                    </label>
                                    <select
                                        id="service_interest"
                                        value={data.service_interest}
                                        onChange={(e) => setData('service_interest', e.target.value)}
                                        aria-describedby={errors.service_interest ? 'service-error' : undefined}
                                        className={inputClass}
                                    >
                                        <option value="">Select a service</option>
                                        {services.map((service) => (
                                            <option key={service.slug} value={service.name}>
                                                {service.name}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.service_interest && (
                                        <p id="service-error" className="mt-1 text-sm text-red-600">
                                            {errors.service_interest}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="dentist_preference" className="block text-sm font-medium text-stone-700">
                                        Dentist <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <select
                                        id="dentist_preference"
                                        value={data.dentist_preference}
                                        onChange={(e) => setData('dentist_preference', e.target.value)}
                                        className={inputClass}
                                    >
                                        <option value="">No preference</option>
                                        {dentists.map((dentist) => (
                                            <option key={dentist.slug} value={dentist.name}>
                                                {dentist.name} — {dentist.specialty}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div>
                                    <label htmlFor="preferred_date" className="block text-sm font-medium text-stone-700">
                                        Preferred date
                                    </label>
                                    <input
                                        id="preferred_date"
                                        type="date"
                                        min={todayIsoDate()}
                                        value={data.preferred_date}
                                        onChange={(e) => onDateChange(e.target.value)}
                                        aria-describedby={
                                            errors.preferred_date || dateWarning ? 'date-error' : undefined
                                        }
                                        className={inputClass}
                                    />
                                    {(dateWarning || errors.preferred_date) && (
                                        <p id="date-error" className="mt-1 text-sm text-red-600">
                                            {dateWarning ?? errors.preferred_date}
                                        </p>
                                    )}
                                </div>

                                <fieldset>
                                    <legend className="block text-sm font-medium text-stone-700">
                                        Preferred time of day
                                    </legend>
                                    <div className="mt-2 flex gap-4">
                                        {TIMES_OF_DAY.map((option) => (
                                            <label key={option.value} className="flex items-center gap-2 text-sm text-stone-700">
                                                <input
                                                    type="radio"
                                                    name="preferred_time_of_day"
                                                    value={option.value}
                                                    checked={data.preferred_time_of_day === option.value}
                                                    onChange={(e) => setData('preferred_time_of_day', e.target.value)}
                                                    className="text-teal-700 focus:ring-teal-700"
                                                />
                                                {option.label}
                                            </label>
                                        ))}
                                    </div>
                                    {errors.preferred_time_of_day && (
                                        <p className="mt-1 text-sm text-red-600">{errors.preferred_time_of_day}</p>
                                    )}
                                </fieldset>

                                <div>
                                    <label htmlFor="name" className="block text-sm font-medium text-stone-700">
                                        Name
                                    </label>
                                    <input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        aria-describedby={errors.name ? 'name-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.name && (
                                        <p id="name-error" className="mt-1 text-sm text-red-600">
                                            {errors.name}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="email" className="block text-sm font-medium text-stone-700">
                                        Email
                                    </label>
                                    <input
                                        id="email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        aria-describedby={errors.email ? 'email-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.email && (
                                        <p id="email-error" className="mt-1 text-sm text-red-600">
                                            {errors.email}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="phone" className="block text-sm font-medium text-stone-700">
                                        Phone
                                    </label>
                                    <input
                                        id="phone"
                                        type="tel"
                                        value={data.phone}
                                        onChange={(e) => setData('phone', e.target.value)}
                                        aria-describedby={errors.phone ? 'phone-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.phone && (
                                        <p id="phone-error" className="mt-1 text-sm text-red-600">
                                            {errors.phone}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="notes" className="block text-sm font-medium text-stone-700">
                                        Anything else we should know? <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <textarea
                                        id="notes"
                                        rows={3}
                                        value={data.notes}
                                        onChange={(e) => setData('notes', e.target.value)}
                                        className={inputClass}
                                    />
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-md bg-teal-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Sending…' : 'Request Appointment'}
                                </button>

                                <p className="text-xs leading-relaxed text-stone-500">
                                    Submitting a request doesn&rsquo;t book the appointment — our team will
                                    confirm a time with you first.
                                </p>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

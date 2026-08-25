import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ContactInfo from '@/Components/Public/ContactInfo';
import { Calendar, User } from 'lucide-react';

const STATUS_LABELS = {
    requested: 'Requested',
    scheduled: 'Confirmed',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'No-show',
    declined: 'Declined',
};

const STATUS_STYLES = {
    requested: 'bg-amber-50 text-amber-800',
    scheduled: 'bg-teal-50 text-teal-800',
    completed: 'bg-stone-100 text-stone-700',
    cancelled: 'bg-red-50 text-red-700',
    no_show: 'bg-red-50 text-red-700',
    declined: 'bg-red-50 text-red-700',
};

function formatDateTime(startTime, endTime) {
    const start = new Date(startTime);
    const end = new Date(endTime);
    const dateLabel = start.toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
    const timeLabel = `${start.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })} – ${end.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;

    return `${dateLabel}, ${timeLabel}`;
}

function formatPreferred(preferredDate, preferredTimeOfDay) {
    const dateLabel = new Date(`${preferredDate}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    });
    const timeOfDayLabel = preferredTimeOfDay ? preferredTimeOfDay[0].toUpperCase() + preferredTimeOfDay.slice(1) : null;

    return timeOfDayLabel ? `Preferred: ${dateLabel} (${timeOfDayLabel})` : `Preferred: ${dateLabel}`;
}

export default function AppointmentLookupResults({ patientFirstName, appointments }) {
    return (
        <PublicLayout>
            <Head title="Your Appointments" />

            <section className="py-20 sm:py-24">
                <Container className="mx-auto max-w-2xl">
                    <SectionHeading
                        eyebrow="My Appointments"
                        title={`Hi ${patientFirstName}, here's your appointment history`}
                    />

                    <div className="mt-10 flex flex-col gap-4">
                        {appointments.length === 0 ? (
                            <div className="rounded-lg border border-stone-200 bg-white p-8 text-center text-sm text-stone-600">
                                We don&rsquo;t have any appointments on file for you yet.
                            </div>
                        ) : (
                            appointments.map((appointment) => (
                                <div
                                    key={appointment.id}
                                    className="rounded-lg border border-stone-200 bg-white p-6"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <h3 className="text-base font-semibold text-stone-900">
                                            {appointment.service}
                                        </h3>
                                        <span
                                            className={`rounded-full px-3 py-1 text-xs font-medium ${STATUS_STYLES[appointment.status] ?? 'bg-stone-100 text-stone-700'}`}
                                        >
                                            {STATUS_LABELS[appointment.status] ?? appointment.status}
                                        </span>
                                    </div>

                                    <div className="mt-3 flex flex-col gap-2 text-sm text-stone-600">
                                        <div className="flex items-center gap-2">
                                            <Calendar className="h-4 w-4 text-teal-700" aria-hidden="true" />
                                            {appointment.start_time
                                                ? formatDateTime(appointment.start_time, appointment.end_time)
                                                : formatPreferred(
                                                      appointment.preferred_date,
                                                      appointment.preferred_time_of_day,
                                                  )}
                                        </div>
                                        {appointment.provider && (
                                            <div className="flex items-center gap-2">
                                                <User className="h-4 w-4 text-teal-700" aria-hidden="true" />
                                                {appointment.provider}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    <div className="mt-12 border-t border-stone-200 pt-8">
                        <p className="mb-4 text-sm text-stone-600">
                            Need to make a change or have a question? Get in touch:
                        </p>
                        <ContactInfo />
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

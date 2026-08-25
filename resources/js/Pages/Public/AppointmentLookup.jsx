import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import { MailCheck } from 'lucide-react';

const inputClass =
    'mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-teal-700 focus:outline-none focus:ring-1 focus:ring-teal-700';

export default function AppointmentLookup() {
    const [submitted, setSubmitted] = useState(false);
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('appointments.lookup.send'), {
            onSuccess: () => setSubmitted(true),
        });
    }

    return (
        <PublicLayout>
            <Head title="Check Your Appointments" />

            <section className="py-20 sm:py-24">
                <Container className="mx-auto max-w-lg">
                    <SectionHeading
                        eyebrow="My Appointments"
                        title="Check your appointment status"
                        subtitle="Enter the email you used when requesting an appointment. We'll send you a link to view its status — no account needed."
                    />

                    <div className="mt-10 rounded-lg border border-stone-200 bg-white p-8">
                        {submitted ? (
                            <div className="flex flex-col items-center gap-3 py-8 text-center">
                                <MailCheck className="h-10 w-10 text-teal-700" aria-hidden="true" />
                                <h3 className="text-lg font-semibold text-stone-900">Check your inbox</h3>
                                <p className="text-sm leading-relaxed text-stone-600">
                                    If that email has any appointments with us, we&rsquo;ve sent a link to
                                    view them. The link expires in 30 minutes.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
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

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-md bg-teal-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Sending…' : 'Send me the link'}
                                </button>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

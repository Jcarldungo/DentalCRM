import { useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ContactInfo from '@/Components/Public/ContactInfo';
import { CheckCircle2 } from 'lucide-react';

const inputClass =
    'mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-teal-700 focus:outline-none focus:ring-1 focus:ring-teal-700';

export default function Contact({ initialService }) {
    const [submitted, setSubmitted] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        phone: '',
        service_interest: initialService ?? '',
        message: '',
    });

    function submit(e) {
        e.preventDefault();
        post(route('inquiries.store'), {
            onSuccess: () => {
                reset();
                setSubmitted(true);
            },
        });
    }

    return (
        <PublicLayout>
            <Head title="Contact">
                <meta
                    name="description"
                    content="Get in touch with Harborview Dental Clinic to book an appointment or ask a question — our team is happy to help."
                />
            </Head>

            <section className="py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-8">
                        <SectionHeading
                            as="h1"
                            align="left"
                            eyebrow="Contact"
                            title="Get in touch"
                            subtitle="Send us your inquiry and our clinic team will get back to you."
                        />

                        <ContactInfo />
                    </div>

                    <div className="rounded-lg border border-stone-200 bg-white p-8">
                        {submitted ? (
                            <div className="flex flex-col items-center gap-3 py-8 text-center">
                                <CheckCircle2 className="h-10 w-10 text-teal-700" aria-hidden="true" />
                                <h3 className="text-lg font-semibold text-stone-900">Thank you!</h3>
                                <p className="text-sm leading-relaxed text-stone-600">
                                    Thanks — our clinic team will get back to you shortly.
                                </p>
                            </div>
                        ) : (
                            <form onSubmit={submit} className="flex flex-col gap-5" noValidate>
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
                                        Phone <span className="text-stone-400">(optional)</span>
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
                                    <label htmlFor="service_interest" className="block text-sm font-medium text-stone-700">
                                        Service of interest <span className="text-stone-400">(optional)</span>
                                    </label>
                                    <input
                                        id="service_interest"
                                        type="text"
                                        value={data.service_interest}
                                        onChange={(e) => setData('service_interest', e.target.value)}
                                        aria-describedby={errors.service_interest ? 'service_interest-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.service_interest && (
                                        <p id="service_interest-error" className="mt-1 text-sm text-red-600">
                                            {errors.service_interest}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label htmlFor="message" className="block text-sm font-medium text-stone-700">
                                        Message
                                    </label>
                                    <textarea
                                        id="message"
                                        rows={4}
                                        value={data.message}
                                        onChange={(e) => setData('message', e.target.value)}
                                        aria-describedby={errors.message ? 'message-error' : undefined}
                                        className={inputClass}
                                    />
                                    {errors.message && (
                                        <p id="message-error" className="mt-1 text-sm text-red-600">
                                            {errors.message}
                                        </p>
                                    )}
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="inline-flex items-center justify-center rounded-md bg-teal-700 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {processing ? 'Sending…' : 'Send Inquiry'}
                                </button>
                            </form>
                        )}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

import { Head } from '@inertiajs/react';
import PublicLayout, { CLINIC } from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import Button from '@/Components/Public/Button';
import SectionHeading from '@/Components/Public/SectionHeading';
import ServiceCard from '@/Components/Public/ServiceCard';
import DentistCard from '@/Components/Public/DentistCard';
import TestimonialCard from '@/Components/Public/TestimonialCard';
import FaqItem from '@/Components/Public/FaqItem';
import ContactInfo from '@/Components/Public/ContactInfo';
import { services } from '@/Data/services';
import { dentists } from '@/Data/dentists';
import { testimonials } from '@/Data/testimonials';
import { faqs } from '@/Data/faqs';
import { ShieldCheck, HeartPulse, Building2, UserCheck, CalendarCheck } from 'lucide-react';

const WHY_CHOOSE_US = [
    {
        icon: ShieldCheck,
        title: 'Experienced professionals',
        description: 'Our dentists bring years of hands-on experience across general, cosmetic, and pediatric care.',
    },
    {
        icon: HeartPulse,
        title: 'Patient-centered care',
        description: 'We take time to listen and explain every step, so you always know what to expect.',
    },
    {
        icon: Building2,
        title: 'Modern facilities',
        description: 'Our clinic is equipped with up-to-date technology in a clean, comfortable space.',
    },
    {
        icon: UserCheck,
        title: 'Personalized treatment',
        description: 'Every treatment plan is tailored to your specific needs, not a one-size-fits-all approach.',
    },
    {
        icon: CalendarCheck,
        title: 'Convenient scheduling',
        description: 'Flexible hours and a responsive front desk make it easy to find a time that works for you.',
    },
];

export default function Home() {
    return (
        <PublicLayout>
            <Head title="Home">
                <meta
                    name="description"
                    content="Harborview Dental Clinic offers gentle, modern dental care for the whole family, from routine cleanings to advanced treatments."
                />
            </Head>

            <section className="border-b border-stone-200 bg-stone-50">
                <Container className="grid gap-12 py-20 sm:py-28 lg:grid-cols-2 lg:items-center">
                    <div className="flex flex-col items-start gap-6">
                        <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">
                            {CLINIC.name}
                        </span>
                        <h1 className="text-4xl font-semibold tracking-tight text-stone-900 sm:text-5xl">
                            Dental care that puts you at ease.
                        </h1>
                        <p className="max-w-xl text-lg leading-relaxed text-stone-600">
                            From routine cleanings to complete smile makeovers, our team provides gentle,
                            modern dental care in a calm, welcoming environment.
                        </p>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button href={route('book')}>Book an Appointment</Button>
                            <Button href={route('contact')} variant="outline">
                                Contact Us
                            </Button>
                        </div>
                    </div>

                    <div className="relative mx-auto h-64 w-64 sm:h-80 sm:w-80" aria-hidden="true">
                        <div className="absolute inset-0 rounded-full bg-teal-100" />
                        <div className="absolute inset-8 rounded-full bg-teal-200/70" />
                        <div className="absolute inset-16 flex items-center justify-center rounded-full bg-white shadow-sm">
                            <svg viewBox="0 0 24 24" className="h-16 w-16 text-teal-700" fill="none" stroke="currentColor" strokeWidth="1.5">
                                <path
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    d="M8 10c1.5-3 2.5-3 4-3s2.5 0 4 3c1 2-.5 3-1 5-.4 1.6-.5 4-2 4s-1.2-2.7-2-2.7S9.4 19 8 19c-1.5 0-1.6-2.4-2-4-.5-2-2-3-1-5Z"
                                />
                            </svg>
                        </div>
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Services"
                        title="Comprehensive dental care"
                        subtitle="From everyday check-ups to advanced procedures, we offer the full range of dental services under one roof."
                    />
                    <div className="grid w-full gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {services.slice(0, 6).map((service) => (
                            <ServiceCard key={service.slug} service={service} />
                        ))}
                    </div>
                    <Button href={route('services')} variant="outline">
                        View All Services
                    </Button>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Why Harborview" title="Why choose us" />
                    <div className="grid w-full gap-8 sm:grid-cols-2 lg:grid-cols-3">
                        {WHY_CHOOSE_US.map((item) => {
                            const Icon = item.icon;
                            return (
                                <div key={item.title} className="flex flex-col gap-3">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                                        <Icon className="h-6 w-6" aria-hidden="true" />
                                    </div>
                                    <h3 className="text-base font-semibold text-stone-900">{item.title}</h3>
                                    <p className="text-sm leading-relaxed text-stone-600">{item.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        eyebrow="Our Team"
                        title="Meet our dentists"
                        subtitle="A team of experienced, approachable dental professionals dedicated to your care."
                    />
                    <div className="grid w-full gap-8 sm:grid-cols-3">
                        {dentists.map((dentist) => (
                            <DentistCard key={dentist.slug} dentist={dentist} />
                        ))}
                    </div>
                    <Button href={route('dentists')} variant="outline">
                        Meet Our Dentists
                    </Button>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Testimonials" title="What our patients say" />
                    <div className="grid w-full gap-6 sm:grid-cols-3">
                        {testimonials.map((testimonial) => (
                            <TestimonialCard key={testimonial.name} testimonial={testimonial} />
                        ))}
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-2">
                    <SectionHeading eyebrow="FAQ" title="Frequently asked questions" />
                    <div className="mt-8">
                        {faqs.map((faq) => (
                            <FaqItem key={faq.question} question={faq.question} answer={faq.answer} />
                        ))}
                    </div>
                </Container>
            </section>

            <section className="border-t border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="grid gap-10 lg:grid-cols-2 lg:items-center">
                    <div className="flex flex-col gap-4">
                        <h2 className="text-3xl font-semibold tracking-tight text-stone-900">Visit us</h2>
                        <ContactInfo />
                    </div>

                    <div className="flex flex-col items-start gap-4 rounded-lg border border-stone-200 bg-white p-8">
                        <h3 className="text-xl font-semibold text-stone-900">Have a question?</h3>
                        <p className="text-sm leading-relaxed text-stone-600">
                            Send us your inquiry and our clinic team will get back to you.
                        </p>
                        <Button href={route('contact')}>Contact Us</Button>
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

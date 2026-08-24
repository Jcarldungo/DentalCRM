import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import { HeartPulse, Target, Sparkles, Building2 } from 'lucide-react';

const VALUES = [
    { icon: HeartPulse, title: 'Compassion', description: 'We treat every patient with warmth, patience, and respect.' },
    { icon: Target, title: 'Precision', description: 'We hold our clinical work to a high, consistent standard.' },
    { icon: Sparkles, title: 'Comfort', description: 'We design every visit to feel calm, not clinical.' },
    { icon: Building2, title: 'Community', description: "We're proud to be a trusted part of the neighborhoods we serve." },
];

export default function About() {
    return (
        <PublicLayout>
            <Head title="About">
                <meta
                    name="description"
                    content="Learn about Harborview Dental Clinic's mission, values, and commitment to patient-centered dental care in a calm environment."
                />
            </Head>

            <section className="py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-6 text-center">
                    <span className="text-sm font-semibold uppercase tracking-wide text-teal-700">Our Story</span>
                    <h1 className="text-3xl font-semibold tracking-tight text-stone-900 sm:text-4xl">
                        Dentistry built around people, not procedures.
                    </h1>
                    <p className="text-lg leading-relaxed text-stone-600">
                        Harborview Dental Clinic was founded to offer a different kind of dental visit —
                        one where patients feel heard, informed, and comfortable from the moment they walk
                        in. What started as a small practice has grown into a full-service clinic, but our
                        approach hasn't changed: careful, personalized care in a calm environment.
                    </p>
                </Container>
            </section>

            <section className="border-y border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="grid gap-12 lg:grid-cols-2">
                    <div className="flex flex-col gap-3">
                        <h2 className="text-2xl font-semibold text-stone-900">Mission</h2>
                        <p className="leading-relaxed text-stone-600">
                            To provide accessible, high-quality dental care that helps every patient
                            maintain a healthy, confident smile for life.
                        </p>
                    </div>
                    <div className="flex flex-col gap-3">
                        <h2 className="text-2xl font-semibold text-stone-900">Vision</h2>
                        <p className="leading-relaxed text-stone-600">
                            To be the clinic our community trusts first — known for gentle care, modern
                            treatment, and lasting patient relationships.
                        </p>
                    </div>
                </Container>
            </section>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading eyebrow="Values" title="What we stand for" />
                    <div className="grid w-full gap-8 sm:grid-cols-2 lg:grid-cols-4">
                        {VALUES.map((value) => {
                            const Icon = value.icon;
                            return (
                                <div key={value.title} className="flex flex-col items-center gap-3 text-center">
                                    <div className="flex h-11 w-11 items-center justify-center rounded-md bg-teal-50 text-teal-700">
                                        <Icon className="h-6 w-6" aria-hidden="true" />
                                    </div>
                                    <h3 className="text-base font-semibold text-stone-900">{value.title}</h3>
                                    <p className="text-sm leading-relaxed text-stone-600">{value.description}</p>
                                </div>
                            );
                        })}
                    </div>
                </Container>
            </section>

            <section className="border-t border-stone-200 bg-stone-50 py-20 sm:py-24">
                <Container className="mx-auto flex max-w-3xl flex-col gap-4 text-center">
                    <h2 className="text-2xl font-semibold text-stone-900">Our facilities</h2>
                    <p className="leading-relaxed text-stone-600">
                        Our clinic is equipped with modern dental technology in a clean, welcoming space —
                        designed to make every visit as comfortable as possible, for patients of every age.
                    </p>
                </Container>
            </section>
        </PublicLayout>
    );
}

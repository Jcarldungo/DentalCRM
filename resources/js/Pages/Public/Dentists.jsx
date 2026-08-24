import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import DentistCard from '@/Components/Public/DentistCard';
import { dentists } from '@/Data/dentists';

export default function Dentists() {
    return (
        <PublicLayout>
            <Head title="Dentists">
                <meta
                    name="description"
                    content="Meet the experienced, approachable dentists at Harborview Dental Clinic, dedicated to your comfort and dental health."
                />
            </Head>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        as="h1"
                        eyebrow="Our Team"
                        title="Meet our dentists"
                        subtitle="An experienced, approachable team dedicated to your comfort and care."
                    />
                    <div className="grid w-full gap-8 sm:grid-cols-3">
                        {dentists.map((dentist) => (
                            <DentistCard key={dentist.slug} dentist={dentist} />
                        ))}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

import { Head } from '@inertiajs/react';
import PublicLayout from '@/Layouts/PublicLayout';
import Container from '@/Components/Public/Container';
import SectionHeading from '@/Components/Public/SectionHeading';
import ServiceCard from '@/Components/Public/ServiceCard';
import { services } from '@/Data/services';

export default function Services() {
    return (
        <PublicLayout>
            <Head title="Services">
                <meta
                    name="description"
                    content="Explore Harborview Dental Clinic's full range of dental services, from routine check-ups to cosmetic and restorative treatments."
                />
            </Head>

            <section className="py-20 sm:py-24">
                <Container className="flex flex-col items-center gap-12">
                    <SectionHeading
                        as="h1"
                        eyebrow="Services"
                        title="Our dental services"
                        subtitle="Comprehensive care for every stage of your dental health, from routine visits to advanced procedures."
                    />
                    <div className="grid w-full gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        {services.map((service) => (
                            <ServiceCard key={service.slug} service={service} />
                        ))}
                    </div>
                </Container>
            </section>
        </PublicLayout>
    );
}

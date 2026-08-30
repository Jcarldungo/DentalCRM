import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { inquiryStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { Check, Inbox, Mail, Phone } from 'lucide-react';

export default function Index({ inquiries }) {
    const unread = inquiries.filter((inquiry) => !inquiry.read_at).length;

    return (
        <AuthenticatedLayout title="Inquiries">
            <Head title="Inquiries" />

            <PageContainer>
                <PageHeader
                    title="Inquiries"
                    description={
                        inquiries.length === 0
                            ? 'Messages from the public contact form land here.'
                            : `${unread} unread of ${inquiries.length}`
                    }
                />

                <Card>
                    {inquiries.length === 0 ? (
                        <EmptyState
                            icon={Inbox}
                            title="No inquiries yet"
                            description="Messages submitted through the public contact form appear here for the front desk to answer."
                        />
                    ) : (
                        <ul className="divide-y divide-slate-200">
                            {inquiries.map((inquiry) => (
                                <li
                                    key={inquiry.id}
                                    className={`px-4 py-4 sm:px-5 ${inquiry.read_at ? '' : 'bg-amber-50/40'}`}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <p className="text-sm font-semibold text-slate-900">
                                                    {inquiry.name}
                                                </p>
                                                <StatusBadge
                                                    status={inquiryStatus(inquiry.read_at ? 'read' : 'new')}
                                                />
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-0.5 text-xs text-slate-500">
                                                <a
                                                    href={`mailto:${inquiry.email}`}
                                                    className="inline-flex min-w-0 items-center gap-1 hover:text-brand-700"
                                                >
                                                    <Mail className="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    <span className="truncate">{inquiry.email}</span>
                                                </a>
                                                {inquiry.phone && (
                                                    <a
                                                        href={`tel:${inquiry.phone}`}
                                                        className="inline-flex items-center gap-1 hover:text-brand-700"
                                                    >
                                                        <Phone className="h-3 w-3" aria-hidden="true" />
                                                        {inquiry.phone}
                                                    </a>
                                                )}
                                                <span>{inquiry.created_at}</span>
                                            </div>
                                        </div>

                                        {!inquiry.read_at && (
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                icon={Check}
                                                onClick={() =>
                                                    router.patch(
                                                        route('inquiries.update', inquiry.id),
                                                        { read: true },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                Mark as read
                                            </Button>
                                        )}
                                    </div>

                                    {inquiry.service_interest && (
                                        <p className="mt-2 text-xs font-medium text-slate-600">
                                            Interested in: {inquiry.service_interest}
                                        </p>
                                    )}

                                    <p className="mt-2 whitespace-pre-line rounded-lg bg-white px-3 py-2 text-sm leading-relaxed text-slate-700 ring-1 ring-slate-200">
                                        {inquiry.message}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </PageContainer>
        </AuthenticatedLayout>
    );
}

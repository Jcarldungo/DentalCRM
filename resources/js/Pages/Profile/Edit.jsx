import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import { PageContainer, PageHeader } from '@/Components/UI/Page';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <AuthenticatedLayout title="Profile">
            <Head title="Profile" />

            <PageContainer className="max-w-3xl">
                <PageHeader
                    title="Your account"
                    description="Every staff account has the same access — there are no roles."
                />

                <div className="space-y-5">
                    <Card>
                        <CardBody>
                            <UpdateProfileInformationForm mustVerifyEmail={mustVerifyEmail} status={status} />
                        </CardBody>
                    </Card>

                    <Card>
                        <CardBody>
                            <UpdatePasswordForm />
                        </CardBody>
                    </Card>

                    <Card className="border-rose-200">
                        <CardBody>
                            <DeleteUserForm />
                        </CardBody>
                    </Card>
                </div>
            </PageContainer>
        </AuthenticatedLayout>
    );
}

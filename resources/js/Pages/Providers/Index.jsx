import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import Field, { CheckboxField } from '@/Components/UI/Field';
import Modal, { ConfirmDialog } from '@/Components/UI/Modal';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2, UserCog } from 'lucide-react';
import { useState } from 'react';

const EMPTY = { name: '', specialty: '', active: true };

export default function Index({ providers }) {
    const { errors: pageErrors } = usePage().props;
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [confirming, setConfirming] = useState(null);
    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm(EMPTY);

    function openCreate() {
        clearErrors();
        reset();
        setData(EMPTY);
        setEditing(null);
        setShowForm(true);
    }

    function openEdit(provider) {
        clearErrors();
        setEditing(provider);
        setData({ name: provider.name, specialty: provider.specialty ?? '', active: provider.active });
        setShowForm(true);
    }

    function submit(event) {
        event.preventDefault();
        const done = { onSuccess: () => setShowForm(false), preserveScroll: true };

        if (editing) {
            put(route('providers.update', editing.id), done);
        } else {
            post(route('providers.store'), done);
        }
    }

    const active = providers.filter((provider) => provider.active).length;

    return (
        <AuthenticatedLayout
            title="Providers"
            actions={
                <Button size="sm" icon={Plus} onClick={openCreate}>
                    Add
                </Button>
            }
        >
            <Head title="Providers" />

            <PageContainer>
                <PageHeader
                    title="Providers"
                    description={`${active} active of ${providers.length}`}
                    actions={
                        <Button icon={Plus} onClick={openCreate} className="hidden lg:inline-flex">
                            Add provider
                        </Button>
                    }
                />

                {/* The delete guard returns its refusal here; without this it
                    was discarded silently and the row simply stayed put. */}
                {pageErrors?.provider && (
                    <div
                        role="alert"
                        className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800"
                    >
                        {pageErrors.provider}
                    </div>
                )}

                <Card>
                    {providers.length === 0 ? (
                        <EmptyState
                            icon={UserCog}
                            title="No providers yet"
                            description="Providers are the dentists and hygienists appointments are booked against."
                            action={
                                <Button icon={Plus} onClick={openCreate}>
                                    Add the first provider
                                </Button>
                            }
                        />
                    ) : (
                        <ul className="divide-y divide-slate-200">
                            {providers.map((provider) => (
                                <li
                                    key={provider.id}
                                    className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-5"
                                >
                                    <div className="min-w-0">
                                        <p
                                            className={`truncate text-sm font-medium ${provider.active ? 'text-slate-900' : 'text-slate-500'}`}
                                        >
                                            {provider.name}
                                        </p>
                                        <p className="truncate text-xs text-slate-500">
                                            {provider.specialty || 'No specialty recorded'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <StatusBadge
                                            status={{
                                                label: provider.active ? 'Active' : 'Inactive',
                                                tone: provider.active ? 'success' : 'muted',
                                            }}
                                        />
                                        <Button variant="ghost" size="sm" onClick={() => openEdit(provider)}>
                                            Edit
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setConfirming(provider)}
                                            aria-label={`Delete ${provider.name}`}
                                            className="text-slate-400 hover:bg-rose-50 hover:text-rose-700"
                                        >
                                            <Trash2 className="h-4 w-4" aria-hidden="true" />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </PageContainer>

            <Modal
                as="form"
                onSubmit={submit}
                show={showForm}
                onClose={() => setShowForm(false)}
                closeable={!processing}
                title={editing ? `Edit ${editing.name}` : 'Add provider'}
                width="md"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowForm(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field
                        label="Name"
                        required
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        error={errors.name}
                    />
                    <Field
                        label="Specialty"
                        value={data.specialty}
                        onChange={(e) => setData('specialty', e.target.value)}
                        error={errors.specialty}
                    />
                    <CheckboxField
                        label="Active"
                        hint="Inactive providers keep their history but stop appearing in pickers."
                        checked={data.active}
                        onChange={(e) => setData('active', e.target.checked)}
                    />
                </div>
            </Modal>

            <ConfirmDialog
                show={confirming !== null}
                onClose={() => setConfirming(null)}
                onConfirm={() =>
                    router.delete(route('providers.destroy', confirming.id), {
                        preserveScroll: true,
                        onFinish: () => setConfirming(null),
                    })
                }
                title={`Delete ${confirming?.name}?`}
                confirmLabel="Delete provider"
                body="A provider with any appointment history is protected and will be refused — mark them inactive instead, which keeps their record intact."
            />
        </AuthenticatedLayout>
    );
}

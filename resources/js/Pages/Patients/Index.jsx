import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import Field, { TextareaField } from '@/Components/UI/Field';
import Modal, { ConfirmDialog } from '@/Components/UI/Modal';
import { EmptyState, PageContainer, PageHeader } from '@/Components/UI/Page';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Mail, Phone, Plus, Search, Trash2, UserPlus, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { formatDate, formatPeso } from './format';

const EMPTY = {
    first_name: '',
    last_name: '',
    date_of_birth: '',
    phone: '',
    email: '',
    emergency_contact_name: '',
    emergency_contact_phone: '',
    notes: '',
    recall_interval_months: '',
};

/** A debounced, replace-in-place search so typing doesn't stack history entries. */
function useSearch(initial) {
    const [term, setTerm] = useState(initial);
    const first = useRef(true);

    useEffect(() => {
        if (first.current) {
            first.current = false;
            return undefined;
        }

        const timer = setTimeout(() => {
            router.get(
                route('patients.index'),
                term ? { search: term } : {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term]);

    return [term, setTerm];
}

function PatientRow({ patient, summary, onEdit, onDelete }) {
    return (
        <li className="group relative flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors hover:bg-slate-50 sm:flex-nowrap sm:px-5">
            <div className="min-w-0 flex-1 basis-full sm:basis-auto">
                <Link
                    href={route('patients.show', patient.id)}
                    className="text-sm font-medium text-slate-900 after:absolute after:inset-0 hover:text-brand-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500"
                >
                    {patient.full_name}
                </Link>
                <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500">
                    {patient.phone && (
                        <span className="inline-flex items-center gap-1">
                            <Phone className="h-3 w-3" aria-hidden="true" />
                            {patient.phone}
                        </span>
                    )}
                    {patient.email && (
                        <span className="inline-flex min-w-0 items-center gap-1">
                            <Mail className="h-3 w-3 shrink-0" aria-hidden="true" />
                            <span className="truncate">{patient.email}</span>
                        </span>
                    )}
                    {!patient.phone && !patient.email && <span>No contact details</span>}
                </div>
            </div>

            <dl className="flex shrink-0 flex-wrap items-center gap-x-6 gap-y-1 text-xs sm:w-auto">
                <div className="w-24">
                    <dt className="text-slate-400">Last visit</dt>
                    <dd className="tabular text-slate-700">
                        {summary?.last_visit ? formatDate(summary.last_visit) : '—'}
                    </dd>
                </div>
                <div className="w-24">
                    <dt className="text-slate-400">Next visit</dt>
                    <dd className="tabular text-slate-700">
                        {summary?.next_visit ? formatDate(summary.next_visit) : '—'}
                    </dd>
                </div>
                <div className="w-24">
                    <dt className="text-slate-400">Balance</dt>
                    <dd
                        className={`tabular font-medium ${
                            summary?.balance > 0 ? 'text-amber-700' : 'text-slate-500'
                        }`}
                    >
                        {summary?.balance > 0 ? formatPeso(summary.balance) : '—'}
                    </dd>
                </div>
            </dl>

            {/* Above the row link's ::after so they stay clickable. */}
            <div className="relative z-10 flex shrink-0 items-center gap-1">
                <Button variant="ghost" size="sm" onClick={onEdit}>
                    Edit
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onDelete}
                    aria-label={`Delete ${patient.full_name}`}
                    className="text-slate-400 hover:bg-rose-50 hover:text-rose-700"
                >
                    <Trash2 className="h-4 w-4" aria-hidden="true" />
                </Button>
            </div>
        </li>
    );
}

export default function Index({ patients, summaries, filters }) {
    const [term, setTerm] = useSearch(filters.search ?? '');
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [confirming, setConfirming] = useState(null);
    const { data, setData, post, put, processing, errors, clearErrors, reset } = useForm(EMPTY);

    function openCreate() {
        clearErrors();
        reset();
        setData(EMPTY);
        setEditing(null);
        setShowForm(true);
    }

    function openEdit(patient) {
        clearErrors();
        setEditing(patient);
        setData(
            Object.fromEntries(Object.keys(EMPTY).map((key) => [key, patient[key] ?? ''])),
        );
        setShowForm(true);
    }

    function submit(event) {
        event.preventDefault();
        const done = { onSuccess: () => setShowForm(false), preserveScroll: true };

        if (editing) {
            put(route('patients.update', editing.id), done);
        } else {
            post(route('patients.store'), done);
        }
    }

    const field = (label, name, props = {}) => (
        <Field
            label={label}
            value={data[name]}
            onChange={(e) => setData(name, e.target.value)}
            error={errors[name]}
            {...props}
        />
    );

    return (
        <AuthenticatedLayout
            title="Patients"
            actions={
                <Button size="sm" icon={Plus} onClick={openCreate}>
                    Add patient
                </Button>
            }
        >
            <Head title="Patients" />

            <PageContainer>
                <PageHeader
                    title="Patients"
                    description={`${patients.total} patient${patients.total === 1 ? '' : 's'} on file`}
                    actions={
                        <Button icon={Plus} onClick={openCreate} className="hidden lg:inline-flex">
                            Add patient
                        </Button>
                    }
                />

                <div className="relative mb-4 max-w-md">
                    <Search
                        className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"
                        aria-hidden="true"
                    />
                    <input
                        type="search"
                        value={term}
                        onChange={(e) => setTerm(e.target.value)}
                        aria-label="Search patients by name, phone, or email"
                        placeholder="Search by name, phone, or email…"
                        className="h-10 w-full rounded-lg border-slate-300 ps-9 pe-9 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                    />
                    {term && (
                        <button
                            type="button"
                            onClick={() => setTerm('')}
                            aria-label="Clear search"
                            className="absolute end-2 top-1/2 -translate-y-1/2 rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            <X className="h-4 w-4" aria-hidden="true" />
                        </button>
                    )}
                </div>

                <Card>
                    {patients.data.length === 0 ? (
                        filters.search ? (
                            <EmptyState
                                icon={Search}
                                title={`No patients match “${filters.search}”`}
                                description="Try part of a name, a phone number, or an email address."
                                action={
                                    <Button variant="secondary" onClick={() => setTerm('')}>
                                        Clear search
                                    </Button>
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={Users}
                                title="No patients yet"
                                description="Add a patient here, or let one arrive through the public booking form."
                                action={
                                    <Button icon={UserPlus} onClick={openCreate}>
                                        Add the first patient
                                    </Button>
                                }
                            />
                        )
                    ) : (
                        <ul className="divide-y divide-slate-200">
                            {patients.data.map((patient) => (
                                <PatientRow
                                    key={patient.id}
                                    patient={patient}
                                    summary={summaries[patient.id]}
                                    onEdit={() => openEdit(patient)}
                                    onDelete={() => setConfirming(patient)}
                                />
                            ))}
                        </ul>
                    )}
                </Card>

                {patients.last_page > 1 && (
                    <nav
                        aria-label="Patient list pages"
                        className="mt-4 flex flex-wrap items-center justify-between gap-3"
                    >
                        <p className="text-xs text-slate-500">
                            Showing {patients.from}–{patients.to} of {patients.total}
                        </p>
                        <div className="flex flex-wrap gap-1">
                            {patients.links.map((link, index) => (
                                <Link
                                    key={index}
                                    href={link.url ?? '#'}
                                    preserveScroll
                                    aria-current={link.active ? 'page' : undefined}
                                    aria-disabled={!link.url}
                                    className={`inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-3 text-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${
                                        link.active
                                            ? 'bg-brand-600 font-medium text-white'
                                            : link.url
                                              ? 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                              : 'pointer-events-none border border-slate-200 text-slate-300'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </nav>
                )}
            </PageContainer>

            <Modal
                as="form"
                onSubmit={submit}
                show={showForm}
                onClose={() => setShowForm(false)}
                closeable={!processing}
                title={editing ? `Edit ${editing.full_name}` : 'Add patient'}
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowForm(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : editing ? 'Save changes' : 'Add patient'}
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {field('First name', 'first_name', { required: true, autoComplete: 'given-name' })}
                    {field('Last name', 'last_name', { required: true, autoComplete: 'family-name' })}
                    {field('Date of birth', 'date_of_birth', { type: 'date' })}
                    {field('Phone', 'phone', { type: 'tel', autoComplete: 'tel' })}
                    {field('Email', 'email', {
                        type: 'email',
                        hint: 'Used to match public booking requests to this patient.',
                    })}
                    {field('Recall interval', 'recall_interval_months', {
                        type: 'number',
                        min: 1,
                        max: 60,
                        hint: 'Months between cleanings. Defaults to 6.',
                    })}
                    {field('Emergency contact', 'emergency_contact_name')}
                    {field('Emergency contact phone', 'emergency_contact_phone', { type: 'tel' })}
                </div>
                <TextareaField
                    label="Notes"
                    className="mt-4"
                    value={data.notes}
                    onChange={(e) => setData('notes', e.target.value)}
                    error={errors.notes}
                    hint="Allergies, access needs, anything the front desk should see first."
                />
            </Modal>

            <ConfirmDialog
                show={confirming !== null}
                onClose={() => setConfirming(null)}
                onConfirm={() =>
                    router.delete(route('patients.destroy', confirming.id), {
                        preserveScroll: true,
                        onFinish: () => setConfirming(null),
                    })
                }
                title={`Delete ${confirming?.full_name}?`}
                confirmLabel="Delete patient"
                body="This cannot be undone. A patient with any appointment, clinical record, or invoice is protected and will be refused — only a record created by mistake, with nothing attached, can be deleted."
            />
        </AuthenticatedLayout>
    );
}

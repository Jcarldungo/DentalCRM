import Button from '@/Components/UI/Button';
import Card, { CardBody, CardHeader } from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { DetailItem, EmptyState, PageContainer, SectionHeading } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import Tabs, { TabPanel } from '@/Components/UI/Tabs';
import { toothCondition, treatmentPriority, treatmentStatus } from '@/Components/UI/statuses';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { ClipboardList, FileText, Plus } from 'lucide-react';
import { useState } from 'react';
import BillingTab from './BillingTab';
import DentalChart, { ALL_TEETH } from './DentalChart';
import PatientHeader from './PatientHeader';
import PrescriptionsTab from './PrescriptionsTab';
import { formatDate, formatDateTime, formatPeso } from './format';

const RECORD_TYPES = ['consultation', 'procedure', 'follow_up', 'other'];
const TOOTH_CONDITIONS = [
    'healthy',
    'caries',
    'filling',
    'crown',
    'missing',
    'extraction',
    'root_canal',
    'implant',
    'other',
];
const TREATMENT_PRIORITIES = ['low', 'medium', 'high'];
const TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress', 'completed', 'cancelled'];
const OPEN_TREATMENT_STATUSES = ['planned', 'scheduled', 'in_progress'];

/** The appointment picker's option label, shared by all three clinical forms. */
function appointmentLabel(appointment) {
    const when = appointment.start_time ? formatDateTime(appointment.start_time) : 'Unscheduled';

    return `${when} — ${appointment.type ?? 'request'}`;
}

function AppointmentPicker({ appointments, value, onChange, error }) {
    return (
        <SelectField label="Linked appointment" value={value} onChange={onChange} error={error}>
            <option value="">No linked appointment</option>
            {appointments.map((appointment) => (
                <option key={appointment.id} value={appointment.id}>
                    {appointmentLabel(appointment)}
                </option>
            ))}
        </SelectField>
    );
}

function ProviderPicker({ providers, value, onChange, error, label = 'Provider' }) {
    return (
        <SelectField label={label} value={value} onChange={onChange} error={error}>
            <option value="">No provider</option>
            {providers.map((provider) => (
                <option key={provider.id} value={provider.id}>
                    {provider.name}
                </option>
            ))}
        </SelectField>
    );
}

function RecordCard({ record }) {
    const sections = [
        ['Examination', record.examination],
        ['Diagnosis', record.diagnosis],
        ['Procedure', record.procedure],
        ['Notes', record.notes],
    ].filter(([, value]) => value);

    return (
        <Card>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge status={{ label: record.type.replace('_', ' '), tone: 'info' }} />
                    <span className="tabular text-xs text-slate-500">{formatDate(record.created_at)}</span>
                </div>
                <span className="text-xs text-slate-500">
                    {record.provider_name ?? 'No provider'}
                    {record.appointment_start_time && ` · ${formatDateTime(record.appointment_start_time)}`}
                </span>
            </div>

            <dl className="divide-y divide-slate-100">
                {sections.map(([label, value]) => (
                    <div key={label} className="px-4 py-2.5 sm:flex sm:gap-4">
                        <dt className="w-28 shrink-0 text-xs font-medium uppercase tracking-wide text-slate-500">
                            {label}
                        </dt>
                        <dd className="mt-0.5 text-sm leading-relaxed text-slate-800 sm:mt-0">{value}</dd>
                    </div>
                ))}
            </dl>

            <p className="border-t border-slate-100 px-4 py-2 text-xs text-slate-400">
                Logged by {record.creator_name}
            </p>
        </Card>
    );
}

function TreatmentCard({ item, onEdit }) {
    return (
        <Card className="p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h4 className="text-sm font-semibold text-slate-900">{item.treatment}</h4>
                    <p className="mt-0.5 text-xs text-slate-500">
                        {item.tooth_number ? `Tooth ${item.tooth_number}` : 'Whole mouth'}
                        {item.provider_name && ` · ${item.provider_name}`}
                        {item.appointment_start_time && ` · ${formatDateTime(item.appointment_start_time)}`}
                    </p>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                    <span className="tabular text-sm font-semibold text-slate-900">
                        {formatPeso(item.estimated_cost)}
                    </span>
                    <Button variant="secondary" size="sm" onClick={onEdit}>
                        Edit
                    </Button>
                </div>
            </div>

            <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
                <StatusBadge status={treatmentStatus(item.status)} />
                <StatusBadge status={treatmentPriority(item.priority)}>
                    {treatmentPriority(item.priority).label} priority
                </StatusBadge>
            </div>

            {item.notes && <p className="mt-2.5 text-sm leading-relaxed text-slate-700">{item.notes}</p>}

            <p className="mt-3 border-t border-slate-100 pt-2.5 text-xs text-slate-400">
                Added by {item.creator_name} on {formatDate(item.created_at)}
            </p>
        </Card>
    );
}

export default function Show({
    patient,
    summary,
    dentalRecords,
    toothConditions,
    treatmentPlanItems,
    billableTreatmentItems,
    prescriptions,
    invoices,
    providers,
    appointments,
}) {
    const [tab, setTab] = useState('overview');
    const [showEdit, setShowEdit] = useState(false);
    const [showRecord, setShowRecord] = useState(false);
    const [selectedTooth, setSelectedTooth] = useState(null);
    const [showTreatment, setShowTreatment] = useState(false);
    const [editingTreatment, setEditingTreatment] = useState(null);

    const openTreatments = treatmentPlanItems.filter((item) =>
        OPEN_TREATMENT_STATUSES.includes(item.status),
    );
    const resolvedTreatments = treatmentPlanItems.filter(
        (item) => !OPEN_TREATMENT_STATUSES.includes(item.status),
    );
    const activeRx = prescriptions.filter((rx) => rx.status === 'active');
    const balance = invoices
        .filter((invoice) => invoice.status !== 'void')
        .reduce((sum, invoice) => sum + invoice.balance, 0);

    const patientForm = useForm({
        first_name: patient.first_name,
        last_name: patient.last_name,
        date_of_birth: patient.date_of_birth ?? '',
        phone: patient.phone ?? '',
        email: patient.email ?? '',
        emergency_contact_name: patient.emergency_contact_name ?? '',
        emergency_contact_phone: patient.emergency_contact_phone ?? '',
        notes: patient.notes ?? '',
        recall_interval_months: patient.recall_interval_months ?? '',
    });

    const recordForm = useForm({
        type: 'consultation',
        provider_id: '',
        appointment_id: '',
        examination: '',
        diagnosis: '',
        procedure: '',
        notes: '',
    });

    const toothForm = useForm({
        tooth_number: null,
        condition: 'healthy',
        notes: '',
        provider_id: '',
        appointment_id: '',
    });

    const treatmentForm = useForm({
        treatment: '',
        tooth_number: '',
        provider_id: '',
        appointment_id: '',
        estimated_cost: '',
        priority: 'medium',
        notes: '',
    });

    const treatmentEditForm = useForm({
        status: 'planned',
        priority: 'medium',
        estimated_cost: '',
        notes: '',
    });

    function openTooth(number) {
        toothForm.clearErrors();
        toothForm.setData({
            tooth_number: number,
            condition: 'healthy',
            notes: '',
            provider_id: '',
            appointment_id: '',
        });
        setSelectedTooth(number);
    }

    function openTreatmentEdit(item) {
        treatmentEditForm.clearErrors();
        treatmentEditForm.setData({
            status: item.status,
            priority: item.priority,
            estimated_cost: item.estimated_cost,
            notes: item.notes ?? '',
        });
        setEditingTreatment(item);
    }

    const submit = (form, url, onDone) => (event) => {
        event.preventDefault();
        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onDone();
            },
        });
    };

    const selectedToothEntries = toothConditions.filter(
        (entry) => entry.tooth_number === selectedTooth,
    );

    const tabs = [
        { id: 'overview', label: 'Overview' },
        { id: 'records', label: 'Records', count: dentalRecords.length },
        { id: 'chart', label: 'Dental chart', count: toothConditions.length },
        { id: 'treatment', label: 'Treatment plan', count: openTreatments.length },
        { id: 'prescriptions', label: 'Prescriptions', count: activeRx.length },
        { id: 'billing', label: 'Billing', count: invoices.length },
    ];

    return (
        <AuthenticatedLayout title={`${patient.first_name} ${patient.last_name}`}>
            <Head title={`${patient.first_name} ${patient.last_name}`} />

            <PageContainer>
                <PatientHeader
                    patient={patient}
                    summary={summary}
                    balance={balance}
                    openTreatments={openTreatments.length}
                    activeRx={activeRx.length}
                    onEdit={() => {
                        patientForm.clearErrors();
                        setShowEdit(true);
                    }}
                />

                <Tabs tabs={tabs} active={tab} onChange={setTab} className="mb-5" />

                <TabPanel id="overview" active={tab}>
                    <div className="grid gap-5 lg:grid-cols-2">
                        <Card>
                            <CardHeader title="Contact & emergency" />
                            <CardBody>
                                <dl className="grid grid-cols-2 gap-4">
                                    <DetailItem label="Date of birth">
                                        {patient.date_of_birth ? formatDate(patient.date_of_birth) : null}
                                    </DetailItem>
                                    <DetailItem label="Phone">{patient.phone}</DetailItem>
                                    <DetailItem label="Email" className="col-span-2">
                                        {patient.email}
                                    </DetailItem>
                                    <DetailItem label="Emergency contact">
                                        {patient.emergency_contact_name}
                                    </DetailItem>
                                    <DetailItem label="Emergency phone">
                                        {patient.emergency_contact_phone}
                                    </DetailItem>
                                    <DetailItem label="Recall interval">
                                        {patient.recall_interval_months
                                            ? `${patient.recall_interval_months} months`
                                            : '6 months (default)'}
                                    </DetailItem>
                                </dl>
                            </CardBody>
                        </Card>

                        <Card>
                            <CardHeader title="Recent clinical activity" />
                            {dentalRecords.length === 0 ? (
                                <EmptyState
                                    icon={FileText}
                                    title="No clinical records yet"
                                    description="Examinations, diagnoses, and procedures recorded on this patient appear here."
                                />
                            ) : (
                                <ul className="divide-y divide-slate-100">
                                    {dentalRecords.slice(0, 4).map((record) => (
                                        <li key={record.id} className="px-4 py-3 sm:px-5">
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                <StatusBadge
                                                    status={{ label: record.type.replace('_', ' '), tone: 'info' }}
                                                />
                                                <span className="tabular text-xs text-slate-500">
                                                    {formatDate(record.created_at)}
                                                </span>
                                            </div>
                                            <p className="mt-1.5 line-clamp-2 text-sm text-slate-700">
                                                {record.diagnosis || record.examination || record.procedure || record.notes}
                                            </p>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {dentalRecords.length > 4 && (
                                <div className="border-t border-slate-200 px-4 py-2.5 sm:px-5">
                                    <button
                                        type="button"
                                        onClick={() => setTab('records')}
                                        className="text-sm font-medium text-brand-700 hover:text-brand-800"
                                    >
                                        View all {dentalRecords.length} records
                                    </button>
                                </div>
                            )}
                        </Card>
                    </div>
                </TabPanel>

                <TabPanel id="records" active={tab}>
                    <SectionHeading
                        title="Dental records"
                        count={dentalRecords.length}
                        actions={
                            <Button
                                size="sm"
                                icon={Plus}
                                onClick={() => {
                                    recordForm.clearErrors();
                                    setShowRecord(true);
                                }}
                            >
                                New record
                            </Button>
                        }
                    />
                    {dentalRecords.length === 0 ? (
                        <Card>
                            <EmptyState
                                icon={FileText}
                                title="No dental records yet"
                                description="Records are append-only — once saved, a record stays on the chart exactly as written."
                            />
                        </Card>
                    ) : (
                        <div className="space-y-3">
                            {dentalRecords.map((record) => (
                                <RecordCard key={record.id} record={record} />
                            ))}
                        </div>
                    )}
                </TabPanel>

                <TabPanel id="chart" active={tab}>
                    <Card>
                        <CardHeader
                            title="Dental chart"
                            description="Each tooth shows its most recent charted condition."
                        />
                        <CardBody>
                            <DentalChart
                                toothConditions={toothConditions}
                                selected={selectedTooth}
                                onSelect={openTooth}
                            />
                        </CardBody>
                    </Card>
                </TabPanel>

                <TabPanel id="treatment" active={tab}>
                    <div className="space-y-6">
                        <div>
                            <SectionHeading
                                title="Active"
                                count={openTreatments.length}
                                actions={
                                    <Button
                                        size="sm"
                                        icon={Plus}
                                        onClick={() => {
                                            treatmentForm.reset();
                                            treatmentForm.clearErrors();
                                            setShowTreatment(true);
                                        }}
                                    >
                                        New treatment
                                    </Button>
                                }
                            />
                            {openTreatments.length === 0 ? (
                                <Card>
                                    <EmptyState
                                        icon={ClipboardList}
                                        title="No active treatments"
                                        description="Proposed work appears here until it is completed or cancelled."
                                    />
                                </Card>
                            ) : (
                                <div className="space-y-3">
                                    {openTreatments.map((item) => (
                                        <TreatmentCard
                                            key={item.id}
                                            item={item}
                                            onEdit={() => openTreatmentEdit(item)}
                                        />
                                    ))}
                                </div>
                            )}
                        </div>

                        {resolvedTreatments.length > 0 && (
                            <div>
                                <SectionHeading title="Resolved" count={resolvedTreatments.length} />
                                <div className="space-y-3">
                                    {resolvedTreatments.map((item) => (
                                        <TreatmentCard
                                            key={item.id}
                                            item={item}
                                            onEdit={() => openTreatmentEdit(item)}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                </TabPanel>

                <TabPanel id="prescriptions" active={tab}>
                    <PrescriptionsTab
                        patient={patient}
                        prescriptions={prescriptions}
                        providers={providers}
                        appointments={appointments}
                    />
                </TabPanel>

                <TabPanel id="billing" active={tab}>
                    <BillingTab
                        patient={patient}
                        invoices={invoices}
                        billableTreatmentItems={billableTreatmentItems}
                    />
                </TabPanel>
            </PageContainer>

            {/* Edit patient */}
            <Modal
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    patientForm.put(route('patients.update', patient.id), {
                        onSuccess: () => setShowEdit(false),
                    });
                }}
                show={showEdit}
                onClose={() => setShowEdit(false)}
                closeable={!patientForm.processing}
                title="Edit patient details"
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowEdit(false)} disabled={patientForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={patientForm.processing}>
                            {patientForm.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    {[
                        ['First name', 'first_name', { required: true }],
                        ['Last name', 'last_name', { required: true }],
                        ['Date of birth', 'date_of_birth', { type: 'date' }],
                        ['Phone', 'phone', { type: 'tel' }],
                        ['Email', 'email', { type: 'email' }],
                        ['Recall interval (months)', 'recall_interval_months', { type: 'number', min: 1, max: 60 }],
                        ['Emergency contact', 'emergency_contact_name', {}],
                        ['Emergency contact phone', 'emergency_contact_phone', { type: 'tel' }],
                    ].map(([label, name, props]) => (
                        <Field
                            key={name}
                            label={label}
                            value={patientForm.data[name]}
                            onChange={(e) => patientForm.setData(name, e.target.value)}
                            error={patientForm.errors[name]}
                            {...props}
                        />
                    ))}
                </div>
                <TextareaField
                    label="Notes"
                    className="mt-4"
                    value={patientForm.data.notes}
                    onChange={(e) => patientForm.setData('notes', e.target.value)}
                    error={patientForm.errors.notes}
                    hint="Shown as an alert at the top of this patient's record."
                />
            </Modal>

            {/* New dental record */}
            <Modal
                as="form"
                onSubmit={submit(recordForm, route('dental-records.store', patient.id), () => setShowRecord(false))}
                show={showRecord}
                onClose={() => setShowRecord(false)}
                closeable={!recordForm.processing}
                title="New dental record"
                description="Records are append-only. Once saved this cannot be edited or removed."
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowRecord(false)} disabled={recordForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={recordForm.processing}>
                            {recordForm.processing ? 'Saving…' : 'Save record'}
                        </Button>
                    </>
                }
            >
                {recordForm.errors.clinical_content && (
                    <p className="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-sm font-medium text-rose-700">
                        {recordForm.errors.clinical_content}
                    </p>
                )}

                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="Type"
                        value={recordForm.data.type}
                        onChange={(e) => recordForm.setData('type', e.target.value)}
                        error={recordForm.errors.type}
                    >
                        {RECORD_TYPES.map((type) => (
                            <option key={type} value={type}>
                                {type.replace('_', ' ')}
                            </option>
                        ))}
                    </SelectField>
                    <ProviderPicker
                        providers={providers}
                        value={recordForm.data.provider_id}
                        onChange={(e) => recordForm.setData('provider_id', e.target.value)}
                        error={recordForm.errors.provider_id}
                    />
                    <AppointmentPicker
                        appointments={appointments}
                        value={recordForm.data.appointment_id}
                        onChange={(e) => recordForm.setData('appointment_id', e.target.value)}
                        error={recordForm.errors.appointment_id}
                    />
                </div>

                <div className="mt-4 space-y-4">
                    {[
                        ['Examination', 'examination'],
                        ['Diagnosis', 'diagnosis'],
                        ['Procedure', 'procedure'],
                        ['Notes', 'notes'],
                    ].map(([label, name]) => (
                        <TextareaField
                            key={name}
                            label={label}
                            rows={2}
                            value={recordForm.data[name]}
                            onChange={(e) => recordForm.setData(name, e.target.value)}
                            error={recordForm.errors[name]}
                        />
                    ))}
                </div>
            </Modal>

            {/* Tooth history + new entry */}
            <Modal
                as="form"
                onSubmit={submit(toothForm, route('tooth-conditions.store', patient.id), () =>
                    setSelectedTooth(null),
                )}
                show={selectedTooth !== null}
                onClose={() => setSelectedTooth(null)}
                closeable={!toothForm.processing}
                title={`Tooth ${selectedTooth ?? ''}`}
                description="Chart entries are append-only — a change of condition is a new entry, not an edit."
                width="xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setSelectedTooth(null)} disabled={toothForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={toothForm.processing}>
                            {toothForm.processing ? 'Saving…' : 'Add entry'}
                        </Button>
                    </>
                }
            >
                {selectedToothEntries.length > 0 ? (
                    <ol className="mb-5 space-y-2">
                        {selectedToothEntries.map((entry, index) => (
                            <li
                                key={entry.id}
                                className={`rounded-lg border px-3 py-2.5 ${
                                    index === 0 ? 'border-brand-200 bg-brand-50/50' : 'border-slate-200 bg-slate-50'
                                }`}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <span className="text-sm font-medium text-slate-900">
                                        {toothCondition(entry.condition).label}
                                        {index === 0 && (
                                            <span className="ms-2 text-xs font-normal text-brand-700">current</span>
                                        )}
                                    </span>
                                    <span className="tabular text-xs text-slate-500">
                                        {formatDate(entry.created_at)}
                                    </span>
                                </div>
                                {entry.notes && <p className="mt-1 text-sm text-slate-700">{entry.notes}</p>}
                                <p className="mt-1 text-xs text-slate-400">
                                    {entry.creator_name}
                                    {entry.provider_name && ` · ${entry.provider_name}`}
                                </p>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="mb-5 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-500">
                        Nothing charted on this tooth yet.
                    </p>
                )}

                <div className="border-t border-slate-200 pt-4">
                    <h3 className="mb-3 text-sm font-semibold text-slate-900">Add an entry</h3>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <SelectField
                            label="Condition"
                            value={toothForm.data.condition}
                            onChange={(e) => toothForm.setData('condition', e.target.value)}
                            error={toothForm.errors.condition}
                        >
                            {TOOTH_CONDITIONS.map((condition) => (
                                <option key={condition} value={condition}>
                                    {toothCondition(condition).label}
                                </option>
                            ))}
                        </SelectField>
                        <ProviderPicker
                            providers={providers}
                            value={toothForm.data.provider_id}
                            onChange={(e) => toothForm.setData('provider_id', e.target.value)}
                            error={toothForm.errors.provider_id}
                        />
                        <AppointmentPicker
                            appointments={appointments}
                            value={toothForm.data.appointment_id}
                            onChange={(e) => toothForm.setData('appointment_id', e.target.value)}
                            error={toothForm.errors.appointment_id}
                        />
                    </div>
                    <TextareaField
                        label="Notes"
                        className="mt-4"
                        rows={2}
                        value={toothForm.data.notes}
                        onChange={(e) => toothForm.setData('notes', e.target.value)}
                        error={toothForm.errors.notes}
                    />
                </div>
            </Modal>

            {/* New treatment item */}
            <Modal
                as="form"
                onSubmit={submit(treatmentForm, route('treatment-plan-items.store', patient.id), () =>
                    setShowTreatment(false),
                )}
                show={showTreatment}
                onClose={() => setShowTreatment(false)}
                closeable={!treatmentForm.processing}
                title="New treatment item"
                description="Treatment, tooth, provider, and linked appointment are fixed once saved."
                width="2xl"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setShowTreatment(false)}
                            disabled={treatmentForm.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={treatmentForm.processing}>
                            {treatmentForm.processing ? 'Saving…' : 'Add treatment'}
                        </Button>
                    </>
                }
            >
                <Field
                    label="Treatment"
                    required
                    value={treatmentForm.data.treatment}
                    onChange={(e) => treatmentForm.setData('treatment', e.target.value)}
                    error={treatmentForm.errors.treatment}
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="Tooth"
                        value={treatmentForm.data.tooth_number}
                        onChange={(e) => treatmentForm.setData('tooth_number', e.target.value)}
                        error={treatmentForm.errors.tooth_number}
                    >
                        <option value="">Whole mouth</option>
                        {ALL_TEETH.map((number) => (
                            <option key={number} value={number}>
                                Tooth {number}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label="Priority"
                        value={treatmentForm.data.priority}
                        onChange={(e) => treatmentForm.setData('priority', e.target.value)}
                        error={treatmentForm.errors.priority}
                    >
                        {TREATMENT_PRIORITIES.map((priority) => (
                            <option key={priority} value={priority}>
                                {priority}
                            </option>
                        ))}
                    </SelectField>
                    <ProviderPicker
                        providers={providers}
                        value={treatmentForm.data.provider_id}
                        onChange={(e) => treatmentForm.setData('provider_id', e.target.value)}
                        error={treatmentForm.errors.provider_id}
                    />
                    <Field
                        label="Estimated cost (₱)"
                        type="number"
                        min="0"
                        step="0.01"
                        inputClassName="tabular"
                        value={treatmentForm.data.estimated_cost}
                        onChange={(e) => treatmentForm.setData('estimated_cost', e.target.value)}
                        error={treatmentForm.errors.estimated_cost}
                    />
                    <AppointmentPicker
                        appointments={appointments}
                        value={treatmentForm.data.appointment_id}
                        onChange={(e) => treatmentForm.setData('appointment_id', e.target.value)}
                        error={treatmentForm.errors.appointment_id}
                    />
                </div>

                <TextareaField
                    label="Notes"
                    className="mt-4"
                    rows={2}
                    value={treatmentForm.data.notes}
                    onChange={(e) => treatmentForm.setData('notes', e.target.value)}
                    error={treatmentForm.errors.notes}
                />
            </Modal>

            {/* Edit treatment item */}
            <Modal
                as="form"
                onSubmit={(event) => {
                    event.preventDefault();
                    treatmentEditForm.patch(
                        route('treatment-plan-items.update', {
                            patient: patient.id,
                            treatmentPlanItem: editingTreatment.id,
                        }),
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                treatmentEditForm.reset();
                                setEditingTreatment(null);
                            },
                        },
                    );
                }}
                show={editingTreatment !== null}
                onClose={() => setEditingTreatment(null)}
                closeable={!treatmentEditForm.processing}
                title={editingTreatment?.treatment ?? 'Edit treatment'}
                description="Status, priority, cost, and notes can change. The treatment itself and its tooth cannot."
                width="lg"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setEditingTreatment(null)}
                            disabled={treatmentEditForm.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={treatmentEditForm.processing}>
                            {treatmentEditForm.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    </>
                }
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="Status"
                        value={treatmentEditForm.data.status}
                        onChange={(e) => treatmentEditForm.setData('status', e.target.value)}
                        error={treatmentEditForm.errors.status}
                    >
                        {TREATMENT_STATUSES.map((status) => (
                            <option key={status} value={status}>
                                {status.replace('_', ' ')}
                            </option>
                        ))}
                    </SelectField>
                    <SelectField
                        label="Priority"
                        value={treatmentEditForm.data.priority}
                        onChange={(e) => treatmentEditForm.setData('priority', e.target.value)}
                        error={treatmentEditForm.errors.priority}
                    >
                        {TREATMENT_PRIORITIES.map((priority) => (
                            <option key={priority} value={priority}>
                                {priority}
                            </option>
                        ))}
                    </SelectField>
                    <Field
                        label="Estimated cost (₱)"
                        type="number"
                        min="0"
                        step="0.01"
                        className="sm:col-span-2"
                        inputClassName="tabular"
                        value={treatmentEditForm.data.estimated_cost}
                        onChange={(e) => treatmentEditForm.setData('estimated_cost', e.target.value)}
                        error={treatmentEditForm.errors.estimated_cost}
                    />
                </div>
                <TextareaField
                    label="Notes"
                    className="mt-4"
                    rows={2}
                    value={treatmentEditForm.data.notes}
                    onChange={(e) => treatmentEditForm.setData('notes', e.target.value)}
                    error={treatmentEditForm.errors.notes}
                />
            </Modal>
        </AuthenticatedLayout>
    );
}

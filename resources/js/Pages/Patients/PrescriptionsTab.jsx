import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import Field, { SelectField, TextareaField } from '@/Components/UI/Field';
import Modal from '@/Components/UI/Modal';
import { EmptyState, SectionHeading } from '@/Components/UI/Page';
import StatusBadge from '@/Components/UI/StatusBadge';
import { prescriptionStatus } from '@/Components/UI/statuses';
import { useForm } from '@inertiajs/react';
import { Pill, Plus } from 'lucide-react';
import { useState } from 'react';
import { formatDate, formatDateTime } from './format';

function PrescriptionCard({ rx, onDiscontinue }) {
    const discontinued = rx.status === 'discontinued';

    return (
        <Card className={`p-4 ${discontinued ? 'bg-slate-50' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <h4
                            className={`text-sm font-semibold ${discontinued ? 'text-slate-500 line-through' : 'text-slate-900'}`}
                        >
                            {rx.medication}
                        </h4>
                        <StatusBadge status={prescriptionStatus(rx.status)} />
                    </div>
                    <p className="mt-1 text-sm text-slate-600">
                        {[rx.dosage, rx.frequency, rx.duration, rx.quantity].filter(Boolean).join(' · ')}
                    </p>
                </div>
                {!discontinued && (
                    <Button variant="secondary" size="sm" onClick={onDiscontinue}>
                        Discontinue
                    </Button>
                )}
            </div>

            {rx.instructions && (
                <p className="mt-2.5 rounded-lg bg-slate-50 px-3 py-2 text-sm leading-relaxed text-slate-700">
                    {rx.instructions}
                </p>
            )}

            {discontinued && (
                <p className="mt-2.5 text-xs text-slate-600">
                    Discontinued {formatDate(rx.discontinued_at)}
                    {rx.discontinued_reason && ` — ${rx.discontinued_reason}`}
                </p>
            )}

            <p className="mt-3 border-t border-slate-100 pt-2.5 text-xs text-slate-400">
                Prescribed by {rx.creator_name} on {formatDate(rx.created_at)}
                {rx.provider_name && ` · ${rx.provider_name}`}
                {rx.appointment_start_time && ` · linked to ${formatDateTime(rx.appointment_start_time)}`}
            </p>
        </Card>
    );
}

export default function PrescriptionsTab({ patient, prescriptions, providers, appointments }) {
    const [showNew, setShowNew] = useState(false);
    const [discontinuing, setDiscontinuing] = useState(null);

    const newForm = useForm({
        medication: '',
        dosage: '',
        frequency: '',
        duration: '',
        quantity: '',
        provider_id: '',
        appointment_id: '',
        instructions: '',
    });

    const discontinueForm = useForm({ discontinued_reason: '' });

    function openNew() {
        newForm.reset();
        newForm.clearErrors();
        setShowNew(true);
    }

    function submitNew(event) {
        event.preventDefault();
        newForm.post(route('prescriptions.store', patient.id), {
            preserveScroll: true,
            onSuccess: () => {
                newForm.reset();
                setShowNew(false);
            },
        });
    }

    function openDiscontinue(rx) {
        discontinueForm.reset();
        discontinueForm.clearErrors();
        setDiscontinuing(rx);
    }

    function submitDiscontinue(event) {
        event.preventDefault();
        discontinueForm.patch(
            route('prescriptions.update', { patient: patient.id, prescription: discontinuing.id }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    discontinueForm.reset();
                    setDiscontinuing(null);
                },
            },
        );
    }

    const active = prescriptions.filter((rx) => rx.status === 'active');
    const discontinued = prescriptions.filter((rx) => rx.status === 'discontinued');

    return (
        <div className="space-y-6">
            <div>
                <SectionHeading
                    title="Active"
                    count={active.length}
                    actions={
                        <Button size="sm" icon={Plus} onClick={openNew}>
                            New prescription
                        </Button>
                    }
                />
                {active.length === 0 ? (
                    <Card>
                        <EmptyState
                            icon={Pill}
                            title="No active prescriptions"
                            description="Prescriptions recorded here are for the patient's chart — nothing is transmitted to a pharmacy."
                        />
                    </Card>
                ) : (
                    <div className="space-y-3">
                        {active.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} onDiscontinue={() => openDiscontinue(rx)} />
                        ))}
                    </div>
                )}
            </div>

            {discontinued.length > 0 && (
                <div>
                    <SectionHeading title="Discontinued" count={discontinued.length} />
                    <div className="space-y-3">
                        {discontinued.map((rx) => (
                            <PrescriptionCard key={rx.id} rx={rx} />
                        ))}
                    </div>
                </div>
            )}

            <Modal
                as="form"
                onSubmit={submitNew}
                show={showNew}
                onClose={() => setShowNew(false)}
                closeable={!newForm.processing}
                title="New prescription"
                description="Clinical content is fixed once saved — a prescription can later be discontinued, never edited."
                width="2xl"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setShowNew(false)} disabled={newForm.processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={newForm.processing}>
                            {newForm.processing ? 'Saving…' : 'Save prescription'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-4">
                    <Field
                        label="Medication"
                        required
                        value={newForm.data.medication}
                        onChange={(e) => newForm.setData('medication', e.target.value)}
                        error={newForm.errors.medication}
                    />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Dosage"
                            required
                            placeholder="500 mg"
                            value={newForm.data.dosage}
                            onChange={(e) => newForm.setData('dosage', e.target.value)}
                            error={newForm.errors.dosage}
                        />
                        <Field
                            label="Frequency"
                            required
                            placeholder="Three times daily"
                            value={newForm.data.frequency}
                            onChange={(e) => newForm.setData('frequency', e.target.value)}
                            error={newForm.errors.frequency}
                        />
                        <Field
                            label="Duration"
                            placeholder="7 days"
                            value={newForm.data.duration}
                            onChange={(e) => newForm.setData('duration', e.target.value)}
                            error={newForm.errors.duration}
                        />
                        <Field
                            label="Quantity"
                            placeholder="21 capsules"
                            value={newForm.data.quantity}
                            onChange={(e) => newForm.setData('quantity', e.target.value)}
                            error={newForm.errors.quantity}
                        />
                        <SelectField
                            label="Prescribing provider"
                            value={newForm.data.provider_id}
                            onChange={(e) => newForm.setData('provider_id', e.target.value)}
                            error={newForm.errors.provider_id}
                        >
                            <option value="">No provider</option>
                            {providers.map((provider) => (
                                <option key={provider.id} value={provider.id}>
                                    {provider.name}
                                </option>
                            ))}
                        </SelectField>
                        <SelectField
                            label="Linked appointment"
                            value={newForm.data.appointment_id}
                            onChange={(e) => newForm.setData('appointment_id', e.target.value)}
                            error={newForm.errors.appointment_id}
                        >
                            <option value="">No linked appointment</option>
                            {appointments.map((appointment) => (
                                <option key={appointment.id} value={appointment.id}>
                                    {appointment.start_time
                                        ? formatDateTime(appointment.start_time)
                                        : 'Unscheduled'}{' '}
                                    — {appointment.type ?? 'request'}
                                </option>
                            ))}
                        </SelectField>
                    </div>

                    <TextareaField
                        label="Instructions"
                        value={newForm.data.instructions}
                        onChange={(e) => newForm.setData('instructions', e.target.value)}
                        error={newForm.errors.instructions}
                        hint="What the patient should be told — timing, food, warnings."
                    />
                </div>
            </Modal>

            <Modal
                as="form"
                onSubmit={submitDiscontinue}
                show={discontinuing !== null}
                onClose={() => setDiscontinuing(null)}
                closeable={!discontinueForm.processing}
                title={`Discontinue ${discontinuing?.medication ?? ''}`}
                description="The prescription stays on the record — this marks it no longer active, and cannot be undone."
                width="md"
                footer={
                    <>
                        <Button
                            variant="secondary"
                            onClick={() => setDiscontinuing(null)}
                            disabled={discontinueForm.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" variant="danger-solid" disabled={discontinueForm.processing}>
                            {discontinueForm.processing ? 'Working…' : 'Discontinue'}
                        </Button>
                    </>
                }
            >
                <Field
                    label="Reason"
                    value={discontinueForm.data.discontinued_reason}
                    onChange={(e) => discontinueForm.setData('discontinued_reason', e.target.value)}
                    error={discontinueForm.errors.discontinued_reason}
                    hint="Optional, but it is what the next clinician reads."
                />
            </Modal>
        </div>
    );
}

import Button from '@/Components/UI/Button';
import Card from '@/Components/UI/Card';
import StatusBadge from '@/Components/UI/StatusBadge';
import { appointmentStatus } from '@/Components/UI/statuses';
import { AlertTriangle, CalendarDays, Mail, Pencil, Phone } from 'lucide-react';
import { formatDate, formatDateTime, formatPeso } from './format';

/**
 * Who this patient is and what state they are in, above the tabs.
 *
 * The page used to open on a six-field definition list inside a card:
 * true, but it answered none of the questions someone opens a patient
 * record to answer — how old are they, what do they owe, when are they
 * next in, is there anything I should know before they sit down.
 */
function initials(first, last) {
    return `${first?.[0] ?? ''}${last?.[0] ?? ''}`.toUpperCase();
}

function Stat({ label, children, tone = 'text-slate-900' }) {
    return (
        <div>
            <dt className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</dt>
            <dd className={`mt-0.5 text-sm font-medium ${tone}`}>{children ?? '—'}</dd>
        </div>
    );
}

export default function PatientHeader({ patient, summary, balance, openTreatments, activeRx, onEdit }) {
    const next = summary.next_appointment;

    return (
        <Card className="mb-5 overflow-hidden">
            <div className="flex flex-wrap items-start gap-4 p-4 sm:p-5">
                <span
                    className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-brand-50 text-base font-semibold text-brand-700"
                    aria-hidden="true"
                >
                    {initials(patient.first_name, patient.last_name)}
                </span>

                <div className="min-w-0 flex-1">
                    <h1 className="truncate text-xl font-semibold tracking-tight text-slate-900">
                        {patient.first_name} {patient.last_name}
                    </h1>
                    <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                        <span className="tabular">
                            {summary.age !== null ? `${summary.age} yrs` : 'Age unknown'}
                            {patient.date_of_birth && ` · ${formatDate(patient.date_of_birth)}`}
                        </span>
                        {patient.phone && (
                            <a
                                href={`tel:${patient.phone}`}
                                className="inline-flex items-center gap-1 hover:text-brand-700"
                            >
                                <Phone className="h-3 w-3" aria-hidden="true" />
                                {patient.phone}
                            </a>
                        )}
                        {patient.email && (
                            <a
                                href={`mailto:${patient.email}`}
                                className="inline-flex min-w-0 items-center gap-1 hover:text-brand-700"
                            >
                                <Mail className="h-3 w-3 shrink-0" aria-hidden="true" />
                                <span className="truncate">{patient.email}</span>
                            </a>
                        )}
                    </div>
                </div>

                <Button variant="secondary" size="sm" icon={Pencil} onClick={onEdit}>
                    Edit details
                </Button>
            </div>

            {patient.notes && (
                <div className="flex items-start gap-2 border-t border-amber-200 bg-amber-50 px-4 py-2.5 sm:px-5">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                    <p className="text-xs leading-relaxed text-amber-900">
                        <span className="font-semibold">Note: </span>
                        {patient.notes}
                    </p>
                </div>
            )}

            <dl className="grid grid-cols-2 gap-4 border-t border-slate-200 bg-slate-50/60 px-4 py-3 sm:grid-cols-4 sm:px-5">
                <Stat label="Next appointment">
                    {next ? (
                        <span className="flex flex-wrap items-center gap-1.5">
                            <CalendarDays className="h-3.5 w-3.5 text-slate-400" aria-hidden="true" />
                            <span className="tabular">{formatDateTime(next.start_time)}</span>
                            <StatusBadge status={appointmentStatus(next.status)} />
                        </span>
                    ) : (
                        <span className="text-slate-500">None scheduled</span>
                    )}
                </Stat>

                <Stat label="Last visit">
                    <span className="tabular">
                        {summary.last_visit ? formatDate(summary.last_visit) : (
                            <span className="text-slate-500">No completed visits</span>
                        )}
                    </span>
                </Stat>

                <Stat label="Balance" tone={balance > 0 ? 'text-amber-700' : 'text-slate-900'}>
                    <span className="tabular">{formatPeso(balance)}</span>
                </Stat>

                <Stat label="Open clinical">
                    <span className="flex flex-wrap items-center gap-1.5">
                        {openTreatments === 0 && activeRx === 0 ? (
                            <span className="text-slate-500">Nothing open</span>
                        ) : (
                            <>
                                {openTreatments > 0 && (
                                    <StatusBadge status={{ label: `${openTreatments} treatment${openTreatments === 1 ? '' : 's'}`, tone: 'warning' }} />
                                )}
                                {activeRx > 0 && (
                                    <StatusBadge status={{ label: `${activeRx} Rx`, tone: 'info' }} />
                                )}
                            </>
                        )}
                    </span>
                </Stat>
            </dl>
        </Card>
    );
}

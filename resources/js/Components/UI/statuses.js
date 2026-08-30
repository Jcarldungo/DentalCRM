/**
 * Every status in the staff app maps to a label and a tone here, and
 * nowhere else.
 *
 * Six pages used to carry their own Tailwind class strings for the same
 * statuses, so a `scheduled` appointment was grey in the workspace and
 * blue on the patient page. The tone names are semantic — reading
 * `warning` tells you what a status *means*, which is what keeps the
 * mapping honest when a new one is added.
 *
 * These label maps mirror server-side constants (Appointment::STATUSES,
 * TreatmentPlanItem::STATUSES, Invoice::STATUSES, Prescription::STATUSES,
 * StockMovement::TYPES, ToothCondition::CONDITIONS). Keep them in step —
 * a missing key falls back to the humanised key, so a drift degrades
 * rather than crashes.
 */

export const TONES = {
    neutral: 'bg-slate-100 text-slate-700 ring-slate-200',
    info: 'bg-brand-50 text-brand-700 ring-brand-200',
    progress: 'bg-violet-50 text-violet-700 ring-violet-200',
    success: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    warning: 'bg-amber-50 text-amber-800 ring-amber-200',
    danger: 'bg-rose-50 text-rose-700 ring-rose-200',
    muted: 'bg-slate-50 text-slate-500 ring-slate-200',
};

/** Solid fills, for the few places a status has to read at a glance across a room. */
export const SOLID_TONES = {
    neutral: 'bg-slate-500 text-white',
    info: 'bg-brand-600 text-white',
    progress: 'bg-violet-600 text-white',
    success: 'bg-emerald-600 text-white',
    warning: 'bg-amber-500 text-white',
    danger: 'bg-rose-600 text-white',
    muted: 'bg-slate-300 text-slate-700',
};

function humanise(value) {
    if (!value) return '—';
    return String(value).replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());
}

function make(map) {
    return (value) => map[value] ?? { label: humanise(value), tone: 'neutral' };
}

export const appointmentStatus = make({
    requested: { label: 'Requested', tone: 'warning' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    checked_in: { label: 'Checked in', tone: 'progress' },
    in_treatment: { label: 'In treatment', tone: 'success' },
    completed: { label: 'Completed', tone: 'muted' },
    cancelled: { label: 'Cancelled', tone: 'neutral' },
    no_show: { label: 'No-show', tone: 'danger' },
    declined: { label: 'Declined', tone: 'neutral' },
});

export const treatmentStatus = make({
    planned: { label: 'Planned', tone: 'neutral' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    in_progress: { label: 'In progress', tone: 'progress' },
    completed: { label: 'Completed', tone: 'success' },
    cancelled: { label: 'Cancelled', tone: 'muted' },
});

export const treatmentPriority = make({
    low: { label: 'Low', tone: 'muted' },
    medium: { label: 'Medium', tone: 'warning' },
    high: { label: 'High', tone: 'danger' },
});

export const invoiceStatus = make({
    draft: { label: 'Draft', tone: 'neutral' },
    issued: { label: 'Issued', tone: 'info' },
    void: { label: 'Void', tone: 'muted' },
    paid: { label: 'Paid', tone: 'success' },
    outstanding: { label: 'Outstanding', tone: 'warning' },
});

/** Derived, not stored: an issued invoice with no balance left reads as paid. */
export function invoiceDisplayStatus(invoice) {
    if (invoice.status === 'issued') {
        return invoiceStatus(invoice.is_paid ? 'paid' : 'outstanding');
    }

    return invoiceStatus(invoice.status);
}

export const prescriptionStatus = make({
    active: { label: 'Active', tone: 'success' },
    discontinued: { label: 'Discontinued', tone: 'muted' },
});

export const stockStatus = make({
    ok: { label: 'In stock', tone: 'success' },
    low: { label: 'Low stock', tone: 'warning' },
    out: { label: 'Out of stock', tone: 'danger' },
});

export const movementType = make({
    received: { label: 'Received', tone: 'success' },
    consumed: { label: 'Consumed', tone: 'info' },
    adjustment: { label: 'Adjustment', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'danger' },
});

export const inquiryStatus = make({
    new: { label: 'New', tone: 'warning' },
    read: { label: 'Read', tone: 'muted' },
});

/**
 * Tooth conditions.
 *
 * `swatch` is the chart's fill and `dot` the legend's — separate from the
 * badge tones above because a tooth is a filled shape, not a pill, and it
 * has to stay distinguishable at 40px with a number on top of it. Colour
 * is never the only channel: every tooth carries its condition in its
 * accessible name.
 */
export const TOOTH_CONDITIONS = {
    healthy: {
        label: 'Healthy',
        swatch: 'border-emerald-400 bg-emerald-100 text-emerald-900',
        dot: 'border-emerald-400 bg-emerald-100',
    },
    caries: {
        label: 'Caries',
        swatch: 'border-rose-400 bg-rose-100 text-rose-900',
        dot: 'border-rose-400 bg-rose-100',
    },
    filling: {
        label: 'Filling',
        swatch: 'border-brand-400 bg-brand-100 text-brand-900',
        dot: 'border-brand-400 bg-brand-100',
    },
    crown: {
        label: 'Crown',
        swatch: 'border-amber-400 bg-amber-100 text-amber-900',
        dot: 'border-amber-400 bg-amber-100',
    },
    missing: {
        label: 'Missing',
        swatch: 'border-slate-300 bg-slate-100 text-slate-400 line-through',
        dot: 'border-slate-300 bg-slate-100',
    },
    extraction: {
        label: 'For extraction',
        swatch: 'border-slate-600 bg-slate-500 text-white',
        dot: 'border-slate-600 bg-slate-500',
    },
    root_canal: {
        label: 'Root canal',
        swatch: 'border-violet-400 bg-violet-100 text-violet-900',
        dot: 'border-violet-400 bg-violet-100',
    },
    implant: {
        label: 'Implant',
        swatch: 'border-teal-400 bg-teal-100 text-teal-900',
        dot: 'border-teal-400 bg-teal-100',
    },
    other: {
        label: 'Other',
        swatch: 'border-orange-400 bg-orange-100 text-orange-900',
        dot: 'border-orange-400 bg-orange-100',
    },
};

export const UNCHARTED_TOOTH = {
    label: 'No history',
    swatch: 'border-slate-200 bg-white text-slate-400',
    dot: 'border-slate-200 bg-white',
};

export function toothCondition(condition) {
    return TOOTH_CONDITIONS[condition] ?? UNCHARTED_TOOTH;
}

export { humanise };

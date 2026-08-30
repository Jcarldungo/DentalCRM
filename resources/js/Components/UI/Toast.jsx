import { usePage } from '@inertiajs/react';
import { CheckCircle2, X, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

/**
 * Success and error feedback.
 *
 * Nothing in the app confirmed a save: every controller returned back()
 * and the page simply re-rendered, so a staff member had to re-read the
 * list to know whether their edit landed. This renders the fixed
 * flash.success / flash.error shape shared by HandleInertiaRequests.
 *
 * role="status" and aria-live="polite": announced to a screen reader
 * without interrupting whatever it is currently reading, which is right
 * for a confirmation and would be wrong for an alert.
 */
const VISIBLE_MS = 5000;

export default function Toast() {
    const { flash } = usePage().props;
    const [dismissed, setDismissed] = useState(false);

    const message = flash?.success ?? flash?.error ?? null;
    const isError = Boolean(flash?.error);

    // Keyed on the message so a second identical save still re-shows it.
    useEffect(() => {
        if (!message) return undefined;

        setDismissed(false);
        const timer = setTimeout(() => setDismissed(true), VISIBLE_MS);

        return () => clearTimeout(timer);
    }, [message, flash]);

    if (!message || dismissed) return null;

    const Icon = isError ? XCircle : CheckCircle2;

    return (
        <div
            role="status"
            aria-live="polite"
            className="pointer-events-none fixed inset-x-0 top-3 z-[60] flex justify-center px-4"
        >
            <div
                className={`animate-toast-in pointer-events-auto flex max-w-md items-start gap-3 rounded-xl border px-4 py-3 shadow-lg ${
                    isError ? 'border-rose-200 bg-rose-50' : 'border-emerald-200 bg-white'
                }`}
            >
                <Icon
                    className={`mt-0.5 h-5 w-5 shrink-0 ${isError ? 'text-rose-600' : 'text-emerald-600'}`}
                    aria-hidden="true"
                />
                <p className={`text-sm ${isError ? 'text-rose-900' : 'text-slate-800'}`}>{message}</p>
                <button
                    type="button"
                    onClick={() => setDismissed(true)}
                    aria-label="Dismiss"
                    className="-me-1 -mt-0.5 shrink-0 rounded-md p-1 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    <X className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
}

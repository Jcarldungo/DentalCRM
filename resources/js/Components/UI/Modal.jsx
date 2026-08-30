import {
    Dialog,
    DialogPanel,
    DialogTitle,
    Transition,
    TransitionChild,
} from '@headlessui/react';
import { X } from 'lucide-react';
import { Fragment } from 'react';
import Button from './Button';

/**
 * Every dialog in the staff app.
 *
 * The pages used to hand-roll `<div className="fixed inset-0 bg-black/40">`
 * overlays: no focus trap, no Escape, no scroll lock, no `role="dialog"`,
 * and focus left wherever it was when the dialog closed. Headless UI's
 * Dialog gives all of that, and routing every dialog through one
 * component means a page cannot accidentally opt out of it.
 *
 * `onClose` is wired to both the backdrop and Escape. A form mid-submit
 * passes `closeable={false}` so a stray Escape cannot abandon a save in
 * flight.
 */
const WIDTHS = {
    sm: 'sm:max-w-sm',
    md: 'sm:max-w-md',
    lg: 'sm:max-w-lg',
    xl: 'sm:max-w-xl',
    '2xl': 'sm:max-w-2xl',
    '3xl': 'sm:max-w-3xl',
};

export default function Modal({
    show,
    onClose,
    title,
    description,
    width = 'lg',
    closeable = true,
    footer,
    as = 'div',
    onSubmit,
    children,
}) {
    const close = () => closeable && onClose?.();
    const Panel = as;

    return (
        <Transition show={show} as={Fragment}>
            <Dialog as="div" className="relative z-50" onClose={close}>
                <TransitionChild
                    as={Fragment}
                    enter="ease-out duration-150"
                    enterFrom="opacity-0"
                    enterTo="opacity-100"
                    leave="ease-in duration-100"
                    leaveFrom="opacity-100"
                    leaveTo="opacity-0"
                >
                    <div className="fixed inset-0 bg-slate-900/40 backdrop-blur-[1px]" />
                </TransitionChild>

                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4">
                        <TransitionChild
                            as={Fragment}
                            enter="ease-out duration-150"
                            enterFrom="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-[0.98]"
                            enterTo="opacity-100 translate-y-0 sm:scale-100"
                            leave="ease-in duration-100"
                            leaveFrom="opacity-100 translate-y-0 sm:scale-100"
                            leaveTo="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-[0.98]"
                        >
                            <DialogPanel
                                as={Panel}
                                onSubmit={onSubmit}
                                className={`relative flex w-full max-h-[92vh] flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl ${WIDTHS[width] ?? WIDTHS.lg}`}
                            >
                                <div className="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4">
                                    <div className="min-w-0">
                                        <DialogTitle className="text-base font-semibold text-slate-900">
                                            {title}
                                        </DialogTitle>
                                        {description && (
                                            <p className="mt-0.5 text-sm text-slate-500">{description}</p>
                                        )}
                                    </div>
                                    {closeable && (
                                        <button
                                            type="button"
                                            onClick={close}
                                            aria-label="Close"
                                            className="-me-1.5 -mt-1 shrink-0 rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                        >
                                            <X className="h-5 w-5" aria-hidden="true" />
                                        </button>
                                    )}
                                </div>

                                <div className="min-h-0 flex-1 overflow-y-auto px-5 py-4">{children}</div>

                                {footer && (
                                    <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                                        {footer}
                                    </div>
                                )}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}

/**
 * A confirmation for one destructive action.
 *
 * Replaces window.confirm(), which cannot say what the consequence is,
 * cannot be styled to signal danger, and cannot be dismissed by anything
 * but its own two buttons.
 */
export function ConfirmDialog({
    show,
    onClose,
    onConfirm,
    title,
    body,
    confirmLabel = 'Confirm',
    variant = 'danger-solid',
    processing = false,
}) {
    return (
        <Modal
            show={show}
            onClose={onClose}
            title={title}
            width="md"
            closeable={!processing}
            footer={
                <>
                    <Button variant="secondary" onClick={onClose} disabled={processing}>
                        Cancel
                    </Button>
                    <Button variant={variant} onClick={onConfirm} disabled={processing}>
                        {processing ? 'Working…' : confirmLabel}
                    </Button>
                </>
            }
        >
            <p className="text-sm leading-relaxed text-slate-600">{body}</p>
        </Modal>
    );
}

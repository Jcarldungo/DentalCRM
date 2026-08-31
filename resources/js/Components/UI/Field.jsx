import { useId } from 'react';

/**
 * Labelled form controls.
 *
 * Every input in the staff app used to be `<label className="block">Foo</label>`
 * followed by a sibling `<input>` — visually a label, but with no
 * programmatic association, so no control had an accessible name and
 * clicking the label did nothing. These generate an id, wire `htmlFor`,
 * and link any error through `aria-describedby` + `aria-invalid`, so
 * getting that right stops being per-call-site diligence.
 */

const CONTROL =
    'block w-full rounded-xl border-slate-300 text-sm text-slate-900 shadow-sm placeholder:text-slate-400 ' +
    'focus:border-brand-500 focus:ring-brand-500 disabled:bg-slate-50 disabled:text-slate-500';

const CONTROL_INVALID = 'border-rose-400 focus:border-rose-500 focus:ring-rose-500';

function Wrapper({ id, label, error, hint, required, className = '', children }) {
    return (
        <div className={className}>
            <label htmlFor={id} className="block text-sm font-medium text-slate-700">
                {label}
                {required && (
                    <span className="text-rose-600" aria-hidden="true">
                        {' '}
                        *
                    </span>
                )}
            </label>
            <div className="mt-1.5">{children}</div>
            {hint && !error && (
                <p id={`${id}-hint`} className="mt-1 text-xs text-slate-500">
                    {hint}
                </p>
            )}
            {error && (
                <p id={`${id}-error`} className="mt-1 text-xs font-medium text-rose-600">
                    {error}
                </p>
            )}
        </div>
    );
}

function describedBy(id, error, hint) {
    if (error) return `${id}-error`;
    if (hint) return `${id}-hint`;
    return undefined;
}

export default function Field({
    label,
    error,
    hint,
    required,
    className = '',
    inputClassName = '',
    type = 'text',
    ...props
}) {
    const id = useId();

    return (
        <Wrapper id={id} label={label} error={error} hint={hint} required={required} className={className}>
            <input
                {...props}
                id={id}
                type={type}
                required={required}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={describedBy(id, error, hint)}
                className={`${CONTROL} ${error ? CONTROL_INVALID : ''} ${inputClassName}`}
            />
        </Wrapper>
    );
}

export function SelectField({
    label,
    error,
    hint,
    required,
    className = '',
    children,
    ...props
}) {
    const id = useId();

    return (
        <Wrapper id={id} label={label} error={error} hint={hint} required={required} className={className}>
            <select
                {...props}
                id={id}
                required={required}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={describedBy(id, error, hint)}
                className={`${CONTROL} ${error ? CONTROL_INVALID : ''}`}
            >
                {children}
            </select>
        </Wrapper>
    );
}

export function TextareaField({
    label,
    error,
    hint,
    required,
    className = '',
    rows = 3,
    ...props
}) {
    const id = useId();

    return (
        <Wrapper id={id} label={label} error={error} hint={hint} required={required} className={className}>
            <textarea
                {...props}
                id={id}
                rows={rows}
                required={required}
                aria-invalid={error ? 'true' : undefined}
                aria-describedby={describedBy(id, error, hint)}
                className={`${CONTROL} ${error ? CONTROL_INVALID : ''}`}
            />
        </Wrapper>
    );
}

export function CheckboxField({ label, hint, className = '', ...props }) {
    const id = useId();

    return (
        <div className={`flex items-start gap-2.5 ${className}`}>
            <input
                {...props}
                id={id}
                type="checkbox"
                className="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            <div>
                <label htmlFor={id} className="text-sm font-medium text-slate-700">
                    {label}
                </label>
                {hint && <p className="text-xs text-slate-500">{hint}</p>}
            </div>
        </div>
    );
}

/** A radio group that is a real fieldset, so the group itself has a name. */
export function RadioGroupField({ label, name, value, options, onChange, error, className = '' }) {
    return (
        <fieldset className={className}>
            <legend className="text-sm font-medium text-slate-700">{label}</legend>
            <div className="mt-2 flex flex-wrap gap-x-5 gap-y-2">
                {options.map((option) => (
                    <label key={option.value} className="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="radio"
                            name={name}
                            value={option.value}
                            checked={value === option.value}
                            onChange={(e) => onChange(e.target.value)}
                            className="h-4 w-4 border-slate-300 text-brand-600 focus:ring-brand-500"
                        />
                        {option.label}
                    </label>
                ))}
            </div>
            {error && <p className="mt-1 text-xs font-medium text-rose-600">{error}</p>}
        </fieldset>
    );
}

export { CONTROL as controlClasses };

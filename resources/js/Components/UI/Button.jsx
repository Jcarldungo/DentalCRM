import { Link } from '@inertiajs/react';
import { forwardRef } from 'react';

/**
 * The staff app's one button.
 *
 * `variant` carries meaning, not decoration: `primary` is the single
 * action a page wants you to take, `danger` is destructive and never
 * looks like anything else, and `ghost` is for the low-stakes actions
 * that used to be bare `text-sm text-blue-600` links sitting at the same
 * visual weight as a delete.
 */
const VARIANTS = {
    primary:
        'bg-brand-600 text-white shadow-sm hover:bg-brand-700 active:bg-brand-800 disabled:hover:bg-brand-600',
    secondary:
        'border border-slate-300 bg-white text-slate-700 shadow-sm hover:bg-slate-50 active:bg-slate-100',
    ghost: 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
    danger: 'border border-rose-200 bg-white text-rose-700 shadow-sm hover:bg-rose-50 active:bg-rose-100',
    'danger-solid':
        'bg-rose-600 text-white shadow-sm hover:bg-rose-700 active:bg-rose-800 disabled:hover:bg-rose-600',
};

/* Every size clears a 40px hit target except `xs`, which is only used
   inside cards that are themselves a target. */
const SIZES = {
    xs: 'h-7 gap-1 px-2 text-xs',
    sm: 'h-9 gap-1.5 px-3 text-sm',
    md: 'h-10 gap-2 px-4 text-sm',
};

const BASE =
    'inline-flex shrink-0 items-center justify-center whitespace-nowrap rounded-xl font-medium transition-colors duration-100 ' +
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 ' +
    'disabled:cursor-not-allowed disabled:opacity-50';

export function buttonClasses({ variant = 'primary', size = 'md', className = '' } = {}) {
    return [BASE, VARIANTS[variant] ?? VARIANTS.primary, SIZES[size] ?? SIZES.md, className]
        .filter(Boolean)
        .join(' ');
}

const Button = forwardRef(function Button(
    { variant = 'primary', size = 'md', className = '', type = 'button', icon: Icon, children, ...props },
    ref,
) {
    return (
        <button
            {...props}
            ref={ref}
            type={type}
            className={buttonClasses({ variant, size, className })}
        >
            {Icon && <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />}
            {children}
        </button>
    );
});

export default Button;

/** The same surface as an Inertia link, so a navigation never has to fake a button. */
export function ButtonLink({ variant = 'secondary', size = 'md', className = '', icon: Icon, children, ...props }) {
    return (
        <Link {...props} className={buttonClasses({ variant, size, className })}>
            {Icon && <Icon className="h-4 w-4 shrink-0" aria-hidden="true" />}
            {children}
        </Link>
    );
}

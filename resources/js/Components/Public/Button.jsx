import { Link } from '@inertiajs/react';

const VARIANTS = {
    primary: 'bg-teal-700 text-white hover:bg-teal-800',
    outline: 'border border-stone-300 text-stone-700 hover:bg-stone-50',
};

export default function Button({ href, variant = 'primary', className = '', children, ...props }) {
    const classes = `inline-flex items-center justify-center rounded-md px-5 py-2.5 text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${VARIANTS[variant]} ${className}`;

    if (href) {
        return (
            <Link href={href} className={classes} {...props}>
                {children}
            </Link>
        );
    }

    return (
        <button type="button" className={classes} {...props}>
            {children}
        </button>
    );
}

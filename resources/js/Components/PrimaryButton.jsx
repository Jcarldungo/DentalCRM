import Button from '@/Components/UI/Button';

/**
 * Kept as the name Breeze's auth pages import. It is now a thin alias
 * over Components/UI/Button so the sign-in screens and the staff app
 * share one button, rather than the app having two that look different.
 */
export default function PrimaryButton({ className = '', disabled, children, ...props }) {
    return (
        <Button type="submit" className={className} disabled={disabled} {...props}>
            {children}
        </Button>
    );
}

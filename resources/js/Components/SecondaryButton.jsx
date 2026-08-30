import Button from '@/Components/UI/Button';

/** Alias over Components/UI/Button — see PrimaryButton. */
export default function SecondaryButton({ className = '', disabled, children, ...props }) {
    return (
        <Button variant="secondary" className={className} disabled={disabled} {...props}>
            {children}
        </Button>
    );
}
